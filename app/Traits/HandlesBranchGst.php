<?php

namespace App\Traits;

use App\Models\Branch;
use App\Models\Business;
use App\Models\GstConfigurationHistory;
use App\Services\BranchGstSetup;
use App\Services\GstConfiguration;
use App\Services\GstPricingMethod;
use App\Services\GstStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The GST rules that apply when a branch is created or edited.
 *
 * A branch can be created from two places — the admin web form and the mobile API —
 * and each has its own controller. Holding these rules in one place is what stops a
 * branch created from the web bypassing the validation the API enforces, which is
 * exactly the kind of gap that produces two branches of one shop billing differently.
 *
 * The rules themselves live in [BranchGstSetup] and [GstConfiguration]; this is only
 * the request-handling around them.
 */
trait HandlesBranchGst
{
    /** §5: the GST fields a branch carries. */
    protected function gstFields(): array
    {
        return [
            'gst_registered',
            'gstin',
            'gst_state',
            'place_of_supply',
            'default_vat_type',
            'allow_gst_override',
            'gst_effective_from',
        ];
    }

    protected function gstRules(): array
    {
        return [
            'gst_registered'     => 'nullable|boolean',
            'gstin'              => 'nullable|string|max:15',
            'gst_state'          => 'nullable|string|max:100',
            'place_of_supply'    => 'nullable|string|max:100',
            'default_vat_type'   => 'nullable|string',
            'allow_gst_override' => 'nullable|boolean',
            'gst_effective_from' => 'nullable|date',
            'gst_change_reason'  => 'nullable|string|max:255',
        ];
    }

    /**
     * The GST fields that were actually submitted, in the types the rules expect.
     *
     * Two normalisations, both of which the rules depend on:
     *
     * An HTML form posts booleans as "1"/"0" and JSON posts real booleans, while the
     * checks compare with `===` against true — so without this, ticking "GST
     * registered" on the admin form would not make a GSTIN mandatory, and an
     * unregistered branch would not be forced to Not Applicable.
     *
     * A form also posts every field it renders, empty ones as "". Those must read as
     * "not submitted", or saving an unrelated edit would look like a request to blank
     * the branch's tax identity.
     *
     * @return array<string, mixed>
     */
    protected function gstInput(Request $request): array
    {
        $submitted = array_filter(
            $request->only($this->gstFields()),
            fn ($value) => $value !== null && $value !== '',
        );

        foreach (['gst_registered', 'allow_gst_override'] as $flag) {
            if (array_key_exists($flag, $submitted)) {
                $submitted[$flag] = filter_var(
                    $submitted[$flag],
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                );
            }
        }

        return $submitted;
    }

    protected function businessForGst(): ?Business
    {
        return Business::with('category:id,name,default_vat_type')
            ->find(auth()->user()->business_id);
    }

    /**
     * What the branch form needs to render its Tax and GST Configuration section.
     *
     * §16: the form tells the user what the shop type implies before they change
     * anything, so a deviation is a deliberate act rather than an accident.
     *
     * @return array<string, mixed>
     */
    protected function gstFormContext(): array
    {
        $business = $this->businessForGst();

        return [
            'notice'            => BranchGstSetup::shopTypeNotice($business),
            'shop_type'         => $business?->category?->name,
            'shop_type_default' => $business?->category?->default_vat_type,
            'methods'           => array_map(
                fn (string $method) => [
                    'value' => $method,
                    'label' => GstPricingMethod::label($method),
                ],
                GstPricingMethod::all(),
            ),
            'states'            => GstStates::options(),
        ];
    }

    /**
     * The GST attributes a new branch should be created with.
     *
     * Returns [$attributes, $errorResponse]. Exactly one is meaningful: when the
     * configuration cannot stand, $attributes is null and the response says why.
     *
     * @return array{0: ?array, 1: ?JsonResponse}
     */
    protected function gstAttributesForNewBranch(Request $request): array
    {
        $business = $this->businessForGst();

        // §4: the shop type decides the pricing method, so the branch does not have
        // to be told it again. Anything the user did supply still wins.
        $gst = BranchGstSetup::defaultsFor($business, $this->gstInput($request));

        if (BranchGstSetup::shopTypeDefaultMissing($business, $gst)) {
            return [null, response()->json([
                'message' => __(BranchGstSetup::NO_SHOP_TYPE_DEFAULT),
                'errors'  => ['default_vat_type' => __(BranchGstSetup::NO_SHOP_TYPE_DEFAULT)],
            ], 422)];
        }

        if ($errors = GstConfiguration::validate($gst)) {
            return [null, response()->json([
                'message' => __('The GST configuration is not valid.'),
                'errors'  => $errors,
            ], 422)];
        }

        if (GstConfiguration::isBeforeClosedPeriod($gst['gst_effective_from'] ?? null, $business)
            && ! GstConfiguration::mayBackdate(auth()->user())
        ) {
            return [null, response()->json([
                'message' => __(GstConfiguration::CLOSED_PERIOD_MESSAGE),
                'reason'  => 'closed_period',
                'errors'  => [
                    'gst_effective_from' => __(GstConfiguration::CLOSED_PERIOD_MESSAGE),
                ],
            ], 422)];
        }

        // §16: deviating from the shop type's convention is a decision someone
        // should be able to account for later.
        if (GstConfiguration::requiresReason($request->input('default_vat_type'), $business)
            && blank($request->input('gst_change_reason'))
        ) {
            return [null, response()->json([
                'message' => __('A reason is required when overriding the default for this shop type.'),
                'reason'  => 'reason_required',
                'shop_type_default' => $business?->category?->default_vat_type,
            ], 422)];
        }

        return [$gst, null];
    }

