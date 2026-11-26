<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerTier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customer tiers / grades.
 *
 * A tier is a label shown on the bill — "Wholesale", "Staff", "Gold". It carries no
 * pricing rule by design: discounts stay a manual decision at the till, so editing or
 * removing a tier can never change what a customer is charged.
 */
class CustomerTierController extends Controller
{
    public function index(Request $request)
    {
        $businessId = auth()->user()->business_id;

        $tiers = CustomerTier::forBusiness($businessId)
            ->when(
                $request->boolean('active_only'),
                fn ($query) => $query->where('status', true)
            )
            ->withCount('customers')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data'    => $tiers,
        ]);
    }

    public function store(Request $request)
    {
        $businessId = auth()->user()->business_id;

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                // Scoped to the business: two shops may both have a "Gold" tier.
                Rule::unique('customer_tiers')->where(
                    fn ($query) => $query->where('business_id', $businessId)
                ),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'status'      => ['nullable', 'boolean'],
        ]);

        $tier = CustomerTier::create($validated + [
            'business_id' => $businessId,
            'status'      => $request->boolean('status', true),
            'sort_order'  => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data'    => $tier,
        ]);
    }

    public function update(Request $request, CustomerTier $customer_tier)
    {
        $businessId = auth()->user()->business_id;

        if ($customer_tier->business_id !== $businessId) {
            return response()->json(['message' => __('Unauthorized.')], 403);
        }

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('customer_tiers')
                    ->ignore($customer_tier->id)
                    ->where(fn ($query) => $query->where('business_id', $businessId)),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'status'      => ['nullable', 'boolean'],
        ]);

        $customer_tier->update($validated);

        return response()->json([
            'message' => __('Data updated successfully.'),
            'data'    => $customer_tier->fresh(),
        ]);
    }

    /**
     * Removing a tier detaches it from its customers rather than deleting them —
     * the foreign key nulls on delete, so the customers survive and simply lose
     * their grade.
     */
    public function destroy(CustomerTier $customer_tier)
    {
        if ($customer_tier->business_id !== auth()->user()->business_id) {
            return response()->json(['message' => __('Unauthorized.')], 403);
        }

        $customer_tier->delete();

        return response()->json(['message' => __('Data deleted successfully.')]);
    }
}
