<?php

namespace App\Http\Controllers\Api\Customer;

use App\Models\VisitReason;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiRequest;

class VisitReasonController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $search = ApiRequest::search($request);
        $perPage = ApiRequest::perPage($request, 50, 100);

        $query = VisitReason::query()
            ->select(['id', 'business_id', 'reason', 'created_at', 'updated_at'])
            ->forBusiness((int) $user->business_id)
            ->latest();

        if ($search) {
            $query->where('reason', 'like', '%' . $search . '%');
        }

        $visitReasons = $query->paginate($perPage);

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $visitReasons,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $business_id = auth()->user()->business_id;

        $visitReason = VisitReason::create([
            'business_id' => $business_id,
            'reason' => $request->reason,
        ]);

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data' => $visitReason,
        ]);
    }

    public function show(VisitReason $visitReason)
    {
        if ($visitReason->business_id !== auth()->user()->business_id) {
            return response()->json([
                'message' => __('Visit reason not found.')
            ], 404);
        }

        return response()->json([
            'data' => $visitReason
        ]);
    }

    public function update(Request $request, VisitReason $visitReason)
    {
        $businessId = auth()->user()->business_id;

        if ($visitReason->business_id !== $businessId) {
            return response()->json([
                'message' => __('Visit reason not found.')
            ], 404);
        }

        $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $visitReason->update([
            'reason' => $request->reason,
        ]);

        return response()->json([
            'message' => __('Visit reason updated successfully.'),
            'data' => $visitReason
        ]);
    }

    public function destroy($id)
    {
        $businessId = auth()->user()->business_id;

        $visitReason = VisitReason::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (!$visitReason) {
            return response()->json([
                'message' => 'Visit reason not found'
            ], 404);
        }

        $visitReason->delete();

        return response()->json([
            'message' => 'Visit reason deleted successfully.'
        ]);
    }
}
