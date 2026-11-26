<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\GstConfigurationHistory;
use App\Services\BranchGstSetup;
use App\Services\GstConfiguration;
use App\Services\GstPricingMethod;
use App\Services\GstStates;
use Illuminate\Http\Request;

/**
 * The GST identity and pricing method a shop bills under.
 *
 * Reading is open to anyone who can see the shop's settings. Changing it needs an
 * explicit permission (§19) because the pricing method appears on every invoice issued
 * afterwards, and a mistake here is visible to customers and to the tax authority; the
 * owner always has it, anyone else must be granted it.
 *
 * Changes never touch existing transactions. An invoice keeps the method it was billed
 * under, which is what makes a change safe to make part-way through a year.
 */
class GstConfigurationController extends Controller
{
    /**
     * §19: changing the shop's GST configuration is a grantable permission, not a
     * role. The owner passes automatically; anyone else needs it granted.
     */
    public const UPDATE_PERMISSION = 'gst.update';

    /** Columns a client may set. */
    private const EDITABLE = [
        'gst_registered',
        'gstin',
        'gst_state',
        'place_of_supply',
        'default_vat_type',
        'allow_gst_override',
    ];

    public function show()
    {
        $business = Business::with('category:id,name,default_vat_type')
            ->findOrFail(auth()->user()->business_id);

        $shopTypeDefault = $business->category?->default_vat_type;

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => [
                'gst_registered'     => $business->gst_registered,
                'gstin'              => $business->gstin,
                'gst_state'          => $business->gst_state,
                'place_of_supply'    => $business->place_of_supply,
                'default_vat_type'   => $business->default_vat_type,
                'allow_gst_override' => (bool) $business->allow_gst_override,
                'gst_effective_from' => $business->gst_effective_from?->toDateString(),

                'shop_type'          => $business->category?->name,
                'shop_type_default'  => $shopTypeDefault,

                // What billing would actually use right now, after the hierarchy is
                // applied — so the screen can show the effective value rather than
                // leaving the user to work it out.
                'effective_method'   => GstConfiguration::resolve(null, null, $business),

                'available_states'   => GstStates::options(),

                'available_methods'  => array_map(
                    fn (string $method) => [
                        'value' => $method,
                        'label' => GstPricingMethod::label($method),
                    ],
                    GstPricingMethod::all(),
                ),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $user = auth()->user();

        // §19: who may change this is configurable rather than fixed to the owner, so
        // a shop can delegate it without handing over the owner account. An owner
        // always passes; a staff member with nothing granted is refused exactly as
        // before.
        if (! $user->hasPermission(self::UPDATE_PERMISSION)) {
            return response()->json([
                'message' => __('You are not authorised to change the GST configuration.'),
                'reason'  => 'not_permitted',
            ], 403);
        }

        $business = Business::with('category:id,name,default_vat_type')
            ->findOrFail($user->business_id);

        $validated = $request->validate([
            'gst_registered'     => ['nullable', 'boolean'],
            'gstin'              => ['nullable', 'string', 'max:15'],
            'gst_state'          => ['nullable', 'string', 'max:100'],
            'place_of_supply'    => ['nullable', 'string', 'max:100'],
            'default_vat_type'   => ['nullable', 'string'],
            'allow_gst_override' => ['nullable', 'boolean'],
            // A change applies from here forward; invoices before it are untouched.
            'effective_from'     => ['nullable', 'date'],
            'reason'             => ['nullable', 'string', 'max:255'],
        ]);

        $changes = array_intersect_key($validated, array_flip(self::EDITABLE));

        // Validated against the resulting state, not the submitted fragment: a
        // partial update that leaves the shop registered without a GSTIN is just as
        // invalid as one that says so outright.
        $resulting = array_merge([
            'gst_registered'   => $business->gst_registered,
            'gstin'            => $business->gstin,
            'gst_state'        => $business->gst_state,
            'default_vat_type' => $business->default_vat_type,
        ], $changes);

        if ($errors = GstConfiguration::validate($resulting)) {
            return response()->json([
                'message' => __('The GST configuration is not valid.'),
                'errors'  => $errors,
            ], 422);
        }

        $newMethod = $changes['default_vat_type'] ?? null;

        if ($newMethod !== null
            && $newMethod !== $business->default_vat_type
            && ! BranchGstSetup::mayOverride($user, (bool) $business->allow_gst_override)
        ) {
            return response()->json([
                'message' => __(BranchGstSetup::NOT_AUTHORISED),
                'reason'  => 'override_not_allowed',
            ], 403);
        }

        if (GstConfiguration::requiresReason($newMethod, $business) && blank($validated['reason'] ?? null)) {
            return response()->json([
                'message' => __('A reason is required when overriding the default for this shop type.'),
                'reason'  => 'reason_required',
                'shop_type_default' => $business->category?->default_vat_type,
            ], 422);
        }

        // §17: a change cannot reach back into a period already filed, unless the
        // user carries the permission to correct one.
        if (GstConfiguration::isBeforeClosedPeriod($validated['effective_from'] ?? null, $business)
            && ! GstConfiguration::mayBackdate($user)
        ) {
            return response()->json([
                'message' => __(GstConfiguration::CLOSED_PERIOD_MESSAGE),
                'reason'  => 'closed_period',
                'errors'  => [
                    'effective_from' => __(GstConfiguration::CLOSED_PERIOD_MESSAGE),
                ],
            ], 422);
        }

        // §17: a change to the pricing method takes effect from a stated date. Without
        // one there is no answer to "which method was this invoice billed under",
        // which is the question a return is reconciled against.
        if ($newMethod !== null
            && $newMethod !== $business->default_vat_type
            && blank($validated['effective_from'] ?? null)
        ) {
            return response()->json([
                'message' => __('An effective date is required when changing the GST pricing method.'),
                'reason'  => 'effective_date_required',
                'errors'  => [
                    'effective_from' => __('An effective date is required when changing the GST pricing method.'),
                ],
            ], 422);
        }

        $applied = GstConfiguration::apply(
            $business,
            $changes,
            $user->id,
            $validated['reason'] ?? null,
            $validated['effective_from'] ?? null,
        );

        return response()->json([
            'message' => __('Data updated successfully.'),
            'data'    => [
                'changed'          => $applied,
                'effective_method' => GstConfiguration::resolve(null, null, $business->fresh()),
                // Stated plainly because it is the question every user asks when
                // changing a tax setting mid-year.
                'note' => $applied === []
                    ? null
                    : __('Existing invoices are unchanged. This applies to new transactions only.'),
            ],
        ]);
    }

    /** §20: who changed what, when, and why. */
    public function history(Request $request)
    {
        $businessId = auth()->user()->business_id;

        $history = GstConfigurationHistory::where('business_id', $businessId)
            ->with('changedByUser:id,name')
            ->latest()
            ->limit((int) $request->input('limit', 100))
            ->get();

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data'    => $history,
        ]);
    }
}
