<?php

namespace App\Services;

use App\Models\Business;

/**
 * Resolves the tax mode a new product should start with.
 *
 * The convention follows the trade — a pharmacy prices inclusive of tax because the
 * shelf price is what the customer pays, a wholesaler quotes exclusive and adds tax on
 * the invoice — so it is recorded on the shop type and inherited by the business.
 *
 * Resolution order, most specific first:
 *   1. the business's own setting, if the owner overrode it
 *   2. the shop type it was created under
 *   3. 'exclusive', which is what every product defaulted to before this existed
 *
 * Step 3 is what keeps existing shops behaving exactly as they did.
 */
class DefaultVatType
{
    public const INCLUSIVE = 'inclusive';
    public const EXCLUSIVE = 'exclusive';

    /** The behaviour before a default was configurable. */
    public const FALLBACK = self::EXCLUSIVE;

    public static function options(): array
    {
        return [self::INCLUSIVE, self::EXCLUSIVE];
    }

    public static function isValid(?string $value): bool
    {
        return $value !== null && in_array($value, self::options(), true);
    }

    /**
     * The mode this business should use, never null.
     */
    public static function forBusiness(?Business $business): string
    {
        // Delegates so there is one resolution order. This entry point predates
        // branch-level configuration and the third "not applicable" state, and is
        // kept because existing callers read naturally; GstConfiguration is the
        // fuller one to reach for in new code.
        return GstConfiguration::resolve(null, null, $business);
    }

    /**
     * Convenience for request handling: use what the client sent when it is a valid
     * choice, otherwise fall back to the business default.
     */
    public static function resolve(?string $requested, ?Business $business): string
    {
        return GstConfiguration::resolve($requested, null, $business);
    }
}
