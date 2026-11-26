<?php

namespace Tests\Feature\Api;

use App\Services\LoyaltyDiscount;
use App\Services\LoyaltyPeriod;
use App\Services\LoyaltyUpgrade;
use Tests\TestCase;

/**
 * The loyalty rules that decide what a customer pays.
 *
 * Every worked example printed in the specification is reproduced here as a test, so
 * the figures the business signed off are the figures the code produces. The rest
 * cover the boundaries the examples do not reach — the cases where a misreading
 * would give money away or refuse a discount someone paid for.
 */
class LoyaltyCalculationTest extends TestCase
{
    // ── §6: discount configuration ───────────────────────────────────────────

    /** §6.1 */
    public function test_a_percentage_discount_matches_the_specification(): void
    {
        $this->assertSame(
            100.0,
            LoyaltyDiscount::calculate(1000, LoyaltyDiscount::PERCENTAGE, 10),
        );
    }

    /** §6.2 */
    public function test_a_fixed_discount_matches_the_specification(): void
    {
        $this->assertSame(
            150.0,
            LoyaltyDiscount::calculate(1000, LoyaltyDiscount::FIXED, 150),
        );
    }

    /** §6.2: "shall not exceed the eligible cart amount". */
    public function test_a_fixed_discount_cannot_exceed_the_cart(): void
    {
        $this->assertSame(
            80.0,
            LoyaltyDiscount::calculate(80, LoyaltyDiscount::FIXED, 150),
        );
    }

    /** §6.3 */
    public function test_the_maximum_caps_the_discount(): void
    {
        $this->assertSame(
            200.0,
            LoyaltyDiscount::calculate(3000, LoyaltyDiscount::PERCENTAGE, 10, 200),
        );
    }

    /** §6.4: blank maximum means no ceiling, not a zero one. */
    public function test_no_maximum_means_the_full_discount_applies(): void
    {
        $this->assertSame(
            500.0,
            LoyaltyDiscount::calculate(5000, LoyaltyDiscount::PERCENTAGE, 10, null),
        );
    }

    /**
     * A configured zero is a real ceiling — the package simply grants nothing.
     * Treating it as "unset" would hand out a discount nobody configured.
     */
    public function test_a_zero_maximum_is_not_the_same_as_no_maximum(): void
    {
        $this->assertSame(
            0.0,
            LoyaltyDiscount::calculate(5000, LoyaltyDiscount::PERCENTAGE, 10, 0),
        );
    }

    public function test_a_percentage_over_one_hundred_cannot_pay_the_customer(): void
    {
        $this->assertSame(
            1000.0,
            LoyaltyDiscount::calculate(1000, LoyaltyDiscount::PERCENTAGE, 150),
        );
    }

    /** §11: "shall not result in a negative payable amount". */
    public function test_an_empty_or_negative_cart_earns_nothing(): void
    {
        $this->assertSame(0.0, LoyaltyDiscount::calculate(0, LoyaltyDiscount::PERCENTAGE, 10));
        $this->assertSame(0.0, LoyaltyDiscount::calculate(-50, LoyaltyDiscount::FIXED, 10));
    }

    // ── §8: where the discount is applied in the cart ────────────────────────

    /** §8.2 — the specification's own table. */
    public function test_the_cart_sequence_example_reproduces(): void
    {
        $afterRowDiscounts = 2000 - 200;

        $eligible = LoyaltyDiscount::eligibleAmount($afterRowDiscounts);
        $discount = LoyaltyDiscount::calculate($eligible, LoyaltyDiscount::PERCENTAGE, 10);

        $this->assertSame(1800.0, $eligible);
        $this->assertSame(180.0, $discount);
        $this->assertSame(1620.0, round($eligible - $discount, 2));
    }

    /** §8.3 — the maximum-discount table. */
    public function test_the_capped_cart_example_reproduces(): void
    {
        $discount = LoyaltyDiscount::calculate(5000, LoyaltyDiscount::PERCENTAGE, 10, 300);

        $this->assertSame(300.0, $discount);
        $this->assertSame(4700.0, round(5000 - $discount, 2));
    }

    /**
     * §8.1 step 4. The order is the whole point: 10% of the gross cart is not 10%
     * of the cart after row discounts, and charging the first gives away money the
     * shop never agreed to.
     */
    public function test_ineligible_lines_are_excluded_before_the_discount(): void
    {
        $eligible = LoyaltyDiscount::eligibleAmount(1800, ineligibleAmount: 300);

        $this->assertSame(1500.0, $eligible);
        $this->assertSame(
            150.0,
            LoyaltyDiscount::calculate($eligible, LoyaltyDiscount::PERCENTAGE, 10),
        );
    }

    public function test_a_cart_of_only_ineligible_items_earns_nothing(): void
    {
        $this->assertSame(0.0, LoyaltyDiscount::eligibleAmount(500, 500));
        $this->assertSame(0.0, LoyaltyDiscount::eligibleAmount(500, 900));
    }

