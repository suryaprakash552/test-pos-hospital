<?php

namespace App\Services;

/**
 * What a customer pays to move up a package (§4).
 *
 * The customer already paid for days they have not used, so charging the full price
 * of the new package would bill them twice for the same period. §4.2 credits that
 * unused value against the new cost.
 *
 * Worked example from §4.2, which these rules reproduce exactly:
 *
 *   old cost 100 over 365 days, 100 days remaining
 *     → unused value  100 × 100 / 365 = 27.40
 *     → new cost 250 − 27.40          = 222.60
 */
class LoyaltyUpgrade
{
    // §4.3
    public const IMMEDIATE = 'immediate';
    public const AFTER_EXPIRY = 'after_expiry';
    public const ON_DATE = 'on_date';

    /**
     * The value of the days the customer has paid for but not yet used.
     *
     * Pro-rata on whole days, because that is the unit the package was sold in.
     * A package with no term left is worth nothing, never a negative credit.
     */
    public static function unusedValue(
        float $currentCost,
        int $totalDays,
        int $remainingDays,
    ): float {
        if ($currentCost <= 0 || $totalDays <= 0 || $remainingDays <= 0) {
            return 0.0;
        }

        // Cannot credit more than was paid, however the dates were edited.
        $remaining = min($remainingDays, $totalDays);

        return round($currentCost * ($remaining / $totalDays), 2);
    }

    /**
     * What the customer pays to upgrade.
     *
     * Never negative: moving to a cheaper package is not an upgrade and must not
     * produce a refund through this path. §4.1 sends downgrades elsewhere precisely
     * so that money moving outward is a deliberate, separately authorised act.
     */
    public static function amountPayable(float $targetCost, float $unusedValue): float
    {
        return round(max($targetCost - $unusedValue, 0), 2);
    }

    /**
     * Whether this customer may move from one package to the other (§4.1).
     *
     * Priority is what defines the hierarchy, so equal priority is refused too —
     * a sideways move is not an upgrade, and allowing it would let a customer swap
     * benefits without paying the difference.
     */
    public static function isEligible(
        string $currentStatus,
        int $currentPriority,
        int $targetPriority,
        bool $targetIsActive,
        bool $targetAllowsUpgrade,
    ): bool {
        return $currentStatus === LoyaltyPeriod::ACTIVE
            && $targetIsActive
            && $targetAllowsUpgrade
            && $targetPriority > $currentPriority;
    }

    /**
     * Why an upgrade was refused, or null when it is allowed.
     *
     * Separate from [isEligible] so the till can say which rule stopped it rather
     * than only that something did.
     */
    public static function rejectionReason(
        string $currentStatus,
        int $currentPriority,
        int $targetPriority,
        bool $targetIsActive,
        bool $targetAllowsUpgrade,
    ): ?string {
        if ($currentStatus !== LoyaltyPeriod::ACTIVE) {
            return 'Only a customer with an active package can upgrade.';
        }

        if (! $targetIsActive) {
            return 'That package is not available for selection.';
        }

        if (! $targetAllowsUpgrade) {
            return 'That package cannot be upgraded to.';
        }

        if ($targetPriority <= $currentPriority) {
            return 'An upgrade must move to a higher package. '
                . 'Downgrades are handled separately.';
        }

        return null;
    }

    /**
     * When the new package starts (§4.3).
     *
     * @param string      $currentEndDate end of the package being replaced
     * @param string|null $chosenDate     required by [ON_DATE]
     */
    public static function effectiveDate(
        string $method,
        string $upgradeDate,
        string $currentEndDate,
        ?string $chosenDate = null,
    ): string {
        return match ($method) {
            self::AFTER_EXPIRY => \Illuminate\Support\Carbon::parse($currentEndDate)
                ->startOfDay()->addDay()->toDateString(),
            self::ON_DATE => \Illuminate\Support\Carbon::parse($chosenDate ?? $upgradeDate)
                ->startOfDay()->toDateString(),
            default => \Illuminate\Support\Carbon::parse($upgradeDate)
                ->startOfDay()->toDateString(),
        };
    }

    public static function isValidEffectiveMethod(?string $method): bool
    {
        return in_array($method, [self::IMMEDIATE, self::AFTER_EXPIRY, self::ON_DATE], true);
    }
}
