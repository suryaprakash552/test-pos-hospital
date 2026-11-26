<?php

namespace App\Http\Controllers\Api;

use App\Models\Sale;
use App\Models\SaleImportBatch;
use App\Models\Party;
use App\Models\Customer;
use App\Models\Stock;
use App\Models\Business;
use App\Models\SaleDetails;
use App\Helpers\HasUploader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Http\Requests\Api\StoreSaleRequest;
use App\Services\BatchConsumption;
use App\Services\Inventory\MovementContext;
use App\Services\GstComponents;
use App\Models\LoyaltyAudit;
use App\Services\CartLoyaltyDiscount;
use App\Services\LoyaltyEligibility;
use App\Services\LoyaltyReturnAdjustment;
use App\Services\LoyaltyDiscount;
use App\Services\GstConfiguration;
use App\Services\LoyaltySubscriptionManager;
use App\Support\Api\ApiRequest;
use Illuminate\Validation\Rule;
use App\Jobs\SendSmsMessageJob;
use App\Services\Pricing\CartCalculationException;
use App\Services\Pricing\CartCalculationService;
use App\Services\CacheService;
use App\Services\InventoryChangeService;

class AcnooSaleController extends Controller
{
    use HasUploader;

    public function __construct(
        private readonly CartCalculationService $cartCalculator,
        private readonly InventoryChangeService $inventoryChanges,
    )
    {
        $this->middleware('check.permission:sales.read')
            ->only(['index', 'searchInvoiceById', 'productReport']);
        $this->middleware('check.permission:sales.create')->only('store');
        $this->middleware('check.permission:sales.update')->only('update');
        $this->middleware('check.permission:sales.delete')->only('destroy');
    }

