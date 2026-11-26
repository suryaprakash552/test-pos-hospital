<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Support\LookupDeletionGuard;

class AcnooUserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();

        $data = User::with('branch:id,name')
            ->where('business_id', $user->business_id)
            ->when($user->role == 'staff', function ($q) use ($user) {
                $q->where('branch_id', $user->branch_id);
            })
            ->when($user->branch_id || $user->active_branch_id, function ($q) use ($user) {
                $q->where('branch_id', \App\Support\ActiveBranch::forWrite($user));
            })
            ->where('id', '!=', auth()->id())
            ->where('role', 'staff')
            ->latest()
            ->get();

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $data,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|max:30',
            'password' => 'required|min:4|max:15',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|max:20',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $data = User::create([
            'role' => 'staff',
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'visibility' => $request->visibility,
            'password' => Hash::make($request->password),
            'business_id' => auth()->user()->business_id,
            'branch_id' => $request->branch_id ?? \App\Support\ActiveBranch::forWrite(),
        ]);

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data' => $data,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $currentUser = auth()->user();
        
        $user = User::where('business_id', $currentUser->business_id)
            ->where('id', $id)
            ->firstOrFail();

        $request->validate([
            'name' => 'required|max:30',
            'password' => 'nullable|min:4|max:15',
            'phone' => 'nullable|max:20',
            'branch_id' => 'nullable|exists:branches,id',
            'email' => 'required|email|unique:users,email,' . $user->id,
        ]);

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'branch_id' => $request->branch_id ?? \App\Support\ActiveBranch::forWrite(),
            'visibility' => $request->visibility,
            'business_id' => auth()->user()->business_id,
            'password' => $request->password ? Hash::make($request->password) : $user->password,
        ]);

        return response()->json([
            'message' => __('Data saved successfully.')
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $currentUser = auth()->user();
        
        $user = User::where('business_id', $currentUser->business_id)
            ->where('id', $id)
            ->firstOrFail();

        // This method had no guard at all, and sales.user_id, purchases.user_id,
        // expenses.user_id and incomes.user_id all cascaded — so removing a
        // member of staff who had left deleted everything they had ever
        // recorded, and the totals changed with them.
        $blocked = LookupDeletionGuard::blockedByHistory([
            'sale(s)' => ['sales', 'user_id'],
            'purchase(s)' => ['purchases', 'user_id'],
            'expense(s)' => ['expenses', 'user_id'],
            'income entr(ies)' => ['incomes', 'user_id'],
            'due collection(s)' => ['due_collects', 'user_id'],
        ], $user->id, 'user');

        if ($blocked !== null) {
            return response()->json(['message' => $blocked], 409);
        }

        $user->delete();
        return response()->json([
            'message' => __('User deleted.'),
        ]);
    }
}
