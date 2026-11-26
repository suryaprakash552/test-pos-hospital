<?php

namespace Tests\Feature\Api;

use App\Services\LoyaltyDiscount;
use App\Services\LoyaltyReturnAdjustment;
use Tests\TestCase;

/**
 * Revising a loyalty discount when goods come back (§11).
 *
 * The failure this prevents costs real money and compounds: refund the full price of
 * an item while leaving the discount that was earned on it, and the customer is paid
 * twice for the same return. On a percentage package it happens on every return, in
 * the customer's favour, and nothing on the invoice would look wrong.
 */
class LoyaltyReturnTest extends TestCase
{
    // ── Automatic ────────────────────────────────────────────────────────────

    /** Half the cart back, half the discount back. */
    public function test_a_partial_return_reduces_the_discount_proportionally(): void
    {
        $revised = LoyaltyReturnAdjustment::revise(
            originalEligible: 1000,
            returnedAmount: 500,
            currentDiscount: 100,
            discountType: LoyaltyDiscount::PERCENTAGE,
            discountValue: 10,
        );

        $this->assertSame(500.0, $revised['eligible_amount']);
        $this->assertSame(50.0, $revised['discount_amount']);
        $this->assertSame(50.0, $revised['reversed']);
    }

    public function test_returning_everything_removes_the_discount(): void
    {
        $revised = LoyaltyReturnAdjustment::revise(
            originalEligible: 1000,
            returnedAmount: 1000,
            currentDiscount: 100,
            discountType: LoyaltyDiscount::PERCENTAGE,
            discountValue: 10,
        );

        $this->assertSame(0.0, $revised['eligible_amount']);
        $this->assertSame(0.0, $revised['discount_amount']);
        $this->assertSame(100.0, $revised['reversed']);
    }

    public function test_returning_nothing_changes_nothing(): void
    {
        $revised = LoyaltyReturnAdjustment::revise(
            originalEligible: 1000,
            returnedAmount: 0,
            currentDiscount: 100,
            discountType: LoyaltyDiscount::PERCENTAGE,
            discountValue: 10,
        );

        $this->assertSame(100.0, $revised['discount_amount']);
        $this->assertSame(0.0, $revised['reversed']);
    }

    /**
     * A fixed discount does not scale with the cart, but it still cannot exceed
     * what is left of it (§6.2).
     */
    public function test_a_fixed_discount_is_capped_by_the_remaining_cart(): void
    {
        $revised = LoyaltyReturnAdjustment::revise(
            originalEligible: 1000,
            returnedAmount: 900,
            currentDiscount: 150,
            discountType: LoyaltyDiscount::FIXED,
            discountValue: 150,
        );

        $this->assertSame(100.0, $revised['eligible_amount']);
        $this->assertSame(100.0, $revised['discount_amount']);
        $this->assertSame(50.0, $revised['reversed']);
    }

    /** §6.3: the ceiling still applies after the cart shrinks. */
    public function test_the_package_maximum_still_applies_after_a_return(): void
    {
        $revised = LoyaltyReturnAdjustment::revise(
            originalEligible: 5000,
            returnedAmount: 1000,
            currentDiscount: 300,
            discountType: LoyaltyDiscount::PERCENTAGE,
            discountValue: 10,
            maxDiscountAmount: 300,
        );

        // 10% of 4,000 is 400, but the package caps at 300 — so nothing reverses.
        $this->assertSame(300.0, $revised['discount_amount']);
        $this->assertSame(0.0, $revised['reversed']);
    }

    // ── Manual ───────────────────────────────────────────────────────────────

    /**
     * A manual figure was a person's judgement inside an entitlement. Recalculating
     * it upward would overrule a decision with someone's name against it.
     */
    public function test_a_manual_discount_is_not_raised_by_a_return(): void
    {
        $revised = LoyaltyReturnAdjustment::revise(
            originalEligible: 1000,
            returnedAmount: 100,
            currentDiscount: 40,       // a person chose 40, below the 100 entitlement
            discountType: LoyaltyDiscount::PERCENTAGE,
            discountValue: 10,
            isManual: true,
        );

        $this->assertSame(40.0, $revised['discount_amount']);
        $this->assertSame(0.0, $revised['reversed']);
    }

    /** But it is capped down once the smaller cart no longer supports it. */
    public function test_a_manual_discount_is_capped_down_when_it_no_longer_fits(): void
    {
        $revised = LoyaltyReturnAdjustment::revise(
            originalEligible: 1000,
            returnedAmount: 800,
            currentDiscount: 90,
            discountType: LoyaltyDiscount::PERCENTAGE,
            discountValue: 10,
            isManual: true,
        );

        // 10% of the remaining 200 is 20, so 70 comes back.
        $this->assertSame(20.0, $revised['discount_amount']);
        $this->assertSame(70.0, $revised['reversed']);
    }

    // ── Cancellation ─────────────────────────────────────────────────────────

    /** §11: nothing was sold, so nothing was earned. */
    public function test_cancelling_an_invoice_reverses_the_whole_discount(): void
    {
        $revised = LoyaltyReturnAdjustment::reverseAll(180);

        $this->assertSame(0.0, $revised['discount_amount']);
        $this->assertSame(180.0, $revised['reversed']);
    }

    // ── Boundaries ───────────────────────────────────────────────────────────

    /** A return can never earn the customer a bigger discount than they had. */
    public function test_a_return_never_increases_the_discount(): void
    {
        $revised = LoyaltyReturnAdjustment::revise(
            originalEligible: 1000,
            returnedAmount: 0,
            currentDiscount: 20,
            discountType: LoyaltyDiscount::PERCENTAGE,
            discountValue: 10,
            isManual: true,
        );

        $this->assertGreaterThanOrEqual(0, $revised['reversed']);
        $this->assertLessThanOrEqual(20.0, $revised['discount_amount']);
    }

    /** An over-stated return must not drive the eligible amount negative. */
    public function test_returning_more_than_the_cart_floors_at_zero(): void
    {
        $revised = LoyaltyReturnAdjustment::revise(
            originalEligible: 1000,
            returnedAmount: 5000,
            currentDiscount: 100,
            discountType: LoyaltyDiscount::PERCENTAGE,
            discountValue: 10,
        );

        $this->assertSame(0.0, $revised['eligible_amount']);
        $this->assertSame(0.0, $revised['discount_amount']);
    }
}