  public function index(Request $request)
	{
	    $user = auth()->user();
	    $perPage = ApiRequest::perPage($request, 50, 100);

    $phone = request('phone');
    $relationship = request('relationship');
    $name = request('name');
    $minAmount = request('min_amount');
    $fromDate = request('from_date');
    $toDate = request('to_date');

    $query = Sale::with([
        'user:id,name,role',
        'customer:id,name,email,phone,gstin,type,relationship,customer_tier_id',
        'customer.tier:id,name',
        'details',
        'details.stock' => fn ($query) => $query
            ->select('id', 'batch_no', 'expire_date')
            ->withSum('availableLots as available_quantity', 'conversionQuantity')
            ->withSum('saleDetails as sold_quantity', 'quantities'),
        'details.stock.purchaseDetails',
        // `product_flat_discount`/`product_percent_discount` are the product's
        // configured discount ceiling, not the row's own entered discount.
        // The app decides whether to show the discount inputs at all from
        // this pair (a row with no ceiling and nothing entered has no reason
        // to offer the fields) — a column-limited eager load that dropped
        // them left every row on an edit screen looking undiscountable, even
        // where the product plainly had one configured.
        'details.product:id,productName,category_id,manufacturer_id,model_id,productCode,productPurchasePrice,productStock,product_type,hsn_code,rack,shelf,vat_id,vat_type,vat_amount,product_flat_discount,product_percent_discount',
        'details.product.category:id,categoryName',
        'details.product.manufacturer:id,manufacturerName',
        // `.saleDetail.product` alongside the plain `details` already loaded:
        // the return-report screen showed a total per return with no way to
        // tell which product it was for, because this relation chain — it
        // already existed on the models — was never walked to the product.
        'saleReturns.details',
        'saleReturns.details.saleDetail:id,product_id',
        'saleReturns.details.saleDetail.product:id,productName,productCode',
        'details.product.product_model:id,name',
        'vat:id,name,rate',
        'doctor:id,name,qualification,specialization',
        'payment_type:id,name',
        'branch:id,name,phone,address',
        'details.product.vat:id,name,rate'
    ])

    ->when(request('invoiceNumber'), function ($query) {
        $query->where('invoiceNumber', request('invoiceNumber'));
    })
    ->when($request->filled('party_id'), function ($query) use ($request) {
        $query->where('customer_id', (int) $request->input('party_id'));
    })
    // A relationship is a fact of this particular sale, not a mutable customer
    // profile attribute. A customer can buy for self today and spouse tomorrow;
    // filtering through `customers.relationship` made both invoices move when
    // the customer record was edited later. Legacy sales predate the snapshot,
    // so only those rows fall back to the customer relationship.
    ->when($phone, function ($query) use ($phone) {
        $query->whereHas('customer', function ($q) use ($phone) {
            $q->where('phone', $phone);
        });
    })
    ->when($relationship, function ($query) use ($relationship) {
        $query->where(function ($q) use ($relationship) {
            $q->where('sales.relationship', $relationship)
                ->orWhere(function ($legacy) use ($relationship) {
                    $legacy->whereNull('sales.relationship')
                        ->whereHas('customer', function ($customer) use ($relationship) {
                            $customer->where('relationship', $relationship);
                        });
                });
        });
    })
    ->when($name, function ($query) use ($name) {
        $guestAliases = ['guest', 'walk-in', 'walk-in customer', 'cash sale'];
        $isGuest = in_array(strtolower(trim((string) $name)), $guestAliases, true);

        $query->where(function ($filtered) use ($name, $isGuest) {
            if ($isGuest) {
                // Fast cash sales intentionally have no customer row. They are
                // rendered as Guest throughout the app, so the same visible name
                // must be searchable in the report.
                $filtered->whereNull('sales.customer_id');
            }

            $method = $isGuest ? 'orWhereHas' : 'whereHas';
            $filtered->{$method}('customer', function ($customer) use ($name) {
                $customer->where('name', 'LIKE', "%{$name}%");
            });
        });
    })
    ->when($minAmount, function ($query) use ($minAmount) {
        $query->whereRaw('totalAmount >= ?', [(float) $minAmount]);
    })
    ->when(request('returned-sales') == "true", function ($query) {
        $query->whereHas('saleReturns');
    })
    ->when($fromDate && $toDate, function ($query) use ($fromDate, $toDate) {
        $query->whereDate('saleDate', '>=', $fromDate)
              ->whereDate('saleDate', '<=', $toDate);
    })
    ->when($fromDate && !$toDate, function ($query) use ($fromDate) {
        $query->whereDate('saleDate', '>=', $fromDate);
    })
    ->when(!$fromDate && $toDate, function ($query) use ($toDate) {
        $query->whereDate('saleDate', '<=', $toDate);
    })
    // Business-wide, not per-staff: any user may need to look up a past sale to
    // take a return or collect a due against it. The business boundary still
    // applies; only the per-user narrowing is gone.
    ->forBusiness((int) $user->business_id)
    ->when(request('doctor_name'), function ($query) {

    $query->whereHas('doctor', function ($q) {

        $q->where(
            'name',
            'LIKE',
            '%' . request('doctor_name') . '%'
        );

    }); 

})

    ->latest();

    // Totals for the whole filtered report, not for the page being shown.
    //
    // The app had no choice but to fold over the rows it had, so the sales
    // report's headline read the first page and called it the total: 96,123
    // against a real 3,782,660 across 929 sales. It looked right only when a
    // filter narrowed the report to a single page, which is exactly when a
    // wrong total is hardest to notice.
    //
    // Eager loads are dropped for this one: it returns a single aggregate row,
    // and hydrating relations onto it would be work for nothing.
    $totals = (clone $query)
        ->setEagerLoads([])
        ->reorder()
        ->selectRaw(
            'COUNT(*) as sales_count,'
            // SaleReturnController already updates these persisted columns when
            // a return is saved. Subtracting return details here again made a
            // refunded sale appear too small in the report.
            .' COALESCE(SUM(totalAmount), 0) as total_amount,'
            .' COALESCE(SUM(paidAmount), 0) as received_amount,'
            .' COALESCE(SUM(dueAmount), 0) as due_amount'
        )
        ->first();

    $data = $query->paginate($perPage);

    return response()->json([
        'message' => __('Data fetched successfully.'),
        'data' => $data,
        'totals' => [
            'count' => (int) ($totals->sales_count ?? 0),
            'total' => (float) ($totals->total_amount ?? 0),
            'received' => (float) ($totals->received_amount ?? 0),
            'due' => (float) ($totals->due_amount ?? 0),
        ],
    ]);
}

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSaleRequest $request)
    {
        DB::beginTransaction();
        try {

            $business_id = auth()->user()->business_id;
            $request_products = $request->validated('products_payload');

            if ($request->customer_id) {
                $customer = Customer::forBusiness($business_id)
                    ->whereKey($request->customer_id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            // The bill, worked out from the database rather than from the request.
            //
            // This runs inside the transaction and takes a row lock on every batch
            // it prices, so the prices and stock it reads are the ones the sale is
            // actually written against. Whatever totals, prices or tax the client
            // sent are ignored from here on.
            $cartOptions = $this->cartOptionsFrom($request);

            $baseCart = $this->cartCalculator->calculate(
                $business_id,
                $request_products,
                $cartOptions,
                $customer ?? null,
                lockStock: true,
            );

            $business = Business::findOrFail($business_id);
            $business_name = $business->companyName;

            // §8, §9: the loyalty discount, resolved from the customer's active
            // package. Automatic mode grants the entitlement outright; manual mode
            // applies only what a permitted user typed, and refuses anything above
            // the entitlement rather than silently capping it — a cashier who asked
            // for more needs to be told, not quietly overruled.
            $activeSubscription = $request->customer_id
                ? LoyaltySubscriptionManager::activeFor((int) $request->customer_id)
                : null;

            // §11: the eligible base is the server's own line values, not the
            // request's. `LoyaltyEligibility` reads `subtotal` off each line, so
            // feeding it the request would let a caller enlarge their discount by
            // claiming a bigger line.
            $eligibility = LoyaltyEligibility::split(
                $business_id,
                $this->eligibilityLinesFrom($baseCart),
                $activeSubscription?->loyalty_package_id,
            );

            $loyalty = CartLoyaltyDiscount::resolve(
                $eligibility['eligible'],
                $activeSubscription,
                $request->filled('loyalty_discount_amount')
                    ? (float) $request->loyalty_discount_amount
                    : null,
                auth()->user()->hasPermission('loyalty.manual-discount'),
                $request->saleDate ?: null,
                manualReason: $request->input('loyalty_discount_reason'),
                // §9: departing from an automatic package's own figure is its own
                // permission, separate from being allowed to enter a manual one.
                mayOverrideAutomatic: auth()->user()->hasPermission('loyalty.override'),
            );

            if ($loyalty['reason'] !== null) {
                DB::rollback();

                return response()->json(['message' => $loyalty['reason']], 422);
            }

            // The final bill, now including the resolved loyalty discount. The batch
            // rows are already locked by the first pass, so this re-reads them
            // within the same transaction rather than racing anything.
            $cart = $this->cartCalculator->calculate(
                $business_id,
                $request_products,
                $cartOptions + ['loyalty_discount' => (float) ($loyalty['amount'] ?? 0)],
                $customer ?? null,
                lockStock: true,
            );

            // Credit is extended against the server's due, not the client's. A till
            // that understates the due could otherwise walk a customer past their
            // credit limit.
            $serverDue = $cart->due->toFloat();

            if ($serverDue > 0) {
                if (! $request->customer_id) {
                    DB::rollback();

                    return response()->json([
                        'message' => __('You cannot sell on credit to a walk-in customer.')
                    ], 400);
                }

                $newDue = $customer->due + $serverDue;

                if ($customer->credit_limit > 0 && $newDue > $customer->credit_limit) {
                    DB::rollback();

                    return response()->json(['message' => __('Sale cannot be created. Customer due will exceed credit limit!')], 400);
                }

                $customer->update(['due' => $newDue]);
            }

            $serverPaid = $cart->paid->toFloat();

            updateBalance($serverPaid, 'increment');

            [$cash, $upi, $card] = $this->paymentSplitFor($request, $serverPaid);

            if ($cash === null) {
                DB::rollback();

                return response()->json([
                    'message' => 'Split payment total must equal paid amount'
                ], 400);
            }

            // Breakdown of the tax the server calculated. Additive: the components
            // sum back to `vat_amount`, so every existing report and receipt is
            // unaffected.
            $user = auth()->user();
            $gstColumns = GstComponents::columnsFor(
                $cart->taxTotal->toFloat(),
                $user->branch->gst_state ?? $business->gst_state,
                // What the customer supplied, else the shop's own default.
                $request->place_of_supply
                    ?? $user->branch->place_of_supply
                    ?? $business->place_of_supply,
                // As of the sale's own date, not today: a future-dated change must
                // not apply to a sale being entered for an earlier day.
                GstConfiguration::resolveAsOf(
                    null,
                    $user->branch ?? null,
                    $business,
                    $request->saleDate ?: now()->toDateString(),
                ),
                (float) ($request->cess_amount ?? 0),
            );

            // Stored like any other upload; the request carries a file, the column
            // carries a path.
            $gstColumns['upi_confirmation_image'] = $request->hasFile('upi_confirmation_image')
                ? $this->upload($request, 'upi_confirmation_image')
                : null;

            $sale = Sale::create($request->except(
                'image', 'isPaid', 'upi_confirmation_image',
                // Resolved server-side: a client-supplied figure is a request, not
                // a decision, and must not reach the invoice unchecked.
                'loyalty_discount_amount', 'loyalty_subscription_id',
                // Every monetary field the client sends is dropped here and
                // replaced by `saleColumns()` below. Listing them explicitly means
                // a new money field cannot slip in through mass assignment.
                'totalAmount', 'actual_total_amount', 'rounding_amount',
                'vat_amount', 'discountAmount', 'rowDiscount', 'shipping_charge',
                'paidAmount', 'dueAmount', 'change_amount', 'lossProfit',
                'cash_amount', 'upi_amount', 'card_amount',
                'products', 'products_payload',
                // Ownership belongs to the session. These sat only in the
                // explicit array below, which `+` never reaches when the request
                // carries the same key - so a caller could attribute a sale to
                // another user, or post it into another business.
                'user_id', 'business_id', 'branch_id',
            ) + $gstColumns + CartLoyaltyDiscount::saleColumns($loyalty, auth()->id()) + $cart->saleColumns() + [
                'discount_percent' => $request->discount_percent ?? 0,
                'discount_type'    => $request->discount_type ?? 'flat',
                'customer_id' => $request->customer_id,
                'doctor_id' => $request->doctor_id ?? null,
                'user_id' => auth()->id(),
                'business_id' => $business_id,
                'branch_id' => auth()->user()->branch_id, 
                'isPaid' => filter_var($request->isPaid, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                'cash_amount' => $cash,
                'upi_amount' => $upi,
                'card_amount' => $card,
                'follow_up_date' => $request->follow_up_date ?? null,
                'image' => $request->image ? $this->upload($request, 'image') : null,
                'meta' => [
                    'note' => $request->note,
                    'customer_phone' => $request->customer_phone,
                ],
            ]);

            // §14: a manual discount is somebody's decision, so it is recorded with
            // their name against it. An automatic one is the system applying the
            // package as configured and needs no entry.
            if ($loyalty['applied']
                && ($loyalty['snapshot']['loyalty_discount_mode'] ?? null) === LoyaltyDiscount::MANUAL
            ) {
                LoyaltyAudit::record(LoyaltyAudit::MANUAL_DISCOUNT, $sale, [
                    'customer_id' => $request->customer_id,
                    'field'       => 'loyalty_discount_amount',
                    // The entitlement they could have given, against what they did.
                    'old_value'   => LoyaltyAudit::stringify($loyalty['maximum_eligible']),
                    'new_value'   => LoyaltyAudit::stringify($loyalty['amount']),
                ]);
            }

            $saleDetails = [];

            // Keyed by batch so each loop iteration can pick up the priced line the
            // calculator produced for it.
            $calculatedLines = collect($cart->lines)->keyBy('stockId');

            foreach ($request_products as $key => $productData) {
            $stock = Stock::forBusiness($business_id)
                ->whereKey($productData['stock_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $stock->load('product');

            // UI filtering is only convenience; the locked stock row is the
            // authority. A stale/offline client must not be able to sell a batch
            // that expired after its catalogue was loaded. Expiry today remains
            // valid and becomes invalid on the following calendar day.
            if ($stock->expire_date?->lt(today())) {
                DB::rollBack();

                return response()->json([
                    'message' => __('The selected batch has expired and cannot be sold.'),
                    'stock_id' => $stock->id,
                    'batch_no' => $stock->batch_no,
                    'expire_date' => $stock->expire_date->toDateString(),
                ], 422);
            }

            // The server's own record first, the client's label only as a fallback.
            // Reading this from the payload alone left the name blank whenever the
            // client did not send one, so an out-of-stock refusal on a multi-line
            // sale could not say which item was short.
            $product_name = $stock->product?->productName
                ?? ($productData['product_name'] ?? '');
            $conversionValue = $stock->conversionValue ?? $stock->product->conversionValue ?? 1;
            $qtyInUpperUnit = ($productData['quantities'] ?? 0) / ($conversionValue > 0 ? $conversionValue : 1);

            $productId = $stock->product_id;
            $requestedQty = $productData['quantities'] ?? 0;

            // Qualified: `product_id` exists on both `purchase_details` and
            // `stocks`, so an unqualified reference is rejected by MySQL as
            // ambiguous (error 1052) — which threw on every single sale, making
            // this endpoint impossible to complete. Named against `stocks`
            // because the join and the business filter are both on that table.
            $availableConversionQty = DB::table('purchase_details')
                ->join('stocks', 'stocks.id', '=', 'purchase_details.stock_id')
                ->where('stocks.product_id', $productId)
                ->where('stocks.business_id', $business_id)
                ->sum('conversionQuantity');

            if ($availableConversionQty < $requestedQty) {
                DB::rollback();

                return response()->json([
                    'message' => "Stock not available for product : " . $product_name . ". Available stock is : " . $availableConversionQty
                ], 406);
            }

        $sellQty = $productData['quantities'] ?? 0;

        $purchases = DB::table('purchase_details')
    ->where('stock_id', $stock->id)
    ->where('conversionQuantity', '>', 0)
    ->orderBy('id') // FIFO
    ->lockForUpdate()
    ->get();

$totalAvailable = $purchases->sum('conversionQuantity');

if ($totalAvailable < $sellQty) {

    DB::rollback();

    // Named, quantified, and scoped to the batch.
    //
    // This used to say only "Insufficient stock in selected batch". On a sale of
    // one line that is merely unhelpful; on a sale of ten it is unusable, because
    // the cashier cannot tell which item was refused. It also gave no figure, so
    // there was no way to see that the batch held nothing while the product list
    // showed the product in stock — the two are different questions and the
    // refusal has to say which one it is answering.
    return response()->json([
        'message' => 'Not enough stock in this batch of '
            . $product_name
            . ($stock->batch_no ? ' (batch ' . $stock->batch_no . ')' : '')
            . '. This batch has ' . rtrim(rtrim(number_format((float) $totalAvailable, 4, '.', ''), '0'), '.')
            . ', and ' . rtrim(rtrim(number_format((float) $sellQty, 4, '.', ''), '0'), '.')
            . ' was requested. Choose another batch for this item.',
        'reason'     => 'batch_stock_insufficient',
        'stock_id'   => $stock->id,
        'product'    => $product_name,
        'available'  => (float) $totalAvailable,
        'requested'  => (float) $sellQty,
    ], 400);
}

$remainingQty = $sellQty;
$correlationId = 'sale-'.$sale->id;
$movement = MovementContext::forSource($sale, 'sale', $correlationId, 'Sale completed');

foreach ($purchases as $purchase) {

    if ($remainingQty <= 0) {
        break;
    }

    $beforeLotQuantity = (float) $purchase->conversionQuantity;
    $deductedLotQuantity = min($beforeLotQuantity, $remainingQty);

    if ($purchase->conversionQuantity <= $remainingQty) {

        $remainingQty -= $purchase->conversionQuantity;

        DB::table('purchase_details')
            ->where('id', $purchase->id)
            ->update([
                'conversionQuantity' => 0
            ]);

    } else {

        DB::table('purchase_details')
            ->where('id', $purchase->id)
            ->update([
                'conversionQuantity' =>
                    $purchase->conversionQuantity - $remainingQty
            ]);

        $remainingQty = 0;
    }

    $afterLotQuantity = max(0, $beforeLotQuantity - $deductedLotQuantity);

    // Recorded against the lot column, not just inside the JSON payload.
    // Reversal reads these rows back to give each lot exactly what it lent, and
    // a value buried in `before_values` cannot be queried or summed.
    $this->inventoryChanges->recordLotMovement(
        $movement,
        InventoryChangeService::SALE_OUT,
        $stock,
        (int) $purchase->id,
        -($beforeLotQuantity - $afterLotQuantity),
        ['remaining_quantity' => $beforeLotQuantity],
        ['remaining_quantity' => $afterLotQuantity],
    );
}

            // Price and profit come from the server's own calculation of this line,
            // never from `$productData` - the client's figures are intent only.
            $saleDetails[$key] = $calculatedLines[$stock->id]->toSaleDetail($sale->id);

            $stock->decrement('productStock', $qtyInUpperUnit);
            $this->inventoryChanges->synchronizeDerivedTallies($stock);
        }

            SaleDetails::insert($saleDetails);

            // An import becomes a sale inside the same transaction that
            // creates it — the purchase-side original of this fix is
            // PurchaseController::store's own `import_batch_id` handling,
            // and the note there explains why: closing the batch as a
            // second, separate request left a window where a dropped
            // connection could commit the same import twice, doubling the
            // sale (and the stock it drew down) on real inventory.
            if ($request->filled('import_batch_id')) {
                $claimedImport = SaleImportBatch::query()
                    ->forBusiness($business_id)
                    ->whereKey($request->input('import_batch_id'))
                    ->whereNull('sale_id')
                    ->where('status', 'ready')
                    ->lockForUpdate()
                    ->first();

                if ($claimedImport === null) {
                    DB::rollback();

                    // The first response may be lost and the app may restart
                    // with a new checkout key. The import link is the durable
                    // identity: return the existing sale after rolling back
                    // every stock change from this duplicate attempt.
                    $existingBatch = SaleImportBatch::query()
                        ->forBusiness($business_id)
                        ->whereKey($request->input('import_batch_id'))
                        ->first();
                    $existingSale = $existingBatch?->sale_id
                        ? Sale::query()
                            ->where('business_id', $business_id)
                            ->find($existingBatch->sale_id)
                        : null;

                    if ($existingSale) {
                        return response()->json([
                            'message' => __('This import was already saved.'),
                            'reason' => 'already_committed',
                            'replayed' => true,
                            'data' => $existingSale->load(
                                'user:id,name,role',
                                'customer:id,name,email,phone,gstin,type,relationship,customer_tier_id',
                                'customer.tier:id,name',
                                'details',
                                'details.stock:id,batch_no,expire_date',
                                'details.product:id,productName,productCode,category_id,manufacturer_id,model_id,product_type,hsn_code,rack,shelf,vat_id,vat_type,vat_amount',
                                'details.product.vat:id,name,rate',
                                'details.product.product_model:id,name',
                                'payment_type:id,name',
                                'branch:id,name,phone,address',
                            ),
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

                $claimedImport->update([
                    'status' => 'committed',
                    'sale_id' => $sale->id,
                    'committed_at' => now(),
                ]);
            }

            if ($customer ?? false && $customer->phone) {
                if (env('MESSAGE_ENABLED')) {
                    SendSmsMessageJob::dispatch(
                        $customer->phone,
                        saleMessage($sale, $customer, $business_name)
                    )->afterCommit();
                }
            }

            $this->inventoryChanges->invalidateInventoryAfterCommit();
            DB::commit();
            // The product list is cached for an hour and keyed on TAG_PRODUCTS.
            // Nothing here cleared it, so a sale left every catalogue screen
            // serving the stock figures from before it: a product sold down to
            // zero still read as in stock, and the batch sheet behind it
            // disagreed with the card in front. Same reason store/update/destroy
            // on the product controller each call this.
            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $sale->load('user:id,name,role', 'customer:id,name,email,phone,gstin,type,relationship,customer_tier_id', 'customer.tier:id,name', 'details', 'details.stock:id,batch_no,expire_date', 'details.stock.purchaseDetails', 'details.product:id,productName,productCode,category_id,manufacturer_id,model_id,product_type,hsn_code,rack,shelf,vat_id,vat_type,vat_amount,product_flat_discount,product_percent_discount', 'details.product.product_model:id,name', 'details.product.category:id,categoryName', 'details.product.manufacturer:id,manufacturerName', 'details.product.vat:id,name,rate', 'saleReturns.details', 'saleReturns.details.saleDetail:id,product_id', 'saleReturns.details.saleDetail.product:id,productName,productCode', 'vat:id,name,rate', 'payment_type:id,name', 'branch:id,name,phone,address'),
            ]);
        } catch (CartCalculationException $e) {
            DB::rollback();

            // A refusal the cashier can act on: which rule failed, and about what.
            // Not a 500 - the request was understood and declined.
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
            ] + $e->context, $e->status);
        } catch (\Exception $e) {
            DB::rollback();

            // A short code, shown to the cashier and written to the log with the
            // stack trace. "The app is broken" is not something anyone can act
            // on; a reference that finds the exact failure in the log is.
            $reference = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

            Log::error('Sale creation failed', [
                'reference' => $reference,
                'business_id' => $business_id ?? null,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => __('Failed to create sale.') . ' (ref ' . $reference . ')',
                'reference' => $reference,
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreSaleRequest $request, $id)
    {
        $user = auth()->user();

        $sale = Sale::where('id', $id)
            ->where('business_id', $user->business_id)
            ->when($user->role == 'staff', function ($query) use ($user) {
                $query->where('branch_id', $user->branch_id);
            })
            ->firstOrFail();

        DB::beginTransaction();
        try {

            if ($sale->load('saleReturns')->saleReturns->count() > 0) {
                DB::rollback();
                return response()->json([
                    'message' => __("You can not update this sale because it has already been returned.")
                ], 400);
            }

            $request_products = $request->validated('products_payload');
            $prevDetails = SaleDetails::where('sale_id', $sale->id)->get();
            $stockIds = collect($request_products)->pluck('stock_id')->toArray();

            if ($request->customer_id) {
                $customer = Customer::forBusiness($user->business_id)
                    ->whereKey($request->customer_id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            // The edited bill, priced by the same service `store()` uses.
            //
            // This path never called it, so an edit wrote whatever prices and
            // totals the client sent - a sale could not be *created* with a
            // made-up total but could be *edited* into one, and an edited sale
            // never picked up the taxable-base rule either.
            //
            // Priced before the loop so the calculated lines are available for the
            // detail rows. Stock is locked and adjusted by the loop below, so this
            // does not take the locks a second time.
            $editedCart = $this->cartCalculator->calculate(
                (int) $user->business_id,
                $request_products,
                $this->cartOptionsFrom($request),
                $customer ?? null,
                lockStock: false,
            );

            foreach ($request_products as $key => $productData) {

    $current_stock = Stock::forBusiness($user->business_id)
        ->whereKey($productData['stock_id'])
        ->lockForUpdate()
        ->firstOrFail();

    $prevStock = $prevDetails->first(function ($item) use ($current_stock) {
        return $item->stock_id == $current_stock->id;
    });

    $product_name = $current_stock->product?->productName
        ?? ($productData['product_name'] ?? '');

    $requestedQty = $productData['quantities'] ?? 0;

    $purchaseDetails = DB::table('purchase_details')
        ->where('stock_id', $current_stock->id)
        ->lockForUpdate()
        ->get();

    if ($purchaseDetails->isEmpty()) {

        DB::rollback();

        return response()->json([
            'message' => 'Purchase detail not found for selected batch'
        ], 400);
    }

    // ADD OLD SALE QTY BACK FOR VALIDATION
    $oldQty = $prevStock ? $prevStock->quantities : 0;

    $availableQty = $purchaseDetails->sum('conversionQuantity') + $oldQty;

    if ($availableQty < $requestedQty) {

        DB::rollback();

        return response()->json([
            'message' => "Stock not available for product : "
                . $product_name .
                ". Available stock is : "
                . $availableQty . " strips"
        ], 406);
    }
}

            // Reverse the previous lines against the lots they actually came
            // from, then rebuild the tallies from what the lots now hold.
            //
            // The tally was previously incremented by hand from the sale line,
            // and the lots restored separately, so the two could disagree the
            // moment either was wrong. Both now derive from one authoritative
            // movement.
            $reversal = MovementContext::forSource(
                $sale,
                'sale_update_reversal',
                'sale-'.$sale->id,
                'Sale edited: previous lines reversed',
            );

            foreach ($prevDetails->pluck('stock_id')->unique() as $prevStockId) {
                $restored = $this->restorePurchaseDetailQuantity((int) $prevStockId, $reversal);

                // Ledger-based restore reads what this sale is recorded as
                // having taken from each lot, and there is nothing to read for
                // a sale made before movement logging existed (2026-09-07) -
                // `restoreAllocation` correctly returns 0 rather than guess.
                // Left there, the batch never gets its stock back, and
                // re-consuming the same quantity two steps below fails with
                // "not enough stock" on an edit that never touched quantity at
                // all. Falling back to the sale's own recorded quantity is
                // exactly what a pre-ledger sale always reversed with, before
                // per-lot lineage existed to do better.
                if ($restored <= 0) {
                    $quantityToRestore = $prevDetails
                        ->where('stock_id', $prevStockId)
                        ->sum('quantities');

                    if ($quantityToRestore > 0) {
                        BatchConsumption::restore((int) $prevStockId, (float) $quantityToRestore);
                    }
                }

                $prevStock = Stock::forBusiness($user->business_id)
                    ->lockForUpdate()
                    ->findOrFail($prevStockId);

                $this->inventoryChanges->synchronizeDerivedTallies($prevStock);
            }

            $prevDetails->each->delete();

            $saleDetails = [];
            foreach ($request_products as $key => $productData) {

                $stock = Stock::forBusiness($user->business_id)
                    ->whereKey($productData['stock_id'])
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($stock->expire_date?->lt(today())) {
                    DB::rollBack();

                    return response()->json([
                        'message' => __('The selected batch has expired and cannot be sold.'),
                        'stock_id' => $stock->id,
                        'batch_no' => $stock->batch_no,
                        'expire_date' => $stock->expire_date->toDateString(),
                    ], 422);
                }
                $reconsume = MovementContext::forSource(
                    $sale,
                    'sale',
                    'sale-'.$sale->id.'-r'.$sale->updated_at?->timestamp,
                    'Sale edited: new lines consumed',
                );

                if (!$this->consumePurchaseDetailQuantity($stock->id, $productData['quantities'], $reconsume)) {
                    DB::rollback();

                    $stock->load('product');

                    // Same reasoning as the create path: a refusal that names
                    // nothing cannot be acted on.
                    $available = DB::table('purchase_details')
                        ->where('stock_id', $stock->id)
                        ->sum('conversionQuantity');

                    return response()->json([
                        'message' => 'Not enough stock in this batch of '
                            . ($stock->product?->productName ?? 'the selected product')
                            . ($stock->batch_no ? ' (batch ' . $stock->batch_no . ')' : '')
                            . '. This batch has ' . (float) $available
                            . ', and ' . (float) ($productData['quantities'] ?? 0)
                            . ' was requested.',
                        'reason'    => 'batch_stock_insufficient',
                        'stock_id'  => $stock->id,
                        'available' => (float) $available,
                    ], 400);
                }

                // The server's own line, positionally matched to this one.
                //
                // Reading `price` and `lossProfit` off the request meant the row
                // recorded whatever the client claimed - and crashed outright with
                // "Undefined array key" when a client omitted `price`, which is
                // nullable and exactly what a client should omit now that the
                // server prices the sale.
                $saleDetails[$key] = $editedCart->lines[$key]->toSaleDetail($sale->id);

                $conversionValue = $stock->conversionValue ?? 1;
                $stock->decrement('productStock', $productData['quantities'] / ($conversionValue > 0 ? $conversionValue : 1));
            }

            SaleDetails::insert($saleDetails);

            if ($sale->dueAmount || $request->dueAmount) {
                $customer = Customer::forBusiness($user->business_id)
                    ->whereKey($request->customer_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Party Limit Check
                if ($request->dueAmount > 0) {
                    $newDue = $request->customer_id == $sale->customer_id ? (($customer->due - $sale->dueAmount) + $request->dueAmount) : ($customer->due + $request->dueAmount);

                    if ($customer->dueLimit > 0 && $newDue > $customer->dueLimit) {
                        DB::rollback();
                        return response()->json([
                            'message' => __("Cannot update sale. customer due will exceed credit limit!")
                        ], 400);
                    }
                }

                $customer->update([
                    'due' => $request->customer_id == $sale->customer_id ? (($customer->due - $sale->dueAmount) + $request->dueAmount) : ($customer->due + $request->dueAmount)
                ]);

                if ($request->customer_id != $sale->customer_id) {
                    $prev_customer = Customer::forBusiness($user->business_id)
                        ->whereKey($sale->customer_id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $prev_customer->update([
                        'due' => $prev_customer->due - $sale->dueAmount
                    ]);
                }
            }

            $balanceDiff = ($request->paidAmount ?? 0) - $sale->paidAmount;
            updateBalance($balanceDiff, 'increment');

            $lossProfit = collect($request_products)->pluck('lossProfit')->toArray();
            $baseProfit = array_sum($lossProfit);
            $totalDiscount =($request->discountAmount ?? 0) +($request->discount_percent ?? 0) +($request->rowDiscount ?? 0);

            $cash = $request->cash_amount ?? 0;
            $upi  = $request->upi_amount ?? 0;
            $card = $request->card_amount ?? 0;

            if ($request->paymentType == 'split') {

                $totalSplit = $cash + $upi + $card;

                if (abs((float) $totalSplit - (float) $request->paidAmount) > 0.01) {
                    DB::rollback();
                    return response()->json([
                        'message' => 'Split payment total must equal paid amount'
                    ], 400);
                }

            } else {

                if ($request->paymentType == 'cash') {
                    $cash = $request->paidAmount;
                    $upi = 0;
                    $card = 0;
                }

                if ($request->paymentType == 'upi') {
                    $cash = 0;
                    $upi = $request->paidAmount;
                    $card = 0;
                }

                if ($request->paymentType == 'card') {
                    $cash = 0;
                    $upi = 0;
                    $card = $request->paidAmount;
                }

            }

            $sale->update($request->except(
                'image', 'isPaid',
                // Replaced wholesale by `saleColumns()` below. Listed explicitly
                // so a new money field cannot slip back in through mass
                // assignment, exactly as `store()` does.
                'totalAmount', 'actual_total_amount', 'rounding_amount',
                'vat_amount', 'discountAmount', 'rowDiscount', 'shipping_charge',
                'paidAmount', 'dueAmount', 'change_amount', 'lossProfit',
                'cash_amount', 'upi_amount', 'card_amount',
                'products', 'products_payload',
                // Ownership belongs to the session, and `+` favours the left
                // operand - so without these the request's copy would win.
                'user_id', 'business_id', 'branch_id',
            ) + $editedCart->saleColumns() + [
                'discount_percent' => $request->discount_percent ?? 0,
                'discount_type' => $request->discount_type ?? 'flat',
                'doctor_id' => $request->doctor_id ?? null,
                'user_id' => auth()->id(),
                'isPaid' => filter_var($request->isPaid, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                'cash_amount' => $cash,
                'upi_amount' => $upi,
                'card_amount' => $card,
                'follow_up_date' => $request->follow_up_date ?? null,
                'image' => $request->image ? $this->upload($request, 'image', $sale->image) : $sale->image,
                'meta' => [
                    'note' => $request->note,
                    'customer_phone' => $request->customer_phone,
                ],
            ]);

            DB::commit();

            // The product list is cached for an hour and keyed on TAG_PRODUCTS.
            // Nothing here cleared it, so a sale left every catalogue screen
            // serving the stock figures from before it: a product sold down to
            // zero still read as in stock, and the batch sheet behind it
            // disagreed with the card in front. Same reason store/update/destroy
            // on the product controller each call this.
            try {
                CacheService::clearProductsCache();
            } catch (\Exception $e) {
                Log::warning('Failed to clear products cache after sale update', [
                    'business_id' => $user->business_id,
                    'sale_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }

return response()->json([
    'message' => __('Data saved successfully.'),
    'data' => $sale->load(
        'user:id,name,role',
        'customer:id,name,email,phone,gstin,type,relationship,customer_tier_id',
        'customer.tier:id,name',
        'details',
        'details.stock:id,batch_no,expire_date',
        'details.stock.purchaseDetails',
        'details.product:id,productName,category_id,manufacturer_id,model_id,productCode,productPurchasePrice,productStock,product_type,hsn_code,rack,shelf,vat_id,vat_type,vat_amount,product_flat_discount,product_percent_discount',
        'details.product.category:id,categoryName',
        'details.product.manufacturer:id,manufacturerName',
        'details.product.vat:id,name,rate',
        'details.product.product_model:id,name',
        'saleReturns.details',
        'saleReturns.details.saleDetail:id,product_id',
        'saleReturns.details.saleDetail.product:id,productName,productCode',
        'vat:id,name,rate',
        'payment_type:id,name',
        'branch:id,name,phone,address'
    ),
]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Sale update failed', [
                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'sale_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => __('Failed to update sale.'),
            ], 500);
        }
    }

    /**
     * Both now delegate to BatchConsumption, so a sale created here and one created
     * by an import draw stock down through identical code. Kept as methods because
     * the call sites read naturally and there are several of them.
     */
    /**
     * The order-level intent the calculator needs, taken from the request.
     *
     * Only inputs appear here - what discount is being asked for, what was
     * tendered, which rounding rule applies. No total, tax or price crosses over:
     * those are results, and the server produces them.
     *
     * @return array<string, mixed>
     */
    /**
     * Price a cart without saving anything.
     *
     * The till used to work its own totals out for the billing screen and only
     * learn the server's when the sale came back saved. Any rule the client does
     * not model - the taxable base, rounding, a capped line discount, a refused
     * order discount - showed up as the invoice disagreeing with the screen the
     * cashier had just been reading.
     *
     * This runs the same sequence as `store()` against the same service, so the
     * figures shown before saving are the figures that get saved. It takes no
     * locks and writes nothing: it is a question, not a reservation, and stock can
     * still move between asking and committing - which is why `store()` prices the
     * cart again under lock rather than trusting anything returned here.
     */
    public function calculate(Request $request)
    {
        $business_id = auth()->user()->business_id;

        try {
            // The same key `store()` prices from. `products` on a real checkout is
            // a JSON *string*; the line array lives in `products_payload`. Reading
            // the wrong one here would have made the preview disagree with the save
            // for the exact reason this endpoint exists to remove.
            $products = $request->input('products_payload', $request->input('products', []));

            if (! is_array($products)) {
                return response()->json([
                    'message' => __('Send the cart lines as `products_payload`.'),
                ], 422);
            }

            $options = $this->cartOptionsFrom($request);

            $customer = $request->customer_id
                ? Customer::forBusiness($business_id)->find($request->customer_id)
                : null;

            $base = $this->cartCalculator->calculate(
                $business_id,
                $products,
                $options,
                $customer,
                lockStock: false,
            );

            $subscription = $request->customer_id
                ? LoyaltySubscriptionManager::activeFor((int) $request->customer_id)
                : null;

            $eligibility = LoyaltyEligibility::split(
                $business_id,
                $this->eligibilityLinesFrom($base),
                $subscription?->loyalty_package_id,
            );

            $loyalty = CartLoyaltyDiscount::resolve(
                $eligibility['eligible'],
                $subscription,
                $request->filled('loyalty_discount_amount')
                    ? (float) $request->loyalty_discount_amount
                    : null,
                auth()->user()->hasPermission('loyalty.manual-discount'),
                $request->saleDate ?: null,
                manualReason: $request->input('loyalty_discount_reason'),
                // §9: departing from an automatic package's own figure is its own
                // permission, separate from being allowed to enter a manual one.
                mayOverrideAutomatic: auth()->user()->hasPermission('loyalty.override'),
            );

            if ($loyalty['reason'] !== null) {
                return response()->json(['message' => $loyalty['reason']], 422);
            }

            $cart = $this->cartCalculator->calculate(
                $business_id,
                $products,
                $options + ['loyalty_discount' => (float) ($loyalty['amount'] ?? 0)],
                $customer,
                lockStock: false,
            );

            return response()->json([
                'message' => __('Data fetched successfully.'),
                'data' => $cart->toArray(),
            ]);
        } catch (CartCalculationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
            ] + $e->context, $e->status);
        } catch (\Exception $e) {
            Log::error('Cart preview failed', [
                'business_id' => $business_id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('Failed to price the cart.'),
            ], 500);
        }
    }

    private function cartOptionsFrom(Request $request): array
    {
        return [
            'discount_amount' => $request->discountAmount ?? 0,
            'discount_percent' => $request->discount_percent ?? 0,
            'discount_type' => $request->discount_type ?? 'flat',
            'shipping_charge' => $request->shipping_charge ?? 0,
            'rounding_option' => $request->rounding_option ?? config('pos.rounding.default', 'none'),
            // GST configuration can be effective-dated. Preview and checkout use
            // the same sale date so a scheduled method cannot start early.
            'transaction_date' => $request->saleDate ?: now()->toDateString(),
            // What the customer handed over. Due and change are derived from it.
            'paid_amount' => $request->paidAmount ?? 0,
        ];
    }

    /**
     * Recast the priced cart as the line shape `LoyaltyEligibility` reads.
     *
     * It takes `subtotal` per line, so handing it the server's own net keeps the
     * discount base out of the client's hands.
     *
     * @return array<int, array<string, mixed>>
     */
    private function eligibilityLinesFrom(\App\Services\Pricing\CalculatedCart $cart): array
    {
        return array_map(fn ($line) => [
            'product_id' => $line->productId,
            'stock_id' => $line->stockId,
            'quantities' => $line->quantity,
            'subtotal' => $line->net->toFloat(),
        ], $cart->lines);
    }

    /**
     * How the paid amount divides across cash, UPI and card.
     *
     * The split is checked against the server's paid figure, not the client's, so
     * a till cannot record a sale as settled by money it never took. Returns
     * `[null, null, null]` when a declared split does not add up.
     *
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private function paymentSplitFor(StoreSaleRequest $request, float $paid): array
    {
        if ($request->paymentType === 'split') {
            $cash = (float) ($request->cash_amount ?? 0);
            $upi = (float) ($request->upi_amount ?? 0);
            $card = (float) ($request->card_amount ?? 0);

            // A paisa of tolerance for the client's own rounding, no more.
            if (abs(($cash + $upi + $card) - $paid) > 0.01) {
                return [null, null, null];
            }

            return [$cash, $upi, $card];
        }

        return match ($request->paymentType) {
            'cash' => [$paid, 0.0, 0.0],
            'upi' => [0.0, $paid, 0.0],
            'card' => [0.0, 0.0, $paid],
            // No type named: recorded as cash, which is what the till defaults to.
            default => [$paid, 0.0, 0.0],
        };
    }

    private function consumePurchaseDetailQuantity(
        int $stockId,
        float $quantity,
        ?MovementContext $context = null,
    ): bool {
        return BatchConsumption::consume($stockId, $quantity, $context);
    }

    /**
     * Gives a batch back exactly what this sale took from each of its lots.
     *
     * The quantity is no longer passed in: it is read from the allocation the
     * sale recorded when the stock went out. Passing a total was how a sale
     * that drew 30 from one lot and 20 from another handed all 50 back to
     * whichever lot happened to be newest.
     */
    private function restorePurchaseDetailQuantity(int $stockId, MovementContext $context): float
    {
        return BatchConsumption::restoreAllocation($stockId, $context);
    }

    public function searchInvoiceById(Request $request)
{
    $request->validate([
        'invoiceNumber' => 'required'
    ]);

    $user = auth()->user();

    $data = Sale::with(
        'user:id,name,role',
        'customer:id,name,email,phone,gstin,type,relationship,customer_tier_id',
        'customer.tier:id,name',
        'details',
        'details.stock:id,batch_no,expire_date',
        'details.stock.purchaseDetails',
        'details.product:id,productName,category_id,manufacturer_id,model_id,productCode,productPurchasePrice,productStock,product_type,hsn_code,rack,shelf,vat_id,vat_type,vat_amount,product_flat_discount,product_percent_discount',
        'details.product.category:id,categoryName',
        'details.product.manufacturer:id,manufacturerName',
        'details.product.product_model:id,name',
        'saleReturns.details',
        'saleReturns.details.saleDetail:id,product_id',
        'saleReturns.details.saleDetail.product:id,productName,productCode',
        'vat:id,name,rate',
        'payment_type:id,name',
        'branch:id,name,phone,address',
        'details.product.vat:id,name,rate',
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
    public function destroy(Sale $sale)
    {
        $user = auth()->user();

        if ($sale->business_id != $user->business_id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }

        if ($user->role == 'staff' && $sale->branch_id != $user->branch_id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }

        DB::beginTransaction();
        try {
            $sale = Sale::with('details')
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Cancelling gives every lot back exactly what this sale took from
            // it, then rebuilds the tallies from the lots themselves.
            //
            // The tally was incremented from the sale line while the lots were
            // restored separately; the two agreed only for as long as both were
            // right. They now come from one authoritative movement.
            $cancellation = MovementContext::forSource(
                $sale,
                'sale_cancellation',
                'sale-'.$sale->id,
                'Sale cancelled',
            );

            foreach ($sale->details->pluck('stock_id')->unique() as $cancelStockId) {
                $this->restorePurchaseDetailQuantity((int) $cancelStockId, $cancellation);

                $itemStock = Stock::whereKey($cancelStockId)->lockForUpdate()->firstOrFail();
                $this->inventoryChanges->synchronizeDerivedTallies($itemStock);
            }

            if ($sale->dueAmount) {
                $customer = Customer::whereKey($sale->customer_id)->lockForUpdate()->firstOrFail();
                $customer->update([
                    'due' => $customer->due - $sale->dueAmount
                ]);
            }

            updateBalance($sale->paidAmount, 'decrement');

            // §11: a cancelled invoice reverses its loyalty discount. Nothing was
            // sold, so nothing was earned — and §12.6 must stop counting a discount
            // given against an invoice that no longer exists.
            if ((float) $sale->loyalty_discount_amount > 0) {
                LoyaltyAudit::record(LoyaltyAudit::DISCOUNT_REVERSED, $sale, [
                    'customer_id' => $sale->customer_id,
                    'field'       => 'loyalty_discount_amount',
                    'old_value'   => LoyaltyAudit::stringify($sale->loyalty_discount_amount),
                    'new_value'   => '0',
                    'reason'      => 'Invoice cancelled',
                ]);

                $reversed = LoyaltyReturnAdjustment::reverseAll(
                    (float) $sale->loyalty_discount_amount,
                );

                $sale->update([
                    'loyalty_discount_amount' => $reversed['discount_amount'],
                    'loyalty_eligible_amount' => $reversed['eligible_amount'],
                ]);
            }

            if (file_exists($sale->image)) {
                Storage::delete($sale->image);
            }

            $sale->delete();

            Log::info('Sale deleted', [
                'business_id' => $user->business_id,
                'branch_id' => $sale->branch_id,
                'user_id' => $user->id,
                'sale_id' => $sale->id,
                'invoice_number' => $sale->invoiceNumber,
            ]);

            DB::commit();
            // The product list is cached for an hour and keyed on TAG_PRODUCTS.
            // Nothing here cleared it, so a sale left every catalogue screen
            // serving the stock figures from before it: a product sold down to
            // zero still read as in stock, and the batch sheet behind it
            // disagreed with the card in front. Same reason store/update/destroy
            // on the product controller each call this.
            CacheService::clearProductsCache();

            return response()->json([
                'message' => __('Data deleted successfully.'),
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Sale deletion failed', [
                'business_id' => $user->business_id,
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('Failed to delete sale.')
            ], 500);
        }
    }

    public function generateUpiQr(Request $request)
{
    try {

        $request->validate([
            'amount' => 'required|numeric|min:1'
        ]);

        $amount = $request->amount;

        $business = Business::findOrFail(
            auth()->user()->business_id
        );

        $upi_id = $business->upi_id;
        $name   = $business->companyName;

        if (empty($upi_id)) {
            return response()->json([
                'status' => false,
                'message' => 'UPI ID not configured for this business.'
            ], 400);
        }

        $upi_url = "upi://pay?pa={$upi_id}"
            . "&pn=" . urlencode($name)
            . "&am={$amount}"
            . "&cu=INR";

        $googleQrUrl =
            "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data="
            . rawurlencode($upi_url);

        // §11: which QR to show is the shop's decision, not this method's.
        //
        // 'auto' keeps the original rule - uploaded if one exists, rendered
        // otherwise - so a shop that has never chosen sees no change. The two
        // explicit modes are honoured even when the other option is available,
        // because the choice is the point.
        $mode = $business->upi_qr_mode ?: 'auto';

        if ($mode === 'uploaded' && empty($business->upi_qr_image)) {
            // Deliberately not falling back to a dynamic code. A shop that asked
            // for its own QR and gets a different one has no way to notice, and
            // the money would go to whatever the fallback encodes.
            return response()->json([
                'status'  => false,
                'message' => __('Please upload a QR code first, or switch the QR mode to dynamic.'),
                'reason'  => 'qr_image_missing',
                'amount'  => $amount,
                'upi_id'  => $upi_id,
                'upi_url' => $upi_url,
                'qr_source' => 'unavailable',
            ], 422);
        }

        // The shop's own uploaded QR. A static QR encodes the payee but not the
        // amount, so the amount is still returned for the cashier to read out or
        // the customer to type.
        if ($mode !== 'dynamic' && ! empty($business->upi_qr_image)) {
            return response()->json([
                'status'        => true,
                'amount'        => $amount,
                'upi_id'        => $upi_id,
                'business_name' => $name,
                'upi_url'       => $upi_url,
                'qr_image'      => null,
                'qr_image_url'  => $business->upi_qr_image,
                'qr_source'     => 'uploaded',
                // A static QR cannot carry the amount; the client must show it.
                'amount_encoded' => false,
            ]);
        }

        // Otherwise fall back to rendering a dynamic QR that does encode the amount.
        //
        // This reaches a third-party service on the payment path, so a slow or
        // unreachable renderer would otherwise hang the till. The timeout keeps the
        // failure quick and the client still receives `upi_url`, which any UPI app
        // can open directly.
        $imageData = @file_get_contents($googleQrUrl, false, stream_context_create([
            'http' => ['timeout' => 5],
        ]));

        if ($imageData === false) {
            Log::warning('UPI QR renderer unreachable; returning the payment link only', [
                'business_id' => $business->id,
            ]);

            return response()->json([
                'status'         => true,
                'amount'         => $amount,
                'upi_id'         => $upi_id,
                'business_name'  => $name,
                'upi_url'        => $upi_url,
                'qr_image'       => null,
                'qr_source'      => 'unavailable',
                'amount_encoded' => true,
                'message'        => __('Could not draw the QR code. The payment link is still valid.'),
            ]);
        }

        $base64Image =
            "data:image/png;base64," .
            base64_encode($imageData);

        return response()->json([
            'status' => true,
            'amount' => $amount,
            'upi_id' => $upi_id,
            'business_name' => $name,
            'upi_url' => $upi_url,
            'qr_image' => $base64Image,
            'qr_source' => 'generated',
            'amount_encoded' => true,
        ]);

    } catch (\Exception $e) {

        Log::error('UPI QR generation failed', [
            'business_id' => auth()->user()?->business_id,
            'user_id' => auth()->id(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'status' => false,
            'message' => __('Failed to generate UPI QR code.')
        ], 500);
    }
}

/**
 * One product's sale history: who bought it, when, from which batch.
 *
 * `product_id` is required. It used to be optional, and omitting it loaded
 * *every* `sale_details` row belonging to the business and mapped them all in
 * PHP — on a shop with 5,000 invoices that is a request that never returns, and
 * it is reachable by simply leaving a parameter off. The app has always sent
 * one; nothing was relying on the tenant-wide scan.
 *
 * Paginated for the same reason: a fast-moving line can carry thousands of rows
 * on its own.
 */
public function productReport(Request $request)
{
    $user = auth()->user();

    $validated = $request->validate([
        'product_id' => [
            'required',
            Rule::exists('products', 'id')->where('business_id', $user->business_id),
        ],
        'from_date' => ['nullable', 'date'],
        'to_date' => ['nullable', 'date'],
    ]);

    $perPage = ApiRequest::perPage($request, 100, 500);

    $query = SaleDetails::with([
        'sale.customer:id,name,phone,relationship',
        'product:id,productName',
        'stock:id,batch_no',
    ])
        ->where('product_id', $validated['product_id'])
        ->whereHas('sale', function ($q) use ($user, $validated) {
            $q->withoutGlobalScopes()
                ->where('business_id', $user->business_id);

            if (! empty($validated['from_date'])) {
                $q->whereDate('saleDate', '>=', $validated['from_date']);
            }

            if (! empty($validated['to_date'])) {
                $q->whereDate('saleDate', '<=', $validated['to_date']);
            }
        });

    // Counted over the whole filtered history, not the page, and counted as
    // *customers*. This reported `$data->count()` — the number of sale-detail
    // rows on the page — under a key called `total_customers`, so one customer
    // buying the same product ten times read as ten customers.
    $totals = (clone $query)
        ->join('sales', 'sales.id', '=', 'sale_details.sale_id')
        ->selectRaw('COUNT(DISTINCT sales.customer_id) as customers, COUNT(*) as rows_total')
        ->first();

    $page = $query->orderByDesc('sale_id')->paginate($perPage);

    $data = collect($page->items())->map(function ($item) {
        return [
            'product_name' => optional($item->product)->productName,
            'invoice_number' => optional($item->sale)->invoiceNumber,
            'sale_date' => optional($item->sale)->saleDate,
            'batch' => optional($item->stock)->batch_no,
            'customer_name' => optional(optional($item->sale)->customer)->name,
            // Prefer the sale snapshot. The customer relationship is only a
            // compatibility fallback for invoices created before the column
            // existed.
            'relationship' => optional($item->sale)->relationship
                ?: optional(optional($item->sale)->customer)->relationship,
            'customer_phone' => optional(optional($item->sale)->customer)->phone,
            'quantity_purchased' => $item->quantities,
        ];
    });

    return response()->json([
        'message' => 'Data fetched successfully.',
        'total_customers' => (int) ($totals->customers ?? 0),
        'total_rows' => (int) ($totals->rows_total ?? 0),
        'meta' => [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ],
        // Kept a flat array: the app reads `data` as a list.
        'data' => $data,
    ]);
}

}
