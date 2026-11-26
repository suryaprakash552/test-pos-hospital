<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * When a loyalty package starts, ends, and stops counting (§3.3, §4.3, §5).
 *
 * The end date is inclusive: §3.3 says a package is valid until 23:59:59 on it and
 * expired from the next calendar day. That one detail decides whether a customer gets
 * their discount on the last day they paid for, so every check here compares whole
 * dates rather than timestamps — comparing "now" against a date would expire someone
 * at midnight-plus-one-second on a day they are still entitled to.
 */
class LoyaltyPeriod
{
    public const DAYS = 'days';
    public const MONTHS = 'months';
    public const YEARS = 'years';

    /**
     * §2.1: a term with explicit start and end dates rather than a length.
     *
     * A seasonal package — "valid for the festival period" — has an end date that
     * is the same calendar day for everyone, so it cannot be derived from when the
     * customer happened to buy it. The end date is supplied at subscription time
     * instead of computed.
     */
    public const FIXED = 'fixed';

    // §3.2
    public const ACTIVE = 'active';
    public const EXPIRED = 'expired';
    public const RENEWED = 'renewed';
    public const UPGRADED = 'upgraded';
    public const CANCELLED = 'cancelled';
    public const SUSPENDED = 'suspended';

    /**
     * The last day a package bought on [start] remains valid.
     *
     * Inclusive of the start day: a 30-day package bought on the 1st runs to the
     * 30th, not the 31st. Counting from the day after would quietly sell 31 days.
     */
    public static function endDate(string $start, string $validityType, int $period): string
    {
        $date = Carbon::parse($start)->startOfDay();

        if ($period <= 0) {
            return $date->toDateString();
        }

        if ($validityType === self::DAYS) {
            // Counted from the start day, which is itself day one.
            return $date->copy()->addDays($period)->subDay()->toDateString();
        }

        $anniversary = $validityType === self::YEARS
            ? $date->copy()->addYearsNoOverflow($period)
            : $date->copy()->addMonthsNoOverflow($period);

        // Normally the term runs to the day before the anniversary: 1 January plus
        // one month ends on 31 January, not 1 February.
        //
        // Unless the anniversary does not exist. A package bought on 31 January has
        // no 31 February to land on, so the date is clamped back to the 28th — and
        // subtracting another day there would end the term on the 27th, charging a
        // month and delivering three days less than someone who bought on the 1st.
        // When the day was clamped, that clamped date is already the last day of the
        // month and is therefore the end of the term.
        $wasClamped = $anniversary->day !== $date->day;

        return ($wasClamped ? $anniversary : $anniversary->subDay())->toDateString();
    }

    /**
     * Whether a subscription is live on [onDate] (defaults to today).
     *
     * Only an Active subscription counts. A Cancelled or Suspended one may still be
     * inside its dates, and must not grant a discount.
     */
    public static function isCurrentlyValid(
        string $status,
        string $startDate,
        string $endDate,
        ?string $onDate = null,
    ): bool {
        if ($status !== self::ACTIVE) {
            return false;
        }

        $day = Carbon::parse($onDate ?? Carbon::now())->startOfDay();

        return $day->betweenIncluded(
            Carbon::parse($startDate)->startOfDay(),
            Carbon::parse($endDate)->startOfDay(),
        );
    }

    /**
     * Whether the end date has passed — §3.3's "from the next calendar day".
     */
    public static function hasExpired(string $endDate, ?string $onDate = null): bool
    {
        return Carbon::parse($onDate ?? Carbon::now())->startOfDay()
            ->greaterThan(Carbon::parse($endDate)->startOfDay());
    }

    /** Whole days left, inclusive of today; 0 once expired. */
    public static function remainingDays(string $endDate, ?string $onDate = null): int
    {
        $day = Carbon::parse($onDate ?? Carbon::now())->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        return $day->greaterThan($end) ? 0 : $day->diffInDays($end) + 1;
    }

    /**
     * Where a renewal starts (§5).
     *
     * Renewing early extends rather than restarts — the customer keeps the days they
     * already paid for, so the new term begins the day after the old one ends.
     * Renewing after expiry starts from the renewal date, and §5.2 is explicit that
     * the gap in between earns no benefit.
     *
     * @param bool $extendFromEnd false starts the renewed term immediately even when
     *                            the current one is still running (§5.1)
     */
    public static function renewalStartDate(
        string $currentEndDate,
        string $renewalDate,
        bool $extendFromEnd = true,
    ): string {
        if (! $extendFromEnd || self::hasExpired($currentEndDate, $renewalDate)) {
            return Carbon::parse($renewalDate)->startOfDay()->toDateString();
        }

        return Carbon::parse($currentEndDate)->startOfDay()->addDay()->toDateString();
    }

    public static function isValidValidityType(?string $type): bool
    {
        return in_array($type, [self::DAYS, self::MONTHS, self::YEARS, self::FIXED], true);
    }

    /** Whether this term's end date must be supplied rather than calculated. */
    public static function needsExplicitEndDate(?string $validityType): bool
    {
        return $validityType === self::FIXED;
    }

    /** @return array<int, string> */
    public static function statuses(): array
    {
        return [
            self::ACTIVE,
            self::EXPIRED,
            self::RENEWED,
            self::UPGRADED,
            self::CANCELLED,
            self::SUSPENDED,
        ];
    }
}
