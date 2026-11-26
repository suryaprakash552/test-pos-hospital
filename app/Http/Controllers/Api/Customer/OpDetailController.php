<?php

namespace App\Http\Controllers\Api\Customer;

use App\Models\OpDetail;
use App\Helpers\HasUploader;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiRequest;

class OpDetailController extends Controller
{
    use HasUploader;

    public function index(Request $request)
    {
        $user = auth()->user();
        $search = ApiRequest::search($request);
        $perPage = ApiRequest::perPage($request, 50, 100);

        // Get customer_id by matching phone with customer_users phone
        $customer = \App\Models\Customer::where('business_id', $user->business_id)
            ->where('phone', $user->phone)
            ->first();

        if (!$customer) {
            return response()->json([
                'message' => __('Customer not found.')
            ], 404);
        }

        $query = OpDetail::query()
            ->with(['doctor:id,doctor_name,specialisation', 'customer:id,name,phone'])
            ->select([
                'id',
                'business_id',
                'doctor_id',
                'customer_id',
                'reason',
                'appointment_time',
                'documents',
                'amount',
                'payment_type',
                'payment_status',
                'created_at',
                'updated_at',
            ])
            ->forBusiness((int) $user->business_id)
            ->where('customer_id', $customer->id)
            ->latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('reason', 'like', '%' . $search . '%')
                    ->orWhere('payment_type', 'like', '%' . $search . '%');
            });
        }

        if ($request->filled('doctor_id')) {
            $query->where('doctor_id', $request->doctor_id);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        $opDetails = $query->paginate($perPage);

        // Get customer user phone for each op detail
        $opDetails->getCollection()->transform(function ($opDetail) {
            $customerUser = \App\Models\CustomerUser::where('phone', $opDetail->customer->phone)->first(['id', 'phone']);
            $opDetail->customer->customer_user_phone = $customerUser ? $customerUser->phone : null;
            return $opDetail;
        });

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $opDetails,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'doctor_id' => 'required|exists:app_doctors,id',
            'customer_id' => 'required|exists:customers,id',
            'reason' => 'nullable|string',
            'appointment_time' => 'nullable|date',
            'documents' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png,gif|max:10240',
            'amount' => 'nullable|numeric|min:0',
            'payment_type' => 'nullable|string|max:255',
            'payment_status' => 'nullable|in:Pending,Completed',
        ]);

        $business_id = auth()->user()->business_id;

        $opDetail = OpDetail::create([
            'business_id' => $business_id,
            'doctor_id' => $request->doctor_id,
            'customer_id' => $request->customer_id,
            'reason' => $request->reason,
            'appointment_time' => $request->appointment_time,
            'documents' => $request->documents ? $this->upload($request, 'documents') : NULL,
            'amount' => $request->amount ?? 0,
            'payment_type' => $request->payment_type,
            'payment_status' => $request->payment_status ?? 'Pending',
        ]);

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data' => $opDetail,
        ]);
    }

    public function show(OpDetail $opDetail)
    {
        if ($opDetail->business_id !== auth()->user()->business_id) {
            return response()->json([
                'message' => __('OP detail not found.')
            ], 404);
        }

        $opDetail->load(['doctor', 'customer']);

        return response()->json([
            'data' => $opDetail
        ]);
    }

    public function update(Request $request, OpDetail $opDetail)
    {
        $businessId = auth()->user()->business_id;

        if ($opDetail->business_id !== $businessId) {
            return response()->json([
                'message' => __('OP detail not found.')
            ], 404);
        }

        $request->validate([
            'doctor_id' => 'nullable|exists:app_doctors,id',
            'customer_id' => 'nullable|exists:customers,id',
            'reason' => 'nullable|string',
            'appointment_time' => 'nullable|date',
            'documents' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png,gif|max:10240',
            'amount' => 'nullable|numeric|min:0',
            'payment_type' => 'nullable|string|max:255',
            'payment_status' => 'nullable|in:Pending,Completed',
        ]);

        $opDetail->update([
            'doctor_id' => $request->doctor_id ?? $opDetail->doctor_id,
            'customer_id' => $request->customer_id ?? $opDetail->customer_id,
            'reason' => $request->reason ?? $opDetail->reason,
            'appointment_time' => $request->appointment_time ?? $opDetail->appointment_time,
            'documents' => $request->documents ? $this->upload($request, 'documents', $opDetail->documents) : $opDetail->documents,
            'amount' => $request->amount ?? $opDetail->amount,
            'payment_type' => $request->payment_type ?? $opDetail->payment_type,
            'payment_status' => $request->payment_status ?? $opDetail->payment_status,
        ]);

        return response()->json([
            'message' => __('OP detail updated successfully.'),
            'data' => $opDetail
        ]);
    }

    public function destroy($id)
    {
        $businessId = auth()->user()->business_id;

        $opDetail = OpDetail::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (!$opDetail) {
            return response()->json([
                'message' => 'OP detail not found'
            ], 404);
        }

        if ($opDetail->documents && file_exists($opDetail->documents)) {
            unlink($opDetail->documents);
        }

        $opDetail->delete();

        return response()->json([
            'message' => 'OP detail deleted successfully.'
        ]);
    }
}
