<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Relationship;
use Illuminate\Http\Request;
use App\Helpers\DataSanitizer;
use App\Http\Requests\Api\StoreRelationshipRequest;
use App\Http\Requests\Api\UpdateRelationshipRequest;
use Illuminate\Support\Facades\Cache;

class AcnooRelationshipController extends Controller
{
    /**
     * Display a listing
     */
    public function index()
    {
        try {
            $business_id = auth()->user()->business_id;

            $data = Cache::remember("relationships_{$business_id}", 86400, function () use ($business_id) {
                return Relationship::where('business_id', $business_id)->latest()->get();
            });

            return response()->json([
                'message' => __('Data fetched successfully.'),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to fetch relationships.')
            ], 500);
        }
    }

    /**
     * Store new relationship
     */
    public function store(StoreRelationshipRequest $request)
    {
        try {
            $business_id = auth()->user()->business_id;

            $data = Relationship::create([
                'relationship' => DataSanitizer::sanitizeString($request->relationship),
                'business_id' => $business_id,
            ]);

            Cache::forget("relationships_{$business_id}");

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to create relationship.')
            ], 500);
        }
    }

    /**
     * Update relationship
     */
    public function update(UpdateRelationshipRequest $request, Relationship $relationship)
    {
        $user = auth()->user();

        if ($relationship->business_id != $user->business_id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }

        try {
            $relationship->update([
                'relationship' => DataSanitizer::sanitizeString($request->relationship)
            ]);

            Cache::forget("relationships_{$user->business_id}");

            return response()->json([
                'message' => __('Data updated successfully.'),
                'data' => $relationship,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to update relationship.')
            ], 500);
        }
    }

    /**
     * Delete relationship
     */
    public function destroy(Relationship $relationship)
    {
        $user = auth()->user();

        if ($relationship->business_id != $user->business_id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }

        try {
            $relationship->delete();

            Cache::forget("relationships_{$user->business_id}");

            return response()->json([
                'message' => __('Data deleted successfully.'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to delete relationship.')
            ], 500);
        }
    }
}