    // ── §10: manual entry ────────────────────────────────────────────────────

    public function test_a_manual_discount_within_the_entitlement_is_accepted(): void
    {
        $this->assertNull(
            LoyaltyDiscount::rejectManual(50, 1000, 100),
        );
    }

    /** §10.1, first message. */
    public function test_a_manual_discount_over_the_entitlement_is_refused(): void
    {
        $message = LoyaltyDiscount::rejectManual(150, 1000, 100);

        $this->assertNotNull($message);
        $this->assertStringContainsString('maximum eligible discount', $message);
        $this->assertStringContainsString('100.00', $message);
    }

    /** §10.1, second message. */
    public function test_a_manual_discount_over_the_cart_is_refused(): void
    {
        $this->assertSame(
            'Loyalty discount cannot exceed the eligible cart amount.',
            LoyaltyDiscount::rejectManual(1200, 1000, 1000),
        );
    }

    /** §10.1, third message — it must name the date, not just the fact. */
    public function test_an_expired_package_refuses_with_its_expiry_date(): void
    {
        $message = LoyaltyDiscount::rejectManual(50, 1000, 100, expiredOn: '2026-07-01');

        $this->assertStringContainsString('expired on 2026-07-01', $message);
    }

    public function test_a_negative_manual_discount_is_refused(): void
    {
        $this->assertNotNull(LoyaltyDiscount::rejectManual(-1, 1000, 100));
    }

    // ── §3.3: dates ──────────────────────────────────────────────────────────

    /** Inclusive of the start day: 30 days from the 1st ends on the 30th. */
    public function test_a_day_based_package_ends_inclusively(): void
    {
        $this->assertSame(
            '2026-01-30',
            LoyaltyPeriod::endDate('2026-01-01', LoyaltyPeriod::DAYS, 30),
        );
    }

    public function test_a_month_based_package_ends_the_day_before_the_anniversary(): void
    {
        $this->assertSame(
            '2026-01-31',
            LoyaltyPeriod::endDate('2026-01-01', LoyaltyPeriod::MONTHS, 1),
        );
        $this->assertSame(
            '2026-06-14',
            LoyaltyPeriod::endDate('2026-03-15', LoyaltyPeriod::MONTHS, 3),
        );
    }

    /**
     * A month-end subscription has no anniversary to land on.
     *
     * 31 January plus one month has no 31 February, so the date clamps to the 28th.
     * Ending the term the day before that would charge a month and deliver three
     * days fewer than someone who subscribed on the 1st — so a clamped anniversary
     * is itself the last day of the term. The specification does not cover this, and
     * it is resolved in the customer's favour deliberately.
     */
    public function test_a_month_end_subscription_is_not_short_changed(): void
    {
        $this->assertSame(
            '2026-02-28',
            LoyaltyPeriod::endDate('2026-01-31', LoyaltyPeriod::MONTHS, 1),
        );
        $this->assertSame(
            '2026-02-28',
            LoyaltyPeriod::endDate('2026-01-29', LoyaltyPeriod::MONTHS, 1),
        );

        // And the ordinary case is unaffected: the term still ends the day before.
        $this->assertSame(
            '2026-03-27',
            LoyaltyPeriod::endDate('2026-02-28', LoyaltyPeriod::MONTHS, 1),
        );
    }

    public function test_a_year_long_package_ends_the_day_before(): void
    {
        $this->assertSame(
            '2026-12-31',
            LoyaltyPeriod::endDate('2026-01-01', LoyaltyPeriod::YEARS, 1),
        );
    }

    /** §3.3: valid until 23:59:59 on the end date. */
    public function test_a_package_is_valid_on_its_last_day(): void
    {
        $this->assertTrue(LoyaltyPeriod::isCurrentlyValid(
            LoyaltyPeriod::ACTIVE, '2026-01-01', '2026-01-30', '2026-01-30',
        ));
    }

    /** §3.3: expired from the next calendar day. */
    public function test_a_package_is_expired_the_day_after(): void
    {
        $this->assertFalse(LoyaltyPeriod::isCurrentlyValid(
            LoyaltyPeriod::ACTIVE, '2026-01-01', '2026-01-30', '2026-01-31',
        ));
        $this->assertTrue(LoyaltyPeriod::hasExpired('2026-01-30', '2026-01-31'));
        $this->assertFalse(LoyaltyPeriod::hasExpired('2026-01-30', '2026-01-30'));
    }

    /**
     * A cancelled or suspended package can still be inside its dates, and must not
     * grant anything (§3.2).
     */
    public function test_only_an_active_subscription_grants_a_discount(): void
    {
        foreach ([
            LoyaltyPeriod::CANCELLED,
            LoyaltyPeriod::SUSPENDED,
            LoyaltyPeriod::UPGRADED,
            LoyaltyPeriod::EXPIRED,
        ] as $status) {
            $this->assertFalse(
                LoyaltyPeriod::isCurrentlyValid($status, '2026-01-01', '2026-12-31', '2026-06-01'),
                "$status must not grant a discount",
            );
        }
    }

