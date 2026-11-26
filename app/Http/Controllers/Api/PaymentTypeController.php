<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MasterNameRequest;
use App\Models\PaymentType;
use Illuminate\Validation\Rule;

class PaymentTypeController extends Controller
{
    public function index()
    {
        $businessId = (int) auth()->user()->business_id;

        $data = PaymentType::forBusiness($businessId)
            ->orderByRaw("CASE LOWER(name) WHEN 'cash' THEN 1 WHEN 'upi' THEN 2 WHEN 'card' THEN 3 WHEN 'split' THEN 4 ELSE 5 END")
            ->orderBy('name')
            ->get();

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $data,
        ]);
    }

    public function store(MasterNameRequest $request)
    {
        $request->validate([
            'name' => [
                'required',
                Rule::unique('payment_types')->where(function ($query) {
                    return $query->where('business_id', auth()->user()->business_id);
                }),
            ],
        ]);

        $data = PaymentType::create($request->validated() + [
            'business_id' => auth()->user()->business_id
        ]);

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data' => $data,
        ]);
    }



    public function update(MasterNameRequest $request, string $id)
    {
        $payment_type = PaymentType::forBusiness((int) auth()->user()->business_id)->findOrFail($id);

        $request->validate([
            'name' => 'required|unique:payment_types,name,' . $payment_type->id . ',id,business_id,' . auth()->user()->business_id,
        ]);

        $payment_type->update($request->validated());

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data' => $payment_type,
        ]);
    }

    public function destroy(string $id)
    {
        $payment_type = PaymentType::forBusiness((int) auth()->user()->business_id)->findOrFail($id);
        $payment_type->delete();
        return response()->json([
            'message' => __('Data deleted successfully.'),
        ]);
    }
}
