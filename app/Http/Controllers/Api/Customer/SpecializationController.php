<?php

namespace App\Http\Controllers\Api\Customer;

use App\Models\Specialization;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiRequest;

class SpecializationController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $search = ApiRequest::search($request);
        $perPage = ApiRequest::perPage($request, 50, 100);

        $query = Specialization::query()
            ->select(['id', 'business_id', 'name', 'description', 'status', 'created_at', 'updated_at'])
            ->forBusiness((int) $user->business_id)
            ->latest();

        if ($search) {
            $query->where('name', 'like', '%' . $search . '%');
        }

        $specializations = $query->paginate($perPage);

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $specializations,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:0,1',
        ]);

        $business_id = auth()->user()->business_id;

        $specialization = Specialization::create([
            'business_id' => $business_id,
            'name' => $request->name,
            'description' => $request->description,
            'status' => $request->status ? 1 : 0,
        ]);

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data' => $specialization,
        ]);
    }

    public function show(Specialization $specialization)
    {
        if ($specialization->business_id !== auth()->user()->business_id) {
            return response()->json([
                'message' => __('Specialization not found.')
            ], 404);
        }

        return response()->json([
            'data' => $specialization
        ]);
    }

    public function update(Request $request, Specialization $specialization)
    {
        $businessId = auth()->user()->business_id;

        if ($specialization->business_id !== $businessId) {
            return response()->json([
                'message' => __('Specialization not found.')
            ], 404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:0,1',
        ]);

        $specialization->update([
            'name' => $request->name,
            'description' => $request->description,
            'status' => $request->status ? 1 : 0,
        ]);

        return response()->json([
            'message' => __('Specialization updated successfully.'),
            'data' => $specialization
        ]);
    }

    public function destroy($id)
    {
        $businessId = auth()->user()->business_id;

        $specialization = Specialization::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (!$specialization) {
            return response()->json([
                'message' => 'Specialization not found'
            ], 404);
        }

        $specialization->delete();

        return response()->json([
            'message' => 'Specialization deleted successfully.'
        ]);
    }
}
