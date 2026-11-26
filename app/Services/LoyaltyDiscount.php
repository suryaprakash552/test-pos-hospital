<?php

namespace App\Services;

/**
 * The cart-level discount a loyalty package grants (§6, §8).
 *
 * Applied *after* row-level discounts, on the eligible amount only — §8.1 fixes that
 * order, and it matters: 10% of the gross cart is not 10% of the cart after row
 * discounts, and charging the first would give away money the shop never agreed to.
 *
 * Every rule here bounds the discount downward. In order of application:
 *
 *   1. the configured percentage or fixed value
 *   2. the package's maximum, when one is configured (§6.3)
 *   3. the eligible amount itself (§6.2, §6.4)
 *
 * The last is absolute: a discount can never exceed what is being discounted, so the
 * payable amount can never go negative (§11).
 *
 * Nothing here reads the database. The configuration is passed in from the
 * subscription, which holds the values as they stood when it was sold — so a past
 * invoice recalculates to the same figure however the package master has changed
 * since (§11).
 */
class LoyaltyDiscount
{
    public const PERCENTAGE = 'percentage';
    public const FIXED = 'fixed';

    public const AUTOMATIC = 'automatic';
    public const MANUAL = 'manual';

    /**
     * The discount the package entitles this cart to.
     *
     * @param float      $eligibleAmount     cart total after row-level discounts,
     *                                       excluding ineligible items and charges
     * @param string     $discountType       [PERCENTAGE] or [FIXED]
     * @param float      $discountValue      percent, or an absolute amount
     * @param float|null $maxDiscountAmount  null means no ceiling (§6.4)
     */
    public static function calculate(
        float $eligibleAmount,
        string $discountType,
        float $discountValue,
        ?float $maxDiscountAmount = null,
    ): float {
        // A cart with nothing eligible in it earns nothing, and a negative total is
        // not a cart — either way there is nothing to discount.
        if ($eligibleAmount <= 0 || $discountValue <= 0) {
            return 0.0;
        }

        $discount = $discountType === self::PERCENTAGE
            // Capped at 100%: a misconfigured 150% would otherwise pay the customer
            // to shop here.
            ? $eligibleAmount * (min($discountValue, 100) / 100)
            : $discountValue;

        // §6.3. Null is not zero: "no maximum configured" means no ceiling, while a
        // configured 0 would mean the package grants nothing.
        if ($maxDiscountAmount !== null) {
            $discount = min($discount, max($maxDiscountAmount, 0));
        }

        // §6.2 and §6.4: never more than the amount being discounted.
        $discount = min($discount, $eligibleAmount);

        return round(max($discount, 0), 2);
    }

    /**
     * The largest discount a user may enter by hand (§10).
     *
     * The same figure as [calculate] — the manual mode does not grant a different
     * entitlement, it only withholds it until someone types a number.
     */
    public static function maximumEligible(
        float $eligibleAmount,
        string $discountType,
        float $discountValue,
        ?float $maxDiscountAmount = null,
    ): float {
        return self::calculate(
            $eligibleAmount,
            $discountType,
            $discountValue,
            $maxDiscountAmount,
        );
    }

    /**
     * Why a manually entered discount cannot be accepted, or null when it can.
     *
     * §10.1 fixes the wording, because the cashier has to be able to act on it: the
     * message names the figure that was exceeded rather than saying "invalid".
     *
     * @param string|null $expiredOn set when the package has lapsed, so the message
     *                               can say when rather than merely that
     */
    public static function rejectManual(
        float $entered,
        float $eligibleAmount,
        float $maximumEligible,
        ?string $expiredOn = null,
    ): ?string {
        if ($expiredOn !== null) {
            return "Loyalty discount cannot be applied because the customer's "
                . "package expired on {$expiredOn}.";
        }

        if ($entered < 0) {
            return 'Loyalty discount cannot be negative.';
        }

        if ($entered > $eligibleAmount) {
            return 'Loyalty discount cannot exceed the eligible cart amount.';
        }

        if ($entered > $maximumEligible) {
            return 'Entered loyalty discount exceeds the maximum eligible discount of '
                . number_format($maximumEligible, 2)
                . ' for the selected package.';
        }

        return null;
    }

    /**
     * The eligible base a package discount is calculated on (§8.1 steps 3–4).
     *
     * Taxes and delivery charges are excluded because they are not the shop's margin
     * to give away, and ineligible lines are excluded because §11 restricts loyalty
     * discounts to eligible products.
     *
     * @param float $subtotalAfterRowDiscounts step 3 of the sequence
     * @param float $ineligibleAmount          lines excluded by configuration
     */
    public static function eligibleAmount(
        float $subtotalAfterRowDiscounts,
        float $ineligibleAmount = 0,
    ): float {
        return round(max($subtotalAfterRowDiscounts - max($ineligibleAmount, 0), 0), 2);
    }

    public static function isValidType(?string $type): bool
    {
        return in_array($type, [self::PERCENTAGE, self::FIXED], true);
    }

    public static function isValidMode(?string $mode): bool
    {
        return in_array($mode, [self::AUTOMATIC, self::MANUAL], true);
    }
}
