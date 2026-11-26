<?php

namespace App\Http\Controllers\Api\Customer;

use App\Models\QuickAction;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiRequest;

class QuickActionController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $search = ApiRequest::search($request);
        $perPage = ApiRequest::perPage($request, 50, 100);

        $query = QuickAction::query()
            ->with(['customerUser:id,phone'])
            ->select(['id', 'business_id', 'customer_user_id', 'action_type', 'created_at', 'updated_at'])
            ->forBusiness((int) $user->business_id)
            ->where('customer_user_id', $user->id)
            ->latest();

        if ($search) {
            $query->where('action_type', 'like', '%' . $search . '%');
        }

        $quickActions = $query->paginate($perPage);

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $quickActions,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'action_type' => 'required|string|max:255',
        ]);

        $user = auth()->user();

        // Ensure required fields are present
        if (!$user->business_id) {
            return response()->json([
                'message' => __('Business ID is required.')
            ], 422);
        }

        if (!$user->id) {
            return response()->json([
                'message' => __('Customer user ID is required.')
            ], 422);
        }

        $quickAction = QuickAction::create([
            'business_id' => $user->business_id,
            'customer_user_id' => $user->id,
            'action_type' => $request->action_type,
        ]);

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data' => $quickAction,
        ]);
    }

    public function show(QuickAction $quickAction)
    {
        if ($quickAction->business_id !== auth()->user()->business_id) {
            return response()->json([
                'message' => __('Quick action not found.')
            ], 404);
        }

        $quickAction->load(['customerUser']);

        return response()->json([
            'data' => $quickAction
        ]);
    }

    public function update(Request $request, QuickAction $quickAction)
    {
        $businessId = auth()->user()->business_id;

        if ($quickAction->business_id !== $businessId) {
            return response()->json([
                'message' => __('Quick action not found.')
            ], 404);
        }

        $request->validate([
            'action_type' => 'required|string|max:255',
        ]);

        $quickAction->update([
            'action_type' => $request->action_type,
        ]);

        return response()->json([
            'message' => __('Quick action updated successfully.'),
            'data' => $quickAction
        ]);
    }

    public function destroy($id)
    {
        $businessId = auth()->user()->business_id;

        $quickAction = QuickAction::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (!$quickAction) {
            return response()->json([
                'message' => 'Quick action not found'
            ], 404);
        }

        $quickAction->delete();

        return response()->json([
            'message' => 'Quick action deleted successfully.'
        ]);
    }
}
