<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Business;
use App\Models\GstConfigurationHistory;
use App\Services\GstConfiguration;
use App\Services\GstPricingMethod;
use App\Services\GstStates;
use Illuminate\Http\Request;

/**
 * §21: what each branch is configured to bill under.
 *
 * Distinct from the GST *transaction* report, which totals what was actually charged.
 * This one answers "why does that branch bill the way it does" — the question asked
 * when two branches of the same shop produce differently shaped invoices, and the
 * question an auditor asks before looking at a single invoice.
 *
 * Read-only. Nothing here changes a configuration; that is
 * [GstConfigurationController] and the branch endpoints, which record history.
 */
class BranchGstConfigurationReportController extends Controller
{
    public function __construct()
    {
        // The same permission that gates the GST transaction report: both expose the
        // shop's tax position.
        $this->middleware('check.permission:vat-reports.read');
    }

    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'shop_type'             => ['nullable', 'string'],
            'gst_pricing_method'    => ['nullable', 'string'],
            'gst_registered'        => ['nullable', 'boolean'],
            'gstin'                 => ['nullable', 'string'],
            'state'                 => ['nullable', 'string'],
            'effective_from'        => ['nullable', 'date'],
            'effective_to'          => ['nullable', 'date'],
            'status'                => ['nullable', 'in:active,inactive'],
        ]);

        if (! blank($validated['gst_pricing_method'] ?? null)
            && ! GstPricingMethod::isValid($validated['gst_pricing_method'])
        ) {
            return response()->json([
                'message' => __('Unknown GST pricing method.'),
                'errors'  => ['gst_pricing_method' => __('Unknown GST pricing method.')],
            ], 422);
        }

        if (! blank($validated['effective_from'] ?? null)
            && ! blank($validated['effective_to'] ?? null)
            && $validated['effective_to'] < $validated['effective_from']
        ) {
            return response()->json([
                'message' => __('The end date cannot be before the start date.'),
                'errors'  => ['effective_to' => __('The end date cannot be before the start date.')],
            ], 422);
        }

        $business = Business::with('category:id,name,default_vat_type')
            ->find(auth()->user()->business_id);

        $query = Branch::where('business_id', $business?->id);

        if (! blank($validated['gst_pricing_method'] ?? null)) {
            $query->where('default_vat_type', $validated['gst_pricing_method']);
        }

        if (array_key_exists('gst_registered', $validated) && $validated['gst_registered'] !== null) {
            $query->where('gst_registered', (bool) $validated['gst_registered']);
        }

        if (! blank($validated['gstin'] ?? null)) {
            $query->where('gstin', 'like', '%' . $validated['gstin'] . '%');
        }

        if (! blank($validated['state'] ?? null)) {
            $query->where('gst_state', 'like', '%' . $validated['state'] . '%');
        }

        if (! blank($validated['effective_from'] ?? null)) {
            $query->whereDate('gst_effective_from', '>=', $validated['effective_from']);
        }

        if (! blank($validated['effective_to'] ?? null)) {
            $query->whereDate('gst_effective_from', '<=', $validated['effective_to']);
        }

        if (! blank($validated['status'] ?? null)) {
            $query->where('status', $validated['status'] === 'active' ? 1 : 0);
        }

        $branches = $query->orderBy('name')->get();

        // Who last touched each branch's configuration, read from the history rather
        // than a column: the history is the record that survives the value being
        // overwritten, and duplicating it on the branch would let the two disagree.
        $lastChanges = GstConfigurationHistory::where('business_id', $business?->id)
            ->where('configurable_type', Branch::class)
            ->whereIn('configurable_id', $branches->pluck('id'))
            ->with('changedByUser:id,name')
            ->orderBy('id')
            ->get()
            ->keyBy('configurable_id');

        $shopType = $business?->category?->name;

        // Filtering by shop type is filtering the whole business, since every branch
        // of one shop shares its type. Applied after the query for that reason.
        if (! blank($validated['shop_type'] ?? null)
            && ! str_contains(strtolower((string) $shopType), strtolower($validated['shop_type']))
        ) {
            $branches = $branches->take(0);
        }

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data'    => [
                'shop_type'         => $shopType,
                'shop_type_default' => $business?->category?->default_vat_type,
                'branches'          => $branches->map(function (Branch $branch) use ($business, $lastChanges) {
                    $last = $lastChanges[$branch->id] ?? null;

                    return [
                        'branch_id'          => $branch->id,
                        'branch_name'        => $branch->name,
                        'branch_code'        => $branch->id,
                        'shop_type'          => $business?->category?->name,
                        'gst_registered'     => (bool) $branch->gst_registered,
                        'gstin'              => $branch->gstin,
                        'gst_state'          => $branch->gst_state,
                        'gst_state_code'     => GstStates::codeFor($branch->gst_state),
                        'place_of_supply'    => $branch->place_of_supply,
                        'gst_pricing_method' => $branch->default_vat_type,
                        'gst_pricing_label'  => $branch->default_vat_type
                            ? GstPricingMethod::label($branch->default_vat_type)
                            : null,
                        // What billing would actually use, after the hierarchy — a
                        // branch with nothing of its own still bills under something.
                        'effective_method'   => GstConfiguration::resolve(null, $branch, $business),
                        'effective_from'     => $branch->gst_effective_from?->toDateString(),
                        'override_allowed'   => (bool) $branch->allow_gst_override,
                        'is_active'          => (bool) $branch->status,
                        'created_at'         => $branch->created_at?->toDateTimeString(),
                        'last_changed_by'    => $last?->changedByUser?->name,
                        'last_changed_at'    => $last?->created_at?->toDateTimeString(),
                    ];
                })->values(),
            ],
        ]);
    }
}
