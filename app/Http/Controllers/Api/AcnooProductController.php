<?php

namespace App\Http\Controllers\Api;

use App\Models\Stock;
use App\Models\Product;
use App\Models\PurchaseDetails;
use App\Constants\UserRoles;
use App\Helpers\HasUploader;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Rules\TenantOwned;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Http\Requests\Api\StoreProductRequest;
use App\Helpers\DataSanitizer;
use App\Services\CacheService;
use App\Services\GstConfiguration;
use App\Exceptions\ProductHistoryAppeared;
use App\Services\ProductTransactionGuard;
use App\Support\Api\ApiRequest;

class AcnooProductController extends Controller
{
    use HasUploader;
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            $business_id = $user->business_id;
            $perPage = ApiRequest::perPage($request, 50, 200);
            $cacheKey = "products_{$business_id}_" . md5(json_encode($request->all()));

            $payload = CacheService::rememberUnderLoad($cacheKey, function () use ($request, $business_id, $perPage) {
                $query = Product::with([
                    'unit:id,unitName',
                    'upperSelectedUnit:id,unitName',
                    'lowerSelectedUnit:id,unitName',
                    'vat:id,rate',
                    'brand:id,brandName',
                    'manufacturer:id,manufacturerName',
                    'category:id,categoryName',
                    'product_model:id,name',
                    'stocks' => function ($q) {
                        // Sums the batch's remaining lots in the same query, so the
                        // available_quantity accessor never falls back to a per-row
                        // lookup across a product list.
                        $q->latest()
                            ->withSum('availableLots as available_quantity', 'conversionQuantity')
                            ->withSum('saleDetails as sold_quantity', 'quantities');
                    },
                    'stocks.purchaseDetails' => function ($q) {
                        $q->orderByDesc('id');
                    }
                ])
                ->withSum('purchaseDetails as stock_quantity', 'conversionQuantity')
                // purchase_details intentionally has no timestamps. Sort by the
                // owning purchase's business date instead of asking MySQL for a
                // non-existent purchase_details.created_at column (which made
                // every product list return HTTP 500 in production).
                ->addSelect([
                    'latest_purchase_at' => PurchaseDetails::query()
                        ->selectRaw('MAX(purchases.purchaseDate)')
                        ->join('purchases', 'purchases.id', '=', 'purchase_details.purchase_id')
                        ->whereColumn('purchase_details.product_id', 'products.id'),
                    // What is actually on the shelf, summed from the batches.
                    //
                    // The same figure `stock_status` and the summary cards are
                    // computed from, now sent per row as well. `products.productStock`
                    // is a cached column that drifts, and the list was rendering it:
                    // a product the server correctly counted as low stock showed
                    // "Out Of Stock · 0 Units" on its card. One source, one answer.
                    'available_quantity' => PurchaseDetails::query()
                        ->join('stocks', 'stocks.id', '=', 'purchase_details.stock_id')
                        ->whereColumn('stocks.product_id', 'products.id')
                        ->where('stocks.business_id', $business_id)
                        ->selectRaw('COALESCE(SUM(purchase_details.conversionQuantity), 0)'),
                ])
                ->where('business_id', $business_id);

                if ($request->product_name) {
                    $query->where('productName', 'LIKE', '%' . $request->product_name . '%');
                }

                if ($request->barcode) {
                    $query->where('productCode', $request->barcode);
                }

                if ($request->category_id) {
                    $categoryIds = explode(',', $request->category_id);
                    $query->whereIn('products.category_id', $categoryIds);
                }

                // Stock status must be applied before pagination. Filtering the
                // 20 rows already loaded by the app made valid matches on later
                // pages appear to be missing and produced inconsistent pages.
                $stockStatus = $request->input('stock_status');
                if (in_array($stockStatus, ['in_stock', 'low_stock', 'out_of_stock'], true)) {
                    $availableSql = 'COALESCE((SELECT SUM(pd.conversionQuantity) '
                        .'FROM purchase_details pd INNER JOIN stocks st ON st.id = pd.stock_id '
                        .'WHERE st.product_id = products.id AND st.business_id = ?), 0)';

                    if ($stockStatus === 'out_of_stock') {
                        $query->whereRaw("{$availableSql} <= 0", [$business_id]);
                    } elseif ($stockStatus === 'low_stock') {
                        $query->whereRaw("{$availableSql} > 0", [$business_id])
                            ->whereRaw('COALESCE(products.alert_qty, 0) > 0')
                            ->whereRaw("{$availableSql} <= COALESCE(products.alert_qty, 0)", [$business_id]);
                    } else {
                        $query->whereRaw("{$availableSql} > 0", [$business_id])
                            ->where(function ($stockQuery) use ($availableSql, $business_id) {
                                $stockQuery->whereRaw('COALESCE(products.alert_qty, 0) <= 0')
                                    ->orWhereRaw("{$availableSql} > COALESCE(products.alert_qty, 0)", [$business_id]);
                            });
                    }
                }

                if ($request->rack) {
                    $query->where('rack', 'LIKE', '%' . $request->rack . '%');
                }

                if ($request->shelf) {
                    $query->where('shelf', 'LIKE', '%' . $request->shelf . '%');
                }

                if ($request->manufacturer_id) {
                    $query->where('manufacturer_id', $request->manufacturer_id);
                }

                if ($request->model_id) {
                    $query->where('model_id', $request->model_id);
                }

                // Counter Sale, Sales Products and Purchase Products share this
                // catalogue. Staff need the products received most recently at
                // the top, not merely the products whose master record was
                // created most recently.
                // Two orders, because two screens are asking different
                // questions.
                //
                // Counter Sale, Sales and Purchase share this catalogue and
                // want the stock that arrived most recently at the top, which
                // is what `latest_purchase_at` gives them. The Inventory list
                // is where a product is *created*, and there the useful answer
                // is the one just added — which may have no stock at all yet
                // and would otherwise sort last.
                //
                // Opt-in, so the till keeps the order it was given.
                $newestFirst = $request->input('sort') === 'newest';

                $products = $query
                    ->when(! $newestFirst, fn ($q) => $q->orderByDesc('latest_purchase_at'))
                    ->orderByDesc('products.id')
                    ->paginate($perPage);

                $products->map(function ($product) {
                    $product->conversion_quantity = $product->stocks->sum(function ($stock) {
                        return $stock->purchaseDetails->sum('conversionQuantity');
                    });
                    return $product;
                });

                $total_stock_value = $products->sum(function ($product) {
                    return $product->stocks->sum(function ($stock) {
                        return $stock->productPurchasePrice * $stock->productStock;
                    });
                });

                return [
                    'products' => $products,
                    'total_stock_value' => $total_stock_value,
                ];
            }, 3600, [CacheService::TAG_PRODUCTS]);

