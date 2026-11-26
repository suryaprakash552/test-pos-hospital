<?php

namespace App\Http\Controllers\Api;

use App\Models\Stock;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Purchase;
use App\Models\StockAudit;
use App\Models\PurchaseDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Services\Reporting\ProductStockSummary;
use App\Rules\TenantOwned;
use App\Services\CacheService;
use App\Services\InventoryChangeService;
use App\Support\Api\ApiRequest;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;

class StockController extends Controller
{
    public function __construct(private readonly InventoryChangeService $inventoryChanges)
    {
    }

    /**
     * Store a newly created resource in storage.
     */
    public function index(Request $request)
    {
        $user = auth()->user(); 

        $perPage = $request->per_page ?? 100;

        $query = Product::with([
            'category:id,categoryName',
            'brand:id,brandName',
            'manufacturer:id,manufacturerName',
            'stocks' => function ($q) {
                $q->withSum('availableLots as available_quantity', 'conversionQuantity')
                  ->withSum('saleDetails as sold_quantity', 'quantities');
            },
            'stocks.purchaseDetails'
        ])
        ->where('products.business_id', $user->business_id);

        if ($request->search) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('productName', 'LIKE', "%$search%")
                ->orWhere('rack', 'LIKE', "%$search%")
                ->orWhere('shelf', 'LIKE', "%$search%");
            });
        }

        // ✅ Cast to int to avoid string comparison issues
        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->category_id);
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', (int) $request->brand_id);
        }

        if ($request->filled('manufacturer_id')) {
            $query->where('manufacturer_id', (int) $request->manufacturer_id);
        }

        if ($request->filled('rack')) {
            $query->where('rack', 'LIKE', "%{$request->rack}%");
        }

        if ($request->filled('shelf')) {
            $query->where('shelf', 'LIKE', "%{$request->shelf}%");
        }


        if ($request->exp_from || $request->exp_to) {
            $query->whereHas('stocks', function ($q) use ($request) {
                if ($request->exp_from) {
                    $q->whereDate('expire_date', '>=', $request->exp_from);
                }
                if ($request->exp_to) {
                    $q->whereDate('expire_date', '<=', $request->exp_to);
                }
            });
        }

    if ($request->filled('min_stock') && $request->filled('max_stock')) {

            $query->where(function ($q) use ($request) {

                // min_stock and below
                $q->where('alert_qty', '<=', $request->min_stock)

                // max_stock and above
                ->orWhere('productStock', '>=', $request->max_stock);
            });

        } elseif ($request->filled('min_stock')) {

            // entered min_stock and less than min_stock
            $query->where('alert_qty', '<=', $request->min_stock);

        } elseif ($request->filled('max_stock')) {

            // entered max_stock and greater than max_stock
            $query->where('productStock', '>=', $request->max_stock);
        }
        $query->select('products.*')
            ->selectSub(function ($q) {
                $q->from('purchase_details')
                    ->join('stocks', 'stocks.id', '=', 'purchase_details.stock_id')
                    ->whereColumn('stocks.product_id', 'products.id')
                    ->selectRaw('COALESCE(SUM(purchase_details.conversionQuantity),0)');
            }, 'total_conversion_quantity');

        $products = $query
            ->orderBy('total_conversion_quantity', 'asc')
            ->orderBy('products.id', 'desc')
            ->paginate($perPage);

        // Ensure dates in stocks are serialized as date strings without timezone conversion
        $products->getCollection()->transform(function ($product) {
            if ($product->stocks) {
                $product->stocks->transform(function ($stock) {
                    $stockArray = $stock->toArray();
                    $stockArray['mfg_date'] = $stock->mfg_date?->toDateString();
                    $stockArray['expire_date'] = $stock->expire_date?->toDateString();
                    return $stockArray;
                });
            }
            return $product;
        });

            return response()->json([
                'message' => 'Products fetched successfully',
                'data' => $products
            ]);
    }

    public function show(Stock $stock)
    {
        $user = auth()->user();

        if ($stock->business_id !== $user->business_id) {
            return response()->json([
                'message' => __('Stock not found.'),
            ], 404);
        }

        $stock->load([
            'product:id,productName,productCode,category_id,brand_id,manufacturer_id,business_id,rack,shelf',
            'product.category:id,categoryName',
            'product.brand:id,brandName',
            'product.manufacturer:id,manufacturerName',
            'purchaseDetails',
            'warehouse:id,name,business_id',
        ]);

        // Ensure dates are serialized as date strings without timezone conversion
        $stockData = $stock->toArray();
        $stockData['mfg_date'] = $stock->mfg_date?->toDateString();
        $stockData['expire_date'] = $stock->expire_date?->toDateString();

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $stockData,
        ]);
    }

    public function store(Request $request)
    {
        $businessId = auth()->user()->business_id;

        $request->validate([
            'productStock' => 'required|integer|min:0',
            'stock_id' => ['required', \App\Rules\TenantOwned::in('stocks')],
        ]);

        if ($origin = $this->purchaseOriginForStock((int) $request->stock_id)) {
            return $this->protectedInventoryResponse($origin);
        }

        // Scoped to the tenant: unscoped, this incremented any batch in the system
        // by id, including another shop's.
        Stock::where('id', $request->stock_id)
            ->where('business_id', $businessId)
            ->increment('productStock', $request->productStock);

        return response()->json([
            'message' => __('Data saved successfully.'),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
{
    DB::beginTransaction();

    try {
        // Scoped: an unscoped lookup let another shop's batch be edited by id.
        //
        // Deliberately not `findOrFail()`. That threw into the generic catch
        // below, which answers 500 and writes a full stack trace at error
        // level — so an unknown id, a batch already deleted, or one belonging
        // to another business all read in the log exactly like a server fault.
        // The production log filled with `No query results for model [Stock]
        // :stock` — a Postman path variable that was never filled in, which a
        // 404 would have made obvious immediately.
        $stock = Stock::where('business_id', auth()->user()->business_id)
            ->lockForUpdate()
            ->find($id);

        if (! $stock) {
            DB::rollBack();

            return response()->json([
                'message' => __('Stock not found.'),
            ], 404);
        }

        if ($origin = $this->purchaseOriginForStock($stock->id)) {
            DB::rollBack();

            return $this->protectedInventoryResponse($origin);
        }

        $details = PurchaseDetails::where('stock_id', $id)
    ->orderByDesc('id')
    ->get();

if ($details->isEmpty()) {
    return response()->json([
        'message' => 'Purchase details not found for this stock'
    ], 404);
}

/*
|--------------------------------------------------------------------------
| Quantity Adjustment
|--------------------------------------------------------------------------
*/

if ($request->filled('quantities')) {

    $currentTotal = $details->sum('quantities');
    $newTotal = $request->quantities;

    // Increase Quantity
    if ($newTotal > $currentTotal) {

        $diff = $newTotal - $currentTotal;

        $latest = $details->first();

        $newQty = $latest->quantities + $diff;

        $latest->update([
            'quantities' => $newQty,

            'conversionQuantity' =>
                $newQty * $latest->conversionValue,

            'totalPurchasePrice' =>
                $newQty * $latest->productPurchasePrice,

            'totalPrice' =>
                $newQty * $latest->productSalePrice,
        ]);
    }

    // Decrease Quantity
    elseif ($newTotal < $currentTotal) {

        $diff = $currentTotal - $newTotal;

        foreach ($details as $detail) {

            if ($diff <= 0) {
                break;
            }

            if ($detail->quantities <= $diff) {

                $diff -= $detail->quantities;

                $detail->update([
                'quantities' => 0,

                'conversionQuantity' => 0,

                'totalPurchasePrice' => 0,

                'totalPrice' => 0,
            ]);

            } else {

                $newQty = $detail->quantities - $diff;

                    $detail->update([
                        'quantities' => $newQty,

                        'conversionQuantity' =>
                            $newQty * $detail->conversionValue,

                        'totalPurchasePrice' =>
                            $newQty * $detail->productPurchasePrice,

                        'totalPrice' =>
                            $newQty * $detail->productSalePrice,
                    ]);

                $diff = 0;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Update Stock Table
|--------------------------------------------------------------------------
*/

$stock->update([
    'batch_no' => $request->batch_no ?? $stock->batch_no,
    'productStock' => $request->quantities ?? $stock->productStock,
    'productPurchasePrice' => $request->productPurchasePrice ?? $stock->productPurchasePrice,
    'productSalePrice' => $request->productSalePrice ?? $stock->productSalePrice,
    'productWholeSalePrice' => $request->productWholeSalePrice ?? $stock->productWholeSalePrice,
    'productDealerPrice' => $request->productDealerPrice ?? $stock->productDealerPrice,
    'profit_percent' => $request->profit_percent ?? $stock->profit_percent,
    'conversionValue' => $request->conversion_value ?? $stock->conversionValue,
    'mfg_date' => $request->mfg_date ?? $stock->mfg_date,
    'expire_date' => $request->expire_date ?? $stock->expire_date,
]);

/*
|--------------------------------------------------------------------------
| Update Common Batch Fields In All Purchases
|--------------------------------------------------------------------------
*/

$updateData = [];

if ($request->filled('productPurchasePrice')) {
    $updateData['productPurchasePrice'] = $request->productPurchasePrice;
}

if ($request->filled('productSalePrice')) {
    $updateData['productSalePrice'] = $request->productSalePrice;
}

if ($request->filled('productWholeSalePrice')) {
    $updateData['productWholeSalePrice'] = $request->productWholeSalePrice;
}

if ($request->filled('productDealerPrice')) {
    $updateData['productDealerPrice'] = $request->productDealerPrice;
}

if ($request->filled('conversionPurchasePrice')) {
    $updateData['conversionPurchasePrice'] = $request->conversionPurchasePrice;
}

if ($request->filled('conversionValue')) {
    $updateData['conversionValue'] = $request->conversionValue;
}

if ($request->filled('conversionPrice')) {
    $updateData['conversionPrice'] = $request->conversionPrice;
}

if ($request->filled('profit_percent')) {
    $updateData['profit_percent'] = $request->profit_percent;
}

if ($request->filled('upper_selected_unit')) {
    $updateData['purchaseUpperLimit'] = $request->upper_selected_unit;
}

if ($request->filled('lower_selected_unit')) {
    $updateData['purchaseLowerLimit'] = $request->lower_selected_unit;
}

if ($request->filled('mfg_date')) {
    $updateData['mfg_date'] = $request->mfg_date;
}

if ($request->filled('expire_date')) {
    $updateData['expire_date'] = $request->expire_date;
}

if (!empty($updateData)) {
    PurchaseDetails::where('stock_id', $id)
        ->update($updateData);
}

$purchaseDetails = PurchaseDetails::where('stock_id', $id)->get();

foreach ($purchaseDetails as $detail) {

    $detail->update([

        'conversionQuantity' =>
            $detail->quantities * $detail->conversionValue,

        'totalPurchasePrice' =>
            $detail->quantities * $detail->productPurchasePrice,

        'totalPrice' =>
            $detail->quantities * $detail->productSalePrice,
    ]);
}

        $this->inventoryChanges->invalidateInventoryAfterCommit();
        DB::commit();

        // Ensure dates are serialized as date strings without timezone conversion
        $stockData = $stock->toArray();
        $stockData['mfg_date'] = $stock->mfg_date?->toDateString();
        $stockData['expire_date'] = $stock->expire_date?->toDateString();

        return response()->json([
            'message' => __('Data updated successfully.'),
            'stock' => $stockData,
            'purchase_details' => PurchaseDetails::where('stock_id', $id)->get()
        ]);

    } catch (\Exception $e) {
        DB::rollback();

        Log::error('Stock update failed', [
            'business_id' => auth()->user()?->business_id,
            'user_id' => auth()->id(),
            'stock_id' => $id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'message' => __('Failed to update stock.')
        ], 500);
    }
}

public function lowStockProducts()
{
    $user = auth()->user();

    // Driven from `products`, not from `purchase_details`.
    //
    // Starting at the lots and inner-joining products meant a product had to
    // *have* a lot to be considered at all, and the `conversionQuantity > 0`
    // filter below then removed every product whose lots were drained. Between
    // them they encoded "no stock means no need to reorder" — the exact inverse
    // of what a low-stock report is for. An item sold down to zero is the most
    // urgent thing on the list, and the counter reported it missing: "Eupen
    // 1 Gm Injection" showed 0 PACK in the product list and was absent here.
    //
    // Left-joined, so a product with no lots at all still produces a row and
    // its availability computes as 0 through the COALESCE'd sums below.
    $query = Product::leftJoin(
            'purchase_details',
            'purchase_details.product_id',
            '=',
            'products.id'
        )
        ->leftJoin(
            'manufacturers',
            'products.manufacturer_id',
            '=',
            'manufacturers.id'
        )
        ->select(
            'products.id as product_id',
            'products.productName',
            'products.rack',
            'products.shelf',
            'products.manufacturer_id',
            'manufacturers.manufacturerName',
            'products.alert_qty as min_quantity',
            'products.productStock as max_quantity',

            DB::raw("
(
    SELECT COALESCE(SUM(pd.conversionQuantity),0)
    FROM purchase_details pd
    WHERE pd.product_id = products.id
) as total_conversion_quantity
"),

            // COALESCE so a product with no lots reports a usable conversion
            // rather than null, which would make its unit label blank.
            DB::raw('COALESCE(MAX(purchase_details.conversionValue), 1) as conversionValue'),

            // Each lot divided by its own conversion, then summed — see the
            // note on `shortage_quantity` below.
            DB::raw("
ROUND(
    (
    SELECT COALESCE(SUM(pd.conversionQuantity / GREATEST(COALESCE(NULLIF(pd.conversionValue, 0), 1), 1)),0)
    FROM purchase_details pd
    WHERE pd.product_id = products.id
)
) as remaining_upper_quantity
"),

            // The conversion belongs to the lot, not the product: the same
            // medicine arrives in strips of ten on one invoice and fifteen on the
            // next. Dividing the product's total tablets by MAX(conversionValue)
            // let one lot's figure speak for all of them — three strips of ten
            // plus two of fifteen reported four instead of five, and two strips
            // of ten plus five loose units reported three instead of seven.
            //
            // A conversion of zero is read as one rather than dividing the stock
            // away, which previously dropped the product from the report entirely.
            DB::raw("
ROUND(
    (
    SELECT COALESCE(SUM(pd.conversionQuantity / GREATEST(COALESCE(NULLIF(pd.conversionValue, 0), 1), 1)),0)
    FROM purchase_details pd
    WHERE pd.product_id = products.id
)
    - products.alert_qty
) as shortage_quantity
")
        );

        $query->where('products.business_id', $user->business_id);

        // A minimum level of 0 means "discontinued".
        //
        // The shop cannot delete such a product — its sales history would go
        // with it — so they set the minimum to zero to say "never reorder this
        // again". Without this guard the default condition below reads
        // `0 <= 0` for a discontinued item sitting at zero stock, so it is
        // reported every day forever and the list becomes noise.
        $query->where('products.alert_qty', '>', 0);

    // Product Filter
    if (request()->filled('product_name')) {
        $query->where(
            'products.productName',
            'LIKE',
            '%' . request('product_name') . '%'
        );
    }

    // Rack Filter
    if (request()->filled('rack')) {
        $query->where(
            'products.rack',
            'LIKE',
            '%' . request('rack') . '%'
        );
    }

    // Shelf Filter
    if (request()->filled('shelf')) {
        $query->where(
            'products.shelf',
            'LIKE',
            '%' . request('shelf') . '%'
        );
    }
    if (request()->filled('manufacturer_id')) {

        $query->where(
            'products.manufacturer_id',
            (int) request('manufacturer_id')
        );
    }

    // The app's filter sheet is a name typeahead, same as Product Name/Rack/
    // Shelf above - it has never carried an id. Without this the parameter it
    // actually sends, `manufacturer`, matched nothing here and the filter
    // silently did not filter: picking one manufacturer still returned every
    // product's low-stock rows.
    if (request()->filled('manufacturer')) {
        $query->where(
            'manufacturers.manufacturerName',
            'LIKE',
            '%' . request('manufacturer') . '%'
        );
    }

    $query->groupBy(
        'products.id',
        'products.productName',
        'products.rack',
        'products.shelf',
        'products.manufacturer_id',
        'manufacturers.manufacturerName',
        'products.alert_qty',
        'products.productStock'
    );

    // The stock filter counts the same way the displayed figure does.
    //
    // This divided the product's total tablets by MAX(conversionValue), so a
    // product held in two different strip sizes was filtered on a number the
    // screen never showed — a shop searching "10 or fewer strips" got rows
    // holding twenty, and missed rows holding five.
    //
    // COALESCE'd because the lot join is now a LEFT join: a product with no
    // lots aggregates to NULL, and `NULL <= alert_qty` is NULL — falsy — which
    // would silently drop the zero-stock products this report exists to show.
    $conversionExpr = "
        ROUND(
            COALESCE(
                SUM(
                    purchase_details.conversionQuantity
                    / GREATEST(COALESCE(NULLIF(purchase_details.conversionValue, 0), 1), 1)
                ),
                0
            )
        )
    ";

    // Same logic as Stock Index API
    if (
        request()->filled('min_stock') &&
        request()->filled('max_stock')
    ) {

        $query->where(function ($q) {

            $q->where(
                'products.alert_qty',
                '<=',
                request('min_stock')
            )
            ->orWhere(
                'products.productStock',
                '>=',
                request('max_stock')
            );
        });

    } elseif (request()->filled('min_stock')) {

        $query->where(
            'products.alert_qty',
            '<=',
            request('min_stock')
        );

    } elseif (request()->filled('max_stock')) {

        $query->where(
            'products.productStock',
            '>=',
            request('max_stock')
        );

    } else {

        // Default Low Stock Products
        $query->havingRaw(
            "$conversionExpr <= products.alert_qty"
        );
    }

    $products = $query
        ->orderByRaw("
            CASE
                WHEN products.rack REGEXP '^[0-9]' THEN 0
                ELSE 1
            END
        ")
        ->orderByRaw("
            REGEXP_REPLACE(products.rack, '[0-9]', '')
        ")
        ->orderByRaw("
            CAST(
                REGEXP_SUBSTR(products.rack, '[0-9]+')
                AS UNSIGNED
            )
        ")
        ->get();
        $products->transform(function ($item) {

            $num = abs($item->shortage_quantity);

            $item->roundoff_quantity = ($num <= 4)
                ? 0
                : (floor(($num - 5) / 10) + 1) * 10;

            return $item;
        });

    return response()->json([
        'message' => __('Low stock products fetched successfully.'),
        'data' => $products
    ]);
}

public function stockAuditIndex(Request $request)
{
    $user = auth()->user();

    $perPage = $request->per_page ?? 100;

    $query = Product::with([

        'category:id,categoryName',
        'brand:id,brandName',
        'manufacturer:id,manufacturerName',
        'upperSelectedUnit:id,unitName',
        'lowerSelectedUnit:id,unitName',

        'stocks' => function ($q) {

            $q->select(
                'id',
                'product_id',
                'batch_no',
                'expire_date',
                'mfg_date',
                'productStock',
                'productPurchasePrice'
            )
            // Auditable stock is stock the ledger can account for.
            //
            // `productStock` is a redundant tally that has drifted, and 37 rows
            // claim a quantity with no purchase lot behind them at all. Those
            // surfaced here as batches with no number, absent from every other
            // screen, and counting one booked a shrinkage loss against stock
            // that never existed. The lots decide what is on the shelf.
            ->whereHas('purchaseDetails', fn ($p) =>
                $p->where('conversionQuantity', '>', 0))
            // `sold_quantity` is an appended attribute, so serialising a batch
            // asks for it. Without this the accessor falls through to a query
            // per batch - one `SUM(sale_details.quantities)` for every row on
            // the page, which is fine for five and not for five hundred.
            ->withSum('saleDetails as sold_quantity', 'quantities')
            ->with(['purchaseDetails']);
        }

    ])
    ->where('products.business_id', $user->business_id)
    ->whereHas('stocks', function ($q) {
        $q->whereHas('purchaseDetails', fn ($p) =>
            $p->where('conversionQuantity', '>', 0));
    });

    if ($request->search) {

        $search = $request->search;

        $query->where(function ($q) use ($search) {

            $q->where('productName', 'LIKE', "%{$search}%")
              ->orWhere('productCode', 'LIKE', "%{$search}%");

        });
    }
        if ($request->filled('rack')) {
            $query->whereRaw('LOWER(TRIM(products.rack)) = ?', [
                mb_strtolower(trim($request->rack)),
            ]);
        }

        if ($request->filled('shelf')) {
            $query->whereRaw('LOWER(TRIM(products.shelf)) = ?', [
                mb_strtolower(trim($request->shelf)),
            ]);
        }

    $query->select('products.*')

        ->selectSub(function ($q) {

            $q->from('purchase_details')
                ->join(
                    'stocks',
                    'stocks.id',
                    '=',
                    'purchase_details.stock_id'
                )
                ->whereColumn(
                    'stocks.product_id',
                    'products.id'
                )
                ->selectRaw(
                    'COALESCE(SUM(purchase_details.conversionQuantity),0)'
                );

        }, 'total_conversion_quantity');

    $products = $query
        ->orderByRaw("
            CASE
                WHEN products.rack REGEXP '^[0-9]' THEN 0
                ELSE 1
            END
        ")
        ->orderByRaw("
            REGEXP_REPLACE(products.rack, '[0-9]', '')
        ")
        ->orderByRaw("
            CAST(
                REGEXP_SUBSTR(products.rack, '[0-9]+')
                AS UNSIGNED
            )
        ")
        ->orderBy('productName')
        ->paginate($perPage);

    $products->getCollection()->transform(function ($product) {

        $product->batches = $product->stocks->map(function ($stock) {

            // Counted in sellable units - tablets - because that is the figure
            // product details shows batch-wise and the one the shop counts by.
            // It is the lot remainder as stored, so no conversion is applied
            // and none can be applied wrongly.
            $availableQty = round(
                (float) $stock->purchaseDetails->sum('conversionQuantity'), 4
            );

            // Taken from the lot, not the product: `products.conversionValue`
            // is not always populated.
            $conversionValue = (float) ($stock->purchaseDetails->first()->conversionValue ?? 0);
            $conversionValue = $conversionValue > 0 ? $conversionValue : 1.0;

            // The per-tablet price, to match the per-tablet quantity, and the
            // same one the sales sheet quotes for this batch.
            $conversionPrice = $this->lowerUnitPrice($stock, $conversionValue);
            return [

                'stock_id' => $stock->id,

                'batch_no' => $stock->batch_no,

                'available_quantity' => $availableQty,

                'expire_date' => $stock->expire_date,

                'mfg_date' => $stock->mfg_date,
                'price_per_unit' => $conversionPrice,

                // The whole-stock-unit equivalent, for reference only. The
                // count itself is taken in sellable units so that it matches
                // product details, which is the screen the shop reconciles
                // against.
                'available_upper_quantity' => round(
                    $availableQty / $conversionValue, 4
                ),
                'conversion_value' => $conversionValue,
                'upper_unit_name' => $product->upperSelectedUnit->unitName ?? null,
                'lower_unit_name' => $product->lowerSelectedUnit->unitName ?? null,
            ];
        });

        return $product;
    });

    return response()->json([
        'message' => 'Stock audit products fetched successfully',
        'data' => $products,
        'filters' => [
            'racks' => Product::query()
                ->where('business_id', $user->business_id)
                ->whereHas('stocks', fn ($stock) => $stock->whereHas(
                    'purchaseDetails',
                    fn ($p) => $p->where('conversionQuantity', '>', 0)
                ))
                ->whereNotNull('rack')
                ->whereRaw("TRIM(rack) <> ''")
                ->selectRaw('TRIM(rack) as rack_name')
                ->distinct()
                ->orderBy('rack_name')
                ->pluck('rack_name')
                ->values(),
            'shelves' => Product::query()
                ->where('business_id', $user->business_id)
                ->whereHas('stocks', fn ($stock) => $stock->whereHas(
                    'purchaseDetails',
                    fn ($p) => $p->where('conversionQuantity', '>', 0)
                ))
                ->whereNotNull('shelf')
                ->whereRaw("TRIM(shelf) <> ''")
                ->selectRaw('TRIM(shelf) as shelf_name')
                ->distinct()
                ->orderBy('shelf_name')
                ->pluck('shelf_name')
                ->values(),
        ],
    ]);
}

/**
 * What one sellable unit of a batch is priced at.
 *
 * The same `conversionPrice` the sales batch sheet quotes as the piece price,
 * read from the same lot, so the two screens cannot state different prices for
 * the same batch - which is exactly what happened when this valued stock at
 * cost while sales quoted the selling price.
 *
 * Lots are ordered by id so the lot picked here is the one sales picks; the
 * submit path reads them newest-first for the write-back, and taking `first()`
 * off that would have quoted a different lot's price.
 *
 * Falls back to the batch's per-stock-unit sale price divided down, then to 0,
 * because a missing price must not silently become a large one.
 */
private function lowerUnitPrice($stock, float $conversionValue, $lots = null): float
{
    $lots ??= $stock->purchaseDetails;

    $price = (float) ($lots->sortBy('id')->first()->conversionPrice ?? 0);

    if ($price > 0) {
        return round($price, 2);
    }

    $upperPrice = (float) ($stock->productSalePrice ?? 0);

    return ($upperPrice > 0 && $conversionValue > 0)
        ? round($upperPrice / $conversionValue, 2)
        : 0.0;
}

public function stockAuditSubmit(Request $request)
{
    $request->validate([
        'items' => 'required|array|min:1',
        'items.*.stock_id' => ['required', 'integer'],
        'items.*.updated_quantity' => ['required', 'numeric', 'min:0'],
    ]);

    DB::beginTransaction();

    try {

        $user = auth()->user();
        // Every tap on Submit is one independently identifiable audit. This
        // reference is shared by every batch written in the transaction, so
        // two counts of the same rack on the same day never merge in reports.
        $auditReference = 'AUD-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(4));

        foreach ($request->items as $item) {

            // Scoped and locked. Unscoped, this adjusted any batch in the system by
            // id - another shop's inventory included.
            //
            // Deliberately not `findOrFail`: the miss went into the generic
            // catch below and came back a 500 with a stack trace, so a caller
            // naming another shop's batch - or simply mistyping an id - looked
            // like a server fault. A batch this shop does not have is a 404,
            // and it reads the same whether the id belongs to nobody or to
            // someone else.
            $stock = Stock::where('business_id', $user->business_id)
                ->lockForUpdate()
                ->find($item['stock_id']);

            if ($stock === null) {
                DB::rollBack();

                return response()->json([
                    'message' => __('Batch not found.'),
                ], 404);
            }

            // A stock audit writes off inventory, so only the physical count is the
            // operator's to state. Everything else is derived from the batch as it
            // stands: taking `previous_quantity`, `difference_quantity` and
            // `difference_amount` from the request let a caller record a shrinkage
            // of any size against stock that was never missing.
            $physicalQuantity = $this->auditQuantity($item['updated_quantity'] ?? null);

            $purchaseDetails = PurchaseDetails::where(
                'stock_id',
                $stock->id
            )
            ->orderByDesc('id')
            ->get();

            $conversionValue = (float) ($purchaseDetails->first()->conversionValue ?? 0);
            $conversionValue = $conversionValue > 0 ? $conversionValue : 1.0;

            // Counted in sellable units - tablets - matching what the audit
            // list and product details both show.
            //
            // The baseline is what the lots hold, not `productStock`. The
            // write-back reconciles against the lots, so taking the baseline
            // from that redundant tally recorded a variance that did not match
            // the stock actually moved, and on a drifted row booked a loss
            // nobody had incurred.
            $currentQty = round((float) $purchaseDetails->sum('conversionQuantity'), 4);

            $previousQuantity = $currentQty;
            $differenceQuantity = $physicalQuantity - $previousQuantity;
            $pricePerUnit = $this->lowerUnitPrice($stock, $conversionValue, $purchaseDetails);

            $audit = StockAudit::create([

                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'audit_reference' => $auditReference,

                'product_id' => $stock->product_id,
                'stock_id' => $stock->id,

                'batch_no' => $stock->batch_no,

                'previous_quantity' => $previousQuantity,

                'updated_quantity' => $physicalQuantity,

                'difference_quantity' => $differenceQuantity,

                'price_per_unit' => $pricePerUnit,

                'difference_amount' => round($differenceQuantity * $pricePerUnit, 2),

                'remarks' =>
                    $item['remarks'] ?? null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Update Batch Quantity
            |--------------------------------------------------------------------------
            */

            // The batch's own quantity, which is what the rest of the system
            // reads.
            //
            // This was the whole defect: an audit wrote only to
            // `purchase_details.conversionQuantity` and left `productStock`
            // untouched. Sales decrement `productStock`, and the stock, product
            // and sales/purchase pickers all read it, so a counted batch showed
            // its old figure everywhere. Worse, `previous_quantity` above is
            // read from `productStock`, so the *next* audit measured its
            // difference against a baseline that had never moved — which is why
            // the report's quantity, amount, profit and loss were wrong.
            // The redundant upper-unit tally the pickers still read, kept
            // consistent with the lots the count just settled.
            $stock->productStock = round($physicalQuantity / $conversionValue, 4);
            $stock->save();

            // Reconciled in the unit the operator counted in.
            //
            // Both sides are sellable units now, so the difference is applied to
            // the lots as counted with no conversion in between. Scaling here is
            // what previously wrote a batch down by the conversion factor when
            // the two sides disagreed about the unit.
            if ($physicalQuantity > $currentQty) {

                $diff = $physicalQuantity - $currentQty;

                $latest = $purchaseDetails->first();

                if ($latest) {
                    $latest->increment(
                        'conversionQuantity',
                        round($diff, 4)
                    );
                }

            } elseif ($physicalQuantity < $currentQty) {

                $diff = $currentQty - $physicalQuantity;

                foreach ($purchaseDetails as $detail) {

                    if ($diff <= 0) {
                        break;
                    }

                    $held = (float) $detail->conversionQuantity;

                    if ($held <= $diff) {

                        $diff -= $held;

                        $detail->update([
                            'conversionQuantity' => 0
                        ]);

                    } else {

                        $detail->decrement(
                            'conversionQuantity',
                            round($diff, 4)
                        );

                        $diff = 0;
                    }
                }
            }

            // A physical count remains a StockAudit. The matching immutable
            // inventory event makes it visible beside purchase corrections
            // without repurposing or overwriting the audit record itself.
            $this->inventoryChanges->recordStockAuditEvent(
                $audit,
                $stock,
                [
                    'remaining_quantity' => $previousQuantity,
                    'product_stock' => round($previousQuantity / $conversionValue, 4),
                ],
                [
                    'remaining_quantity' => $physicalQuantity,
                    'product_stock' => (float) $stock->productStock,
                ],
            );
        }

            // The product list is cached for an hour and keyed on TAG_PRODUCTS.
            // Nothing here cleared it, so a stock adjustment left every catalogue screen
            // serving the stock figures from before it: a product sold down to
            // zero still read as in stock, and the batch sheet behind it
            // disagreed with the card in front. Same reason store/update/destroy
            // on the product controller each call this.
            $this->inventoryChanges->invalidateInventoryAfterCommit();
        DB::commit();

        return response()->json([
            'message' =>
                'Stock audit submitted successfully',
            'audit_reference' => $auditReference,
            'submitted_at' => now()->toIso8601String(),
        ]);

    } catch (\Exception $e) {

        DB::rollback();

        Log::error('Stock audit submission failed', [
            'business_id' => auth()->user()?->business_id,
            'user_id' => auth()->id(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'message' => __('Failed to submit stock audit.')
        ], 500);
    }
}


public function stockAuditReport(Request $request)
{
    $user = auth()->user();
    $validated = $request->validate([
        'from_date' => ['nullable', 'date_format:Y-m-d'],
        'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        'page' => ['nullable', 'integer', 'min:1'],
        'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        'product_name' => ['nullable', 'string', 'max:150'],
        'rack' => ['nullable', 'string', 'max:100'],
        'batch_no' => ['nullable', 'string', 'max:100'],
        'user_name' => ['nullable', 'string', 'max:150'],
        'role' => ['nullable', 'string', 'max:50'],
        'audit_reference' => ['nullable', 'string', 'max:40'],
    ]);

    $query = StockAudit::query()
        ->join('users', 'stock_audits.user_id', '=', 'users.id')
        ->join('products', 'stock_audits.product_id', '=', 'products.id')
        ->select(
            'stock_audits.id',
            'stock_audits.audit_reference',
            'stock_audits.batch_no',

            'users.name as user_name',
            'users.role as user_role',

            'products.productName',
            'products.rack',
            'products.shelf',

            'stock_audits.previous_quantity',
            'stock_audits.updated_quantity',
            'stock_audits.difference_quantity',

            'stock_audits.price_per_unit',
            'stock_audits.difference_amount',

            'stock_audits.remarks',
            'stock_audits.created_at'
        )
        ->where('stock_audits.business_id', $user->business_id);

    // User Name
    if ($request->filled('user_name')) {

        $query->where('users.name','LIKE','%' . $request->user_name . '%');
    }

    // Role
    if ($request->filled('role')) {

        $query->where('users.role', $request->role);
    }

    if ($request->filled('audit_reference')) {
        $query->where('stock_audits.audit_reference', $request->audit_reference);
    }

    // Product Name
    if ($request->filled('product_name')) {

        $query->where('products.productName','LIKE','%' . $request->product_name . '%');
    }

    // Rack Filter
        if ($request->filled('rack')) {

            // Rack is a selected identifier, not free text. Partial matching
            // made rack "1" include racks "10", "11", etc.
            $query->whereRaw('LOWER(TRIM(products.rack)) = ?', [
                mb_strtolower(trim($request->rack)),
            ]);
        }

    // Batch No
    if ($request->filled('batch_no')) {

        $query->where('stock_audits.batch_no','LIKE','%' . $request->batch_no . '%');
    }

    $hasFilter =
    $request->filled('user_name') ||
    $request->filled('role') ||
    $request->filled('product_name') ||
    $request->filled('rack') ||
    $request->filled('batch_no') ||
    $request->filled('audit_reference') ||
    $request->filled('from_date') ||
    $request->filled('to_date');

    // Date Filter
    if (
        $request->filled('from_date') &&
        $request->filled('to_date')
    ) {

        $query->whereDate(
            'stock_audits.created_at',
            '>=',
            $request->from_date
        )->whereDate(
            'stock_audits.created_at',
            '<=',
            $request->to_date
        );

    } elseif ($request->filled('from_date')) {

        $query->whereDate(
            'stock_audits.created_at',
            '>=',
            $request->from_date
        );

    } elseif ($request->filled('to_date')) {

        $query->whereDate(
            'stock_audits.created_at',
            '<=',
            $request->to_date
        );

    } elseif (!$hasFilter) {

        // Default today only when NO filters are supplied
        $query->whereDate(
            'stock_audits.created_at',
            today()
        );
    }

    $profitAmount = (clone $query)
        ->where('stock_audits.difference_amount', '>', 0)
        ->sum('stock_audits.difference_amount');

    $lossAmount = (clone $query)
        ->where('stock_audits.difference_amount', '<', 0)
        ->sum('stock_audits.difference_amount');

    $data = $query
        ->orderByRaw("
            CASE
                WHEN products.rack REGEXP '^[0-9]' THEN 0
                ELSE 1
            END
        ")
        ->orderByRaw("
            REGEXP_REPLACE(products.rack, '[0-9]', '')
        ")
        ->orderByRaw("
            CAST(
                REGEXP_SUBSTR(products.rack, '[0-9]+')
                AS UNSIGNED
            )
        ")
        ->orderBy('products.productName')
        ->paginate($validated['per_page'] ?? 20);

    return response()->json([
        'message' => 'Stock audit report fetched successfully',
        'from_date' => $request->from_date ?? today()->toDateString(),
        'to_date'   => $request->to_date ?? today()->toDateString(),

        'total_profit' => round($profitAmount, 2),
        'total_loss'   => round($lossAmount, 2),
        'data' => $data,
        // Filter choices must represent the complete tenant/date result set,
        // not only whichever 20 rows happened to be loaded in Flutter.
        'filters' => [
            'products' => Product::query()
                ->where('business_id', $user->business_id)
                ->select('productName')
                ->distinct()
                ->orderBy('productName')
                ->pluck('productName')
                ->values(),
            'racks' => Product::query()
                ->where('business_id', $user->business_id)
                ->whereNotNull('rack')
                ->whereRaw("TRIM(rack) <> ''")
                ->selectRaw('TRIM(rack) as rack_name')
                ->distinct()
                ->orderBy('rack_name')
                ->pluck('rack_name')
                ->values(),
        ],
    ]);
}

public function profitLossReport(Request $request)
{
    $user = auth()->user();

    $validated = $request->validate([
        'from_date' => ['nullable', 'date_format:Y-m-d'],
        'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        'page' => ['nullable', 'integer', 'min:1'],
        'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
    ]);

    $fromDate = $validated['from_date'] ?? now()->toDateString();
    $toDate = $validated['to_date'] ?? now()->toDateString();
    $perPage = $validated['per_page'] ?? 20;

    $query = DB::table('sale_details as sd')
        ->join('sales as s', 'sd.sale_id', '=', 's.id')
        ->join('products as p', 'sd.product_id', '=', 'p.id')
        ->join('stocks as st', 'sd.stock_id', '=', 'st.id')
        // One authoritative cost row per batch. Joining every historical
        // purchase-detail row duplicated a sold line whenever the same batch
        // had been replenished, inflating totals and making a 20-invoice page
        // contain repeated products.
        ->join('purchase_details as pd', function ($join) {
            $join->on('sd.stock_id', '=', 'pd.stock_id')
                ->whereRaw('pd.id = (SELECT MAX(pd2.id) FROM purchase_details pd2 WHERE pd2.stock_id = sd.stock_id)');
        })
        ->select(
            'sd.id as sale_detail_id',
            's.invoiceNumber',
            's.saleDate',
            'p.productName',
            'st.batch_no',

            // sold tablet qty
            'sd.quantities as sold_qty',

            // selling price per tablet
            'sd.price as sale_price',

            // purchase price per tablet
            'pd.conversionPurchasePrice as purchase_price',

            // total sale amount
            DB::raw('
                ROUND((sd.quantities * sd.price), 2)
                as total_sale_amount
            '),

            DB::raw('
                ROUND((sd.quantities * pd.conversionPurchasePrice), 2)
                as total_purchase_amount
            '),

            DB::raw('
                ROUND(
                    CASE 
                        WHEN ((sd.quantities * sd.price) - 
                            (sd.quantities * pd.conversionPurchasePrice)) > 0
                        THEN ((sd.quantities * sd.price) - 
                            (sd.quantities * pd.conversionPurchasePrice))
                        ELSE 0
                    END, 2
                ) as profit
            '),

            DB::raw('
                ROUND(
                    CASE 
                        WHEN ((sd.quantities * sd.price) - 
                            (sd.quantities * pd.conversionPurchasePrice)) < 0
                        THEN ABS(
                            (sd.quantities * sd.price) - 
                            (sd.quantities * pd.conversionPurchasePrice)
                        )
                        ELSE 0
                    END, 2
                ) as loss
            ')
        )
        ->whereDate('s.saleDate', '>=', $fromDate)
        ->whereDate('s.saleDate', '<=', $toDate);


    $query->when($user->role == 'staff', function ($q) use ($user) {
    $q->where('s.user_id', $user->id);
}, function ($q) use ($user) {
    $q->where('s.business_id', $user->business_id);
});

    // Totals cover the complete filtered report, but are calculated by the
    // database. Only one small page of detail rows is ever materialised in PHP.
    $totals = (clone $query)
        ->reorder()
        ->select([])
        ->selectRaw('COALESCE(SUM(sd.quantities * sd.price), 0) as total_sales')
        ->selectRaw('COALESCE(SUM(sd.quantities * pd.conversionPurchasePrice), 0) as total_purchase')
        ->selectRaw('COALESCE(SUM(GREATEST((sd.quantities * sd.price) - (sd.quantities * pd.conversionPurchasePrice), 0)), 0) as total_profit')
        ->selectRaw('COALESCE(SUM(GREATEST((sd.quantities * pd.conversionPurchasePrice) - (sd.quantities * sd.price), 0)), 0) as total_loss')
        ->first();

    // Page invoices, not sale-detail lines. A large invoice may contain dozens
    // of products; paging its lines made a "20 record" page render as one card.
    $invoicePage = (clone $query)
        ->reorder()
        ->select(['s.id', 's.saleDate'])
        ->distinct()
        ->orderByDesc('s.saleDate')
        ->orderByDesc('s.id')
        ->paginate($perPage);

    $saleIds = collect($invoicePage->items())->pluck('id');
    $data = $saleIds->isEmpty()
        ? collect()
        : $query
            ->whereIn('s.id', $saleIds)
            ->orderByDesc('s.saleDate')
            ->orderByDesc('s.id')
            ->orderByDesc('sd.id')
            ->get();

    $totalSales = (float) $totals->total_sales;
    $totalPurchase = (float) $totals->total_purchase;
    $totalProfit = (float) $totals->total_profit;
    $totalLoss = (float) $totals->total_loss;
    $netProfitLoss = $totalProfit - $totalLoss;

    return response()->json([
        'message' => 'Profit/Loss report fetched successfully',

        'from_date' => $fromDate,
        'to_date' => $toDate,

        'total_sales' => round($totalSales, 2),
        'total_purchase' => round($totalPurchase, 2),
        'total_profit' => round($totalProfit, 2),
        'total_loss' => round($totalLoss, 2),
        'net_profit_loss' => round($netProfitLoss, 2),

        'meta' => [
            'current_page' => $invoicePage->currentPage(),
            'last_page' => $invoicePage->lastPage(),
            'per_page' => $invoicePage->perPage(),
            'total' => $invoicePage->total(),
        ],
        'data' => $data->values(),
    ]);
}

    /**
     * Server-side XLSX export for the line-level profit/loss report. The mobile
     * app must not build this from its currently loaded pages: that produced
     * empty/incomplete files once the report was large.
     */
    public function profitLossExport(Request $request)
    {
        $user = auth()->user();
        $validated = $request->validate([
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ]);
        $from = $validated['from_date'] ?? now()->toDateString();
        $to = $validated['to_date'] ?? now()->toDateString();

        $rows = DB::table('sale_details as sd')
            ->join('sales as s', 'sd.sale_id', '=', 's.id')
            ->join('products as p', 'sd.product_id', '=', 'p.id')
            ->leftJoin('stocks as st', 'sd.stock_id', '=', 'st.id')
            ->leftJoin('purchase_details as pd', function ($join) {
                $join->on('sd.stock_id', '=', 'pd.stock_id')
                    ->whereRaw('pd.id = (SELECT MAX(pd2.id) FROM purchase_details pd2 WHERE pd2.stock_id = sd.stock_id)');
            })
            ->where('s.business_id', $user->business_id)
            ->whereDate('s.saleDate', '>=', $from)
            ->whereDate('s.saleDate', '<=', $to)
            ->orderBy('s.saleDate')->orderBy('s.id')->orderBy('sd.id')
            ->get([
                's.invoiceNumber', 's.saleDate', 'p.productName', 'st.batch_no',
                'sd.quantities', 'sd.price', 'pd.conversionPurchasePrice',
            ]);

        $data = [[
            'Invoice', 'Sale date', 'Product', 'Batch', 'Quantity',
            'Sale price', 'Purchase price', 'Sale amount', 'Cost amount', 'Profit', 'Loss',
        ]];
        foreach ($rows as $row) {
            $quantity = (float) $row->quantities;
            $salePrice = (float) $row->price;
            $costPrice = (float) ($row->conversionPurchasePrice ?? 0);
            $sale = round($quantity * $salePrice, 2);
            $cost = round($quantity * $costPrice, 2);
            $difference = round($sale - $cost, 2);
            $data[] = [$row->invoiceNumber, $row->saleDate, $row->productName, $row->batch_no, $quantity, $salePrice, $costPrice, $sale, $cost, max(0, $difference), max(0, -$difference)];
        }

        return Excel::download(new class($data) implements FromArray {
            public function __construct(private readonly array $rows) {}
            public function array(): array { return $this->rows; }
        }, "profit-loss-{$from}-to-{$to}.xlsx");
    }

    /**
     * Remove the specified resource from storage.
     */
public function destroy(string $id)
{
    DB::beginTransaction();

    try {
        $stock = Stock::where('business_id', auth()->user()->business_id)
            ->lockForUpdate()
            ->find($id);

        // An id that never existed is the caller's mistake, not a server fault.
        // `findOrFail` here threw into the generic catch below, which answered
        // 500 and logged a stack trace for every mistyped id - burying real
        // failures in noise.
        if ($stock === null) {
            DB::rollBack();

            return response()->json([
                'message' => __('Data not found.'),
            ], 404);
        }

        if ($origin = $this->purchaseOriginForStock($stock->id)) {
            DB::rollBack();

            return $this->protectedInventoryResponse($origin);
        }

        // get related purchase details
        $purchaseDetails = PurchaseDetails::where('stock_id', $id)->get();

        foreach ($purchaseDetails as $detail) {
            $purchaseId = $detail->purchase_id;

            // delete purchase detail
            $detail->delete();

            // delete purchase if no details exist
            $remainingDetails = PurchaseDetails::where(
                'purchase_id',
                $purchaseId
            )->count();

            if ($remainingDetails == 0) {
                Purchase::where('id', $purchaseId)->delete();
            }
        }

        // delete stock
        $stock->delete();

        DB::commit();
            // The product list is cached for an hour and keyed on TAG_PRODUCTS.
            // Nothing here cleared it, so a stock adjustment left every catalogue screen
            // serving the stock figures from before it: a product sold down to
            // zero still read as in stock, and the batch sheet behind it
            // disagreed with the card in front. Same reason store/update/destroy
            // on the product controller each call this.
            CacheService::clearProductsCache();

        return response()->json([
            'message' => __('Data deleted successfully.'),
        ]);

    } catch (ModelNotFoundException $e) {
        // An id that never existed is the caller's mistake, not a server
        // fault. The generic catch below turned it into a 500 and logged a
        // stack trace for it, which buries real failures in noise.
        DB::rollback();

        return response()->json([
            'message' => __('Data not found.'),
        ], 404);
    } catch (\Exception $e) {
        DB::rollback();

        Log::error('Stock deletion failed', [
            'business_id' => auth()->user()?->business_id,
            'user_id' => auth()->id(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'message' => __('Failed to delete stock.'),
        ], 500);
    }
}

    /**
     * Resolves the purchase document that made this batch part of tracked
     * inventory. Scoped through the source document as well as the stock row,
     * so a caller can never learn another tenant's purchase reference.
     */
    public function origin(Stock $stock)
    {
        if ((int) $stock->business_id !== (int) auth()->user()->business_id) {
            return response()->json(['message' => __('Stock not found.')], 404);
        }

        $origin = $this->purchaseOriginForStock($stock->id);

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => [
                'stock_id' => $stock->id,
                'purchase_originated' => $origin !== null,
                'direct_edit_prohibited' => $origin !== null,
                'purchase_id' => $origin?->purchase_id,
                'purchase_detail_id' => $origin?->id,
                'purchase_invoice_number' => $origin?->purchase?->invoiceNumber,
            ],
        ]);
    }

    /**
     * Read-only, paginated inventory trace for a single batch.  The log is an
     * audit trail, not a second source of truth for the balance.
     */
    public function history(Request $request, Stock $stock)
    {
        if ((int) $stock->business_id !== (int) auth()->user()->business_id) {
            return response()->json(['message' => __('Stock not found.')], 404);
        }

        $logs = \App\Models\InventoryChangeLog::query()
            ->where('business_id', $stock->business_id)
            ->where('stock_id', $stock->id)
            ->with('actor:id,name')
            ->latest('id')
            ->paginate(ApiRequest::perPage($request, 20, 100));

        $eventLabels = [
            InventoryChangeService::PURCHASE_CREATED => 'Purchase',
            InventoryChangeService::PURCHASE_CORRECTED => 'Purchase correction',
            InventoryChangeService::SALE_OUT => 'Sale',
            InventoryChangeService::SALE_RETURN_IN => 'Sale return',
            InventoryChangeService::PURCHASE_RETURN_OUT => 'Purchase return',
            InventoryChangeService::STOCK_AUDIT_ADJUSTMENT => 'Audit adjustment',
            InventoryChangeService::RECONCILIATION_CORRECTED => 'Reconciliation',
            InventoryChangeService::DISPOSAL_OUT => 'Stock disposal',
        ];

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $logs->getCollection()->map(static function ($log) use ($eventLabels) {
                $before = $log->before_values ?? [];
                $after = $log->after_values ?? [];
                $initial = (float) ($before['remaining_quantity'] ?? 0);
                $final = (float) ($after['remaining_quantity'] ?? ($initial + (float) $log->quantity_delta));

                return [
                'id' => $log->id,
                'event_type' => $log->event_type,
                // Display-ready movement values.  Keep before/after below for
                // an auditor who needs the complete immutable snapshot.
                'transaction_type' => $eventLabels[$log->event_type] ?? Str::headline($log->event_type),
                'transaction' => [
                    'source_type' => class_basename($log->source_type),
                    'source_id' => $log->source_id,
                    'purchase_id' => $log->purchase_id,
                    'purchase_detail_id' => $log->purchase_detail_id,
                    'reference' => $log->correlation_id,
                ],
                'initial_quantity' => $initial,
                'quantity_delta' => (float) $log->quantity_delta,
                'final_quantity' => $final,
                'before_values' => $log->before_values,
                'after_values' => $log->after_values,
                'reason' => $log->reason,
                'actor' => $log->actor?->name,
                'created_at' => $log->created_at?->toIso8601String(),
                ];
            })->values(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Read-only ledger for one product across all of its batches.
     *
     * This is deliberately based on append-only inventory events rather than
     * productStock, which is only a derived current tally. Initial/final values
     * are per affected batch; the UI makes the batch visible on every row.
     */
    public function productHistory(Request $request, Product $product)
    {
        $user = auth()->user();

        if ((int) $product->business_id !== (int) $user->business_id) {
            return response()->json(['message' => __('Product not found.')], 404);
        }

        $logs = \App\Models\InventoryChangeLog::query()
            ->where('business_id', $user->business_id)
            ->where('product_id', $product->id)
            ->with(['actor:id,name', 'stock:id,batch_no'])
            ->oldest('id')
            ->paginate(ApiRequest::perPage($request, 50, 100));

        $partyNames = $this->partyNamesForLogs($logs->getCollection());

        // Calculate running totals per stock_id in chronological order
        $collection = $logs->getCollection();
        $runningTotals = [];
        
        $data = [];
        foreach ($collection as $log) {
            $stockId = $log->stock_id;
            // Initialize running total for new stock_id
            if (!isset($runningTotals[$stockId])) {
                $runningTotals[$stockId] = 0;
            }
            
            $initial = $runningTotals[$stockId];
            $final = $initial + (float) $log->quantity_delta;
            $runningTotals[$stockId] = $final;
            
            $data[] = $this->inventoryHistoryRow($log, $partyNames, $initial, $final);
        }

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => array_reverse($data),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /** Read-only business ledger. Filters make it usable once history grows. */
    public function inventoryHistory(Request $request)
    {
        $user = auth()->user();
        $logs = \App\Models\InventoryChangeLog::query()
            ->where('business_id', $user->business_id)
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->when($request->filled('stock_id'), fn ($q) => $q->where('stock_id', $request->integer('stock_id')))
            ->when($request->filled('event_type'), fn ($q) => $q->where('event_type', $request->input('event_type')))
            ->when($request->filled('from_date'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('from_date')))
            ->when($request->filled('to_date'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('to_date')))
            ->with(['actor:id,name', 'stock:id,batch_no', 'product:id,productName'])
            ->oldest('id')
            ->paginate(ApiRequest::perPage($request, 50, 100));

        $partyNames = $this->partyNamesForLogs($logs->getCollection());

        // Calculate running totals per stock_id in chronological order
        $collection = $logs->getCollection();
        $runningTotals = [];
        
        $data = [];
        foreach ($collection as $log) {
            $stockId = $log->stock_id;
            // Initialize running total for new stock_id
            if (!isset($runningTotals[$stockId])) {
                $runningTotals[$stockId] = 0;
            }
            
            $initial = $runningTotals[$stockId];
            $final = $initial + (float) $log->quantity_delta;
            $runningTotals[$stockId] = $final;
            
            $row = $this->inventoryHistoryRow($log, $partyNames, $initial, $final);
            $row['product_name'] = $log->product?->productName;
            $data[] = $row;
        }

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => array_reverse($data),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'per_page' => $logs->perPage(), 'total' => $logs->total()],
        ]);
    }

    /**
     * The customer behind a sale event, or the supplier behind a purchase one.
     *
     * A shop reading this ledger sees "Sale -15" and has to open the sale
     * itself to find out whose bill it was - the one thing a history is for.
     * Batched by source type so a page of 50 rows costs a handful of queries
     * rather than one per row; the ledger has no direct party column of its
     * own; a return only names the purchase or sale it came from.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\InventoryChangeLog>  $logs
     * @return array<string, string> "SourceType:id" => party name
     */
    private function partyNamesForLogs($logs): array
    {
        $idsByType = [];
        foreach ($logs as $log) {
            $type = class_basename($log->source_type);
            $idsByType[$type][] = $log->source_id;
        }

        $names = [];

        if (! empty($idsByType['Sale'])) {
            Sale::query()
                ->whereIn('id', array_unique($idsByType['Sale']))
                ->with('customer:id,name')
                ->get(['id', 'customer_id'])
                ->each(function ($sale) use (&$names) {
                    $names['Sale:'.$sale->id] = $sale->customer?->name;
                });
        }

        if (! empty($idsByType['SaleReturn'])) {
            \App\Models\SaleReturn::query()
                ->whereIn('id', array_unique($idsByType['SaleReturn']))
                ->with('sale.customer:id,name')
                ->get(['id', 'sale_id'])
                ->each(function ($return) use (&$names) {
                    $names['SaleReturn:'.$return->id] = $return->sale?->customer?->name;
                });
        }

        if (! empty($idsByType['Purchase'])) {
            \App\Models\Purchase::query()
                ->whereIn('id', array_unique($idsByType['Purchase']))
                ->with('party:id,name')
                ->get(['id', 'party_id'])
                ->each(function ($purchase) use (&$names) {
                    $names['Purchase:'.$purchase->id] = $purchase->party?->name;
                });
        }

        if (! empty($idsByType['PurchaseReturn'])) {
            \App\Models\PurchaseReturn::query()
                ->whereIn('id', array_unique($idsByType['PurchaseReturn']))
                ->with('purchase.party:id,name')
                ->get(['id', 'purchase_id'])
                ->each(function ($return) use (&$names) {
                    $names['PurchaseReturn:'.$return->id] = $return->purchase?->party?->name;
                });
        }

        return array_filter($names);
    }

    private function inventoryHistoryRow($log, array $partyNames = [], ?float $initial = null, ?float $final = null): array
    {
        $eventLabels = [
            InventoryChangeService::PURCHASE_CREATED => 'Purchase',
            InventoryChangeService::PURCHASE_CORRECTED => 'Purchase correction',
            InventoryChangeService::SALE_OUT => 'Sale',
            InventoryChangeService::SALE_RETURN_IN => 'Sale return',
            InventoryChangeService::PURCHASE_RETURN_OUT => 'Purchase return',
            InventoryChangeService::STOCK_AUDIT_ADJUSTMENT => 'Audit adjustment',
            InventoryChangeService::RECONCILIATION_CORRECTED => 'Reconciliation',
            InventoryChangeService::DISPOSAL_OUT => 'Stock disposal',
        ];
        $sourceType = class_basename($log->source_type);

        return [
            'id' => $log->id,
            'event_type' => $log->event_type,
            'transaction_type' => $eventLabels[$log->event_type] ?? Str::headline($log->event_type),
            'batch_number' => $log->stock?->batch_no,
            'transaction' => [
                'source_type' => $sourceType,
                'source_id' => $log->source_id,
                'stock_id' => $log->stock_id,
                'product_id' => $log->product_id,
                'purchase_id' => $log->purchase_id,
                'purchase_detail_id' => $log->purchase_detail_id,
                'reference' => $log->correlation_id,
            ],
            'initial_quantity' => $initial ?? 0,
            'quantity_delta' => (float) $log->quantity_delta,
            'final_quantity' => $final ?? 0,
            'reason' => $log->reason,
            'actor' => $log->actor?->name,
            // The customer a sale (or sale return) was for, or the supplier a
            // purchase (or purchase return) came from - whichever this row's
            // source document actually names.
            'party_name' => $partyNames[$sourceType.':'.$log->source_id] ?? null,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    private function purchaseOriginForStock(int $stockId): ?PurchaseDetails
    {
        return PurchaseDetails::query()
            ->with('purchase:id,business_id,branch_id,invoiceNumber')
            ->where('stock_id', $stockId)
            ->whereHas('purchase', function ($query) {
                $query->where('business_id', auth()->user()->business_id);
            })
            ->orderBy('id')
            ->first();
    }

    private function protectedInventoryResponse(PurchaseDetails $origin)
    {
        return response()->json([
            'success' => false,
            'code' => 'INVENTORY_HISTORY_PROTECTED',
            'message' => __('This batch is linked to a tracked purchase transaction and cannot be changed or deleted directly. Edit the original purchase bill instead.'),
            'purchase_id' => $origin->purchase_id,
            'purchase_detail_id' => $origin->id,
            'action' => 'EDIT_SOURCE_PURCHASE',
        ], 409);
    }

        public function followUpReport(Request $request)
    {
        $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
        ]);

        $user = auth()->user();

        $today = now()->toDateString();

        $query = Sale::with([
            'customer:id,name,phone,relationship',
            'details.product:id,productName'
        ])
        ->where('business_id', $user->business_id)
        ->whereNotNull('follow_up_date');

            if ($request->filled('customer_name')) {

            $query->whereHas('customer', function ($q) use ($request) {

                $q->where(
                    'name',
                    'LIKE',
                    '%' . $request->customer_name . '%'
                );
            });
        }
        if ($request->filled('customer_phone')) {

            $query->whereHas('customer', function ($q) use ($request) {

                $q->where(
                    'phone',
                    'LIKE',
                    '%' . $request->customer_phone . '%'
                );
            });
        }

        // Who the medicine was for.
        //
        // Matched against the sale's own relationship first — that is the
        // answer the till recorded for this bill — and against the customer's
        // standing one as well, because `sales.relationship` only exists from
        // August 2026 and every follow-up raised before that has none of its
        // own. Without the fallback the filter would silently hide the entire
        // back catalogue.
        if ($request->filled('relationship')) {
            $relationship = $request->input('relationship');

            $query->where(function ($q) use ($relationship) {
                $q->where('sales.relationship', 'LIKE', '%' . $relationship . '%')
                    ->orWhereHas('customer', fn ($c) =>
                        $c->where('relationship', 'LIKE', '%' . $relationship . '%'));
            });
        }

        if ($request->from_date && $request->to_date) {

            $query->whereDate('follow_up_date', '>=', $request->from_date)
                ->whereDate('follow_up_date', '<=', $request->to_date);

        } elseif ($request->from_date) {

            $query->whereDate('follow_up_date', '>=', $today)
                ->whereDate('follow_up_date', '<=', $request->from_date);

        } elseif ($request->to_date) {

            $query->whereDate('follow_up_date', '>=', $today)
                ->whereDate('follow_up_date', '<=', $request->to_date);

        } else {

            $query->whereDate('follow_up_date', $today);
        }

        // Bounded. Every sale carrying a follow-up date came back in one
        // response, each with its own products eager-loaded and mapped in PHP —
        // a report that is quick on a new shop and gets slower every week it is
        // used. `data` stays a flat list so the app's parse is unchanged.
        $page = $query
            ->orderBy('follow_up_date', 'asc')
            ->paginate(ApiRequest::perPage($request, 100, 500));

        $sales = collect($page->items())
            ->map(function ($sale) {

                return [

                    'sale_id' => $sale->id,

                    'follow_up_date' => \Carbon\Carbon::parse(
                        $sale->follow_up_date
                    )->format('Y-m-d'),

                    'customer_name' => optional($sale->customer)->name,
                    // The sale's own answer wins; the customer's standing one
                    // covers follow-ups raised before the column existed.
                    'relationship_name' => $sale->relationship
                        ?: optional($sale->customer)->relationship,

                    'customer_phone' => optional($sale->customer)->phone,
                    'invoice_number' => $sale->invoiceNumber,
                    'created_at' => $sale->created_at->format('Y-m-d H:i:s'),

                    'products_purchased' => $sale->details
                    ->map(function ($detail) {

                        return [
                            'product_name' => optional($detail->product)->productName,
                            'quantity' => $detail->quantities,
                        ];
                    })
                    ->values(),
                ];
            });

        return response()->json([
            'message' => 'Follow up report fetched successfully',
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'data' => $sales,
        ]);
    }

    /**
     * The counted physical quantity for an audited batch.
     *
     * The only figure in a stock audit the operator supplies; a count cannot be
     * negative, and a non-numeric or absurd one is not a count at all.
     */
    private function auditQuantity(mixed $value): float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw new \InvalidArgumentException('Physical quantity must be a number.');
        }

        $quantity = (float) $value;

        if (! is_finite($quantity) || $quantity < 0) {
            throw new \InvalidArgumentException('Physical quantity cannot be negative.');
        }

        if ($quantity > 1_000_000) {
            throw new \InvalidArgumentException('Physical quantity is implausibly large.');
        }

        return $quantity;
    }


    /**
     * Catalogue counts for the product list's summary cards.
     *
     * Deliberately ignores any search term: the cards describe the catalogue, and
     * having them shrink as someone types made them read as a result count.
     * Category is honoured, because that genuinely narrows the catalogue.
     */
    public function summary(Request $request, ProductStockSummary $summary)
    {
        $businessId = auth()->user()->business_id;

        $request->validate([
            'category_id' => ['nullable', TenantOwned::in('categories')],
        ]);

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $summary->forBusiness(
                $businessId,
                $request->filled('category_id') ? (int) $request->category_id : null,
            ),
        ]);
    }

}
