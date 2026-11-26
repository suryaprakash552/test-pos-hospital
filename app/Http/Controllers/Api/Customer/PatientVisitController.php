<?php

namespace App\Http\Controllers\Api\Customer;

use App\Models\PatientVisit;
use App\Helpers\HasUploader;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiRequest;

class PatientVisitController extends Controller
{
    use HasUploader;
    public function index(Request $request)
    {
        $user = auth()->user();
        $search = ApiRequest::search($request);
        $perPage = ApiRequest::perPage($request, 50, 100);

        $query = PatientVisit::query()
            ->with(['doctor:id,doctor_name,specialisation', 'visitReason:id,reason', 'customer:id,name,phone'])
            ->select([
                'id',
                'business_id',
                'customer_id',
                'doctor_id',
                'reason',
                'visiting_datetime',
                'existing_disease',
                'medicine_used',
                'any_allergies',
                'emergency_contact',
                'medical_report',
                'location',
                'address_type',
                'door_no',
                'building_name',
                'area',
                'landmark',
                'instructions',
                'status',
                'cancellation_reason',
                'created_at',
                'updated_at',
            ])
            ->forBusiness((int) $user->business_id)
            ->latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('location', 'like', '%' . $search . '%')
                    ->orWhere('area', 'like', '%' . $search . '%')
                    ->orWhere('building_name', 'like', '%' . $search . '%');
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('doctor_id')) {
            $query->where('doctor_id', $request->doctor_id);
        }

        $visits = $query->paginate($perPage);

        // Get customer user phone for each visit
        $visits->getCollection()->transform(function ($visit) {
            $customerUser = \App\Models\CustomerUser::where('phone', $visit->customer->phone)->first(['id', 'phone']);
            $visit->customer->customer_user_phone = $customerUser ? $customerUser->phone : null;
            return $visit;
        });

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $visits,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'doctor_id' => 'nullable|exists:app_doctors,id',
            'reason' => 'nullable|exists:visit_reasons,id',
            'visiting_datetime' => 'nullable|date',
            'existing_disease' => 'nullable|string',
            'medicine_used' => 'nullable|string',
            'any_allergies' => 'nullable|boolean',
            'emergency_contact' => 'nullable|string|max:255',
            'medical_report' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png,gif|max:10240',
            'location' => 'nullable|string|max:255',
            'address_type' => 'nullable|in:Home,Office,Other',
            'door_no' => 'nullable|string|max:50',
            'building_name' => 'nullable|string|max:255',
            'area' => 'nullable|string|max:255',
            'landmark' => 'nullable|string|max:255',
            'instructions' => 'nullable|string',
            'status' => 'nullable|in:Pending,Cancelled,Completed',
        ]);

        $business_id = auth()->user()->business_id;

        $visit = PatientVisit::create([
            'business_id' => $business_id,
            'doctor_id' => $request->doctor_id,
            'reason' => $request->reason,
            'visiting_datetime' => $request->visiting_datetime,
            'existing_disease' => $request->existing_disease,
            'medicine_used' => $request->medicine_used,
            'any_allergies' => $request->any_allergies ?? false,
            'emergency_contact' => $request->emergency_contact,
            'medical_report' => $request->medical_report ? $this->upload($request, 'medical_report') : NULL,
            'location' => $request->location,
            'address_type' => $request->address_type ?? 'Home',
            'door_no' => $request->door_no,
            'building_name' => $request->building_name,
            'area' => $request->area,
            'landmark' => $request->landmark,
            'instructions' => $request->instructions,
            'status' => $request->status ?? 'Pending',
        ]);

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data' => $visit,
        ]);
    }

    public function show(PatientVisit $patientVisit)
    {
        if ($patientVisit->business_id !== auth()->user()->business_id) {
            return response()->json([
                'message' => __('Patient visit not found.')
            ], 404);
        }

        $patientVisit->load(['doctor:id,doctor_name,specialisation,qualification,experience,consultation_fee,extra_charges', 'visitReason:id,reason']);

        return response()->json([
            'data' => $patientVisit
        ]);
    }

    public function update(Request $request, PatientVisit $patientVisit)
    {
        $businessId = auth()->user()->business_id;

        if ($patientVisit->business_id !== $businessId) {
            return response()->json([
                'message' => __('Patient visit not found.')
            ], 404);
        }

        $request->validate([
            'doctor_id' => 'nullable|exists:app_doctors,id',
            'reason' => 'nullable|exists:visit_reasons,id',
            'visiting_datetime' => 'nullable|date',
            'existing_disease' => 'nullable|string',
            'medicine_used' => 'nullable|string',
            'any_allergies' => 'nullable|boolean',
            'emergency_contact' => 'nullable|string|max:255',
            'medical_report' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png,gif|max:10240',
            'location' => 'nullable|string|max:255',
            'address_type' => 'nullable|in:Home,Office,Other',
            'door_no' => 'nullable|string|max:50',
            'building_name' => 'nullable|string|max:255',
            'area' => 'nullable|string|max:255',
            'landmark' => 'nullable|string|max:255',
            'instructions' => 'nullable|string',
            'status' => 'nullable|in:Pending,Cancelled,Completed',
            'cancellation_reason' => 'nullable|string',
        ]);

        $patientVisit->update([
            'doctor_id' => $request->doctor_id ?? $patientVisit->doctor_id,
            'reason' => $request->reason ?? $patientVisit->reason,
            'visiting_datetime' => $request->visiting_datetime ?? $patientVisit->visiting_datetime,
            'existing_disease' => $request->existing_disease ?? $patientVisit->existing_disease,
            'medicine_used' => $request->medicine_used ?? $patientVisit->medicine_used,
            'any_allergies' => $request->any_allergies ?? $patientVisit->any_allergies,
            'emergency_contact' => $request->emergency_contact ?? $patientVisit->emergency_contact,
            'medical_report' => $request->medical_report ? $this->upload($request, 'medical_report', $patientVisit->medical_report) : $patientVisit->medical_report,
            'location' => $request->location ?? $patientVisit->location,
            'address_type' => $request->address_type ?? $patientVisit->address_type,
            'door_no' => $request->door_no ?? $patientVisit->door_no,
            'building_name' => $request->building_name ?? $patientVisit->building_name,
            'area' => $request->area ?? $patientVisit->area,
            'landmark' => $request->landmark ?? $patientVisit->landmark,
            'instructions' => $request->instructions ?? $patientVisit->instructions,
            'status' => $request->status ?? $patientVisit->status,
            'cancellation_reason' => $request->cancellation_reason,
        ]);

        return response()->json([
            'message' => __('Patient visit updated successfully.'),
            'data' => $patientVisit
        ]);
    }

    public function destroy($id)
    {
        $businessId = auth()->user()->business_id;

        $visit = PatientVisit::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (!$visit) {
            return response()->json([
                'message' => 'Patient visit not found'
            ], 404);
        }

        $visit->delete();

        return response()->json([
            'message' => 'Patient visit deleted successfully.'
        ]);
    }
}
