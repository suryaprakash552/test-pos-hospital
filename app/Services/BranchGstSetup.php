<?php

namespace App\Services;

use App\Constants\UserRoles;
use App\Models\Business;

/**
 * The GST configuration a branch is created with, and the rules for changing it.
 *
 * §4 is the whole point: the user picks a shop type and the branch's GST pricing
 * method follows from it, rather than being keyed in again per branch and drifting.
 * A branch is the unit a GST registration belongs to, so its own configuration wins
 * over the organisation's (§23).
 *
 * The rules that decide *what* a method means live in [GstPricingMethod] and
 * [GstConfiguration]; this only decides what a branch starts with and who may
 * change it.
 */
class BranchGstSetup
{
    /** §18, verbatim, because it is what the user is shown. */
    public const NO_SHOP_TYPE_DEFAULT =
        'Default GST configuration is not available for the selected shop type.';

    public const NOT_AUTHORISED =
        'You are not authorised to override the default GST pricing method.';

    /**
     * The GST attributes a new branch should be created with.
     *
     * Anything the user supplied wins; anything omitted is inherited. An omitted
     * method is the normal case — the user picked a shop type and expects the
     * system to know what that implies.
     *
     * @param  array<string, mixed> $input   the request's GST fields, if any
     * @return array<string, mixed>          attributes to persist
     */
    public static function defaultsFor(?Business $business, array $input): array
    {
        $registered = array_key_exists('gst_registered', $input)
            ? $input['gst_registered']
            : $business?->gst_registered;

        $method = $input['default_vat_type'] ?? null;

        if ($registered === false) {
            // §6: an unregistered branch charges no GST, whatever the shop type says.
            // Overriding a supplied method is deliberate — the two together are a
            // contradiction, and the registration flag is the one the user just set.
            $method = GstPricingMethod::NOT_APPLICABLE;
        } elseif (! GstPricingMethod::isValid($method)) {
            // §4: populate from the shop type, walking the hierarchy §23 describes.
            $method = self::configuredMethod($business);

            // Only an unregistered branch gets the fallback. For a registered one the
            // method appears on a legal document, so an absent configuration is left
            // absent here and refused by [shopTypeDefaultMissing] rather than guessed.
            if ($method === null && $registered !== true) {
                $method = GstPricingMethod::FALLBACK;
            }
        }

        return [
            'gst_registered'     => $registered,
            'gstin'              => $input['gstin'] ?? null,
            'gst_state'          => $input['gst_state'] ?? ($registered === true ? $business?->gst_state : null),
            'place_of_supply'    => $input['place_of_supply'] ?? $business?->place_of_supply,
            'default_vat_type'   => $method,
            // §10: unless told otherwise a branch may be corrected later. Defaulting
            // this to false would lock every branch at creation, and the only way out
            // would be a database edit.
            'allow_gst_override' => $input['allow_gst_override'] ?? true,
            'gst_effective_from' => $input['gst_effective_from'] ?? null,
        ];
    }

    /**
     * The method configured anywhere above a branch, or null when there is none.
     *
     * Distinct from [GstConfiguration::resolve], which always answers: here the
     * difference between "configured as X" and "nothing configured" is the whole
     * question.
     */
    public static function configuredMethod(?Business $business): ?string
    {
        if ($business === null) {
            return null;
        }

        if (GstPricingMethod::isValid($business->default_vat_type)) {
            return $business->default_vat_type;
        }

        $shopType = $business->relationLoaded('category')
            ? $business->category?->default_vat_type
            : $business->category()->value('default_vat_type');

        return GstPricingMethod::isValid($shopType) ? $shopType : null;
    }

    /**
     * §17: a registered branch cannot be created without a resolvable method.
     *
     * Only registered branches are stopped. Applying this to everyone would refuse to
     * create a branch for any shop that has never configured GST, which is most of
     * them, and "no GST" is a complete answer for an unregistered branch.
     */
    public static function shopTypeDefaultMissing(?Business $business, array $attributes): bool
    {
        if (($attributes['gst_registered'] ?? null) !== true) {
            return false;
        }

        if (GstPricingMethod::isValid($attributes['default_vat_type'] ?? null)) {
            return false;
        }

        return true;
    }

    /**
     * §19: the permission that allows changing a locked branch's pricing method.
     *
     * Separate from editing the rest of the GST configuration, because the method is
     * the part that changes what every subsequent invoice looks like.
     */
    public const OVERRIDE_PERMISSION = 'gst.override';

    /**
     * Whether this user may set a method that deviates from the shop type's default.
     *
     * §10: when override is not allowed the populated method is read-only and only an
     * administrator may change it. §19 makes "administrator" configurable rather than
     * a fixed role, so a shop can delegate it without handing over the owner account.
     */
    public static function mayOverride(?object $user, bool $overrideAllowed): bool
    {
        if ($overrideAllowed) {
            return true;
        }

        if (in_array($user?->role, [UserRoles::SHOP_OWNER, 'owner'], true)) {
            return true;
        }

        return method_exists($user, 'hasPermission')
            && $user->hasPermission(self::OVERRIDE_PERMISSION);
    }

    /**
     * The message §16 requires when a shop type is selected.
     *
     * Returns null when the shop type has no default, because there is then nothing
     * to tell the user.
     */
    public static function shopTypeNotice(?Business $business): ?string
    {
        $shopType = $business?->relationLoaded('category')
            ? $business->category?->name
            : $business?->category()->value('name');

        $default = $business?->relationLoaded('category')
            ? $business->category?->default_vat_type
            : $business?->category()->value('default_vat_type');

        if (blank($shopType) || ! GstPricingMethod::isValid($default)) {
            return null;
        }

        return __('The default GST pricing method for :shopType is :method.', [
            'shopType' => $shopType,
            'method'   => GstPricingMethod::label($default),
        ]);
    }
}
