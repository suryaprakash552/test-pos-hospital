<?php

namespace App\Http\Controllers\Api\Customer;

use App\Models\AppDoctor;
use App\Helpers\HasUploader;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiRequest;

class AppDoctorController extends Controller
{
    use HasUploader;

    public function index(Request $request)
    {
        $user = auth()->user();
        $search = ApiRequest::search($request);
        $perPage = ApiRequest::perPage($request, 50, 100);

        $query = AppDoctor::query()
            ->select([
                'id',
                'business_id',
                'doctor_name',
                'specialisation',
                'qualification',
                'experience',
                'consultation_fee',
                'extra_charges',
                'op_charges',
                'image',
                'status',
                'created_at',
                'updated_at',
            ])
            ->forBusiness((int) $user->business_id)
            ->latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('doctor_name', 'like', '%' . $search . '%')
                    ->orWhere('specialisation', 'like', '%' . $search . '%');
            });
        }

        if ($request->filled('specialization')) {
            $query->where('specialisation', 'like', '%' . $request->specialization . '%');
        }

        $doctors = $query->paginate($perPage);

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $doctors,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'doctor_name' => 'required|string|max:255',
            'specialisation' => 'nullable|string|max:255',
            'qualification' => 'nullable|string|max:255',
            'experience' => 'nullable|string|max:255',
            'consultation_fee' => 'nullable|numeric|min:0',
            'extra_charges' => 'nullable|numeric|min:0',
            'op_charges' => 'nullable|numeric|min:0',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg',
            'status' => 'nullable|in:0,1',
        ]);

        $business_id = auth()->user()->business_id;

        $doctor = AppDoctor::create([
            'business_id' => $business_id,
            'doctor_name' => $request->doctor_name,
            'specialisation' => $request->specialisation,
            'qualification' => $request->qualification,
            'experience' => $request->experience,
            'consultation_fee' => $request->consultation_fee ?? 0,
            'extra_charges' => $request->extra_charges ?? 0,
            'op_charges' => $request->op_charges ?? 0,
            'image' => $request->image ? $this->upload($request, 'image') : NULL,
            'status' => $request->status ? 1 : 0,
        ]);

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data' => $doctor,
        ]);
    }

    public function show(AppDoctor $appDoctor)
    {
        if ($appDoctor->business_id !== auth()->user()->business_id) {
            return response()->json([
                'message' => __('Doctor not found.')
            ], 404);
        }

        return response()->json([
            'data' => $appDoctor
        ]);
    }

    public function update(Request $request, AppDoctor $appDoctor)
    {
        $businessId = auth()->user()->business_id;

        if ($appDoctor->business_id !== $businessId) {
            return response()->json([
                'message' => __('Doctor not found.')
            ], 404);
        }

        $request->validate([
            'doctor_name' => 'required|string|max:255',
            'specialisation' => 'nullable|string|max:255',
            'qualification' => 'nullable|string|max:255',
            'experience' => 'nullable|string|max:255',
            'consultation_fee' => 'nullable|numeric|min:0',
            'extra_charges' => 'nullable|numeric|min:0',
            'op_charges' => 'nullable|numeric|min:0',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg',
            'status' => 'nullable|in:0,1',
        ]);

        $appDoctor->update([
            'doctor_name' => $request->doctor_name,
            'specialisation' => $request->specialisation,
            'qualification' => $request->qualification,
            'experience' => $request->experience,
            'consultation_fee' => $request->consultation_fee ?? $appDoctor->consultation_fee,
            'extra_charges' => $request->extra_charges ?? $appDoctor->extra_charges,
            'op_charges' => $request->op_charges ?? $appDoctor->op_charges,
            'image' => $request->image ? $this->upload($request, 'image', $appDoctor->image) : $appDoctor->image,
            'status' => $request->status ? 1 : 0,
        ]);

        return response()->json([
            'message' => __('Doctor updated successfully.'),
            'data' => $appDoctor
        ]);
    }

    public function destroy($id)
    {
        $businessId = auth()->user()->business_id;

        $doctor = AppDoctor::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (!$doctor) {
            return response()->json([
                'message' => 'Doctor not found'
            ], 404);
        }

        if ($doctor->image && file_exists($doctor->image)) {
            unlink($doctor->image);
        }

        $doctor->delete();

        return response()->json([
            'message' => 'Doctor deleted successfully.'
        ]);
    }
}
