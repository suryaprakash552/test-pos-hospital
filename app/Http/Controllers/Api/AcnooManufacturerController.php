<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Manufacturer;
use App\Helpers\DataSanitizer;
use App\Http\Requests\Api\StoreManufacturerRequest;
use App\Http\Requests\Api\UpdateManufacturerRequest;
use App\Services\CacheService;
use App\Support\LookupDeletionGuard;

class AcnooManufacturerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $business_id = auth()->user()->business_id;

            $data = CacheService::rememberBusiness($business_id, 'manufacturers', function () use ($business_id) {
                return Manufacturer::where('business_id', $business_id)->latest()->get();
            }, 86400, [CacheService::TAG_MASTER_DATA]);

            return response()->json([
                'message' => __('Data fetched successfully.'),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to fetch manufacturers.')
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreManufacturerRequest $request)
    {
        try {
            $business_id = auth()->user()->business_id;

            $data = Manufacturer::create([
                'manufacturerName' => DataSanitizer::stripHtml(DataSanitizer::sanitizeString($request->manufacturerName)),
                'business_id' => $business_id
            ]);

            CacheService::forgetBusiness($business_id, 'manufacturers');

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to create manufacturer.')
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateManufacturerRequest $request, Manufacturer $manufacturer)
    {
        try {
            $business_id = auth()->user()->business_id;

            if ($manufacturer->business_id != $business_id) {
                return response()->json([
                    'message' => __('Unauthorized.')
                ], 403);
            }

            $manufacturer->update([
                'manufacturerName' => DataSanitizer::stripHtml(DataSanitizer::sanitizeString($request->manufacturerName))
            ]);

            CacheService::forgetBusiness($business_id, 'manufacturers');

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $manufacturer,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to update manufacturer.')
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Manufacturer $manufacturer)
    {
        try {
            $business_id = auth()->user()->business_id;

            if ($manufacturer->business_id != $business_id) {
                return response()->json([
                    'message' => __('Unauthorized.')
                ], 403);
            }

        // products.manufacturer_id cascades, so deleting this would take the
        // products with it. Checked here so the refusal names a count;
        // the RESTRICT constraint is what actually enforces it.
        $blocked = LookupDeletionGuard::blockedMessage(
            'manufacturer_id',
            $manufacturer->id,
            'manufacturer'
        );

        if ($blocked !== null) {
            return response()->json(['message' => $blocked], 409);
        }

            $manufacturer->delete();

            CacheService::forgetBusiness($business_id, 'manufacturers');

            return response()->json([
                'message' => __(':noun deleted.', ['noun' => __('Manufacturer')]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to delete manufacturer.')
            ], 500);
        }
    }
}
