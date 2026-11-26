<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyAudit;
use App\Models\LoyaltyPackage;
use App\Services\LoyaltyDiscount;
use App\Services\LoyaltyPeriod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The loyalty package master (§2).
 *
 * Creating and editing is gated on its own permission (§13) because a package
 * determines what customers are charged — the same reason the GST configuration is
 * gated rather than left to any signed-in user.
 */
class LoyaltyPackageController extends Controller
{
    public function __construct()
    {
        // Cashiers may view the package ladder while serving a customer. Only
        // creating/changing it remains management permission-gated.
        $this->middleware('check.permission:loyalty.create')->only('store');
        $this->middleware('check.permission:loyalty.update')->only(['update', 'destroy']);
    }

    public function index(Request $request)
    {
        $packages = LoyaltyPackage::query()
            ->where('business_id', auth()->user()->business_id)
            ->when($request->filled('status'), fn ($q) =>
                $q->where('status', $request->boolean('status')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) =>
                $w->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('code', 'like', '%' . $request->search . '%')))
            // Priority is the upgrade hierarchy, so listing by it shows the ladder
            // in the order a customer climbs it.
            ->orderBy('priority')
            ->get();

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data'    => $packages,
        ]);
    }

    public function show(LoyaltyPackage $loyaltyPackage)
    {
        $this->assertOwned($loyaltyPackage);

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data'    => $loyaltyPackage,
        ]);
    }

    public function store(Request $request)
    {
        $businessId = auth()->user()->business_id;

        $validated = $request->validate($this->rules($businessId));

        if ($errors = LoyaltyPackage::validationErrors($validated)) {
            return response()->json([
                'message' => __('The package configuration is not valid.'),
                'errors'  => $errors,
            ], 422);
        }

        $package = LoyaltyPackage::create($validated + [
            'business_id' => $businessId,
            'created_by'  => auth()->id(),
        ]);

        LoyaltyAudit::record(LoyaltyAudit::PACKAGE_CREATED, $package, [
            'field'     => 'name',
            'new_value' => $package->name,
        ]);

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data'    => $package,
        ]);
    }

    public function update(Request $request, LoyaltyPackage $loyaltyPackage)
    {
        $this->assertOwned($loyaltyPackage);

        $businessId = auth()->user()->business_id;

        $validated = $request->validate($this->rules($businessId, $loyaltyPackage->id));

        // Validated against the resulting state, not the submitted fragment: an
        // update that leaves a percentage package on 150% is just as invalid as one
        // that says so outright.
        $resulting = array_merge($loyaltyPackage->only([
            'cost', 'discount_type', 'discount_value', 'discount_mode',
            'validity_type', 'effective_start_date', 'effective_end_date',
        ]), $validated);

        if ($errors = LoyaltyPackage::validationErrors($resulting)) {
            return response()->json([
                'message' => __('The package configuration is not valid.'),
                'errors'  => $errors,
            ], 422);
        }

        // Recorded per field: "the package changed" is not an audit, and §14 asks
        // for the previous value as well as the new one.
        foreach ($validated as $field => $value) {
            if (LoyaltyAudit::stringify($loyaltyPackage->getOriginal($field))
                === LoyaltyAudit::stringify($value)
            ) {
                continue;
            }

            LoyaltyAudit::record(LoyaltyAudit::PACKAGE_UPDATED, $loyaltyPackage, [
                'field'     => $field,
                'old_value' => LoyaltyAudit::stringify($loyaltyPackage->getOriginal($field)),
                'new_value' => LoyaltyAudit::stringify($value),
                'reason'    => $request->input('change_reason'),
            ]);
        }

        $loyaltyPackage->update($validated + ['modified_by' => auth()->id()]);

        return response()->json([
            'message' => __('Data updated successfully.'),
            'data'    => $loyaltyPackage->fresh(),
            // §2.2: existing holders are unaffected by an edit, and the person
            // making it should know that rather than assume the opposite.
            'note'    => __('Customers already on this package keep the terms they '
                . 'bought until their subscription ends.'),
        ]);
    }

    /**
     * Withdraws a package from sale.
     *
     * Soft-deleted, and existing subscriptions are left running: §2.2 allows a
     * customer on a withdrawn package to continue to the end of their validity, and
     * the subscription's foreign key refuses a hard delete for the same reason.
     */
    public function destroy(LoyaltyPackage $loyaltyPackage)
    {
        $this->assertOwned($loyaltyPackage);

        $active = $loyaltyPackage->subscriptions()
            ->where('status', LoyaltyPeriod::ACTIVE)
            ->count();

        $loyaltyPackage->update(['status' => false, 'modified_by' => auth()->id()]);

        LoyaltyAudit::record(LoyaltyAudit::PACKAGE_WITHDRAWN, $loyaltyPackage, [
            'field'     => 'status',
            'old_value' => 'true',
            'new_value' => 'false',
        ]);

        $loyaltyPackage->delete();

        return response()->json([
            'message' => __('Package withdrawn from sale.'),
            'data'    => [
                'active_subscriptions' => $active,
                'note' => $active > 0
                    ? __('It can no longer be sold. :count customer(s) keep it until '
                        . 'their subscription ends.', ['count' => $active])
                    : null,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function rules(int $businessId, ?int $ignoreId = null): array
    {
        $unique = fn (string $column) => Rule::unique('loyalty_packages', $column)
            ->where(fn ($q) => $q->where('business_id', $businessId)->whereNull('deleted_at'))
            ->ignore($ignoreId);

        return [
            'code'    => ['required', 'string', 'max:50', $unique('code')],
            'name'    => ['required', 'string', 'max:100', $unique('name')],
            'description' => ['nullable', 'string', 'max:255'],

            'cost' => ['required', 'numeric', 'min:0'],

            'validity_type'   => ['required', Rule::in([
                LoyaltyPeriod::DAYS, LoyaltyPeriod::MONTHS, LoyaltyPeriod::YEARS,
            ])],
            'validity_period' => ['required', 'integer', 'min:1'],

            'discount_type'  => ['required', Rule::in([
                LoyaltyDiscount::PERCENTAGE, LoyaltyDiscount::FIXED,
            ])],
            'discount_value' => ['required', 'numeric', 'min:0'],

            // Nullable on purpose: absent means no ceiling (§6.4), which is not the
            // same as a configured zero.
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],

            'discount_mode' => ['required', Rule::in([
                LoyaltyDiscount::AUTOMATIC, LoyaltyDiscount::MANUAL,
            ])],

            'renewal_allowed' => ['nullable', 'boolean'],
            'upgrade_allowed' => ['nullable', 'boolean'],
            'priority'        => ['required', 'integer', 'min:0'],

            'effective_start_date' => ['nullable', 'date'],
            'effective_end_date'   => ['nullable', 'date'],

            'status' => ['nullable', 'boolean'],
        ];
    }

    private function assertOwned(LoyaltyPackage $package): void
    {
        abort_if($package->business_id !== auth()->user()->business_id, 404);
    }
}