            return response()->json([
                'message' => __('Data fetched successfully.'),
                'total_stock_value' => $payload['total_stock_value'],
                'data' => $payload['products'],
            ]);
        } catch (\Exception $e) {
            Log::error('Product list failed', [
                'business_id' => auth()->user()?->business_id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('Failed to fetch products.')
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProductRequest $request)
    {
        $business_id = auth()->user()->business_id;

        DB::beginTransaction();
        try {
            $product = Product::create($request->except('productPicture', 'alert_qty', 'expire_date', 'productStock', 'profit_percent', 'productSalePrice', 'productDealerPrice', 'productPurchasePrice', 'productWholeSalePrice', 'product_type') + [
                'business_id' => $business_id,
                // One spelling in the column, whichever the client sent.
                'product_type' => $this->isVariant($request) ? 'variant' : 'single',
                'productDescription' => DataSanitizer::sanitizeString($request->productDescription),
                'rack' => DataSanitizer::sanitizeString($request->rack),
                'shelf' => DataSanitizer::sanitizeString($request->shelf),
                'alert_qty' => $request->alert_qty ?? 0,
                'expire_date' => $request->expire_date ?? null,
                'productStock' => $request->maxProductStock ?? $request->productStock ?? 0,
                'profit_percent' => $request->profit_percent ?? 0,
                'productSalePrice' => $request->productSalePrice ?? 0,
                'productDealerPrice' => $request->productDealerPrice ?? $request->productSalePrice ?? 0,
                'productWholeSalePrice' => $request->productWholeSalePrice ?? $request->productSalePrice ?? 0,
                'productPurchasePrice' => $request->productPurchasePrice ?? 0,
                'productUpperUnit' => $request->upperSelectedUnit ?? 0,
                'productLowerUnit' => $request->lowerSelectedUnit ?? 0,
                'conversionValue' => $request->conversionValue ?? 0,
                'hsn_code' => $request->hsn_code ?: null,
                'cess_rate' => $request->cess_rate ?? 0,
                'gst_exemption_reason' => $request->gst_exemption_reason ?: null,
                'product_flat_discount' => $request->product_flat_discount ?? 0,
                'product_percent_discount' => $request->product_percent_discount ?? 0,
                // Falls back to the business's default tax mode (which itself comes
                // from the shop type) instead of a hardcoded 'exclusive', so a
                // pharmacy does not have to correct every product by hand.
                // Full hierarchy: the item's own choice, then the branch, then the
                // business, then the shop type. Replaces the two-value default so a
                // non-registered shop can record "GST not applicable" rather than
                // being forced to pick inclusive or exclusive.
                'vat_type' => GstConfiguration::resolve(
                    $request->vat_type,
                    auth()->user()->branch ?? null,
                    auth()->user()->business,
                ),
                'productPicture' => $this->upload($request, 'productPicture'),

            ]);

            if ($this->isVariant($request)) {

                $stockData = [];

                foreach ($request->batch_no ?? [] as $key => $batch_no) {

                    $stockData[] = [
                        'batch_no' => $batch_no,
                        'business_id' => $business_id,
                        'product_id' => $product->id,
                        'branch_id' => \App\Support\ActiveBranch::forWrite(),
                        'productSalePrice' => $request->productSalePrice[$key] ?? 0,
                        'mfg_date' => $request->mfg_date[$key] ?? null,
                        'productStock' => $request->productStock[$key] ?? 0,
                        'expire_date' => $request->expire_date[$key] ?? null,
                        'profit_percent' => $request->profit_percent[$key] ?? 0,
                        'productPurchasePrice' => $request->productPurchasePrice[$key] ?? 0,
                        'productDealerPrice' => $request->productDealerPrice[$key] ?? 0,
                        'productWholeSalePrice' => $request->productWholeSalePrice[$key] ?? 0,
                        'conversionValue' => $request->conversionValue ?? 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                Stock::insert($stockData);
            } else {
                Stock::create([
                    'business_id' => $business_id,
                    'product_id' => $product->id,
                    'branch_id' => \App\Support\ActiveBranch::forWrite(),
                    'mfg_date' => $request->mfg_date,
                    'productStock' => $request->productStock ?? 0,
                    'productPurchasePrice' => $request->productPurchasePrice ?? 0,
                    'profit_percent' => $request->profit_percent ?? $product->profit_percent ?? 0,
                    'productSalePrice' => $request->productSalePrice ?? 0,
                    'productWholeSalePrice' => $request->productWholeSalePrice ?? 0,
                    'productDealerPrice' => $request->productDealerPrice ?? 0,
                    'conversionValue' => $request->conversionValue ?? 0,
                    'expire_date' => $request->expire_date,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            DB::commit();

            CacheService::clearProductsCache();

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $product
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Product creation failed', [
                'business_id' => $business_id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => __('Failed to create product.')
            ], 500);
        }
    }

    public function show(string $id)
{
    $user = auth()->user();

    $data = Product::with([
        'unit:id,unitName',
        'upperSelectedUnit:id,unitName',
        'lowerSelectedUnit:id,unitName',
        'vat:id,rate',
        'brand:id,brandName',
        'manufacturer:id,manufacturerName',
        'category:id,categoryName',
        'product_model:id,name',
        'stocks.purchaseDetails.purchase:id,purchaseDate,created_at,updated_at,cash_amount,upi_amount,card_amount,totalAmount',

        'stocks' => function ($query) {
            $query->whereHas('purchaseDetails', function ($q) {
                $q->where('conversionQuantity', '>', 0);
            })
            ->withSum('availableLots as available_quantity', 'conversionQuantity')
            ->withSum('saleDetails as sold_quantity', 'quantities');
        },

        'stocks.purchaseDetails' => function ($query) {
            $query->where('conversionQuantity', '>', 0)
                ->orderByDesc('id');
        }
    ])
    ->withSum('stocks', 'productStock')
    ->where('business_id', $user->business_id)
    ->findOrFail($id);

    return response()->json([
        'message' => __('Data fetched successfully.'),
        'data' => $data,
    ]);
}

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product)
    {
        $user = auth()->user();
        $business_id = $user->business_id;

        if ($product->business_id != $business_id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }

        // The Flutter form re-posts the unit fields on every save, and when its
        // dropdown lost the stored id on prefill (which happens once a product is
        // transaction-locked and the fields are frozen) it falls back to posting
        // the unit *name*. `TenantOwned::in('units')` then rejected the whole
        // update — including an edit that only touched the minimum stock level —
        // with "The selected upper selected unit is invalid.", before the
        // friendlier transaction-lock message could be reached.
        //
        // Resolve a name (or an unusable value) back to the id that is actually
        // stored, so a non-id here means "unchanged" rather than "rejected". A
        // real unit change still flows through as an id and is still caught by
        // the transaction guard below.
        $this->normalizeUnitFields($request, $product, (int) $business_id);
        // Legacy imports may contain dangling or foreign lookup IDs. Reposting
        // an unchanged reference must not block an unrelated metadata edit.
        // New/changed IDs still pass the tenant-scoped validation below.
        foreach ([
            'brand_id' => 'brand_id',
            'unit_id' => 'unit_id',
            'upperSelectedUnit' => 'productUpperUnit',
            'lowerSelectedUnit' => 'productLowerUnit',
        ] as $field => $column) {
            if ($request->has($field)
                && (string) $request->input($field) === (string) $product->{$column}) {
                $request->request->remove($field);
                $request->query->remove($field);
                if ($request->isJson()) {
                    $request->json()->remove($field);
                }
            }
        }

        // Every lookup below is scoped to the signed-in business, using the
        // same `TenantOwned` rule `StoreProductRequest` already uses.
        //
        // `update` had drifted to bare `exists:` — global, not tenant-scoped —
        // while the row is written from the request, so another shop's
        // category, brand or unit could be attached to this shop's product. A
        // foreign `vat_id` would apply *their* tax rate to this shop's sales,
        // which is a financial error rather than only a data leak.
        $request->validate([
            'unit_id' => ['nullable', TenantOwned::in('units')],
            'brand_id' => ['nullable', TenantOwned::in('brands')],
            'manufacturer_id' => ['nullable', TenantOwned::in('manufacturers')],
            'productName' => 'required|string|max:250',
            'category_id' => ['nullable', TenantOwned::in('categories')],
            'model_id' => ['nullable', TenantOwned::in('product_models')],
            'vat_id' => ['nullable', TenantOwned::in('vats')],
            'rack' => 'nullable|string|max:50',
            'shelf' => 'nullable|string|max:50',
            'upperSelectedUnit' => ['nullable', TenantOwned::in('units')],
            'lowerSelectedUnit' => ['nullable', TenantOwned::in('units')],
            'conversionValue' => 'nullable|numeric|min:0',
            // A zero selling price makes the batch impossible to bill and was
            // commonly caused by the edit form recalculating from an empty
            // purchase field after GST changed. Refuse it at the write boundary
            // so an older client cannot erase usable batch prices.
            'productSalePrice' => $this->isVariant($request)
                ? ['nullable', 'array']
                : ['nullable', 'numeric', 'gt:0'],
            'productSalePrice.*' => ['numeric', 'gt:0'],
            // HSN for goods, SAC for services — the same column serves both. Digits
            // only and 4-8 long, which is the range Indian GST uses; a code outside
            // it would be rejected at filing time rather than here.
            'hsn_code' => 'nullable|string|regex:/^[0-9]{4,8}$/',
            // Cess sits outside the CGST/SGST/IGST split and is added on top.
            'cess_rate' => 'nullable|numeric|min:0|max:100',
            // §9: why an item carries no tax. Free text, because the accepted
            // reasons change with notifications and a stale list would push users
            // into picking whichever option was least wrong.
            'gst_exemption_reason' => 'nullable|string|max:255',
            'productCode' => [
                'nullable',
                'unique:products,productCode,' . $product->id . ',id,business_id,' . $business_id,
            ],
        ]);

        // Transaction history protects only the conversion value. Product
        // master data (manufacturer, rack, pricing/discount and tax metadata)
        // describes future transactions and must remain maintainable after the
        // first purchase or sale. Historical documents already retain the
        // values used when they were posted.

        // Changing the conversion value retroactively reinterprets every quantity ever
        // recorded against this product: a lot bought as 10 boxes of 10 becomes 10 of
        // 20, so stock, sales history and profit figures all silently shift. Once the
        // product has been transacted, the number has to stay as it was.
        if ($request->filled('conversionValue')
            && (float) $request->conversionValue !== (float) $product->conversionValue) {

            // Wider than "has it been transacted": a batch alone is enough, because
        // `stocks.product_id` cascades and would take it with the product.
        $usage = ProductTransactionGuard::deletionBlockers($product);

            if ($usage !== []) {
                // Locked, not forbidden. The owner may still authorise it with
                // their password - the same override deletion uses - because a
                // conversion value entered wrongly at setup has to be correctable
                // by someone, and the alternative is recreating the product and
                // losing its history.
                $authorised = $this->authoriseProtectedChange(
                    $request,
                    $user,
                    $product,
                    'conversion_value',
                    __('The conversion value cannot be changed once the product has been bought or sold.'),
                    __('Only the business owner can change the conversion value of a product with transaction history.'),
                );

                if ($authorised !== true) {
                    // The 409 carries the counts and the current value so the till
                    // can explain the block before asking for a password.
                    if ($authorised->getStatusCode() === 409) {
                        $body = $authorised->getData(true);
                        $body['reason'] = 'conversion_value_locked';
                        $body['current_value'] = (float) $product->conversionValue;

                        return response()->json($body, 409);
                    }

                    return $authorised;
                }

                Log::warning('Conversion value changed on a transacted product', [
                    'business_id' => $user->business_id,
                    'user_id'     => $user->id,
                    'product_id'  => $product->id,
                    'from'        => (float) $product->conversionValue,
                    'to'          => (float) $request->conversionValue,
                    'history'     => $usage,
                ]);
            }
        }

        DB::beginTransaction();
        try {

            $productData = [];

            if ($this->isVariant($request)) {
                $productData = [
                    'productSalePrice' => $request->productSalePrice[0] ?? $product->productSalePrice ?? 0,
                    'productPurchasePrice' => $request->productPurchasePrice[0] ?? $product->productPurchasePrice ?? 0,
                    'productDealerPrice' => $request->productDealerPrice[0] ?? $product->productDealerPrice ?? 0,
                    'productWholeSalePrice' => $request->productWholeSalePrice[0] ?? $product->productWholeSalePrice ?? 0,
                    'productStock' => $request->has('maxProductStock') || $request->has('productStock')
                        ? ($request->maxProductStock ?? array_sum($request->productStock ?? []))
                        : $product->productStock,
                    'profit_percent' => $request->profit_percent[0] ?? $product->profit_percent ?? 0,
                ];
            } else {
                $productData = [
                    'productSalePrice' => $request->productSalePrice ?? $product->productSalePrice ?? 0,
                    'productPurchasePrice' => $request->productPurchasePrice ?? $product->productPurchasePrice ?? 0,
                    // Both fall back through the sale price, which may itself be
                    // absent on a partial edit — and both columns are NOT NULL, so
                    // the stored value is the final fallback rather than null.
                    'productDealerPrice' => $request->productDealerPrice
                        ?? $request->productSalePrice
                        ?? $product->productDealerPrice
                        ?? 0,
                    'productWholeSalePrice' => $request->productWholeSalePrice
                        ?? $request->productSalePrice
                        ?? $product->productWholeSalePrice
                        ?? 0,
                    // Metadata edits intentionally omit stock. Falling back to
                    // zero here made a manufacturer/discount-only save erase
                    // the cached product tally even though lot inventory was
                    // unchanged.
                    'productStock' => $request->productStock ?? $product->productStock ?? 0,
                    'profit_percent' => $request->profit_percent ?? $product->profit_percent ?? 0,
                ];
            }

            $product->update($request->except('productPicture', 'alert_qty', 'expire_date', 'productStock', 'profit_percent', 'productSalePrice', 'productDealerPrice', 'productPurchasePrice', 'productWholeSalePrice', 'product_type') + [
                'business_id' => $business_id,
                // Normalised, so the column keeps one spelling whichever client
                // wrote the row. Omitted from the request entirely rather than
                // overwritten, because an edit that does not mention the type
                // must not silently change it.
                'product_type' => $request->has('product_type')
                    ? ($this->isVariant($request) ? 'variant' : 'single')
                    : ($product->product_type ?? 'variant'),
                'productDescription' => $request->has('productDescription')
                    ? $request->productDescription : $product->productDescription,
                'rack' => $request->has('rack') ? $request->rack : $product->rack,
                'shelf' => $request->has('shelf') ? $request->shelf : $product->shelf,
                'alert_qty' => $request->has('alert_qty')
                    ? $request->alert_qty : ($product->alert_qty ?? 0),
                // Both columns are NOT NULL, so writing null made every update that
                // omitted the unit fields fail with "Failed to update product."
                // Keeping the stored value means a partial edit leaves units alone
                // rather than crashing or silently resetting them.
                'productUpperUnit' => $request->upperSelectedUnit ?? $product->productUpperUnit ?? 0,
                'productLowerUnit' => $request->lowerSelectedUnit ?? $product->productLowerUnit ?? 0,
                // Falling back to 0 silently reset the conversion value whenever the
                // field was not resent, which reinterprets every recorded quantity for
                // the product. An edit that omits it must leave it alone.
                'conversionValue' => $request->conversionValue ?? $product->conversionValue ?? 0,
                // Kept when the form omits them, so editing an unrelated field does
                // not silently clear a product's tax classification.
                'hsn_code' => $request->hsn_code ?: $product->hsn_code,
                'cess_rate' => $request->cess_rate ?? $product->cess_rate ?? 0,
                'gst_exemption_reason' => $request->gst_exemption_reason
                    ?: $product->gst_exemption_reason,
                'product_flat_discount' => $request->has('product_flat_discount')
                    ? $request->product_flat_discount : ($product->product_flat_discount ?? 0),
                'product_percent_discount' => $request->has('product_percent_discount')
                    ? $request->product_percent_discount : ($product->product_percent_discount ?? 0),
                'expire_date' => $request->has('expire_date')
                    ? (is_array($request->expire_date) ? null : $request->expire_date)
                    : $product->expire_date,
                'productPicture' => ($request->hasFile('productPicture') || $request->input('productPicture')) ? $this->upload($request, 'productPicture', $product->productPicture) : $product->productPicture,
            ] + $productData + [
                'productStock' => $request->has('productStock') || $request->has('maxProductStock')
                    ? ($request->maxProductStock ?? $request->productStock ?? $product->productStock ?? 0)
                    : $product->productStock,
            ]);

            // Stock price cascade disabled - prices are not pushed to stock batches on product update

            DB::commit();

            // Same reason as store() and destroy(): a list left cached after a
            // write shows the pre-write state, and acting on a row from that
            // stale list is what produces a 404 for an id the client just saw.
            CacheService::clearProductsCache();

            Log::info('Product updated', [
                'business_id' => $business_id,
                'user_id' => $user->id,
                'product_id' => $product->id,
            ]);

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $product->load('stocks'),
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Product update failed', [
                'business_id' => $business_id,
                'product_id' => $product->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => __('Failed to update product.')
            ], 406);
        }
    }

    /**
     * Coerce the unit fields on a product update into ids this business owns.
     *
     * The request field maps to the stored column so an unresolvable value
     * (a name, a stale id, an empty string) becomes the value already on the
     * product — i.e. "no change" — instead of a validation failure.
     */
    private function normalizeUnitFields(Request $request, Product $product, int $businessId): void
    {
        $map = [
            'unit_id'           => 'unit_id',
            'upperSelectedUnit' => 'productUpperUnit',
            'lowerSelectedUnit' => 'productLowerUnit',
        ];

        $units = null; // lazily loaded id/name lookup for this business

        foreach ($map as $field => $column) {
            if (! $request->has($field)) {
                continue;
            }

            $raw = trim((string) $request->input($field));
            $stored = $product->{$column};

            // Empty means "leave it alone".
            if ($raw === '' || strtolower($raw) === 'null') {
                $request->merge([$field => $stored ?: null]);
                continue;
            }

            // A numeric value is passed through untouched: the validation below
            // accepts it if the business owns it and rejects it (422) otherwise,
            // which is the contract another shop's id has to keep hitting.
            if (ctype_digit($raw)) {
                continue;
            }

            // A non-numeric value is the reported case — a unit *name* the form
            // fell back to when it lost the id on prefill. Resolve it to an id
            // this business owns, or treat it as "unchanged".
            $units ??= \App\Models\Unit::where('business_id', $businessId)
                ->pluck('id', 'unitName');
            $byName = $units->first(
                fn ($id, $name) => strcasecmp((string) $name, $raw) === 0,
            );

            $request->merge([$field => $byName ?? ($stored ?: null)]);
        }
    }

    public function getProductsByLocation(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'rack' => ['nullable', 'string', 'max:120'],
            'shelf' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $rack = trim((string) ($validated['rack'] ?? ''));
        $shelf = trim((string) ($validated['shelf'] ?? ''));

        $products = Product::with([
            'unit:id,unitName',
            'upperSelectedUnit:id,unitName',
            'lowerSelectedUnit:id,unitName',
            'vat:id,rate',
            'brand:id,brandName',
            'manufacturer:id,manufacturerName',
            'category:id,categoryName',
            'product_model:id,name',
            'stocks',
            'stocks.purchaseDetails'
        ])
        ->where('business_id', $user->business_id)
        ->when($search !== '', function ($query) use ($search) {
            $query->where(function ($nested) use ($search) {
                $nested->where('productName', 'LIKE', '%' . $search . '%')
                    ->orWhere('productCode', 'LIKE', '%' . $search . '%')
                    ->orWhere('rack', 'LIKE', '%' . $search . '%')
                    ->orWhere('shelf', 'LIKE', '%' . $search . '%');
            });
        })
        // Location filters are selections, not free-text search. A selected
        // "Rack 1" must not include products stored in "Rack 10".
        ->when($rack !== '', fn ($query) =>
            $query->whereRaw('LOWER(TRIM(rack)) = ?', [mb_strtolower($rack)]))
        ->when($shelf !== '', fn ($query) =>
            $query->whereRaw('LOWER(TRIM(shelf)) = ?', [mb_strtolower($shelf)]))
        ->orderByRaw("
                CASE
                    WHEN rack REGEXP '^[0-9]' THEN 0
                    ELSE 1
                END
            ")
            ->orderByRaw("
                REGEXP_REPLACE(rack, '[0-9]', '')
            ")
            ->orderByRaw("
                CAST(
                    REGEXP_SUBSTR(rack, '[0-9]+')
                    AS UNSIGNED
                )
            ")
        ->paginate(ApiRequest::perPage($request, 50, 100));

        return response()->json([
            'message' => 'Products fetched successfully',
            'data' => $products
        ]);
    }

    public function expiredProducts(Request $request)
{
    $user = auth()->user();

    $perPage = ApiRequest::perPage($request, 50, 100);

    $query = Stock::with([
        'product:id,productName,productCode,category_id,manufacturer_id,brand_id,productStock,alert_qty,rack,shelf',
        'product.category:id,categoryName',
        'product.brand:id,brandName',
        'product.manufacturer:id,manufacturerName',
        'purchaseDetails:id,stock_id,conversionQuantity,conversionPrice,conversionValue,expire_date'
    ])

    ->select('stocks.*')

        // Scoped to the batch, not the product.
        //
        // Both subqueries matched on `product_id`, so every batch row reported
        // the whole product's stock: a shop destroying one expired lot of 30 was
        // told it held 100. Each row of this list *is* a batch, and the number
        // beside it has to be what remains of that batch — summed across every
        // purchase into it, because the same lot arrives on more than one invoice.
        //
        // The divisor is the batch's own conversion. Taking MAX across the
        // product meant a lot bought in strips of 10 was divided by another lot's
        // 30, and the count came out a third of what was on the shelf.
        ->selectRaw("
        ROUND(
            (
                SELECT COALESCE(SUM(pd.conversionQuantity),0)
                FROM purchase_details pd
                WHERE pd.stock_id = stocks.id
            )
            /
            COALESCE(
                NULLIF(stocks.conversionValue, 0),
                NULLIF(
                    (
                        SELECT MAX(pd2.conversionValue)
                        FROM purchase_details pd2
                        WHERE pd2.stock_id = stocks.id
                    ),
                    0
                ),
                1
            )
        ) as available_strip_quantity
    ")

    ->where('business_id', $user->business_id);

    if ($request->product_name) {

        $search = $request->product_name;

        $query->whereHas('product', function ($q) use ($search) {
            $q->where('productName', 'LIKE', "%{$search}%");
        });
    }

    if ($request->expire_from && $request->expire_to) {

        $query->whereDate('expire_date', '>=', $request->expire_from)
              ->whereDate('expire_date', '<=', $request->expire_to);

    }

    elseif ($request->expire_from) {

        $query->whereDate('expire_date', '<=', $request->expire_from)
              ->whereDate('expire_date', '>=', now()->toDateString());
    }

    else {

        $query->whereDate('expire_date', '<=', now()->toDateString());
    }

    $data = $query
        ->orderBy('expire_date', 'asc')
        ->latest()
        ->paginate($perPage);

    return response()->json([
        'message' => 'Expired products fetched successfully',
        'data' => $data
    ]);
}

    /**
     * Push edited selling prices down onto the product's stock batches.
     *
     * Billing reads its price from the batch (`stocks.productSalePrice`), but the
     * product form saves to the product master. Editing a price therefore appeared to
     * do nothing: the old price kept being charged until a new purchase happened to
     * create a fresh batch. This closes that gap.
     *
     * Only the single-product path cascades. A `variant` product carries a separate
     * price per batch, supplied as an array and already written per batch, so
     * flattening them to one value would destroy real per-batch pricing.
     *
     * Historical sales are unaffected: `sale_details.price` records what was actually
     * charged, and the profit/loss report reads that column, not the batch. So this
     * changes future sales only and leaves closed periods alone.
     */
    /**
     * Whether the request describes a per-batch product.
     *
     * Three vocabularies for one column had grown up independently: the till
     * sent `single` / `batch`, `StoreProductRequest` accepted `simple` /
     * `variant`, and every branch here tested for `variant` alone. So a batch
     * product edited from the till arrived as `batch`, failed the `variant`
     * test, and was written down the single-product path — which assigns the
     * request's *array* of per-batch prices to scalar columns and cascades one
     * price over every batch the product has.
     *
     * Recognising all of them keeps app versions already installed on shop
     * phones working; the canonical spelling is written back on save.
     */
    private function isVariant(Request $request): bool
    {
        $type = strtolower(trim((string) $request->input('product_type', '')));

        return in_array($type, ['variant', 'batch'], true);
    }

    private function cascadeSalePricesToStocks(Request $request, Product $product, array $productData): void
    {
        if ($this->isVariant($request)) {
            return;
        }

        // Only carry over prices the request actually supplied, so an edit that
        // leaves a field untouched does not reset that price to zero.
        $priceFields = [
            'productSalePrice',
            'productWholeSalePrice',
            'productDealerPrice',
        ];

        $updates = [];
        foreach ($priceFields as $field) {
            if ($request->filled($field) && isset($productData[$field])) {
                $updates[$field] = $productData[$field];
            }
        }

        if ($updates === []) {
            return;
        }

        $affected = Stock::where('product_id', $product->id)->update($updates);

        if ($affected > 0) {
            Log::info('Product prices cascaded to stock batches', [
                'business_id' => $product->business_id,
                'product_id'  => $product->id,
                'batches'     => $affected,
                'fields'      => array_keys($updates),
            ]);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * Deletion is refused while the product carries transaction history. The detail
     * tables used to cascade on this delete, which silently destroyed the sale,
     * purchase and transfer lines that profit/loss and tax reporting are built from;
     * they now RESTRICT, so this check reports the reason instead of letting the
     * database reject it with an opaque constraint error.
     *
     * The owner may still force it by re-entering their password. That deletes the
     * dependent history along with the product, so it is deliberately limited to the
     * business owner and always logged with the counts that were overridden.
     */
    public function destroy(Request $request, Product $product)
    {
        $user = auth()->user();

        if ($product->business_id != $user->business_id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }

        $usage = ProductTransactionGuard::usage($product);

        // A transacted product is not deletable by anyone.
        //
        // The owner could previously force this through by re-entering their
        // password, which deleted the sale, purchase and transfer lines along
        // with the product. Those lines are what profit, stock and GST
        // reporting are built from, so the override quietly rewrote finished
        // history — and the shop asked for it to go. Correcting a product that
        // has been bought or sold now means editing it or marking it inactive,
        // never removing it.
        if ($usage !== []) {
            return response()->json(
                ProductTransactionGuard::protectedResponse($usage) + [
                    // Kept for the installed app, which reads these to decide
                    // whether to offer a password prompt. There is no longer a
                    // path behind it.
                    'reason'             => 'has_transactions',
                    'transactions'       => $usage,
                    'transactions_text'  => ProductTransactionGuard::describe($usage),
                    'force_deletable'    => false,
                    'owner_can_override' => false,
                ],
                409
            );
        }

        try {
            DB::transaction(function () use ($product) {
                // Re-checked under a row lock, because the check above ran
                // outside one.
                //
                // Between deciding a product was unused and removing it, a sale
                // on another connection can write the first history it has ever
                // had. Locking the product row makes that sale wait: it either
                // lands before the lock, in which case the re-check sees it and
                // refuses, or after the delete, when there is no product left
                // to sell. The window is small, but it ends with immutable
                // history pointing at an id that no longer resolves.
                $locked = Product::query()
                    ->whereKey($product->id)
                    ->lockForUpdate()
                    ->first();

                if ($locked && ProductTransactionGuard::deletionBlockers($locked) !== []) {
                    throw new ProductHistoryAppeared();
                }

                $product->delete();
            });

            // Only touch the image once the row is gone, so a failed delete does not
            // leave a product pointing at a file that no longer exists.
            if ($product->productPicture && Storage::disk(config('filesystems.default'))->exists($product->productPicture)) {
                Storage::disk(config('filesystems.default'))->delete($product->productPicture);
            }

            CacheService::clearProductsCache();

            Log::info('Product deleted', [
                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'product_id' => $product->id,
            ]);

            return response()->json([
                'message' => __('Data deleted successfully.'),
            ]);
        } catch (ProductHistoryAppeared $e) {
            // The product was transacted while this request was in flight. The
            // transaction rolled back, so nothing was removed.
            return response()->json(
                ProductTransactionGuard::protectedResponse(
                    ProductTransactionGuard::deletionBlockers($product->fresh() ?? $product)
                ),
                409
            );
        } catch (\Exception $e) {
            Log::error('Product deletion failed', [
                'business_id' => $user->business_id,
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('Failed to delete product.')
            ], 500);
        }
    }

    /**
     * The owner override for any change a product's history would otherwise forbid.
     *
     * Deletion and the conversion value are both protected for the same reason -
     * historical rows are what profit, stock and tax reporting aggregate over -
     * so they share one password path rather than growing a second one. A second
     * password check is a second place to get wrong.
     *
     * Without a password this reports the refusal and the counts behind it, so the
     * client can explain the block and offer the override; with one it checks the
     * caller is the owner and that the password is theirs.
     *
     * @return true|\Illuminate\Http\JsonResponse
     */
    private function authoriseProtectedChange(
        Request $request,
        $user,
        Product $product,
        string $action,
        string $blockedMessage,
        string $notOwnerMessage,
    ) {
        $usage = ProductTransactionGuard::usage($product);

        if (! $request->filled('password')) {
            return response()->json([
                'message'           => $blockedMessage,
                'reason'            => 'has_transactions',
                'transactions'      => $usage,
                'transactions_text' => ProductTransactionGuard::describe($usage),
                // Kept for the delete flow, which has always read this key.
                'force_deletable'   => in_array($user->role, [UserRoles::SHOP_OWNER, 'owner'], true),
                'owner_can_override' => in_array($user->role, [UserRoles::SHOP_OWNER, 'owner'], true),
            ], 409);
        }

        if (! in_array($user->role, [UserRoles::SHOP_OWNER, 'owner'], true)) {
            Log::warning('Protected product change refused: caller is not the owner', [
                'business_id' => $user->business_id,
                'user_id'     => $user->id,
                'product_id'  => $product->id,
                'action'      => $action,
            ]);

            return response()->json([
                'message' => $notOwnerMessage,
                'reason'  => 'not_owner',
            ], 403);
        }

        if (! Hash::check($request->input('password'), $user->password)) {
            Log::warning('Protected product change refused: incorrect password', [
                'business_id' => $user->business_id,
                'user_id'     => $user->id,
                'product_id'  => $product->id,
                'action'      => $action,
            ]);

            return response()->json([
                'message' => __('The password is incorrect.'),
                'reason'  => 'invalid_password',
            ], 403);
        }

        return true;
    }
}
