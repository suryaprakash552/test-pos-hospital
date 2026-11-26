<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyAudit;
use App\Services\LoyaltyEligibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * §11: what a loyalty discount does not apply to.
 *
 * Without this the engine has the rules but no way to enter them, so every line
 * stays eligible and the discount reaches items sold at or below cost.
 *
 * Gated on the same permission as editing a package: adding an exclusion changes
 * what customers are charged just as surely as changing a discount rate does.
 */
class LoyaltyExclusionController extends Controller
{
    public function __construct()
    {
        $this->middleware('check.permission:loyalty.read')->only('index');
        $this->middleware('check.permission:loyalty.update')->only(['store', 'destroy']);
    }

    /**
     * Every rule in force, with the name of what it excludes.
     *
     * The name is resolved here rather than left to the client: a list of bare ids
     * is unreadable, and the caller would have to fetch three more endpoints to
     * render it.
     */
    public function index(Request $request)
    {
        $businessId = auth()->user()->business_id;

        $rules = DB::table('loyalty_exclusions')
            ->where('business_id', $businessId)
            ->when($request->filled('package_id'), fn ($q) =>
                $q->where('loyalty_package_id', $request->integer('package_id')))
            ->orderBy('excludable_type')
            ->get();

        $names = [
            LoyaltyEligibility::PRODUCT  => DB::table('products')->where('business_id', $businessId)
                ->pluck('productName', 'id'),
            LoyaltyEligibility::BRAND    => DB::table('brands')->where('business_id', $businessId)
                ->pluck('brandName', 'id'),
            LoyaltyEligibility::CATEGORY => DB::table('categories')->where('business_id', $businessId)
                ->pluck('categoryName', 'id'),
        ];

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $rules->map(fn ($rule) => [
                'id'                 => $rule->id,
                'loyalty_package_id' => $rule->loyalty_package_id,
                'type'               => $rule->excludable_type,
                'excludable_id'      => $rule->excludable_id,
                'name'               => $names[$rule->excludable_type][$rule->excludable_id] ?? null,
                // Null means the rule covers every package in the shop.
                'scope' => $rule->loyalty_package_id === null ? 'all_packages' : 'package',
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $businessId = auth()->user()->business_id;

        $validated = $request->validate([
            'type' => ['required', Rule::in([
                LoyaltyEligibility::PRODUCT,
                LoyaltyEligibility::BRAND,
                LoyaltyEligibility::CATEGORY,
            ])],
            'excludable_id' => ['required', 'integer', 'min:1'],
            // Absent means the rule applies to every package (§11).
            'loyalty_package_id' => ['nullable', Rule::exists('loyalty_packages', 'id')
                ->where(fn ($q) => $q->where('business_id', $businessId))],
        ]);

        // Checked against the shop's own records: an id from another business would
        // otherwise create a rule that silently matches nothing.
        if (! $this->belongsToBusiness($validated['type'], (int) $validated['excludable_id'], $businessId)) {
            return response()->json([
                'message' => __('That item does not belong to this business.'),
                'errors'  => ['excludable_id' => __('Unknown item.')],
            ], 422);
        }

        $existing = DB::table('loyalty_exclusions')
            ->where('business_id', $businessId)
            ->where('excludable_type', $validated['type'])
            ->where('excludable_id', $validated['excludable_id'])
            ->where('loyalty_package_id', $validated['loyalty_package_id'] ?? null)
            ->exists();

        if ($existing) {
            // Idempotent rather than an error: excluding something already excluded
            // is the state the caller asked for.
            return response()->json([
                'message' => __('That exclusion is already in place.'),
            ]);
        }

        $id = DB::table('loyalty_exclusions')->insertGetId([
            'business_id'        => $businessId,
            'loyalty_package_id' => $validated['loyalty_package_id'] ?? null,
            'excludable_type'    => $validated['type'],
            'excludable_id'      => $validated['excludable_id'],
            'created_by'         => auth()->id(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        LoyaltyAudit::record(LoyaltyAudit::PACKAGE_UPDATED, null, [
            'business_id' => $businessId,
            'field'       => 'exclusion',
            'new_value'   => $validated['type'] . ':' . $validated['excludable_id'],
            'reason'      => $request->input('reason'),
        ]);

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data'    => ['id' => $id],
        ]);
    }

    public function destroy(int $exclusion)
    {
        $businessId = auth()->user()->business_id;

        $rule = DB::table('loyalty_exclusions')
            ->where('business_id', $businessId)
            ->where('id', $exclusion)
            ->first();

        abort_if($rule === null, 404);

        DB::table('loyalty_exclusions')->where('id', $exclusion)->delete();

        LoyaltyAudit::record(LoyaltyAudit::PACKAGE_UPDATED, null, [
            'business_id' => $businessId,
            'field'       => 'exclusion',
            'old_value'   => $rule->excludable_type . ':' . $rule->excludable_id,
            'new_value'   => null,
        ]);

        return response()->json(['message' => __('Exclusion removed.')]);
    }

    private function belongsToBusiness(string $type, int $id, int $businessId): bool
    {
        $table = match ($type) {
            LoyaltyEligibility::PRODUCT  => 'products',
            LoyaltyEligibility::BRAND    => 'brands',
            LoyaltyEligibility::CATEGORY => 'categories',
        };

        return DB::table($table)
            ->where('id', $id)
            ->where('business_id', $businessId)
            ->exists();
    }
}
