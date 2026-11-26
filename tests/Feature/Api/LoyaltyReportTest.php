<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\LoyaltyPackage;
use App\Models\User;
use App\Services\LoyaltyDiscount;
use App\Services\LoyaltyPeriod;
use App\Services\LoyaltySubscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §12: the six loyalty reports.
 *
 * What they must not do is as important as what they show. A term that was renewed
 * did not lapse — reporting it as expired invents a lost customer and sends someone
 * chasing a renewal that already happened.
 */
class LoyaltyReportTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $user;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name'        => 'Loyal customer',
            'phone'       => '9999900000',
        ]);

        $this->actingAs($this->user);
    }

    private function package(array $overrides = []): LoyaltyPackage
    {
        static $n = 0;
        $n++;

        return LoyaltyPackage::create($overrides + [
            'business_id'     => $this->business->id,
            'code'            => 'P' . $n,
            'name'            => 'Tier ' . $n,
            'cost'            => 100,
            'validity_type'   => LoyaltyPeriod::DAYS,
            'validity_period' => 365,
            'discount_type'   => LoyaltyDiscount::PERCENTAGE,
            'discount_value'  => 10,
            'discount_mode'   => LoyaltyDiscount::AUTOMATIC,
            'priority'        => $n,
        ]);
    }

    private function report(string $name, array $query = [])
    {
        return $this->getJson('/api/v1/loyalty-reports/' . $name . '?' . http_build_query($query));
    }

    // ── §12.1 ────────────────────────────────────────────────────────────────

    public function test_the_customer_report_shows_the_term_and_what_is_left(): void
    {
        LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['validity_period' => 30]),
            ['subscription_date' => now()->toDateString(), 'amount_received' => 100],
        );

        $row = $this->report('customers')->assertStatus(200)->json('data.0');

        $this->assertSame($this->customer->id, $row['customer_id']);
        $this->assertSame('Loyal customer', $row['customer_name']);
        $this->assertSame(30, $row['remaining_days']);
        $this->assertSame(LoyaltyPeriod::ACTIVE, $row['status']);
        $this->assertEquals(100.0, $row['amount_received']);
    }

    public function test_the_customer_report_filters_by_mobile(): void
    {
        LoyaltySubscriptionManager::subscribe($this->customer->id, $this->package());

        $this->assertCount(1, $this->report('customers', ['mobile' => '99999'])->json('data'));
        $this->assertCount(0, $this->report('customers', ['mobile' => '12345'])->json('data'));
    }

    // ── §12.2 ────────────────────────────────────────────────────────────────

    public function test_the_expired_report_lists_a_lapsed_customer(): void
    {
        LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['validity_period' => 30]),
            ['subscription_date' => '2026-01-01'],
        );

        $row = $this->report('expired', ['as_of' => '2026-03-01'])
            ->assertStatus(200)->json('data.0');

        $this->assertSame('2026-01-30', $row['expiry_date']);
        $this->assertSame(30, $row['days_since_expiry']);
        $this->assertSame('pending', $row['renewal_status']);
    }

    /**
     * A renewed term was replaced, not lost. Listing it would send someone chasing
     * a renewal that already happened.
     */
    public function test_a_renewed_term_is_not_reported_as_expired(): void
    {
        $original = LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['validity_period' => 30]),
            ['subscription_date' => '2026-01-01'],
        );

        LoyaltySubscriptionManager::renew($original, ['subscription_date' => '2026-01-20']);

        $rows = $this->report('expired', ['as_of' => '2026-02-15'])->json('data');

        $this->assertCount(0, $rows);
    }

    // ── §12.3 / §12.4 ────────────────────────────────────────────────────────

    public function test_the_renewal_report_shows_both_terms(): void
    {
        $original = LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['validity_period' => 30]),
            ['subscription_date' => '2026-01-01'],
        );

        LoyaltySubscriptionManager::renew($original, ['subscription_date' => '2026-01-20']);

        $row = $this->report('renewals')->assertStatus(200)->json('data.0');

        $this->assertSame('2026-01-30', $row['previous_end_date']);
        $this->assertSame('2026-01-31', $row['new_start_date']);
        $this->assertSame('2026-01-20', $row['transaction_date']);
    }

    /** §4.4: the credit for the unused term has to be visible. */
    public function test_the_upgrade_report_shows_the_adjustment(): void
    {
        $silver = $this->package(['priority' => 1, 'cost' => 100, 'validity_period' => 365]);
        $gold   = $this->package(['priority' => 2, 'cost' => 250, 'validity_period' => 365]);

        $current = LoyaltySubscriptionManager::subscribe(
            $this->customer->id, $silver, ['subscription_date' => '2026-01-01'],
        );

        LoyaltySubscriptionManager::upgrade(
            $current, $gold, ['subscription_date' => '2026-09-23'],
        );

        $row = $this->report('upgrades')->assertStatus(200)->json('data.0');

        $this->assertSame($silver->name, $row['previous_package']);
        $this->assertSame($gold->name, $row['new_package']);
        $this->assertEquals(27.40, $row['adjustment_amount']);
        $this->assertEquals(222.60, $row['amount_received']);
    }

    public function test_a_renewal_does_not_appear_in_the_upgrade_report(): void
    {
        $original = LoyaltySubscriptionManager::subscribe($this->customer->id, $this->package());
        LoyaltySubscriptionManager::renew($original);

        $this->assertCount(0, $this->report('upgrades')->json('data'));
        $this->assertCount(1, $this->report('renewals')->json('data'));
    }

    // ── §12.5 ────────────────────────────────────────────────────────────────

    /**
     * Split by origin because the three are different businesses: growth,
     * retention and expansion. A single total hides which of them is working.
     */
    public function test_amounts_are_split_by_what_produced_them(): void
    {
        $silver = $this->package(['priority' => 1, 'cost' => 100]);
        $gold   = $this->package(['priority' => 2, 'cost' => 250]);

        $first = LoyaltySubscriptionManager::subscribe(
            $this->customer->id, $silver,
            ['subscription_date' => '2026-01-01', 'amount_received' => 100],
        );

        LoyaltySubscriptionManager::upgrade(
            $first, $gold,
            ['subscription_date' => '2026-06-01', 'amount_received' => 200],
        );

        $data = $this->report('amount-received')->assertStatus(200)->json('data');

        $this->assertEquals(100.0, $data['new_subscription_amount']);
        $this->assertEquals(200.0, $data['upgrade_amount']);
        $this->assertEquals(0.0, $data['renewal_amount']);
        $this->assertEquals(300.0, $data['net_amount_received']);
    }

    /** §12.5: a period report has to reconcile against what came before it. */
    public function test_the_opening_amount_is_what_was_taken_before_the_period(): void
    {
        LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(),
            ['subscription_date' => '2026-01-10', 'amount_received' => 400],
        );

        $data = $this->report('amount-received', ['from_date' => '2026-06-01'])
            ->assertStatus(200)->json('data');

        $this->assertEquals(400.0, $data['opening_amount']);
        // The January sale is before the window, so it is opening rather than new.
        $this->assertEquals(0.0, $data['new_subscription_amount']);
    }

    public function test_without_a_start_date_there_is_no_opening_balance(): void
    {
        LoyaltySubscriptionManager::subscribe(
            $this->customer->id, $this->package(), ['amount_received' => 400],
        );

        $data = $this->report('amount-received')->json('data');

        $this->assertEquals(0.0, $data['opening_amount']);
        $this->assertEquals(400.0, $data['new_subscription_amount']);
    }

    /**
     * §12.5 lists refunds and there is no refund flow yet. Reported as zero so the
     * report's shape does not change the day one is added.
     */
    public function test_refunds_are_reported_as_zero_until_a_refund_flow_exists(): void
    {
        $data = $this->report('amount-received')->json('data');

        $this->assertArrayHasKey('refund_amount', $data);
        $this->assertEquals(0.0, $data['refund_amount']);
    }

    // ── §12.6 ────────────────────────────────────────────────────────────────

    public function test_the_utilisation_report_is_empty_without_discounted_sales(): void
    {
        $data = $this->report('utilisation')->assertStatus(200)->json('data');

        $this->assertEquals(0.0, $data['total_discount_given']);
        $this->assertSame([], $data['rows']);
    }

    // ── Tenancy ──────────────────────────────────────────────────────────────

    public function test_another_businesses_subscriptions_never_appear(): void
    {
        $other = Business::factory()->create();
        $otherCustomer = Customer::create([
            'business_id' => $other->id,
            'name'        => 'Someone else',
            'phone'       => '8888800000',
        ]);

        $otherPackage = LoyaltyPackage::create([
            'business_id' => $other->id,
            'code' => 'X1', 'name' => 'Theirs', 'cost' => 100,
            'validity_type' => LoyaltyPeriod::DAYS, 'validity_period' => 365,
            'discount_type' => LoyaltyDiscount::PERCENTAGE, 'discount_value' => 10,
            'discount_mode' => LoyaltyDiscount::AUTOMATIC, 'priority' => 1,
        ]);

        LoyaltySubscriptionManager::subscribe($otherCustomer->id, $otherPackage);
        LoyaltySubscriptionManager::subscribe($this->customer->id, $this->package());

        $rows = $this->report('customers')->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($this->customer->id, $rows[0]['customer_id']);
    }

    // ── §12.2 last contacted ─────────────────────────────────────────────────

    /**
     * §12.2: when this customer was last chased about renewing.
     *
     * Derived from the reminder log — a reminder that went out is a contact — so a
     * customer who has never been contacted reads null, which is precisely the list
     * a shop wants: lapsed customers nobody has followed up.
     */
    /**
     * A row in the reminder log means a reminder went out — `sent_on` is NOT NULL —
     * so "never contacted" is the absence of a row, not an unsent one.
     */
    public function test_a_customer_never_contacted_has_no_contact_date(): void
    {
        LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['validity_period' => 1]),
            ['subscription_date' => now()->subDays(30)->toDateString()],
        );

        \Illuminate\Support\Facades\DB::table('loyalty_subscriptions')
            ->update(['status' => LoyaltyPeriod::EXPIRED]);

        $response = $this->report('expired')->assertOk();

        $this->assertNull($response->json('data.0.last_contacted_date'));
    }

    public function test_the_most_recent_reminder_is_the_contact_date(): void
    {
        $subscription = LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['validity_period' => 1]),
            ['subscription_date' => now()->subDays(30)->toDateString()],
        );

        \Illuminate\Support\Facades\DB::table('loyalty_subscriptions')
            ->update(['status' => LoyaltyPeriod::EXPIRED]);

        // Different windows, because a reminder fires once per window and channel —
        // the schema enforces it.
        foreach ([30 => '2026-07-01', 7 => '2026-07-20'] as $daysBefore => $sentOn) {
            \Illuminate\Support\Facades\DB::table('loyalty_reminders')->insert([
                'business_id'             => $this->business->id,
                'loyalty_subscription_id' => $subscription->id,
                'days_before'             => $daysBefore,
                'channel'                 => 'sms',
                'sent_on'                 => $sentOn,
                'created_at'              => now(),
                'updated_at'              => now(),
            ]);
        }

        $response = $this->report('expired')->assertOk();

        $this->assertStringStartsWith(
            '2026-07-20',
            $response->json('data.0.last_contacted_date'),
            'the latest reminder is the last contact, not the first',
        );
    }

    /**
     * The report's single search box matches a name *or* a phone number.
     *
     * `customer_name` and `mobile` are separate filters and are ANDed, so the
     * one box on screen could only ever match a name — a counter typing a
     * mobile number got nothing back and reported the search as broken.
     */
    public function test_the_search_box_matches_a_mobile_number(): void
    {
        LoyaltySubscriptionManager::subscribe($this->customer->id, $this->package());

        $this->assertCount(
            1,
            $this->report('customers', ['search' => '99999'])->json('data'),
        );
    }

    public function test_the_search_box_still_matches_a_name(): void
    {
        LoyaltySubscriptionManager::subscribe($this->customer->id, $this->package());

        $this->assertCount(
            1,
            $this->report('customers', ['search' => 'Loyal'])->json('data'),
        );
    }

    public function test_the_search_box_excludes_what_does_not_match(): void
    {
        LoyaltySubscriptionManager::subscribe($this->customer->id, $this->package());

        $this->assertCount(
            0,
            $this->report('customers', ['search' => 'zzz-no-such-thing'])->json('data'),
        );
    }
}