    /**
     * §20: records what a branch was created with.
     *
     * Including the method that was populated rather than typed — otherwise there is
     * no record of why a branch bills the way it does.
     */
    protected function recordBranchGstCreation(Branch $branch, array $gst, ?string $reason): void
    {
        GstConfigurationHistory::create([
            'business_id'       => $branch->business_id,
            'configurable_type' => Branch::class,
            'configurable_id'   => $branch->id,
            'field'             => 'default_vat_type',
            'old_value'         => null,
            'new_value'         => $gst['default_vat_type'],
            'reason'            => $reason ?? __('Populated from the shop type at branch creation.'),
            'effective_from'    => $gst['gst_effective_from'],
            'changed_by'        => auth()->id(),
        ]);
    }

    /**
     * Applies GST changes to an existing branch, recording each one.
     *
     * Returns an error response when the change is not permitted, and null when
     * there is nothing to object to — including when nothing GST-related was sent.
     */
    protected function applyBranchGstChanges(Request $request, Branch $branch): ?JsonResponse
    {
        $changes = $this->gstInput($request);

        if ($changes === []) {
            return null;
        }

        $business = $this->businessForGst();

        $newMethod = $changes['default_vat_type'] ?? null;
        $methodChanging = $newMethod !== null && $newMethod !== $branch->default_vat_type;

        // §10: when override is not allowed the populated method is read-only, and
        // only an administrator may change it.
        if ($methodChanging
            && ! BranchGstSetup::mayOverride(auth()->user(), (bool) $branch->allow_gst_override)
        ) {
            return response()->json([
                'message' => __(BranchGstSetup::NOT_AUTHORISED),
                'reason'  => 'override_not_allowed',
            ], 403);
        }

        // Validated against the resulting state, not the submitted fragment: an
        // update that leaves the branch registered without a GSTIN is just as
        // invalid as one that says so outright.
        $resulting = array_merge([
            'gst_registered'   => $branch->gst_registered,
            'gstin'            => $branch->gstin,
            'gst_state'        => $branch->gst_state,
            'default_vat_type' => $branch->default_vat_type,
        ], $changes);

        if ($errors = GstConfiguration::validate($resulting)) {
            return response()->json([
                'message' => __('The GST configuration is not valid.'),
                'errors'  => $errors,
            ], 422);
        }

        // §17: a change cannot reach back into a period already filed.
        if (GstConfiguration::isBeforeClosedPeriod($request->input('gst_effective_from'), $business)
            && ! GstConfiguration::mayBackdate(auth()->user())
        ) {
            return response()->json([
                'message' => __(GstConfiguration::CLOSED_PERIOD_MESSAGE),
                'reason'  => 'closed_period',
                'errors'  => [
                    'gst_effective_from' => __(GstConfiguration::CLOSED_PERIOD_MESSAGE),
                ],
            ], 422);
        }

        // §17: a change to the pricing method takes effect from a stated date.
        // Without one there is no answer to "which method was this invoice billed
        // under", which is the question a return is reconciled against.
        if ($methodChanging && blank($request->input('gst_effective_from'))) {
            return response()->json([
                'message' => __('An effective date is required when changing the GST pricing method.'),
                'reason'  => 'effective_date_required',
                'errors'  => [
                    'gst_effective_from' => __('An effective date is required when changing the GST pricing method.'),
                ],
            ], 422);
        }

        if ($methodChanging
            && GstConfiguration::requiresReason($newMethod, $business)
            && blank($request->input('gst_change_reason'))
        ) {
            return response()->json([
                'message' => __('A reason is required when overriding the default for this shop type.'),
                'reason'  => 'reason_required',
                'shop_type_default' => $business?->category?->default_vat_type,
            ], 422);
        }

        GstConfiguration::apply(
            $branch,
            $changes,
            auth()->id(),
            $request->input('gst_change_reason'),
            $request->input('gst_effective_from'),
        );

        return null;
    }
}
