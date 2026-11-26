<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\LoyaltyPackage;
use App\Models\LoyaltySubscription;
use App\Services\LoyaltyDiscount;
use App\Services\LoyaltyPeriod;
use App\Services\LoyaltySubscriptionManager;
use App\Services\LoyaltyUpgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The loyalty subscription lifecycle end to end (§3, §4, §5, §11).
 *
 * The rules that carry money are the ones covered hardest: a customer holding two
 * active packages would be discounted twice, and a renewal or upgrade that erased
 * the previous term would leave the reports in §12 unable to say what was collected.
 */
class LoyaltySubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name'        => 'Walk-in',
            'phone'       => '9999900000',
        ]);
    }

    private function package(array $overrides = []): LoyaltyPackage
    {
        static $n = 0;
        $n++;

        return LoyaltyPackage::create($overrides + [
            'business_id'     => $this->business->id,
            'code'            => 'PKG' . $n,
            'name'            => 'Package ' . $n,
            'cost'            => 100,
            'validity_type'   => LoyaltyPeriod::DAYS,
            'validity_period' => 365,
            'discount_type'   => LoyaltyDiscount::PERCENTAGE,
            'discount_value'  => 10,
            'discount_mode'   => LoyaltyDiscount::AUTOMATIC,
            'priority'        => $n,
            'status'          => true,
        ]);
    }

    // ── §3: subscribing ──────────────────────────────────────────────────────

    public function test_a_customer_starts_with_no_package(): void
    {
        // §1: joining is an act, not a default.
        $this->assertNull(LoyaltySubscriptionManager::activeFor($this->customer->id));
    }

    public function test_a_subscription_runs_from_its_start_date(): void
    {
        $subscription = LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['validity_period' => 30]),
            ['subscription_date' => '2026-01-01'],
        );

        $this->assertSame('2026-01-01', $subscription->start_date->toDateString());
        $this->assertSame('2026-01-30', $subscription->end_date->toDateString());
        $this->assertSame(LoyaltyPeriod::ACTIVE, $subscription->status);
    }

    /** §11: one active package at a time, or the discount applies twice. */
    public function test_a_second_active_package_is_refused(): void
    {
        LoyaltySubscriptionManager::subscribe($this->customer->id, $this->package());

        $this->expectException(\RuntimeException::class);
        LoyaltySubscriptionManager::subscribe($this->customer->id, $this->package());
    }

    /** §2.2: an inactive package cannot be sold to anyone new. */
    public function test_an_inactive_package_cannot_be_sold(): void
    {
        $this->expectException(\RuntimeException::class);

        LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['status' => false]),
        );
    }

    public function test_a_package_outside_its_window_cannot_be_sold(): void
    {
        $this->expectException(\RuntimeException::class);

        LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['effective_start_date' => '2027-01-01']),
            ['subscription_date' => '2026-06-01'],
        );
    }

    /**
     * §11: the terms travel with the subscription, so editing the master later
     * cannot re-price a customer who already bought.
     */
    public function test_the_terms_are_captured_at_the_point_of_sale(): void
    {
        $package = $this->package(['discount_value' => 10]);

        $subscription = LoyaltySubscriptionManager::subscribe($this->customer->id, $package);

        $package->update(['discount_value' => 50]);

        $this->assertEquals(10, $subscription->fresh()->discount_value);
        $this->assertSame(
            100.0,
            $subscription->fresh()->discountFor(1000),
            'an existing customer keeps the deal they bought',
        );
    }

    // ── §7: expiry ───────────────────────────────────────────────────────────

    public function test_an_expired_subscription_grants_nothing(): void
    {
        $subscription = LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['validity_period' => 30]),
            ['subscription_date' => '2026-01-01'],
        );

        $this->assertSame(100.0, $subscription->discountFor(1000, '2026-01-30'));
        $this->assertSame(0.0, $subscription->discountFor(1000, '2026-01-31'));
    }

    /** A cancelled term can still be inside its dates and must not pay out. */
    public function test_a_cancelled_subscription_grants_nothing(): void
    {
        $subscription = LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(),
        );

        $subscription->update(['status' => LoyaltyPeriod::CANCELLED]);

        $this->assertSame(0.0, $subscription->fresh()->discountFor(1000));
    }

    public function test_lapsed_subscriptions_can_be_marked_expired(): void
    {
        LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['validity_period' => 1]),
            ['subscription_date' => '2026-01-01'],
        );

        $marked = LoyaltySubscriptionManager::expireLapsed($this->business->id, '2026-02-01');

        $this->assertSame(1, $marked);
        $this->assertSame(
            LoyaltyPeriod::EXPIRED,
            LoyaltySubscription::latest('id')->first()->status,
        );
    }

    // ── §5: renewal ──────────────────────────────────────────────────────────

    /** §5.1: renewing early keeps the days already paid for. */
    public function test_an_early_renewal_extends_from_the_current_end(): void
    {
        $original = LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['validity_period' => 30]),
            ['subscription_date' => '2026-01-01'],
        );

        $renewed = LoyaltySubscriptionManager::renew(
            $original,
            ['subscription_date' => '2026-01-20'],
        );

        $this->assertSame('2026-01-31', $renewed->start_date->toDateString());
        $this->assertSame('2026-03-01', $renewed->end_date->toDateString());
    }

    /** §11: the previous term is marked, never removed. */
    public function test_a_renewal_preserves_the_previous_term(): void
    {
        $original = LoyaltySubscriptionManager::subscribe(
            $this->customer->id, $this->package(),
        );

        $renewed = LoyaltySubscriptionManager::renew($original);

        $this->assertSame(LoyaltyPeriod::RENEWED, $original->fresh()->status);
        $this->assertSame($original->id, $renewed->previous_subscription_id);
        $this->assertSame('renewal', $renewed->origin);
        $this->assertDatabaseCount('loyalty_subscriptions', 2);
    }

    /** §5.2: after expiry the gap earns nothing and the term starts fresh. */
    public function test_a_late_renewal_starts_on_the_renewal_date(): void
    {
        $original = LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['validity_period' => 30]),
            ['subscription_date' => '2026-01-01'],
        );

        $renewed = LoyaltySubscriptionManager::renew(
            $original,
            ['subscription_date' => '2026-03-15'],
        );

        $this->assertSame('2026-03-15', $renewed->start_date->toDateString());
    }

    public function test_a_package_that_forbids_renewal_is_refused(): void
    {
        $original = LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['renewal_allowed' => false]),
        );

        $this->expectException(\RuntimeException::class);
        LoyaltySubscriptionManager::renew($original);
    }

    // ── §4: upgrade ──────────────────────────────────────────────────────────

    /** §4.2's worked example, driven through the real lifecycle. */
    public function test_an_upgrade_credits_the_unused_term(): void
    {
        $silver = $this->package(['cost' => 100, 'validity_period' => 365, 'priority' => 1]);
        $gold   = $this->package(['cost' => 250, 'validity_period' => 365, 'priority' => 2]);

        $current = LoyaltySubscriptionManager::subscribe(
            $this->customer->id, $silver, ['subscription_date' => '2026-01-01'],
        );

        // The term runs 2026-01-01 .. 2026-12-31; on 23 September exactly 100 of
        // its 365 days remain, which is the specification's example.
        $upgraded = LoyaltySubscriptionManager::upgrade(
            $current, $gold, ['subscription_date' => '2026-09-23'],
        );

        $this->assertEquals(27.40, $upgraded->adjustment_amount);
        $this->assertEquals(222.60, $upgraded->amount_received);
    }

    public function test_an_upgrade_closes_the_previous_term(): void
    {
        $silver = $this->package(['priority' => 1]);
        $gold   = $this->package(['priority' => 2]);

        $current  = LoyaltySubscriptionManager::subscribe($this->customer->id, $silver);
        $upgraded = LoyaltySubscriptionManager::upgrade($current, $gold);

        $this->assertSame(LoyaltyPeriod::UPGRADED, $current->fresh()->status);
        $this->assertSame('upgrade', $upgraded->origin);
        $this->assertSame($gold->id, $upgraded->loyalty_package_id);

        // §11 again: exactly one active package survives the move.
        $this->assertSame(
            $upgraded->id,
            LoyaltySubscriptionManager::activeFor($this->customer->id)->id,
        );
    }

    /** §4.1: an upgrade must move up. */
    public function test_a_downgrade_is_refused(): void
    {
        $gold   = $this->package(['priority' => 5]);
        $silver = $this->package(['priority' => 1]);

        $current = LoyaltySubscriptionManager::subscribe($this->customer->id, $gold);

        $this->expectException(\RuntimeException::class);
        LoyaltySubscriptionManager::upgrade($current, $silver);
    }

    public function test_an_upgrade_can_take_effect_after_the_current_term(): void
    {
        $silver = $this->package(['priority' => 1, 'validity_period' => 30]);
        $gold   = $this->package(['priority' => 2, 'validity_period' => 30]);

        $current = LoyaltySubscriptionManager::subscribe(
            $this->customer->id, $silver, ['subscription_date' => '2026-01-01'],
        );

        $upgraded = LoyaltySubscriptionManager::upgrade(
            $current, $gold,
            ['subscription_date' => '2026-01-10'],
            LoyaltyUpgrade::AFTER_EXPIRY,
        );

        $this->assertSame('2026-01-31', $upgraded->start_date->toDateString());
    }

    // ── §2.1: a term fixed to calendar dates ─────────────────────────────────

    /**
     * A seasonal package ends on the same day for everyone, so its end cannot be
     * derived from when a particular customer bought it.
     */
    public function test_a_fixed_term_uses_the_dates_it_was_given(): void
    {
        $subscription = LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['validity_type' => LoyaltyPeriod::FIXED, 'validity_period' => 0]),
            [
                'subscription_date' => '2026-03-01',
                'start_date'        => '2026-03-01',
                'end_date'          => '2026-04-15',
            ],
        );

        $this->assertSame('2026-04-15', $subscription->end_date->toDateString());
    }

    /** Without one there is no term at all, so the sale is refused. */
    public function test_a_fixed_term_without_an_end_date_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['validity_type' => LoyaltyPeriod::FIXED, 'validity_period' => 0]),
            ['subscription_date' => '2026-03-01'],
        );
    }

    public function test_a_fixed_term_ending_before_it_starts_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['validity_type' => LoyaltyPeriod::FIXED, 'validity_period' => 0]),
            [
                'subscription_date' => '2026-03-01',
                'start_date'        => '2026-03-01',
                'end_date'          => '2026-02-01',
            ],
        );
    }

    // ── §4.2: how an upgrade is priced ───────────────────────────────────────

    /** The default: the unused part of the current term is credited. */
    public function test_prorata_is_the_default_upgrade_method(): void
    {
        config(['pos.loyalty.upgrade_cost_method' => 'prorata']);

        $silver = $this->package(['cost' => 100, 'validity_period' => 365, 'priority' => 1]);
        $gold   = $this->package(['cost' => 250, 'validity_period' => 365, 'priority' => 2]);

        $current = LoyaltySubscriptionManager::subscribe(
            $this->customer->id, $silver, ['subscription_date' => '2026-01-01'],
        );

        $upgraded = LoyaltySubscriptionManager::upgrade(
            $current, $gold, ['subscription_date' => '2026-09-23'],
        );

        $this->assertEquals(27.40, $upgraded->adjustment_amount);
        $this->assertEquals(222.60, $upgraded->amount_received);
    }

    /** §4.2's other method: the customer pays the whole new cost. */
    public function test_the_full_cost_method_credits_nothing(): void
    {
        config(['pos.loyalty.upgrade_cost_method' => 'full']);

        $silver = $this->package(['cost' => 100, 'validity_period' => 365, 'priority' => 1]);
        $gold   = $this->package(['cost' => 250, 'validity_period' => 365, 'priority' => 2]);

        $current = LoyaltySubscriptionManager::subscribe(
            $this->customer->id, $silver, ['subscription_date' => '2026-01-01'],
        );

        $upgraded = LoyaltySubscriptionManager::upgrade(
            $current, $gold, ['subscription_date' => '2026-09-23'],
        );

        $this->assertEquals(0, $upgraded->adjustment_amount);
        $this->assertEquals(250, $upgraded->amount_received);
    }

    // ── §3.3: moving the start date ──────────────────────────────────────────

    /**
     * Backdating or post-dating a term changes when benefits begin and when they
     * run out, so it is a separate permission rather than a free-text field.
     */
    public function test_moving_the_start_date_needs_permission(): void
    {
        $staff = \App\Models\User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'staff',
            'visibility'  => ['loyalty' => ['assign' => '1']],
        ]);

        $package = $this->package();

        $this->actingAs($staff)
            ->postJson('/api/v1/loyalty-subscriptions', [
                'customer_id'        => $this->customer->id,
                'loyalty_package_id' => $package->id,
                'subscription_date'  => '2026-06-01',
                'start_date'         => '2026-01-01',
            ])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'date_change_not_permitted');

        $this->assertDatabaseCount('loyalty_subscriptions', 0);
    }

    public function test_a_start_date_matching_the_subscription_date_needs_nothing(): void
    {
        $staff = \App\Models\User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'staff',
            'visibility'  => ['loyalty' => ['assign' => '1']],
        ]);

        $this->actingAs($staff)
            ->postJson('/api/v1/loyalty-subscriptions', [
                'customer_id'        => $this->customer->id,
                'loyalty_package_id' => $this->package()->id,
                'subscription_date'  => '2026-06-01',
                'start_date'         => '2026-06-01',
            ])
            ->assertStatus(200);
    }

    public function test_the_date_permission_allows_the_move(): void
    {
        $staff = \App\Models\User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'staff',
            'visibility'  => ['loyalty' => ['assign' => '1', 'modify-dates' => '1']],
        ]);

        $this->actingAs($staff)
            ->postJson('/api/v1/loyalty-subscriptions', [
                'customer_id'        => $this->customer->id,
                'loyalty_package_id' => $this->package()->id,
                'subscription_date'  => '2026-06-01',
                'start_date'         => '2026-01-01',
            ])
            ->assertStatus(200);

        $this->assertSame(
            '2026-01-01',
            \App\Models\LoyaltySubscription::latest('id')->first()->start_date->toDateString(),
        );
    }

    // ── Tenancy ──────────────────────────────────────────────────────────────

    public function test_another_businesses_subscription_is_not_visible(): void
    {
        $other = Business::factory()->create();

        $otherCustomer = Customer::create([
            'business_id' => $other->id,
            'name'        => 'Someone else',
            'phone'       => '8888800000',
        ]);

        LoyaltySubscriptionManager::subscribe($this->customer->id, $this->package());

        $this->assertNull(LoyaltySubscriptionManager::activeFor($otherCustomer->id));
    }
}
