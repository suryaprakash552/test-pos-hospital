<?php

namespace App\Http\Controllers\Api;

use App\Models\Sale;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\OrderManagement;
use App\Support\Api\ApiRequest;

class OrderManagementController extends Controller
{

    /**
     * Search Sales Report
     */
    public function search(Request $request)
{
    $request->validate([
        'from_date' => 'required|date',
        'to_date'   => 'required|date'
    ]);

    $query = DB::table('sales')
        ->join('sale_details as sd', 'sales.id', '=', 'sd.sale_id')
        ->join('products as p', 'p.id', '=', 'sd.product_id')

        ->leftJoin('stocks as s', 's.id', '=', 'sd.stock_id')

        ->leftJoin(
            'manufacturers as m',
            'm.id',
            '=',
            'p.manufacturer_id'
        )
        ->leftJoin(
            'product_models as pm',
            'pm.id',
            '=',
            'p.model_id'
        )

        ->select(
            'p.id as product_id',
            'p.productName',
            'p.rack',
            'p.shelf',

            'm.manufacturerName as manufacturer_name',

            'pm.name as model_name',

            // Summed straight off `sale_details`.
            //
            // This used to join `purchase_details` for the conversion. A product is
            // bought in batches and that table holds one row per lot, so the join
            // multiplied every sale row by the number of lots and the SUM counted
            // the same sale two or three times — a product bought in three batches
            // reported three times what it sold, and a shop ordering against this
            // report would buy triple what it needed.
            //
            // `stocks` carries the conversion for the batch already, one row per
            // batch, so nothing has to be joined to read it.
            DB::raw('SUM(sd.quantities) as lower_qty'),

            DB::raw('ROUND(
                SUM(
                    sd.quantities /
                    CASE
                        WHEN s.conversionValue IS NULL
                            OR s.conversionValue = 0
                        THEN 1
                        ELSE s.conversionValue
                    END
                ), 0
            ) as upper_qty')
        )

        ->whereDate('sales.saleDate', '>=', $request->from_date)
        ->whereDate('sales.saleDate', '<=', $request->to_date);
        $user = auth()->user();

        $query->where('sales.business_id', $user->business_id);

        $branchId = \App\Support\ActiveBranch::forWrite($user);

        if ($branchId) {
            $query->where('sales.branch_id', $branchId);
        }

    if ($request->filled('rack')) {
        $query->where('p.rack', $request->rack);
    }

    if ($request->filled('shelf')) {
        $query->where('p.shelf', $request->shelf);
    }

    if ($request->filled('product_id')) {
        $query->where('p.id', $request->product_id);
    }

    if ($request->filled('product_name')) {
        $query->where(
            'p.productName',
            'LIKE',
            '%' . $request->product_name . '%'
        );
    }

    if ($request->filled('manufacturer_id')) {
        $query->where(
            'p.manufacturer_id',
            $request->manufacturer_id
        );
    }

  
    if ($request->filled('model_id')) {
        $query->where(
            'p.model_id',
            $request->model_id
        );
    }

    $data = $query
        ->groupBy(
            'p.id',
            'p.productName',
            'p.rack',
            'p.shelf',
            'm.manufacturerName',
            'pm.name'
        )
        ->orderBy('p.productName')
        ->get();

    return response()->json([
        'message' => 'Sales report fetched successfully.',
        'data' => $data
    ]);
}


public function stockReport(Request $request)
{
    $user = auth()->user();
    $validated = $request->validate([
        'product_name' => ['nullable', 'string', 'max:150'],
        'rack' => ['nullable', 'string', 'max:100'],
        'shelf' => ['nullable', 'string', 'max:100'],
        'manufacturer_id' => ['nullable', 'integer'],
        'model_id' => ['nullable', 'integer'],
        'available_stock_from' => ['nullable', 'numeric', 'min:0'],
        'available_stock_to' => ['nullable', 'numeric', 'min:0'],
    ]);
    $perPage = ApiRequest::perPage($request, 30, 100);

    $purchasedStock = DB::table('purchase_details')
        ->select('product_id', DB::raw('SUM(conversionQuantity) as purchased_quantity'))
        ->groupBy('product_id');

    $query = Product::query()
            ->leftJoinSub($purchasedStock, 'purchased_stock', function ($join) {
                $join->on('purchased_stock.product_id', '=', 'products.id');
            })

            ->leftJoin(
                'manufacturers as m',
                'm.id',
                '=',
                'products.manufacturer_id'
            )
            ->leftJoin(
                'product_models as pm',
                'pm.id',
                '=',
                'products.model_id'
            )
        ->select(
            'products.id',
            'products.productName',
            'products.rack',
            'products.shelf',
            'm.manufacturerName as manufacturer_name',
            'pm.name as model_name',

            DB::raw('products.alert_qty as min_stock'),

            DB::raw('products.productStock as max_stock'),
            DB::raw('
                CASE
                    WHEN products.conversionValue IS NULL
                    OR products.conversionValue = 0
                    THEN 1
                    ELSE products.conversionValue
                END as conversion_value
            '),

            DB::raw('
                ROUND(
                    COALESCE(
                        (
                            purchased_stock.purchased_quantity
                        ), 0
                    )
                    /
                    CASE
                        WHEN products.conversionValue IS NULL
                        OR products.conversionValue = 0
                        THEN 1
                        ELSE products.conversionValue
                    END
                ) as available_stock
            '),

           DB::raw('
            ROUND(
                (
                    (
                        (
                            products.productStock + products.alert_qty
                        ) / 2
                    )
                    + products.alert_qty
                )
                -
                (
                    COALESCE(
                        (
                            purchased_stock.purchased_quantity
                        ), 0
                    )
                    /
                    CASE
                        WHEN products.conversionValue IS NULL
                        OR products.conversionValue = 0
                        THEN 1
                        ELSE products.conversionValue
                    END
                )
            ) as shortage_stock
        ')
        )
        ->where('products.business_id', $user->business_id)
        // A minimum level of 0 means the shop has discontinued the product.
        //
        // They cannot delete it — its history would go too — so they zero the
        // reorder level to say "never order this again". Without this it can
        // still surface here through `shortage_stock`, which is derived partly
        // from `productStock`, and the counter is asked to reorder something
        // they have deliberately stopped selling. Matches the same rule on the
        // low-stock report.
        ->where('products.alert_qty', '>', 0);

    if (!empty($validated['rack'])) {
        $query->where('products.rack', $validated['rack']);
    }

    if (!empty($validated['shelf'])) {
        $query->where('products.shelf', $validated['shelf']);
    }

    if (!empty($validated['manufacturer_id'])) {
    $query->where(
        'products.manufacturer_id',
        $validated['manufacturer_id']
    );
    }

    if (!empty($validated['model_id'])) {
        $query->where(
            'products.model_id',
        $validated['model_id']
        );
    }

    if (!empty($validated['product_name'])) {
        $query->where(
            'products.productName',
            'LIKE',
            '%' . $validated['product_name'] . '%'
        );
    }


    if (
        $request->filled('available_stock_from') &&
        $request->filled('available_stock_to')
    ) {

        $query->havingBetween(
            'available_stock',
            [
                $validated['available_stock_from'],
                $validated['available_stock_to']
            ]
        );

    } elseif ($request->filled('available_stock_from')) {

        $query->having(
            'available_stock',
            '>=',
            $validated['available_stock_from']
        );

    } elseif ($request->filled('available_stock_to')) {

        $query->having(
            'available_stock',
            '<=',
            $validated['available_stock_to']
        );
    }

    $data = $query
    ->having('shortage_stock', '>', 0)
    ->orderBy('available_stock', 'asc')
    ->paginate($perPage);

    return response()->json([
        'message' => 'Stock report fetched successfully.',
        'data' => $data
    ]);
}

    /**
     * Store Submitted Report
     */
    public function store(Request $request)
    {
        $request->validate([
            'products' => 'required|array',
        ]);

        DB::beginTransaction();

        try {

            $user = auth()->user();

            $branchId = \App\Support\ActiveBranch::forWrite($user);

            if (!$branchId) {
                return response()->json([
                    'message' => 'Branch not assigned for this user.'
                ], 400);
            }

            foreach ($request->products as $product) {

                OrderManagement::create([
                    'business_id' => $user->business_id,
                    'branch_id' => $branchId,

                    'product_id' => $product['product_id'],
                    'product_name' => $product['productName'],

                    'rack' => $product['rack'] ?? null,
                    'shelf' => $product['shelf'] ?? null,

                    'lower_qty' => $product['lower_qty'],
                    'upper_qty' => $product['upper_qty'],

                    'from_date' => $request->from_date,
                    'to_date' => $request->to_date
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => __('Report stored successfully.')
            ]);

        } catch (\Exception $e) {

            DB::rollback();

            Log::error('Order report storage failed', [
                'business_id' => auth()->user()?->business_id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => __('Failed to store report.')
            ], 500);
        }
    }
}
