<?php

namespace App\Http\Controllers\Api;

use App\Models\Rack;
use App\Http\Controllers\Controller;
use App\Helpers\DataSanitizer;
use App\Http\Requests\Api\StoreRackRequest;
use App\Http\Requests\Api\UpdateRackRequest;
use App\Services\CacheService;

class RackController extends Controller
{
    /**
     * Display listing
     */
    public function index()
    {
        try {
            $business_id = auth()->user()->business_id;

            $data = CacheService::rememberBusiness($business_id, 'racks', function () use ($business_id) {
                return Rack::where('business_id', $business_id)->latest()->get();
            }, 86400, [CacheService::TAG_MASTER_DATA]);

            return response()->json([
                'message' => __('Data fetched successfully.'),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to fetch racks.')
            ], 500);
        }
    }

    /**
     * Store
     */
    public function store(StoreRackRequest $request)
    {
        try {
            $business_id = auth()->user()->business_id;

            $data = Rack::create([
                'name' => DataSanitizer::stripHtml(DataSanitizer::sanitizeString($request->name)),
                'business_id' => $business_id
            ]);

            CacheService::forgetBusiness($business_id, 'racks');

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to create rack.')
            ], 500);
        }
    }

    /**
     * Update
     */
    public function update(UpdateRackRequest $request, Rack $rack)
    {
        try {
            $business_id = auth()->user()->business_id;

            if ($rack->business_id != $business_id) {
                return response()->json([
                    'message' => __('Unauthorized.')
                ], 403);
            }

            $rack->update([
                'name' => DataSanitizer::stripHtml(DataSanitizer::sanitizeString($request->name))
            ]);

            CacheService::forgetBusiness($business_id, 'racks');

            return response()->json([
                'message' => __('Data updated successfully.'),
                'data' => $rack,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to update rack.')
            ], 500);
        }
    }

    /**
     * Delete
     */
    public function destroy(Rack $rack)
    {
        try {
            $business_id = auth()->user()->business_id;

            if ($rack->business_id != $business_id) {
                return response()->json([
                    'message' => __('Unauthorized.')
                ], 403);
            }

            $rack->delete();

            CacheService::forgetBusiness($business_id, 'racks');

            return response()->json([
                'message' => __(':noun deleted.', ['noun' => __('Rack')]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to delete rack.')
            ], 500);
        }
    }
}
