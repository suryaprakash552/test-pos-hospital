<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Helpers\DataSanitizer;
use App\Http\Requests\Api\StoreUnitRequest;
use App\Http\Requests\Api\UpdateUnitRequest;
use App\Services\CacheService;
use App\Support\LookupDeletionGuard;

class UnitController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $business_id = auth()->user()->business_id;

            $data = CacheService::rememberBusiness($business_id, 'units', function () use ($business_id) {
                return Unit::where('business_id', $business_id)->latest()->get();
            }, 86400, [CacheService::TAG_MASTER_DATA]);

            return response()->json([
                'message' => __('Data fetched successfully.'),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to fetch units.')
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUnitRequest $request)
    {
        try {
            $business_id = auth()->user()->business_id;

            $data = Unit::create([
                'unitName' => DataSanitizer::stripHtml(DataSanitizer::sanitizeString($request->unitName)),
                'business_id' => $business_id
            ]);

            CacheService::forgetBusiness($business_id, 'units');

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to create unit.')
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUnitRequest $request, Unit $unit)
    {
        $user = auth()->user();

        if ($unit->business_id != $user->business_id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }

        try {
            $unit->update([
                'unitName' => DataSanitizer::stripHtml(DataSanitizer::sanitizeString($request->unitName)),
            ]);

            CacheService::forgetBusiness($user->business_id, 'units');

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $unit,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to update unit.')
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Unit $unit)
    {
        $user = auth()->user();

        if ($unit->business_id != $user->business_id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }

        // products.unit_id cascades, so deleting this would take the
        // products with it. Checked here so the refusal names a count;
        // the RESTRICT constraint is what actually enforces it.
        $blocked = LookupDeletionGuard::blockedMessage(
            'unit_id',
            $unit->id,
            'unit'
        );

        if ($blocked !== null) {
            return response()->json(['message' => $blocked], 409);
        }

        try {
            $unit->delete();

            CacheService::forgetBusiness($user->business_id, 'units');

            return response()->json([
                'message' => __(':noun deleted.', ['noun' => __('Unit')]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to delete unit.')
            ], 500);
        }
    }
}
