<?php

namespace App\Http\Controllers\Api;

use App\Models\Party;
use App\Models\Stock;
use App\Models\Product;
use App\Models\Purchase;
use App\Support\Api\ApiRequest;
use Illuminate\Http\Request;
use App\Models\PurchaseReturn;
use App\Models\PurchaseDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\PurchaseReturnDetail;
use App\Services\Pricing\CartCalculationException;
use App\Services\Pricing\PurchaseReturnCalculationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Services\CacheService;
use App\Services\Inventory\MovementContext;
use App\Services\InventoryChangeService;

class PurchaseReturnController extends Controller
{
    public function __construct(
        private readonly PurchaseReturnCalculationService $returnCalculator,
        private readonly InventoryChangeService $inventoryChanges,
    )
    {
    }

    /**
     * Recast the parallel request arrays as the line shape the calculator takes.
     *
     * @return array<int, array<string, mixed>>
     */
    private function returnLinesFrom(Request $request): array
    {
        $ids = (array) $request->input('purchase_detail_id', []);
        $quantities = (array) $request->input('return_qty', []);
        $freeQuantities = (array) $request->input('free_return_qty', []);

        $lines = [];

        foreach ($ids as $key => $id) {
            $lines[] = [
                'purchase_detail_id' => $id,
                'return_qty' => $quantities[$key] ?? null,
                'free_return_qty' => $freeQuantities[$key] ?? 0,
            ];
        }

        return $lines;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $data = PurchaseReturn::with('purchase:id,party_id,isPaid,totalAmount,dueAmount,paidAmount,invoiceNumber', 'purchase.party:id,name', 'details')
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
    public function store(Request $request)
    {
        $request->validate([
            'purchase_id' => 'required|exists:purchases,id',
            'return_date' => 'required',
            'purchase_detail_id' => 'required',
            'return_amount' => 'required',
            'return_qty' => 'required',
            'free_return_qty' => 'nullable',
            // Same ceiling as the sale return: a share of the return cannot
            // exceed the whole of it.
            'return_discount_percent' => 'nullable|numeric|min:0|max:100',
            'non_refundable_amount' => 'nullable|numeric|min:0',
            'cash_amount' => 'nullable|numeric|min:0',
            'upi_amount' => 'nullable|numeric|min:0',
            'card_amount' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {

            $business_id = auth()->user()->business_id;

            $purchase = Purchase::where('business_id', $business_id)
                ->lockForUpdate()
                ->findOrFail($request->purchase_id);

            // What the return is worth, derived from the purchase's own line costs,
            // and whether the stock exists to send back at all.
            //
            // `return_amount` used to come from the request and was written to both
            // the credit and the supplier's balance, and the return rewrote the
            // purchase's totals from client figures.
            $returnTotals = $this->returnCalculator->calculate(
                $purchase,
                $this->returnLinesFrom($request),
            );

            $purchase_return = PurchaseReturn::create($request->except(
                // Replaced by server figures below.
                'return_amount', 'totalAmount', 'dueAmount', 'paidAmount',
                'discountAmount', 'final_return_amount', 'non_refundable_amount',
                // As on the sale side: the request would otherwise win the union
                // for these, because `+` favours its left operand.
                'business_id', 'purchase_id', 'user_id', 'branch_id',
            ) + [
                'business_id' => $business_id,
                'purchase_id' => $purchase->id,
            ]);

            $party = Party::where('business_id', $business_id)
                ->lockForUpdate()
                ->find($purchase->party_id);

            if ($party) {
                // Only the part of the credit that clears what the shop still owes.
                $party->update([
                    'due' => max(0, $party->due - $returnTotals->supplierDueReduction->toFloat()),
                ]);
            }

            // The amended purchase, from the server's own arithmetic.
            $purchase->update([
                'change_amount' => 0,
            ] + $returnTotals->purchaseColumns());

            $data = [];
            $requestedCash = max(0, (float) $request->input('cash_amount', 0));
            $requestedUpi = max(0, (float) $request->input('upi_amount', 0));
            $requestedCard = max(0, (float) $request->input('card_amount', 0));
            $hasRecordedTender = $requestedCash > 0 || $requestedUpi > 0 || $requestedCard > 0;

            // The calculator already resolved each line against this purchase,
            // checked the quantity against what was bought, confirmed the stock is
            // still there to send back, and priced the credit.
            foreach ($returnTotals->lines as $lineIndex => $line) {

                if ($line->stockId !== null) {
                    $stock = Stock::where('id', $line->stockId)
                        ->where('business_id', $business_id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $beforeRemaining = (float) PurchaseDetails::where('stock_id', $stock->id)
                        ->sum('conversionQuantity');
                    // The exact lot this line reduces, read before the update.
                    $lotBefore = (float) PurchaseDetails::whereKey($line->purchaseDetailId)
                        ->value('conversionQuantity');
                    $stock->decrement('productStock', $line->stockReduction);
                }

                PurchaseDetails::whereKey($line->purchaseDetailId)->update([
                    'quantities' => $line->remainingQuantity,
                    'free_quantity' => $line->remainingFreeQuantity,
                    'conversionQuantity' => DB::raw(
                        'GREATEST(0, conversionQuantity - '.((float) $line->stockReduction * (float) $line->conversionValue).')'
                    ),
                ]);

                if (isset($stock)) {
                    $this->inventoryChanges->synchronizeDerivedTallies($stock);
                }

                if (isset($stock, $beforeRemaining, $lotBefore)) {
                    // Recorded against the lot the line actually reduced.
                    //
                    // The movement was written at batch level, so a purchase
                    // return could be seen in the history but not traced to the
                    // lot it came out of - and a batch bought over several lots
                    // has several answers.
                    $lotAfter = (float) PurchaseDetails::whereKey($line->purchaseDetailId)
                        ->value('conversionQuantity');

                    $this->inventoryChanges->recordLotMovement(
                        MovementContext::forSource(
                            $purchase_return,
                            'purchase_return',
                            'purchase-return-'.$purchase_return->id,
                            'Purchase return sent',
                        ),
                        InventoryChangeService::PURCHASE_RETURN_OUT,
                        $stock,
                        (int) $line->purchaseDetailId,
                        $lotAfter - $lotBefore,
                        ['remaining_quantity' => $lotBefore, 'purchase_id' => $purchase->id],
                        ['remaining_quantity' => $lotAfter, 'purchase_id' => $purchase->id],
                    );

                    unset($stock, $beforeRemaining, $lotBefore);
                }

                $data[] = [
                    'business_id' => $business_id,
                    'purchase_detail_id' => $line->purchaseDetailId,
                    'return_qty' => $line->returnQuantity,
                    'free_return_qty' => $line->freeReturnQuantity,
                    'purchase_return_id' => $purchase_return->id,
                    // Priced from the purchase, not from the request.
                    'return_amount' => $line->refund->toFloat(),
                    'return_discount' => $request->return_discount_percent ?? 0,
                    'total_return' => $returnTotals->totalRefund->toFloat(),
                    'non_returnable' => $request->non_refundable_amount ?? 0,
                    // Persist the return event's tender only once; otherwise
                    // a two-line return is counted twice by the dashboard.
                    'return_cash' => $lineIndex === 0
                        ? ($hasRecordedTender ? $requestedCash : $returnTotals->cashRefund->toFloat())
                        : 0,
                    'return_upi' => $lineIndex === 0 ? $requestedUpi : 0,
                    'return_card' => $lineIndex === 0 ? $requestedCard : 0,
                ];
            }

            PurchaseReturnDetail::insert($data);

            $this->inventoryChanges->invalidateInventoryAfterCommit();
            DB::commit();
            // The product list is cached for an hour and keyed on TAG_PRODUCTS.
            // Nothing here cleared it, so a purchase return left every catalogue screen
            // serving the stock figures from before it: a product sold down to
            // zero still read as in stock, and the batch sheet behind it
            // disagreed with the card in front. Same reason store/update/destroy
            // on the product controller each call this.

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $purchase_return,
            ]);
        } catch (ModelNotFoundException $e) {
            DB::rollback();

            // The purchase, or a line of it, is not this business's.
            return response()->json([
                'message' => __('Purchase not found.'),
                'reason' => 'purchase_not_found',
            ], 404);
        } catch (CartCalculationException $e) {
            DB::rollback();

            // A refusal the operator can act on: which rule failed, and about what.
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
            ] + $e->context, $e->status);
        } catch (\Exception $e) {
            DB::rollback();

            Log::error('Purchase return failed', [
                'business_id' => auth()->user()?->business_id,
                'user_id' => auth()->id(),
                'purchase_id' => $request->purchase_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => __('Failed to save purchase return.')], 500);
        }
    }

    public function show($id)
    {
        $data = PurchaseReturn::with('purchase:id,party_id,isPaid,totalAmount,dueAmount,paidAmount,invoiceNumber', 'purchase.party:id,name', 'details')
            ->where('business_id', auth()->user()->business_id)
            ->findOrFail($id);

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $data,
        ]);
    }
}
