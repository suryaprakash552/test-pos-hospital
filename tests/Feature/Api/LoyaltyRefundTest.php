<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\LoyaltyAudit;
use App\Models\LoyaltyPackage;
use App\Models\LoyaltySubscription;
use App\Models\User;
use App\Services\LoyaltyDiscount;
use App\Services\LoyaltyPeriod;
use App\Services\LoyaltySubscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Returning money paid for a loyalty package.
 *
 * The amount is the shop's decision — full, pro-rata, or nothing, depending on a
 * commercial policy this system does not hold. What it enforces is arithmetic: a
 * shop cannot pay back more than it took, cannot refund the same term twice, and
 * cannot leave a refunded customer still holding the discount.
 */
class LoyaltyRefundTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $owner;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name'        => 'Refunded customer',
            'phone'       => '9800000001',
        ]);

        $this->actingAs($this->owner);
    }

    private function subscription(float $paid = 1000, int $days = 365): LoyaltySubscription
    {
        $package = LoyaltyPackage::create([
            'business_id'     => $this->business->id,
            'code'            => 'R' . uniqid(),
            'name'            => 'Refundable',
            'cost'            => $paid,
            'validity_type'   => LoyaltyPeriod::DAYS,
            'validity_period' => $days,
            'discount_type'   => LoyaltyDiscount::PERCENTAGE,
            'discount_value'  => 10,
            'discount_mode'   => LoyaltyDiscount::AUTOMATIC,
            'priority'        => 1,
        ]);

        return LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $package,
            ['subscription_date' => now()->toDateString(), 'amount_received' => $paid],
        );
    }

    private function refund(LoyaltySubscription $s, array $payload)
    {
        return $this->postJson(
            "/api/v1/loyalty-subscriptions/{$s->id}/refund",
            $payload,
        );
    }

    public function test_a_refund_is_recorded_against_the_subscription(): void
    {
        $s = $this->subscription(1000);

        $this->refund($s, ['amount' => 400, 'reason' => 'Customer moved away'])
            ->assertOk();

        $s->refresh();

        $this->assertEquals(400, $s->refund_amount);
        $this->assertSame('Customer moved away', $s->refund_reason);
        $this->assertSame($this->owner->id, $s->refunded_by);
        $this->assertNotNull($s->refunded_at);
    }

    /**
     * A refunded customer must not keep the benefit. Leaving the term active would
     * let someone be paid back and still be discounted on the next sale.
     */
    public function test_a_refund_ends_the_entitlement(): void
    {
        $s = $this->subscription(1000);

        $this->refund($s, ['amount' => 1000, 'reason' => 'Full refund'])->assertOk();

        $this->assertSame(LoyaltyPeriod::CANCELLED, $s->fresh()->status);
        $this->assertFalse($s->fresh()->isCurrentlyValid());
    }

    /** The shop cannot pay back more than it took. */
    public function test_a_refund_cannot_exceed_what_was_received(): void
    {
        $s = $this->subscription(1000);

        $this->refund($s, ['amount' => 1000.01, 'reason' => 'Too much'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertNull($s->fresh()->refund_amount);
    }

    /** Two partial refunds may not together exceed what was received. */
    public function test_refunds_accumulate_against_the_same_ceiling(): void
    {
        $s = $this->subscription(1000);

        $this->refund($s, ['amount' => 600, 'reason' => 'First part'])->assertOk();

        $this->refund($s, ['amount' => 500, 'reason' => 'Second part'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->refund($s, ['amount' => 400, 'reason' => 'Second part'])->assertOk();

        $this->assertEquals(1000, $s->fresh()->refund_amount);
    }

    /** A fully refunded package has nothing left to give back. */
    public function test_a_fully_refunded_package_is_refused(): void
    {
        $s = $this->subscription(1000);

        $this->refund($s, ['amount' => 1000, 'reason' => 'All of it'])->assertOk();

        $this->refund($s, ['amount' => 1, 'reason' => 'Again'])
            ->assertStatus(422);

        $this->assertEquals(1000, $s->fresh()->refund_amount);
    }

    /** Money moving outward is always explained. */
    public function test_a_reason_is_required(): void
    {
        $s = $this->subscription(1000);

        $this->refund($s, ['amount' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_refund_is_audited(): void
    {
        $s = $this->subscription(1000);

        $this->refund($s, ['amount' => 250, 'reason' => 'Goodwill'])->assertOk();

        $this->assertDatabaseHas('loyalty_audits', [
            'event'       => LoyaltyAudit::REFUNDED,
            'customer_id' => $this->customer->id,
            'reason'      => 'Goodwill',
        ]);
    }

    /** Refunds are separately authorised — it is money leaving the business. */
    public function test_it_requires_the_refund_permission(): void
    {
        $s = $this->subscription(1000);

        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'staff',
        ]);

        $this->actingAs($staff)
            ->postJson("/api/v1/loyalty-subscriptions/{$s->id}/refund", [
                'amount' => 100,
                'reason' => 'Not mine to give',
            ])
            ->assertForbidden();

        $this->assertNull($s->fresh()->refund_amount);
    }

    public function test_another_businesses_subscription_cannot_be_refunded(): void
    {
        $s = $this->subscription(1000);

        $outsider = User::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'role'        => 'shop-owner',
        ]);

        $this->actingAs($outsider)
            ->postJson("/api/v1/loyalty-subscriptions/{$s->id}/refund", [
                'amount' => 100,
                'reason' => 'Not my shop',
            ])
            ->assertNotFound();
    }

    // ── The quote ────────────────────────────────────────────────────────────

    /**
     * The suggestion is the unused pro-rata value, the same figure an upgrade
     * credits. It is offered, never applied.
     */
    public function test_the_quote_suggests_the_unused_value(): void
    {
        $s = $this->subscription(1000, days: 100);

        $response = $this->getJson(
            "/api/v1/loyalty-subscriptions/{$s->id}/refund-quote",
        )->assertOk();

        $this->assertEquals(1000, $response->json('data.amount_received'));
        $this->assertEquals(0, $response->json('data.already_refunded'));
        $this->assertEquals(1000, $response->json('data.refundable'));

        // Bought today for 100 days, so almost all of it is unused.
        $this->assertGreaterThan(900, $response->json('data.suggested_refund'));
        $this->assertLessThanOrEqual(1000, $response->json('data.suggested_refund'));
    }

    /** Asking for a quote must not move any money. */
    public function test_the_quote_changes_nothing(): void
    {
        $s = $this->subscription(1000);

        $this->getJson("/api/v1/loyalty-subscriptions/{$s->id}/refund-quote")
            ->assertOk();

        $this->assertNull($s->fresh()->refund_amount);
        $this->assertSame(LoyaltyPeriod::ACTIVE, $s->fresh()->status);
    }

    /** The suggestion never exceeds what is actually left to refund. */
    public function test_the_suggestion_respects_an_earlier_refund(): void
    {
        $s = $this->subscription(1000, days: 100);

        $this->refund($s, ['amount' => 900, 'reason' => 'Most of it'])->assertOk();

        $response = $this->getJson(
            "/api/v1/loyalty-subscriptions/{$s->id}/refund-quote",
        )->assertOk();

        $this->assertEquals(100, $response->json('data.refundable'));
        $this->assertLessThanOrEqual(100, $response->json('data.suggested_refund'));
    }

    // ── The money report ─────────────────────────────────────────────────────

    /** §12.5: what was refunded reduces what the shop kept. */
    public function test_the_money_report_nets_off_refunds(): void
    {
        $s = $this->subscription(1000);

        $this->refund($s, ['amount' => 300, 'reason' => 'Partial'])->assertOk();

        $response = $this->getJson('/api/v1/loyalty-reports/amount-received')
            ->assertOk();

        $this->assertEquals(300, $response->json('data.refund_amount'));
        $this->assertEquals(700, $response->json('data.net_amount_received'));
    }
}
