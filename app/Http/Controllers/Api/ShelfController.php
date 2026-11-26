<?php

namespace App\Http\Controllers\Api;

use App\Models\Shelf;
use App\Http\Controllers\Controller;
use App\Helpers\DataSanitizer;
use App\Http\Requests\Api\StoreShelfRequest;
use App\Http\Requests\Api\UpdateShelfRequest;
use App\Services\CacheService;

class ShelfController extends Controller
{
    /**
     * Display listing
     */
    public function index()
    {
        try {
            $business_id = auth()->user()->business_id;

            $data = CacheService::rememberBusiness($business_id, 'shelves', function () use ($business_id) {
                return Shelf::where('business_id', $business_id)->latest()->get();
            }, 86400, [CacheService::TAG_MASTER_DATA]);

            return response()->json([
                'message' => __('Data fetched successfully.'),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to fetch shelves.')
            ], 500);
        }
    }

    /**
     * Store
     */
    public function store(StoreShelfRequest $request)
    {
        try {
            $business_id = auth()->user()->business_id;

            $data = Shelf::create([
                'name' => DataSanitizer::stripHtml(DataSanitizer::sanitizeString($request->name)),
                'business_id' => $business_id
            ]);

            CacheService::forgetBusiness($business_id, 'shelves');

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to create shelf.')
            ], 500);
        }
    }

    /**
     * Update
     */
    public function update(UpdateShelfRequest $request, Shelf $shelf)
    {
        try {
            $business_id = auth()->user()->business_id;

            if ($shelf->business_id != $business_id) {
                return response()->json([
                    'message' => __('Unauthorized.')
                ], 403);
            }

            $shelf->update([
                'name' => DataSanitizer::stripHtml(DataSanitizer::sanitizeString($request->name))
            ]);

            CacheService::forgetBusiness($business_id, 'shelves');

            return response()->json([
                'message' => __('Data updated successfully.'),
                'data' => $shelf,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to update shelf.')
            ], 500);
        }
    }

    /**
     * Delete
     */
    public function destroy(Shelf $shelf)
    {
        try {
            $business_id = auth()->user()->business_id;

            if ($shelf->business_id != $business_id) {
                return response()->json([
                    'message' => __('Unauthorized.')
                ], 403);
            }

            $shelf->delete();

            CacheService::forgetBusiness($business_id, 'shelves');

            return response()->json([
                'message' => __(':noun deleted.', ['noun' => __('Shelf')]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to delete shelf.')
            ], 500);
        }
    }
}
