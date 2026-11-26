<?php

namespace App\Http\Controllers\Api;

use App\Models\Warehouse;
use App\Http\Controllers\Controller;
use App\Helpers\DataSanitizer;
use App\Http\Requests\Api\StoreWarehouseRequest;
use App\Http\Requests\Api\UpdateWarehouseRequest;
use App\Services\CacheService;

class WarehouseController extends Controller
{
    public function index()
    {
        try {
            $business_id = auth()->user()->business_id;

            $data = CacheService::rememberBusiness($business_id, 'warehouses', function () use ($business_id) {
                return Warehouse::with(['products.stocks'])
                    ->where('business_id', $business_id)
                    ->latest()
                    ->get()
                    ->map(function ($warehouse) {
                        $totalQty = 0;
                        $totalValue = 0;

                        foreach ($warehouse->products as $product) {
                            foreach ($product->stocks as $stock) {
                                $totalQty += $stock->productStock;
                                $totalValue += $stock->productStock * $stock->productPurchasePrice;
                            }
                        }

                        return [
                            'id' => $warehouse->id,
                            'business_id' => $warehouse->business_id,
                            'name' => $warehouse->name,
                            'phone' => $warehouse->phone,
                            'email' => $warehouse->email,
                            'address' => $warehouse->address,
                            'total_quantity' => $totalQty,
                            'total_value' => $totalValue,
                        ];
                    });
            }, 86400, [CacheService::TAG_MASTER_DATA]);

            return response()->json([
                'message' => __('Data fetched successfully.'),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to fetch warehouses.')
            ], 500);
        }
    }


    public function store(StoreWarehouseRequest $request)
    {
        try {
            $business_id = auth()->user()->business_id;

            $data =  Warehouse::create([
                'name' => DataSanitizer::stripHtml(DataSanitizer::sanitizeString($request->name)),
                'phone' => DataSanitizer::sanitizePhone($request->phone),
                'address' => DataSanitizer::sanitizeString($request->address),
                'email' => DataSanitizer::sanitizeEmail($request->email),
                'business_id' => $business_id,
                'branch_id' => $request->branch_id ?? \App\Support\ActiveBranch::forWrite(),
            ]);

            CacheService::forgetBusiness($business_id, 'warehouses');

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to create warehouse.')
            ], 500);
        }
    }

    public function update(UpdateWarehouseRequest $request, string $id)
    {
        try {
            $business_id = auth()->user()->business_id;
            $warehouse = Warehouse::find($id);
            if (!$warehouse) {
                return response()->json([
                    'message' => __('Warehouse not found.'),
                    'data' => null,
                ], 404);
            }

            if ($warehouse->business_id != $business_id) {
                return response()->json([
                    'message' => __('Unauthorized.')
                ], 403);
            }

            $warehouse->update([
                'name' => DataSanitizer::stripHtml(DataSanitizer::sanitizeString($request->name)),
                'phone' => DataSanitizer::sanitizePhone($request->phone),
                'address' => DataSanitizer::sanitizeString($request->address),
                'email' => DataSanitizer::sanitizeEmail($request->email),
                'branch_id' => $request->branch_id ?? \App\Support\ActiveBranch::forWrite(),
            ]);

            CacheService::forgetBusiness($business_id, 'warehouses');

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $warehouse,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to update warehouse.')
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $business_id = auth()->user()->business_id;
            $warehouse = Warehouse::find($id);

            if (!$warehouse) {
                return response()->json([
                    'message' => __('Warehouse not found.'),
                ], 404);
            }

            if ($warehouse->business_id != $business_id) {
                return response()->json([
                    'message' => __('Unauthorized.')
                ], 403);
            }

            $warehouse->delete();

            CacheService::forgetBusiness($business_id, 'warehouses');

            return response()->json([
                'message' => __('Data deleted successfully.'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to delete warehouse.')
            ], 500);
        }
    }
}
