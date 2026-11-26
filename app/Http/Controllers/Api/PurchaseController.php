<?php

namespace App\Http\Controllers\Api;

use App\Models\Party;
use App\Models\Stock;
use App\Services\BatchLotReconciler;
use App\Services\GstComponents;
use App\Services\GstConfiguration;
use App\Services\CacheService;
use App\Services\InventoryChangeService;
use App\Services\Pricing\CartCalculationException;
use App\Services\Pricing\PurchaseCalculationService;
use App\Models\Purchase;
use App\Models\PurchaseImportBatch;
use Illuminate\Http\Request;
use App\Models\PurchaseDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Exceptions\BatchIdentityProtected;
use App\Http\Controllers\Controller;
use App\Models\PurchaseReturn;
use App\Http\Requests\Api\PurchaseMutationRequest;
use App\Support\Api\ApiRequest;
use App\Support\Api\PurchaseLineBuilder;
use App\Support\Api\SplitPayment;
use App\Jobs\SendUserNotificationJob;
use InvalidArgumentException;
use Illuminate\Support\Str;

class PurchaseController extends Controller
{
    public function __construct(
        private readonly PurchaseCalculationService $purchaseCalculator,
        private readonly InventoryChangeService $inventoryChanges,
    )
    {
        $this->middleware('check.permission:purchases.read')
            ->only(['index', 'getBatchDetails', 'searchPurchaseInvoice']);
        $this->middleware('check.permission:purchases.create')->only('store');
        $this->middleware('check.permission:purchases.update')->only('update');
        $this->middleware('check.permission:purchases.delete')->only('destroy');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $perPage = ApiRequest::perPage($request, 50, 100);

        $query = Purchase::with([
            'user:id,name,role',
            'party:id,name,email,phone,gstin,type',
            'details',
            'details.upperSelectedUnit:id,unitName',
            'details.lowerSelectedUnit:id,unitName',
            'details.product:id,productName,productCode,hsn_code,category_id,manufacturer_id,model_id,rack,shelf,product_type,vat_id,vat_type,vat_amount',
            'details.stock' => fn ($query) => $query
                ->select('id', 'batch_no', 'expire_date')
                ->withSum('availableLots as available_quantity', 'conversionQuantity')
                ->withSum('saleDetails as sold_quantity', 'quantities'),
            'details.product.vat:id,name,rate',
            'details.product.category:id,categoryName',
            'details.product.manufacturer:id,manufacturerName',
            'details.product.product_model:id,name',
            'purchaseReturns.details',
            'vat:id,name,rate',
            'payment_type:id,name',
            'branch:id,name,phone,address'
        ])
        // Business-wide, not per-staff: purchase returns and supplier due
        // collections need the whole history, whoever recorded it.
        ->forBusiness((int) $user->business_id)
        ->when($request->boolean('returned-purchase'), function ($query) {
            $query->whereHas('purchaseReturns');
        })
        ->when($request->filled('invoiceNumber'), function ($query) use ($request) {
            $query->where('invoiceNumber', $request->input('invoiceNumber'));
        })
        ->when($request->filled('party_id'), function ($query) use ($request) {
            $query->where('party_id', $request->input('party_id'));
        })
        // The purchase report has always sent these three, and this query had no
        // clause for any of them - so the supplier, phone and minimum-amount
        // filters were accepted and silently discarded, and the list came back
        // unchanged however they were set.
        ->when($request->filled('name'), function ($query) use ($request) {
            $query->whereHas('party', function ($q) use ($request) {
                $q->where('name', 'LIKE', '%' . $request->input('name') . '%');
            });
        })
        ->when($request->filled('phone'), function ($query) use ($request) {
            $query->whereHas('party', function ($q) use ($request) {
                $q->where('phone', $request->input('phone'));
            });
        })
        ->when($request->filled('min_amount'), function ($query) use ($request) {
            $query->where('totalAmount', '>=', (float) $request->input('min_amount'));
        })
        ->when($request->filled('from_date'), function ($query) use ($request) {
            $query->whereDate('purchaseDate', '>=', $request->input('from_date'));
        })
        ->when($request->filled('to_date'), function ($query) use ($request) {
            $query->whereDate('purchaseDate', '<=', $request->input('to_date'));
        })
        ->latest();

        // Only the report needs full-ledger totals. History, supplier preview
        // and export pages avoid this additional aggregate on every request.
        $totals = null;
        if ($request->boolean('include_totals')) {
            $totals = (clone $query)
                ->setEagerLoads([])
                ->reorder()
                ->selectRaw(
                    'COUNT(*) as purchases_count,'
                    .' COALESCE(SUM(totalAmount), 0) as total_amount,'
                    .' COALESCE(SUM(dueAmount), 0) as due_amount'
                )
                ->first();
        }

        $data = $query->paginate($perPage);

        $response = [
            'message' => __('Data fetched successfully.'),
            'data' => $data,
        ];
        if ($totals !== null) {
            $totalAmount = (float) $totals->total_amount;
            $dueAmount = (float) $totals->due_amount;
            $response['totals'] = [
                'count' => (int) ($totals->purchases_count ?? 0),
                'total' => $totalAmount,
                'received' => max(0, $totalAmount - $dueAmount),
                'due' => $dueAmount,
            ];
        }

        return response()->json($response);
    }

