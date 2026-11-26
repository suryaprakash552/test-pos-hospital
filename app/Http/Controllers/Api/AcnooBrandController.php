<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Helpers\DataSanitizer;
use App\Http\Requests\Api\StoreBrandRequest;
use App\Http\Requests\Api\UpdateBrandRequest;
use App\Services\CacheService;
use App\Support\LookupDeletionGuard;

class AcnooBrandController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $business_id = auth()->user()->business_id;

            $data = CacheService::rememberBusiness($business_id, 'brands', function () use ($business_id) {
                return Brand::where('business_id', $business_id)->latest()->get();
            }, 86400, [CacheService::TAG_MASTER_DATA]);

            return response()->json([
                'message' => __('Data fetched successfully.'),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to fetch brands.')
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBrandRequest $request)
    {
        try {
            $business_id = auth()->user()->business_id;

            $data = Brand::create([
                'brandName' => DataSanitizer::stripHtml(DataSanitizer::sanitizeString($request->brandName)),
                'business_id' => $business_id
            ]);

            CacheService::forgetBusiness($business_id, 'brands');

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to create brand.')
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBrandRequest $request, Brand $brand)
    {
        $user = auth()->user();

        if ($brand->business_id != $user->business_id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }

        try {
            $brand->update([
                'brandName' => DataSanitizer::stripHtml(DataSanitizer::sanitizeString($request->brandName)),
            ]);

            CacheService::forgetBusiness($user->business_id, 'brands');

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $brand,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to update brand.')
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Brand $brand)
    {
        $user = auth()->user();

        if ($brand->business_id != $user->business_id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }

        // products.brand_id cascades, so deleting this would take the
        // products with it. Checked here so the refusal names a count;
        // the RESTRICT constraint is what actually enforces it.
        $blocked = LookupDeletionGuard::blockedMessage(
            'brand_id',
            $brand->id,
            'brand'
        );

        if ($blocked !== null) {
            return response()->json(['message' => $blocked], 409);
        }

        try {
            $brand->delete();

            CacheService::forgetBusiness($user->business_id, 'brands');

            return response()->json([
                'message' => __(':noun deleted.', ['noun' => __('Brand')]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to delete brand.')
            ], 500);
        }
    }
}
