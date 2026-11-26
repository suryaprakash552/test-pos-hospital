<?php

namespace Modules\MultiBranchAddon\App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Branch;
use App\Traits\HandlesBranchGst;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class AcnooBranchController extends Controller
{
    use HandlesBranchGst;

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $data = Branch::where('business_id', auth()->user()->business_id)->latest()->get();

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
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'branchOpeningBalance' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:1000',
        ] + $this->gstRules());

        [$gst, $gstError] = $this->gstAttributesForNewBranch($request);

        if ($gstError) {
            return $gstError;
        }

        DB::beginTransaction();
        try {
            $opening_balance = $request->branchOpeningBalance ?? 0;
            $business_id = auth()->user()->business_id;
            $has_main_branch = Branch::where('business_id', $business_id)->where('is_main', 1)->exists();

            if (!branch_count() || !$has_main_branch) {
                manipulateBranchData($business_id);
            }

            $branch = Branch::create($request->except(
                'branchOpeningBalance',
                'branchRemainingBalance',
                ...$this->gstFields(),
            ) + $gst + [
                'branchRemainingBalance' => $opening_balance,
                'branchOpeningBalance' => $opening_balance,
            ]);

            $this->recordBranchGstCreation(
                $branch,
                $gst,
                $request->input('gst_change_reason'),
            );

            Cache::forget('branch-count-' . $business_id);

            DB::commit();
            return response()->json([
                'message' => __('Branch saved successfully.'),
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Branch save failed', [
                'business_id' => auth()->user()?->business_id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => __('Failed to save branch.')], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'branchOpeningBalance' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:1000',
        ] + $this->gstRules());

        $branch = Branch::findOrFail($id);

        // GST fields are handled separately below so every change is recorded. Letting
        // them through mass assignment would change what appears on invoices with no
        // trace of who changed it or why.
        $updateData = $request->except('branchRemainingBalance', ...$this->gstFields());
        $requestedOpeningBalance = $request->input('branchOpeningBalance');

        if ($requestedOpeningBalance != $branch->branchOpeningBalance) {
            if ($branch->branchRemainingBalance === $branch->branchOpeningBalance) {
                $updateData['branchRemainingBalance'] = $requestedOpeningBalance;
            } else {
                return response()->json([
                    'message' => __('You cannot update opening balance because it differs from remaining balance.')
                ], 422);
            }
        }

        if ($gstError = $this->applyBranchGstChanges($request, $branch)) {
            return $gstError;
        }

        $branch->update($updateData);

        return response()->json([
            'message' => __('Branch updated successfully.'),
            'gst'     => [
                'effective_method' => $branch->fresh()->default_vat_type,
                'note' => __('Existing invoices are unchanged. This applies to new transactions only.'),
            ],
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $business_id = auth()->user()->business_id;
        $branch = Branch::where('business_id', $business_id)->findOrFail($id);

        if ($branch->is_main) {
            return response()->json([
                'message' => __('You can not delete main branch.')
            ], 406);
        }

        User::where('branch_id', $branch->id)->delete();
        $branch->delete();

        Cache::forget('branch-count-' . $business_id);

        return response()->json([
            'message' => __('Branch deleted successfully'),
            'redirect' => route('multibranch.branches.index'),
        ]);
    }

    public function switchBranch(string $id)
    {
        if (!auth()->user()->branch_id) {

            $branch = Branch::where('business_id', auth()->user()->business_id)->findOrFail($id);
            auth()->user()->update([
                'active_branch_id' => $branch->id
            ]);

            return response()->json([
                'message' => "You've successfully login to " . $branch->name,
            ]);
        } else {
            return response()->json([
                'message' => "You're not permitted to login on this branch.",
            ]);
        }
    }

    public function exitBranch(string $id)
    {
        if (auth()->user()->active_branch_id) {

            $branch = Branch::where('business_id', auth()->user()->business_id)->findOrFail($id);
            auth()->user()->update([
                'active_branch_id' => null
            ]);

            return response()->json([
                'message' => "You've successfully exit from " . $branch->name,
            ]);
        } else {
            return response()->json([
                'message' => "You're not permitted to exit from this branch.",
            ]);
        }
    }
}