    /** Complete source document for a protected batch correction. */
    public function show(Purchase $purchase)
    {
        $purchase = Purchase::with(
            'user:id,name,role',
            'party:id,name,email,phone,gstin,type',
            'details',
            'details.upperSelectedUnit:id,unitName',
            'details.lowerSelectedUnit:id,unitName',
            'details.stock:id,batch_no,mfg_date,expire_date',
            'details.product:id,productName,productCode,hsn_code,category_id,manufacturer_id,model_id,rack,shelf,product_type,vat_id,vat_type,vat_amount',
            'details.product.product_model:id,name',
            'vat:id,name,rate',
            'payment_type:id,name',
            'branch:id,name,phone,address',
        )
            ->whereKey($purchase->id)
            ->forBusiness((int) auth()->user()->business_id)
            ->first();

        if ($purchase === null) {
            return response()->json(['message' => __('Purchase not found.')], 404);
        }

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $purchase,
        ]);
    }

    /**
     * Price a purchase without saving anything.
     *
     * The counterpart to the sale preview, and there for the same reason: the
     * purchase cart worked its totals out locally, so the taxable base, the
     * rounding and any capped or refused discount only became visible once the
     * purchase had been committed. The options below are the same array
     * `store()` builds, against the same service, so what the screen shows is
     * what gets written.
     *
     * Nothing is locked or persisted.
     */
    public function calculate(Request $request)
    {
        $business_id = auth()->user()->business_id;

        try {
            $totals = $this->purchaseCalculator->calculate(
                $business_id,
                $request->input('products', []),
                $this->purchaseOptionsFrom($request),
            );

            return response()->json([
                'message' => __('Data fetched successfully.'),
                'data' => $totals->toArray(),
            ]);
        } catch (CartCalculationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
            ] + $e->context, $e->status);
        } catch (\Exception $e) {
            Log::error('Purchase preview failed', [
                'business_id' => $business_id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('Failed to price the purchase.'),
            ], 500);
        }
    }

    /**
     * The option array both `store()` and `calculate()` price against.
     *
     * Shared deliberately: two copies of this mapping is how a preview starts
     * disagreeing with the record it is previewing.
     *
     * @return array<string, mixed>
     */
    private function purchaseOptionsFrom(Request $request): array
    {
        return [
            'discount_amount' => $request->discountAmount ?? 0,
            'discount_percent' => $request->discount_percent ?? 0,
            'discount_type' => $request->discount_type ?? 'flat',
            'shipping_charge' => $request->shipping_charge ?? 0,
            'rounding_option' => $request->rounding_option ?? config('pos.rounding.default', 'none'),
            'paid_amount' => $request->paidAmount ?? 0,
        ];
    }

    public function store(PurchaseMutationRequest $request)
    {
        DB::beginTransaction();
        try {

            $business_id = auth()->user()->business_id;

            // The bill, worked out from the quantities and unit costs supplied.
            //
            // The supplier's price is an operator input - the server has no way to
            // look up what someone else charged. Everything derived from it is not:
            // line totals, tax, discounts, rounding, the grand total and the due are
            // all recomputed here, and the client's versions are ignored.
            $purchaseTotals = $this->purchaseCalculator->calculate(
                $business_id,
                $request->input('products', []),
                $this->purchaseOptionsFrom($request),
            );

            $serverDue = $purchaseTotals->due->toFloat();
            $serverPaid = $purchaseTotals->paid->toFloat();

            // Credit is measured against the server's due, not the client's.
            if ($serverDue > 0) {
                $party = Party::forBusiness($business_id)
                    ->whereKey($request->party_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Check party credit limit
                $newTotalDue = $party->due + $serverDue;
                if ($party->credit_limit > 0 && $newTotalDue > $party->credit_limit) {
                    DB::rollBack();
                    return response()->json([
                        'message' => __('Cannot create purchase. Party due will exceed credit limit!')
                    ], 400);
                }

                $party->update([
                    'due' => $newTotalDue
                ]);
            }

            updateBalance($serverPaid, 'decrement');

            try {
                $paymentAmounts = SplitPayment::amounts($request);
            } catch (InvalidArgumentException $e) {
                DB::rollBack();
                return response()->json([
                    'message' => $e->getMessage()
                ], 400);
            }

            $user = auth()->user();

            // Same breakdown sales carry, so input tax on the GST report can be
            // split by component the way output tax is. Additive: `vat_amount`
            // remains the authoritative total.
            $business = $user->business;
            $gstColumns = GstComponents::columnsFor(
                // The tax the server calculated, not the figure that arrived.
                $purchaseTotals->taxTotal->toFloat(),
                $user->branch->gst_state ?? $business?->gst_state,
                // A purchase is received where the shop is, so the shop's own state
                // is the place of supply unless the supplier says otherwise.
                $request->place_of_supply
                    ?? $user->branch->gst_state
                    ?? $business?->gst_state,
                GstConfiguration::resolveAsOf(
                    null,
                    $user->branch ?? null,
                    $business,
                    $request->purchaseDate ?: now()->toDateString(),
                ),
            );

            $purchase = Purchase::create($request->except(
                // Every monetary field the client sends is dropped and replaced by
                // `purchaseColumns()`. Listed explicitly so a new money field
                // cannot slip in through mass assignment.
                'totalAmount', 'vat_amount', 'discountAmount', 'rowDiscount',
                'shipping_charge', 'paidAmount', 'dueAmount', 'change_amount',
                'products',
                // Correlation only — not a column on `purchases`. Consumed by
                // the batch claim further down.
                'import_batch_id',
                // Ownership is the session's to decide. Without these the union
                // below keeps the request's copy, because `+` favours the left
                // operand - so the explicit values underneath never applied.
                'user_id', 'business_id', 'branch_id',
            ) + $gstColumns + $purchaseTotals->purchaseColumns() + [
                'user_id' => $user->id,
                'business_id' => $user->business_id,
                'branch_id' => $user->active_branch_id,
                'cash_amount' => $paymentAmounts['cash_amount'],
                'upi_amount' => $paymentAmounts['upi_amount'],
                'card_amount' => $paymentAmounts['card_amount'],
                'discount_percent' => $request->discount_percent ?? 0,
                'discount_type' => $request->discount_type ?? 'flat',
                'follow_up_date' => $request->follow_up_date ?? null,
            ]);

            // Positional, not keyed by product: the same product may legitimately
            // appear twice in one purchase as two different batches, and keying by
            // id would collapse them onto one set of figures.
            $calculatedLines = $purchaseTotals->lines;

            $purchaseDetails = [];
            foreach ($request->products as $lineIndex => $product_data) {
                $stock = PurchaseLineBuilder::stockFor($product_data, $business_id);

                $line = $calculatedLines[$lineIndex];

                // The builder supplies the non-monetary columns (batch, dates,
                // units); the calculated line overwrites every figure, so the
                // client's totals and conversion prices never reach the row.
                $purchaseDetails[] = array_merge(
                    PurchaseLineBuilder::detailPayload($product_data, $purchase->id, $stock->id),
                    $line->toPurchaseDetail($purchase->id, $stock->id),
                );
            }

            PurchaseDetails::insert($purchaseDetails);

            foreach ($request->products as $lineIndex => $product_data) {
                $stock = Stock::where([
                    'batch_no' => $product_data['batch_no'] ?? null,
                    'business_id' => $business_id,
                    'product_id' => $product_data['product_id'],
                ])->lockForUpdate()->firstOrFail();

                // The quantity the server validated, not the raw request value.
                $stock->increment(
                    'productStock',
                    $calculatedLines[$lineIndex]->quantity + $calculatedLines[$lineIndex]->freeQuantity,
                );
                $this->inventoryChanges->synchronizeDerivedTallies($stock);
            }

            // The purchase lines are the source document and the lots remain the
            // source of current availability.  These append-only events merely
            // record the source-to-batch effect for later verification.
            $this->recordPurchaseInventoryEvents(
                InventoryChangeService::PURCHASE_CREATED,
                $purchase,
                [],
                'Purchase received',
            );

            // An import becomes a purchase inside the same transaction that
            // creates it.
            //
            // The app used to create the purchase and then call
            // `markCommitted` as a second request. Between the two the stock
            // had already moved but the batch still read `ready`, so a dropped
            // connection, a killed app or a failed second call left an import
            // that could be committed again — doubling the stock, silently, on
            // real inventory. Correlating here means the batch cannot be
            // committed without its purchase existing, or the purchase exist
            // without the batch being closed: they succeed or fail together.
            if ($request->filled('import_batch_id')) {
                $claimed = PurchaseImportBatch::query()
                    ->forBusiness($user->business_id)
                    ->whereKey($request->input('import_batch_id'))
                    ->whereNull('purchase_id')
                    ->where('status', 'ready')
                    ->lockForUpdate()
                    ->first();

                if ($claimed === null) {
                    DB::rollback();

                    // A mobile client may time out after the first request was
                    // committed and retry the same reviewed import. That is a
                    // successful replay, not a new purchase and not an error:
                    // return the purchase already linked to the batch so the
                    // app can leave review and show the saved bill. All work
                    // from this duplicate attempt was rolled back above.
                    $existingBatch = PurchaseImportBatch::query()
                        ->forBusiness($user->business_id)
                        ->whereKey($request->input('import_batch_id'))
                        ->first();
                    $existingPurchase = $existingBatch?->purchase_id
                        ? Purchase::query()
                            ->where('business_id', $user->business_id)
                            ->find($existingBatch->purchase_id)
                        : null;

                    if ($existingPurchase) {
                        return response()->json([
                            'message' => __('This import was already saved.'),
                            'reason' => 'already_committed',
                            'replayed' => true,
                            'data' => $existingPurchase,
                        ]);
                    }

                    if ($existingBatch && $existingBatch->status !== 'committed') {
                        return response()->json([
                            'message' => __('This import is not ready to save. Upload and review the bill again.'),
                            'reason' => 'import_not_ready',
                            'status' => $existingBatch->status,
                        ], 409);
                    }

                    return response()->json([
                        'message' => __('This import has already been committed.'),
                        'reason' => 'already_committed',
                    ], 409);
                }

                $claimed->update([
                    'status' => 'committed',
                    'purchase_id' => $purchase->id,
                    'committed_at' => now(),
                ]);
            }

            // Product list responses include live batch stock and selling prices
            // and are cached for an hour. A purchase changes both, so leaving the
            // cache warm made the normal catalogue show the pre-purchase values
            // while a search (a different cache key) showed the correct ones.
            $this->inventoryChanges->invalidateInventoryAfterCommit();
            DB::commit();
            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $purchase->load('user:id,name,role', 'party:id,name,email,phone,gstin,type', 'details','details.upperSelectedUnit:id,unitName','details.lowerSelectedUnit:id,unitName', 'details.stock:id,batch_no,expire_date', 'details.product:id,productName,productCode,hsn_code,category_id,manufacturer_id,model_id,rack,shelf,product_type,vat_id,vat_type,vat_amount', 'details.product.vat:id,name,rate', 'details.product.category:id,categoryName', 'details.product.manufacturer:id,manufacturerName', 'details.product.product_model:id,name','purchaseReturns.details', 'vat:id,name,rate', 'payment_type:id,name', 'branch:id,name,phone,address'),
            ]);
        } catch (CartCalculationException $e) {
            DB::rollback();

            // A refusal the operator can act on: which rule failed, and about what.
            // Not a 500 - the request was understood and declined.
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
            ] + $e->context, $e->status);
        } catch (BatchIdentityProtected $e) {
            DB::rollBack();

            return response()->json($e->toResponse(), 409);
        } catch (\Exception $e) {
            DB::rollback();
            // Shown to the operator and written here, so a report names the exact
            // failure instead of "it did not work".
            $reference = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

            Log::error('Purchase creation failed', [
                'reference' => $reference,
                'business_id' => auth()->user()?->business_id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => __('Failed to create purchase.') . ' (ref ' . $reference . ')',
                'reference' => $reference,
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(PurchaseMutationRequest $request, Purchase $purchase)
    {
        DB::beginTransaction();
        try {
            $purchase = Purchase::with('details')
                ->whereKey($purchase->id)
                ->forBusiness((int) auth()->user()->business_id)
                ->lockForUpdate()
                ->firstOrFail();

            $has_return = PurchaseReturn::where('purchase_id', $purchase->id)->count();

            if ($has_return > 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'code' => 'PURCHASE_RETURN_HISTORY_PROTECTED',
                    'message' => __('This purchase has a recorded return and cannot be edited because that would rewrite inventory history.'),
                    'purchase_id' => $purchase->id,
                    'action' => 'CREATE_CORRECTIVE_PURCHASE_OR_RETURN',
                ], 409);
            }

            // Batches this edit touches, before and after — each one's lot remainders
            // have to be rebuilt once the details are rewritten.
            $affectedStockIds = $purchase->details->pluck('stock_id')->filter()->all();
            $beforeByStockId = [];

            // Revert previous stock changes
            foreach ($purchase->details as $detail) {

                $stock = Stock::whereKey($detail->stock_id)->lockForUpdate()->firstOrFail();

                // Capture the document/batch state before deleting and
                // rebuilding the purchase details. The immutable change event
                // below is written in this same transaction.
                $beforeByStockId[$stock->id] ??=
                    InventoryChangeService::purchaseLineSnapshot($detail, $stock);

                // REVERT PRODUCT STOCK
                $stock->decrement(
                    'productStock',
                    $detail->quantities + ($detail->free_quantity ?? 0)
                );

            }

            // Delete existing purchase details
            $purchase->details()->delete();

            $business_id = auth()->user()->business_id;

            // The edited bill, priced by the same service `store()` uses.
            //
            // This path did not calculate anything at all: it wrote the client's
            // line totals and header figures straight to the database, so editing
            // a purchase could set any amount it liked while creating one could
            // not. An edit is not a lesser operation than a create.
            $purchaseTotals = $this->purchaseCalculator->calculate(
                $business_id,
                $request->input('products', []),
                $this->purchaseOptionsFrom($request),
            );

            $calculatedLines = $purchaseTotals->lines;

            $purchaseDetails = [];
            $lineByStockId = [];
            foreach ($request->products as $lineIndex => $product_data) {
                $stock = PurchaseLineBuilder::stockFor($product_data, $business_id);

                // Same merge order as `store()`: the builder supplies the
                // non-monetary columns and the calculated line overwrites every
                // figure, so the client's totals never reach the row.
                $purchaseDetails[] = array_merge(
                    PurchaseLineBuilder::detailPayload($product_data, $purchase->id, $stock->id),
                    $calculatedLines[$lineIndex]->toPurchaseDetail($purchase->id, $stock->id),
                );

                $stock->increment(
                    'productStock',
                    $calculatedLines[$lineIndex]->quantity + $calculatedLines[$lineIndex]->freeQuantity,
                );

                $affectedStockIds[] = $stock->id;
                $lineByStockId[$stock->id] = $product_data;
            }

            PurchaseDetails::insert($purchaseDetails);

            // Re-inserting the lines resets every lot to its full quantity, which would
            // un-sell anything already sold from these batches. Replaying consumption
            // restores `remaining = purchased - sold`, and refuses the edit outright if
            // it would leave a batch holding less than has already left the shelf.
            $violations = [];

            foreach (array_unique($affectedStockIds) as $stockId) {
                $shortfall = BatchLotReconciler::shortfall($stockId);

                if ($shortfall === null) {
                    BatchLotReconciler::replay($stockId);
                    continue;
                }

                $stock = Stock::find($stockId);
                $line  = $lineByStockId[$stockId] ?? null;
                $conversionValue = (float) ($line['conversion_value'] ?? $stock?->conversionValue ?? 1);
                $conversionValue = $conversionValue > 0 ? $conversionValue : 1;

                $violations[] = [
                    'product_id'       => $stock?->product_id,
                    'product_name'     => $stock?->product?->productName,
                    'batch_no'         => $stock?->batch_no,
                    'sold_quantity'    => $shortfall['sold'],
                    'entered_quantity' => $shortfall['purchased'],
                    // Smallest quantity this line could carry and still cover the sales.
                    'minimum_quantity' => round(
                        (float) ($line['quantities'] ?? 0) + ($shortfall['shortfall'] / $conversionValue),
                        4
                    ),
                ];
            }

            if ($violations !== []) {
                DB::rollBack();

                return response()->json([
                    'message'    => __('Some items cannot be reduced below the quantity already sold.'),
                    'reason'     => 'below_sold_quantity',
                    'violations' => $violations,
                ], 409);
            }

            if ($purchase->dueAmount || $request->dueAmount) {
                // Calculate new due for this party
                $party = Party::forBusiness($business_id)
                    ->whereKey($request->party_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $newDue = $request->party_id == $purchase->party_id ? (($party->due - $purchase->dueAmount) + $request->dueAmount) : ($party->due + $request->dueAmount);

                // Check credit limit
                if ($party->credit_limit > 0 && $newDue > $party->credit_limit) {
                    DB::rollBack();
                    return response()->json([
                        'message' => __('Cannot update purchase. Party due will exceed credit limit!')
                    ], 400);
                }

                $party->update([
                    'due' => $newDue
                ]);

                // If changed to a new party, reduce previous party’s due
                if ($request->party_id != $purchase->party_id) {
                    $prev_party = Party::forBusiness($business_id)
                        ->whereKey($purchase->party_id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $prev_party->update([
                        'due' => $prev_party->due - $purchase->dueAmount
                    ]);
                }
            }

            $balanceDiff = ($purchase->paidAmount ?? 0) - $request->paidAmount;
            updateBalance($balanceDiff, 'decrement');

            try {
                $paymentAmounts = SplitPayment::amounts($request);
            } catch (InvalidArgumentException $e) {
                DB::rollBack();
                return response()->json([
                    'message' => $e->getMessage()
                ], 400);
            }

            // `$request->all() + [...]` reads as "server defaults on the right",
            // but PHP's array union keeps the LEFT operand - so the request won
            // every collision, including `user_id` and every money column. The
            // monetary fields are dropped explicitly and replaced by
            // `purchaseColumns()`, exactly as `store()` does.
            $purchase->update($request->except(
                'totalAmount', 'vat_amount', 'discountAmount', 'rowDiscount',
                'shipping_charge', 'paidAmount', 'dueAmount', 'change_amount',
                'products', 'user_id', 'business_id', 'branch_id',
            ) + $purchaseTotals->purchaseColumns() + [
                'user_id' => auth()->id(),
                'cash_amount' => $paymentAmounts['cash_amount'],
                'upi_amount' => $paymentAmounts['upi_amount'],
                'card_amount' => $paymentAmounts['card_amount'],
                'discount_percent' => $request->discount_percent ?? 0,
                'discount_type' => $request->discount_type ?? 'flat',
                'follow_up_date' => $request->follow_up_date ?? null,
            ]);

            $this->recordPurchaseInventoryEvents(
                InventoryChangeService::PURCHASE_CORRECTED,
                $purchase,
                $beforeByStockId,
                trim((string) $request->input('correction_reason')) ?: 'Purchase corrected',
            );

            $this->inventoryChanges->invalidateInventoryAfterCommit();
            DB::commit();
            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $purchase->load('user:id,name,role', 'party:id,name,email,phone,gstin,type', 'details', 'details.stock:id,batch_no,expire_date', 'details.product:id,productName,productCode,hsn_code,category_id,manufacturer_id,model_id,rack,shelf,product_type,vat_id,vat_type,vat_amount', 'details.product.vat:id,name,rate', 'details.product.category:id,categoryName', 'details.product.manufacturer:id,manufacturerName','details.product.product_model:id,name', 'purchaseReturns.details', 'vat:id,name,rate', 'payment_type:id,name'),
            ]);
        } catch (BatchIdentityProtected $e) {
            DB::rollBack();

            return response()->json($e->toResponse(), 409);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Purchase update failed', [
                'business_id' => auth()->user()?->business_id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => __('Failed to update purchase.')], 500);
        }
    }

    /**
     * Persist one append-only event per current purchase lot.
     *
     * This runs while the caller's transaction is still open. A failed
     * correction therefore rolls back both the source-document changes and the
     * audit rows; a committed correction can never be missing its trace.
     *
     * @param array<int, array<string,mixed>> $beforeByStockId
     */
    private function recordPurchaseInventoryEvents(
        string $eventType,
        Purchase $purchase,
        array $beforeByStockId,
        string $reason,
    ): void {
        $details = PurchaseDetails::where('purchase_id', $purchase->id)
            ->orderBy('id')
            ->get();
        $correlationId = 'purchase-'.$purchase->id.'-'.Str::uuid();
        $recordedStockIds = [];

        foreach ($details as $detail) {
            $stock = Stock::query()
                ->whereKey($detail->stock_id)
                ->where('business_id', $purchase->business_id)
                ->lockForUpdate()
                ->firstOrFail();

            $after = InventoryChangeService::purchaseLineSnapshot($detail, $stock);
            $before = $beforeByStockId[$stock->id] ?? [
                'state' => 'not_on_original_purchase',
                'stock_id' => $stock->id,
                'product_id' => $stock->product_id,
                'remaining_quantity' => 0.0,
            ];

            $this->inventoryChanges->recordPurchaseEvent(
                $eventType,
                $purchase,
                $detail,
                $stock,
                $before,
                $after,
                $reason,
                $correlationId,
            );
            $recordedStockIds[] = $stock->id;
        }

        // Removed lines have no new PurchaseDetails row to iterate, but they
        // are still a material correction and must be visible in the history.
        if ($eventType === InventoryChangeService::PURCHASE_CORRECTED) {
            foreach ($beforeByStockId as $stockId => $before) {
                if (in_array((int) $stockId, $recordedStockIds, true)) {
                    continue;
                }

                $stock = Stock::query()
                    ->whereKey($stockId)
                    ->where('business_id', $purchase->business_id)
                    ->lockForUpdate()
                    ->first();

                if ($stock !== null) {
                    $this->inventoryChanges->recordPurchaseRemovalEvent(
                        $purchase,
                        $stock,
                        $before,
                        $reason,
                        $correlationId,
                    );
                }
            }
        }
    }

    public function getBatchDetails(Request $request, $product_id, $batch_no = null)
    {
        $businessId = auth()->user()->business_id;

        if (!$batch_no) {

        $batches = Stock::where('product_id', $product_id)
            ->where('business_id', $businessId)
            // A batch with no number is not a batch anyone can pick.
            //
            // Creating a product writes a stock row with a null `batch_no` to
            // hold its opening figure, and this listed it — so the picker
            // opened on a blank first row, and a shop looking for its real
            // batches reported that they were "not appearing".
            ->whereNotNull('batch_no')
            ->where('batch_no', '!=', '')
            ->when($request->search, function ($q) use ($request) {

                $q->where(
                    'batch_no',
                    'LIKE',
                    '%' . $request->search . '%'
                );

            })
            ->select(
                'id',
                'batch_no',
                // Carried on the list so the picker can fill the date fields the
                // moment a batch is chosen, rather than showing them empty until
                // a second request lands.
                'mfg_date',
                'expire_date'
            )
            ->orderBy('batch_no')
            ->limit(ApiRequest::perPage($request, 50, 100))
            ->get()
            ->map(function (Stock $stock) {
                return [
                    'id' => $stock->id,
                    'batch_no' => $stock->batch_no,
                    // These are calendar dates, not instants. Serializing the
                    // Carbon cast as UTC shifts an India-local midnight to the
                    // previous date in JSON.
                    'mfg_date' => $stock->mfg_date?->toDateString(),
                    'expire_date' => $stock->expire_date?->toDateString(),
                ];
            });

        return response()->json([
            'type' => 'batch_list',
            'data' => $batches
        ]);
    }

        $stock = Stock::where('product_id', $product_id)
            ->where('business_id', $businessId)
            ->where('batch_no', $batch_no)
            ->first();

        if (!$stock) {
            return response()->json([
                'exists' => false
            ]);
        }

        $purchaseDetails = PurchaseDetails::where('stock_id', $stock->id)
    ->orderByDesc('id')
    ->limit(ApiRequest::perPage($request, 50, 100))
    ->get();

return response()->json([
    'exists' => true,

    'batch_details' => [
        'stock_id' => $stock->id,
        'batch_no' => $stock->batch_no,
        // `conversionQuantity` is the remaining lower-unit quantity after
        // sales, returns and audits.  The received quantity is historical and
        // must never be used as availability in an edit/picker response.
        'available_quantity' => $purchaseDetails->sum(
            fn ($detail) => (float) $detail->conversionQuantity
        ),
        // The batch's own dates. Selecting an existing batch left the two date
        // fields empty whenever the most recent purchase line carried no dates,
        // because the app had nowhere else to read them from - and the batch row
        // has held them all along.
        'mfg_date' => $stock->mfg_date?->toDateString(),
        'expire_date' => $stock->expire_date?->toDateString(),
    ],

    'purchase_details' => $purchaseDetails->map(function ($detail) {

        return [
            'purchase_detail_id'      => $detail->id,
            'productDealerPrice'      => $detail->productDealerPrice,
            'productPurchasePrice'    => $detail->productPurchasePrice,
            'conversionPurchasePrice' => $detail->conversionPurchasePrice,
            'conversionQuantity'      => $detail->conversionQuantity,
            'conversionPrice'         => $detail->conversionPrice,
            'conversionValue'         => $detail->conversionValue,
            'productSalePrice'        => $detail->productSalePrice,
            'productWholeSalePrice'   => $detail->productWholeSalePrice,
            'quantity'                => $detail->quantities,
            'free_quantity'           => $detail->free_quantity ?? 0,
            'purchaseUpperLimit'      => $detail->purchaseUpperLimit,
            'purchaseLowerLimit'      => $detail->purchaseLowerLimit,
            'profit_percent'          => $detail->profit_percent,
            'mfg_date'                => $detail->mfg_date,
            'expire_date'             => $detail->expire_date,
        ];
    })
]);
    }

    public function searchPurchaseInvoice(Request $request)
{
    $request->validate([
        'invoiceNumber' => 'required'
    ]);

    $user = auth()->user();

    $data = Purchase::with(
        'user:id,name,role',
        'party:id,name,email,phone,gstin,type',
        'details',
        'details.upperSelectedUnit:id,unitName',
        'details.lowerSelectedUnit:id,unitName',
        'details.product:id,productName,productCode,hsn_code,category_id,manufacturer_id,model_id,rack,shelf,product_type,vat_id,vat_type,vat_amount',
        'details.stock:id,batch_no,expire_date',
        'details.product.vat:id,name,rate',
        'details.product.category:id,categoryName',
        'details.product.manufacturer:id,manufacturerName',
        'details.product.product_model:id,name',
        'purchaseReturns.details',
        'vat:id,name,rate',
        'payment_type:id,name',
        'branch:id,name,phone,address'
    )
    ->where('invoiceNumber', $request->invoiceNumber)
    ->where('business_id', $user->business_id)

    ->when($user->role == 'staff', function ($query) use ($user) {
        $query->where('branch_id', $user->branch_id);
    })

    ->latest()
    ->get();

    return response()->json([
        'message' => __('Data fetched successfully.'),
        'data' => $data,
    ]);
}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        DB::beginTransaction();
        try {
            // Deliberately not `firstOrFail()`. That threw a
            // ModelNotFoundException into the generic catch below, which
            // answered 500 and wrote a full stack trace at error level — so a
            // purchase that had merely already been deleted, or belonged to
            // another business, read in the log exactly like a server fault.
            // It was the single loudest thing in the production log.
            $purchase = Purchase::with('details')
                ->whereKey($id)
                ->forBusiness((int) auth()->user()->business_id)
                ->lockForUpdate()
                ->first();

            if (! $purchase) {
                DB::rollBack();

                return response()->json([
                    'message' => __('Purchase not found.'),
                ], 404);
            }

            // A saved purchase is the source document for lots, sales, returns,
            // audits and immutable inventory events. There is no draft/reversal
            // state on this legacy resource, so deleting its lines would orphan
            // history even when nothing has been sold yet. Keep the document and
            // require a future controlled cancellation/reversal workflow.
            if ($purchase->details->isNotEmpty()) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'code' => 'PURCHASE_HISTORY_PROTECTED',
                    'message' => __('This purchase is a tracked inventory source and cannot be deleted. Use a controlled cancellation or return workflow.'),
                    'purchase_id' => $purchase->id,
                    'action' => 'REVERSE_SOURCE_PURCHASE',
                ], 409);
            }

            $has_return = PurchaseReturn::where('purchase_id', $purchase->id)->count();

            if ($has_return > 0) {
                DB::rollBack();
                return response()->json([
                    'message' => __("You can not update this purchase because it has already been returned.")
                ], 400);
            }

            if ($purchase->dueAmount) {
                $party = Party::forBusiness((int) auth()->user()->business_id)
                    ->whereKey($purchase->party_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $party->update([
                    'due' => $party->due - $purchase->dueAmount
                ]);
            }

            foreach ($purchase->details as $detail) {
                $conversionValue = $detail->conversionValue > 0 ? $detail->conversionValue : 1;
                $originalConversionQuantity =
                    ($detail->quantities + ($detail->free_quantity ?? 0)) * $conversionValue;

                if ($detail->conversionQuantity < $originalConversionQuantity) {
                    DB::rollBack();

                    return response()->json([
                        'message' => __('Cannot delete purchase because some stock has already been sold.')
                    ], 400);
                }

                $stock = Stock::whereKey($detail->stock_id)->lockForUpdate()->firstOrFail();
                $stock->decrement('productStock', $detail->quantities + ($detail->free_quantity ?? 0));
            }
            PurchaseDetails::where('purchase_id', $purchase->id)->delete();

            updateBalance($purchase->paidAmount, 'increment');

            SendUserNotificationJob::dispatch(
                $purchase->id,
                route('business.purchases.index', ['id' => $purchase->id]),
                __('Purchase has been deleted.'),
                $purchase->business_id
            )->afterCommit();

            $purchase->delete();

            DB::commit();
            CacheService::clearProductsCache();
            return response()->json([
                'message' => __('Data deleted successfully.'),
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Purchase deletion failed', [
                'business_id' => auth()->user()?->business_id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => __('Failed to delete purchase.')], 500);
        }
    }
}
