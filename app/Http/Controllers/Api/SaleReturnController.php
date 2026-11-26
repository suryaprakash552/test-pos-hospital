<?php

namespace App\Http\Controllers\Api;

use App\Models\Sale;
use App\Models\Customer;
use App\Models\SaleReturn;
use App\Models\SaleDetails;
use App\Support\Api\ApiRequest;
use Illuminate\Http\Request;
use App\Models\SaleReturnDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Services\Pricing\CartCalculationException;
use App\Services\Pricing\SaleReturnCalculationService;
use App\Http\Controllers\Controller;
use App\Models\LoyaltyAudit;
use App\Services\LoyaltyDiscount;
use App\Services\LoyaltyReturnAdjustment;
use App\Models\Stock;
use App\Services\CacheService;
use App\Services\Inventory\MovementContext;
use App\Services\InventoryChangeService;

class SaleReturnController extends Controller
{
    public function __construct(
        private readonly SaleReturnCalculationService $returnCalculator,
        private readonly InventoryChangeService $inventoryChanges,
    )
    {
    }

    /**
     * Recast the parallel request arrays as the line shape the calculator takes.
     *
     * The endpoint receives `sale_detail_id[]` and `return_qty[]` as two lists
     * indexed in step; everything monetary that used to ride alongside them is
     * dropped here rather than carried forward.
     *
     * @return array<int, array<string, mixed>>
     */
    private function returnLinesFrom(Request $request): array
    {
        $ids = (array) $request->input('sale_detail_id', []);
        $quantities = (array) $request->input('return_qty', []);

        $lines = [];

        foreach ($ids as $key => $id) {
            $lines[] = [
                'sale_detail_id' => $id,
                'return_qty' => $quantities[$key] ?? null,
            ];
        }

        return $lines;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // `details.saleDetail.product` alongside the plain `details` already
        // being loaded: the return-report screen showed a total per
        // transaction with no way to tell which product it was for, because
        // this relation chain — it already existed on the models — was never
        // walked to the product at the end of it.
        $data = SaleReturn::with(
            'sale:id,customer_id,isPaid,totalAmount,dueAmount,paidAmount,invoiceNumber',
            'sale.party:id,name',
            'details',
            'details.saleDetail:id,product_id',
            'details.saleDetail.product:id,productName,productCode',
        )
            ->whereBetween('return_date', [request()->start_date, request()->end_date])
            ->where('business_id', auth()->user()->business_id)
            ->latest();

        // Bounded. A wide enough date range returned every return the shop had
        // ever made, each with its lines and their products eager-loaded — the
        // response grows without limit and the app has to hold all of it.
        // `data` stays a flat list so the existing parse is unchanged.
        $page = $data->paginate(ApiRequest::perPage(request(), 100, 500));

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'data' => $page->items(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    /**
     * Put returned goods back into the purchase lots they were sold out of.
     *
     * The sale consumes lots FIFO, zeroing the oldest before touching the next,
     * so a return refills newest-first — the reverse of the order the goods left
     * in. Each lot is capped at what it was bought with (`quantities` in upper
     * units, times the conversion), so a return can never inflate a lot beyond
     * the stock that lot ever held.
     *
     * Any remainder that will not fit — possible only when a *purchase* return
     * shrank a lot after the sale — lands on the newest lot rather than being
     * dropped. The goods are on the shelf either way, and a till that cannot see
     * them is the worse error.
     */
    /**
     * Puts returned units back on the batch's lots, newest first.
     *
     * Returns what each lot was credited, keyed by lot id, so the caller can
     * record one event per lot. Previously this returned nothing and the caller
     * wrote a single aggregate event with no `purchase_detail_id`, which left
     * the history able to say a batch gained stock but not which lot did - the
     * one question a lot-level audit exists to answer.
     *
     * @return array<int,float> purchase_detail_id => credited lower units
     */
    private function restoreLots(int $stockId, float $quantity): array
    {
        if ($quantity <= 0) {
            return [];
        }

        $lots = DB::table('purchase_details')
            ->where('stock_id', $stockId)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get(['id', 'quantities', 'conversionQuantity', 'conversionValue']);

        if ($lots->isEmpty()) {
            // Historical stock rows created outside the purchase-lot ledger do
            // not carry enough provenance to safely invent a source lot here.
            // Refuse atomically rather than increasing the redundant tally and
            // creating inventory that the authoritative ledger cannot see.
            throw CartCalculationException::reject(
                'inventory_lot_restoration_unavailable',
                'This return cannot be completed because its original inventory lot is unavailable for safe restoration.',
                ['stock_id' => $stockId],
                409,
            );
        }

        $batchConversion = (float) (DB::table('stocks')
            ->where('id', $stockId)
            ->value('conversionValue') ?: 1);

        $remaining = $quantity;
        $credits = [];

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            // The lot's own conversion, because that is what its
            // `conversionQuantity` was filled with when it was bought; the two
            // disagree on a batch whose conversion was edited afterwards.
            $conversionValue = (float) ($lot->conversionValue ?: $batchConversion);
            $conversionValue = $conversionValue > 0 ? $conversionValue : 1.0;

            $capacity = (float) $lot->quantities * $conversionValue;
            $headroom = $capacity - (float) $lot->conversionQuantity;

            if ($headroom <= 0) {
                continue;
            }

            $credit = min($remaining, $headroom);
            $remaining -= $credit;

            DB::table('purchase_details')
                ->where('id', $lot->id)
                ->increment('conversionQuantity', $credit);

            $credits[(int) $lot->id] = round(($credits[(int) $lot->id] ?? 0) + $credit, 4);
        }

        if ($remaining > 0) {
            $overflowLotId = (int) $lots->first()->id;

            DB::table('purchase_details')
                ->where('id', $overflowLotId)
                ->increment('conversionQuantity', $remaining);

            $credits[$overflowLotId] = round(($credits[$overflowLotId] ?? 0) + $remaining, 4);
        }

        return $credits;
    }

    public function store(Request $request)
    {
        $request->validate([
            'return_qty' => 'required',
            'return_date' => 'required',
            'return_amount' => 'required',
            'sale_detail_id' => 'required',
            'sale_id' => 'required|exists:sales,id',
            // A share of the return cannot exceed the whole of it. The app let
            // 1000 be typed, which turned a ₹900 return into a ₹9000 refund
            // with a negative non-refundable amount.
            'return_discount_percent' => 'nullable|numeric|min:0|max:100',
            'non_refundable_amount' => 'nullable|numeric|min:0',
            'cash_amount' => 'nullable|numeric|min:0',
            'upi_amount' => 'nullable|numeric|min:0',
            'card_amount' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {

            $business_id = auth()->user()->business_id;

            $sale = Sale::where('business_id', $business_id)
                ->lockForUpdate()
                ->findOrFail($request->sale_id);

            // What the return is worth, derived from the sale's own line prices.
            //
            // The refund is money leaving the business, so it cannot be taken from
            // the request. `return_amount` used to be, and was written to both the
            // refund and the customer's balance - so any figure could be refunded
            // against a sale of any size, and an outstanding due cleared with it.
            $returnTotals = $this->returnCalculator->calculate(
                $sale,
                $this->returnLinesFrom($request),
            );

            $total_return_amount = $returnTotals->totalRefund->toFloat();

            $sale_return = SaleReturn::create($request->except(
                // Replaced by server figures below.
                'return_amount', 'lossProfit', 'totalAmount', 'dueAmount',
                'paidAmount', 'discountAmount', 'final_return_amount',
                'non_refundable_amount',
                // Which sale, which tenant and who recorded it are the server's
                // to decide. They sit on the right of the union below, and PHP's
                // `+` keeps the LEFT operand - so a request naming any of them
                // won, and the explicit values never applied.
                'business_id', 'sale_id', 'user_id', 'branch_id',
            ) + [
                'business_id' => $business_id,
                'sale_id' => $sale->id,
            ]);

            // sales.party_id was renamed to customer_id: reading the old column
            // silently returned null, so this due adjustment never ran.
            $customer = Customer::where('business_id', $business_id)
                ->lockForUpdate()
                ->find($sale->customer_id);

            if ($customer) {
                // Only the part of the refund that settles what they still owe.
                $customer->update([
                    'due' => max(0, $customer->due - $returnTotals->customerDueReduction->toFloat()),
                ]);
            }

            // §11: the loyalty discount was earned on a cart of a certain size.
            // Returning part of it means some of that discount was granted against
            // goods the customer no longer has, so the invoice's share is revised
            // down using the terms the invoice was billed under — not the package
            // master, which may have changed since.
            $loyaltyColumns = [];

            if ((float) $sale->loyalty_discount_amount > 0) {
                $revised = LoyaltyReturnAdjustment::revise(
                    (float) ($sale->loyalty_eligible_amount ?? 0),
                    $total_return_amount,
                    (float) $sale->loyalty_discount_amount,
                    (string) $sale->loyalty_discount_type,
                    (float) $sale->loyalty_discount_value,
                    $sale->loyalty_max_discount_amount === null
                        ? null
                        : (float) $sale->loyalty_max_discount_amount,
                    $sale->loyalty_discount_mode === LoyaltyDiscount::MANUAL,
                );

                $loyaltyColumns = [
                    'loyalty_eligible_amount' => $revised['eligible_amount'],
                    'loyalty_discount_amount' => $revised['discount_amount'],
                ];

                if ($revised['reversed'] > 0) {
                    LoyaltyAudit::record(LoyaltyAudit::DISCOUNT_REVERSED, $sale, [
                        'customer_id' => $sale->customer_id,
                        'field'       => 'loyalty_discount_amount',
                        'old_value'   => LoyaltyAudit::stringify($sale->loyalty_discount_amount),
                        'new_value'   => LoyaltyAudit::stringify($revised['discount_amount']),
                        'reason'      => 'Sale return',
                    ]);
                }
            }

            // The amended sale, from the server's own arithmetic. These were the
            // request's `totalAmount`, `dueAmount`, `paidAmount` and a `lossProfit`
            // assembled from client figures - a return could rewrite the sale it
            // was returning against to anything at all.
            $sale->update([
                'change_amount' => 0,
            ] + $returnTotals->saleColumns() + $loyaltyColumns);

            $data = [];
            $requestedCash = max(0, (float) $request->input('cash_amount', 0));
            $requestedUpi = max(0, (float) $request->input('upi_amount', 0));
            $requestedCard = max(0, (float) $request->input('card_amount', 0));
            $hasRecordedTender = $requestedCash > 0 || $requestedUpi > 0 || $requestedCard > 0;

            // The calculator already resolved each line against this sale, checked
            // the quantity against what was sold, and priced the refund. Walking its
            // results means the loop cannot disagree with the totals just recorded.
            foreach ($returnTotals->lines as $lineIndex => $line) {

                $stock = Stock::where('business_id', $business_id)
                    ->with('product:id,conversionValue')
                    ->lockForUpdate()
                    ->findOrFail($line->stockId);

                $conversionValue = (float) ($stock->conversionValue
                    ?? $stock->product?->conversionValue
                    ?? 1);
                $conversionValue = $conversionValue > 0 ? $conversionValue : 1.0;
                $beforeRemaining = (float) DB::table('purchase_details')
                    ->where('stock_id', $stock->id)
                    ->sum('conversionQuantity');

                // `productStock` counts *upper* units (packs); a return quantity
                // is in lower ones (tablets), exactly as the sale path divides
                // by the same conversion before decrementing. Adding the raw
                // figure credited a pack for every tablet handed back.
                $stock->increment(
                    'productStock',
                    $line->returnQuantity / $conversionValue,
                );

                // The number the shop actually sees. `available_quantity` — what
                // the product list, the batch popup and the sale endpoint's own
                // stock check are all derived from — is the sum of the purchase
                // lots' `conversionQuantity`, which the sale drains FIFO. Only
                // `productStock` was being credited here, so returned goods went
                // back into a tally nothing reads and the item stayed at "0 in
                // stock" with the customer's stock sitting on the shelf.
                $credits = $this->restoreLots($stock->id, $line->returnQuantity);
                $this->inventoryChanges->synchronizeDerivedTallies($stock);

                // One event per lot credited, naming the lot.
                //
                // This wrote a single movement for the whole batch, so the
                // history recorded that stock came back without recording where
                // it landed. A return spread over two lots is two facts.
                $movement = MovementContext::forSource(
                    $sale_return,
                    'sale_return',
                    'sale-return-'.$sale_return->id,
                    'Sale return received',
                );

                foreach ($credits as $lotId => $credited) {
                    $lotAfter = (float) DB::table('purchase_details')
                        ->where('id', $lotId)
                        ->value('conversionQuantity');

                    $this->inventoryChanges->recordLotMovement(
                        $movement,
                        InventoryChangeService::SALE_RETURN_IN,
                        $stock,
                        (int) $lotId,
                        $credited,
                        ['remaining_quantity' => round($lotAfter - $credited, 4), 'original_sale_id' => $sale->id],
                        ['remaining_quantity' => $lotAfter, 'original_sale_id' => $sale->id],
                    );
                }

                SaleDetails::whereKey($line->saleDetailId)->update([
                    'lossProfit' => DB::raw('lossProfit - '.$line->profitReversal->toFloat()),
                    'quantities' => $line->remainingQuantity,
                ]);

                $data[] = [
                    'sale_detail_id' => $line->saleDetailId,
                    'sale_return_id' => $sale_return->id,
                    'return_qty' => $line->returnQuantity,
                    'business_id' => $business_id,
                    // Priced from the sale, not from the request.
                    'return_amount' => $line->refund->toFloat(),
                    'return_discount' => $request->return_discount_percent ?? 0,
                    'total_return' => $returnTotals->totalRefund->toFloat(),
                    'non_returnable' => $request->non_refundable_amount ?? 0,
                    // A payment belongs to the return event, not every item
                    // on it. Store it on its first line so dashboard sums do
                    // not multiply a multi-item refund.
                    'return_cash' => $lineIndex === 0
                        ? ($hasRecordedTender ? $requestedCash : $returnTotals->cashRefund->toFloat())
                        : 0,
                    'return_upi' => $lineIndex === 0 ? $requestedUpi : 0,
                    'return_card' => $lineIndex === 0 ? $requestedCard : 0,
                ];
            }

            SaleReturnDetails::insert($data);

            $this->inventoryChanges->invalidateInventoryAfterCommit();
            DB::commit();
            // The product list is cached for an hour and keyed on TAG_PRODUCTS.
            // Nothing here cleared it, so a sale return left every catalogue screen
            // serving the stock figures from before it: a product sold down to
            // zero still read as in stock, and the batch sheet behind it
            // disagreed with the card in front. Same reason store/update/destroy
            // on the product controller each call this.

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $sale_return,
            ]);
        } catch (ModelNotFoundException $e) {
            DB::rollback();

            // The sale, or a line of it, is not this business's. A 404 says so;
            // the generic handler below would have reported it as a server fault.
            return response()->json([
                'message' => __('Sale not found.'),
                'reason' => 'sale_not_found',
            ], 404);
        } catch (CartCalculationException $e) {
            DB::rollback();

            // A refusal the cashier can act on: which rule failed, and about what.
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
            ] + $e->context, $e->status);
        } catch (\Exception $e) {
            DB::rollback();

            Log::error('Sale return failed', [
                'business_id' => auth()->user()?->business_id,
                'user_id' => auth()->id(),
                'sale_id' => $request->sale_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => __('Failed to save sale return.')], 500);
        }
    }

    public function show($id)
    {
        $data = SaleReturn::with(
            'sale:id,customer_id,isPaid,totalAmount,dueAmount,paidAmount,invoiceNumber',
            'sale.party:id,name',
            'details',
            'details.saleDetail:id,product_id',
            'details.saleDetail.product:id,productName,productCode',
        )
            ->where('business_id', auth()->user()->business_id)
            ->findOrFail($id);

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $data,
        ]);
    }
}
