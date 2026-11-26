<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use Illuminate\Http\Request;

class DoctorController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Doctor::where(
            'business_id',
            $user->business_id
        );

        if ($request->filled('search')) {

            $query->where(function ($q) use ($request) {

                $q->where(
                    'name',
                    'LIKE',
                    '%' . $request->search . '%'
                )
                ->orWhere(
                    'register_no',
                    'LIKE',
                    '%' . $request->search . '%'
                )
                ->orWhere(
                    'mci_no',
                    'LIKE',
                    '%' . $request->search . '%'
                );
            });
        }

        $doctors = $query
            ->latest()
           ->get();

        return response()->json([
            'message' => 'Doctors fetched successfully',
            'data' => $doctors
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([

            'name' => 'required|string|max:255',

            'qualification' => 'nullable|string|max:255',

            'specialization' => 'nullable|string|max:255',

            'register_no' => 'nullable|string|max:255',

            'mci_no' => 'nullable|string|max:255',

            'location' => 'nullable|string',
        ]);

        $user = auth()->user();

        $doctor = Doctor::create([

            'business_id' => $user->business_id,

            'name' => $request->name,

            'qualification' => $request->qualification,

            'specialization' => $request->specialization,

            'register_no' => $request->register_no,

            'mci_no' => $request->mci_no,

            'location' => $request->location,
        ]);

        return response()->json([
            'message' => 'Doctor added successfully',
            'data' => $doctor
        ]);
    }

    public function show($id)
    {
        $user = auth()->user();

        $doctor = Doctor::where('business_id', $user->business_id)->findOrFail($id);

        return response()->json([
            'message' => 'Doctor fetched successfully',
            'data' => $doctor,
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = auth()->user();

        $doctor = Doctor::where(
            'business_id',
            $user->business_id
        )->findOrFail($id);

        $doctor->update([

            'name' => $request->name,

            'qualification' => $request->qualification,

            'specialization' => $request->specialization,

            'register_no' => $request->register_no,

            'mci_no' => $request->mci_no,

            'location' => $request->location,
        ]);

        return response()->json([
            'message' => 'Doctor updated successfully',
            'data' => $doctor
        ]);
    }

    public function destroy($id)
    {
        $user = auth()->user();

        $doctor = Doctor::where(
            'business_id',
            $user->business_id
        )->findOrFail($id);

        $doctor->delete();

        return response()->json([
            'message' => 'Doctor deleted successfully'
        ]);
    }
}
