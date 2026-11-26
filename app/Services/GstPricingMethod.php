<?php

namespace App\Services;

/**
 * How a price relates to the tax on it.
 *
 * Extends the two-value `DefaultVatType` with the third state a non-registered shop
 * needs: GST does not apply at all, which is different from a zero rate — a zero-rated
 * item is still a taxable supply and appears on a return, an out-of-scope one does not.
 *
 * The stored strings match `products.vat_type`, so a product row and a branch setting
 * mean the same thing without translation.
 */
class GstPricingMethod
{
    /** The entered price already contains the tax. */
    public const INCLUDED = 'inclusive';

    /** Tax is added on top of the entered price. */
    public const EXCLUDED = 'exclusive';

    /** No tax is calculated. Not the same as a zero rate. */
    public const NOT_APPLICABLE = 'not_applicable';

    /**
     * What every product defaulted to before any of this was configurable.
     * Keeping it as the last resort is what makes existing shops behave unchanged.
     */
    public const FALLBACK = self::EXCLUDED;

    public static function all(): array
    {
        return [self::INCLUDED, self::EXCLUDED, self::NOT_APPLICABLE];
    }

    public static function isValid(?string $value): bool
    {
        return $value !== null && in_array($value, self::all(), true);
    }

    /** Whether tax should be calculated at all. */
    public static function chargesTax(?string $method): bool
    {
        return $method === self::INCLUDED || $method === self::EXCLUDED;
    }

    public static function label(string $method): string
    {
        return match ($method) {
            self::INCLUDED => 'GST Included',
            self::EXCLUDED => 'GST Excluded',
            self::NOT_APPLICABLE => 'GST Not Applicable',
            default => $method,
        };
    }

    /**
     * Splits an amount into its taxable value and its tax, according to the method.
     *
     * The two directions are not symmetrical, which is the usual source of a bill that
     * does not add up:
     *
     *   included → the tax is *inside* the amount, so it is extracted:
     *              taxable = amount x 100 / (100 + rate)
     *   excluded → the amount *is* the taxable value, so tax is added on top.
     *
     * The caller passes the amount **after discount**: tax follows the discount, so
     * calculating it on the gross would overstate the liability.
     *
     * @return array{taxable: float, tax: float, total: float}
     */
    public static function split(float $amount, float $rate, string $method): array
    {
        if (! self::chargesTax($method) || $rate <= 0) {
            return [
                'taxable' => round($amount, 2),
                'tax'     => 0.0,
                'total'   => round($amount, 2),
            ];
        }

        if ($method === self::INCLUDED) {
            $taxable = $amount * 100 / (100 + $rate);

            return [
                'taxable' => round($taxable, 2),
                'tax'     => round($amount - $taxable, 2),
                // Unchanged: an inclusive price is what the customer already pays.
                'total'   => round($amount, 2),
            ];
        }

        $tax = $amount * $rate / 100;

        return [
            'taxable' => round($amount, 2),
            'tax'     => round($tax, 2),
            'total'   => round($amount + $tax, 2),
        ];
    }
}