    public function test_remaining_days_counts_today(): void
    {
        $this->assertSame(1, LoyaltyPeriod::remainingDays('2026-01-30', '2026-01-30'));
        $this->assertSame(0, LoyaltyPeriod::remainingDays('2026-01-30', '2026-01-31'));
    }

    // ── §5: renewal ──────────────────────────────────────────────────────────

    /** §5.1: renewing early keeps the days already paid for. */
    public function test_an_early_renewal_starts_the_day_after_the_current_term(): void
    {
        $this->assertSame(
            '2026-02-01',
            LoyaltyPeriod::renewalStartDate('2026-01-31', '2026-01-15'),
        );
    }

    /** §5.2: after expiry there is no bridging of the gap. */
    public function test_a_late_renewal_starts_on_the_renewal_date(): void
    {
        $this->assertSame(
            '2026-03-10',
            LoyaltyPeriod::renewalStartDate('2026-01-31', '2026-03-10'),
        );
    }

    /** §5.1's second option, when the shop configures immediate restart. */
    public function test_an_early_renewal_can_start_immediately_instead(): void
    {
        $this->assertSame(
            '2026-01-15',
            LoyaltyPeriod::renewalStartDate('2026-01-31', '2026-01-15', extendFromEnd: false),
        );
    }

    // ── §4: upgrade ──────────────────────────────────────────────────────────

    /** §4.2 — the specification's worked example, to the paisa. */
    public function test_the_upgrade_example_reproduces(): void
    {
        $unused = LoyaltyUpgrade::unusedValue(100, 365, 100);

        $this->assertSame(27.40, $unused);
        $this->assertSame(222.60, LoyaltyUpgrade::amountPayable(250, $unused));
    }

    public function test_an_expired_package_carries_no_credit(): void
    {
        $this->assertSame(0.0, LoyaltyUpgrade::unusedValue(100, 365, 0));
    }

    /** Moving to something cheaper must not pay money out through this path. */
    public function test_an_upgrade_is_never_negative(): void
    {
        $this->assertSame(0.0, LoyaltyUpgrade::amountPayable(50, 90));
    }

    /** §4.1 */
    public function test_an_upgrade_must_move_up_the_hierarchy(): void
    {
        $this->assertTrue(LoyaltyUpgrade::isEligible(
            LoyaltyPeriod::ACTIVE, 1, 2, true, true,
        ));

        // Sideways is not an upgrade either.
        $this->assertFalse(LoyaltyUpgrade::isEligible(
            LoyaltyPeriod::ACTIVE, 2, 2, true, true,
        ));
        $this->assertFalse(LoyaltyUpgrade::isEligible(
            LoyaltyPeriod::ACTIVE, 3, 2, true, true,
        ));
    }

    public function test_an_inactive_package_cannot_be_upgraded_to(): void
    {
        $this->assertFalse(LoyaltyUpgrade::isEligible(
            LoyaltyPeriod::ACTIVE, 1, 2, false, true,
        ));
    }

    public function test_only_an_active_customer_can_upgrade(): void
    {
        $this->assertFalse(LoyaltyUpgrade::isEligible(
            LoyaltyPeriod::EXPIRED, 1, 2, true, true,
        ));
    }

    /** The till has to be able to say which rule stopped it. */
    public function test_a_refused_upgrade_explains_itself(): void
    {
        $this->assertStringContainsString(
            'higher package',
            LoyaltyUpgrade::rejectionReason(LoyaltyPeriod::ACTIVE, 3, 2, true, true),
        );
        $this->assertStringContainsString(
            'active package',
            LoyaltyUpgrade::rejectionReason(LoyaltyPeriod::EXPIRED, 1, 2, true, true),
        );
        $this->assertNull(
            LoyaltyUpgrade::rejectionReason(LoyaltyPeriod::ACTIVE, 1, 2, true, true),
        );
    }

    /** §4.3 */
    public function test_the_upgrade_effective_date_follows_the_chosen_method(): void
    {
        $this->assertSame('2026-06-01', LoyaltyUpgrade::effectiveDate(
            LoyaltyUpgrade::IMMEDIATE, '2026-06-01', '2026-12-31',
        ));
        $this->assertSame('2027-01-01', LoyaltyUpgrade::effectiveDate(
            LoyaltyUpgrade::AFTER_EXPIRY, '2026-06-01', '2026-12-31',
        ));
        $this->assertSame('2026-08-15', LoyaltyUpgrade::effectiveDate(
            LoyaltyUpgrade::ON_DATE, '2026-06-01', '2026-12-31', '2026-08-15',
        ));
    }
}
