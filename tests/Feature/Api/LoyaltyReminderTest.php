<?php

namespace Tests\Feature\Api;

use App\Jobs\SendSmsMessageJob;
use App\Models\Business;
use App\Models\Customer;
use App\Models\LoyaltyPackage;
use App\Models\User;
use App\Services\LoyaltyDiscount;
use App\Services\LoyaltyPeriod;
use App\Services\LoyaltyRenewalReminder;
use App\Services\LoyaltySubscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * §5.3: renewal reminders.
 *
 * The hard part is not sending one, it is sending exactly one. The command runs on
 * a schedule and may run again after a failure or a redeploy — a customer messaged
 * five times about the same expiry is worse than one never messaged at all.
 */
class LoyaltyReminderTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->business = Business::factory()->create();

        $this->actingAs(User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]));

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name'        => 'Loyal customer',
            'phone'       => '9999900000',
        ]);
    }

    private function package(array $overrides = []): LoyaltyPackage
    {
        static $n = 0;
        $n++;

        return LoyaltyPackage::create($overrides + [
            'business_id'     => $this->business->id,
            'code'            => 'P' . $n,
            'name'            => 'Gold',
            'cost'            => 100,
            'validity_type'   => LoyaltyPeriod::DAYS,
            'validity_period' => 30,
            'discount_type'   => LoyaltyDiscount::PERCENTAGE,
            'discount_value'  => 10,
            'discount_mode'   => LoyaltyDiscount::AUTOMATIC,
            'priority'        => $n,
        ]);
    }

    /** A 30-day term bought on the 1st ends on the 30th. */
    private function subscribe(?Customer $customer = null)
    {
        return LoyaltySubscriptionManager::subscribe(
            ($customer ?? $this->customer)->id,
            $this->package(),
            ['subscription_date' => '2026-01-01'],
        );
    }

    public function test_a_reminder_goes_out_at_the_configured_offset(): void
    {
        $this->subscribe();

        // Seven days before the 30th.
        $result = LoyaltyRenewalReminder::run('2026-01-23', $this->business->id);

        $this->assertSame(1, $result['sent']);
        Queue::assertPushed(SendSmsMessageJob::class, 1);
    }

    public function test_nothing_goes_out_on_a_day_no_offset_falls_on(): void
    {
        $this->subscribe();

        $result = LoyaltyRenewalReminder::run('2026-01-11', $this->business->id);

        $this->assertSame(0, $result['sent']);
        Queue::assertNothingPushed();
    }

    /** The reason the reminder table exists. */
    public function test_running_twice_in_a_day_messages_nobody_twice(): void
    {
        $this->subscribe();

        $first  = LoyaltyRenewalReminder::run('2026-01-23', $this->business->id);
        $second = LoyaltyRenewalReminder::run('2026-01-23', $this->business->id);

        $this->assertSame(1, $first['sent']);
        $this->assertSame(0, $second['sent']);
        $this->assertSame(1, $second['skipped']);

        Queue::assertPushed(SendSmsMessageJob::class, 1);
    }

    /** Each offset is its own reminder, so a later one still goes out. */
    public function test_a_later_offset_still_sends_after_an_earlier_one(): void
    {
        $this->subscribe();

        LoyaltyRenewalReminder::run('2026-01-23', $this->business->id);   // 7 days
        $onExpiry = LoyaltyRenewalReminder::run('2026-01-30', $this->business->id); // 0

        $this->assertSame(1, $onExpiry['sent']);
        Queue::assertPushed(SendSmsMessageJob::class, 2);
    }

    /** §5.3 includes a reminder after expiry. */
    public function test_a_reminder_goes_out_after_expiry(): void
    {
        $this->subscribe();

        $result = LoyaltyRenewalReminder::run('2026-02-06', $this->business->id);

        $this->assertSame(1, $result['sent']);
    }

    /**
     * A customer who already renewed must not be told to renew — their old term
     * was replaced, not lost.
     */
    public function test_a_renewed_subscription_is_not_reminded(): void
    {
        $original = $this->subscribe();
        LoyaltySubscriptionManager::renew($original, ['subscription_date' => '2026-01-10']);

        $result = LoyaltyRenewalReminder::run('2026-01-23', $this->business->id);

        $this->assertSame(0, $result['sent']);
    }

    /** Not an error, but it must not be counted as sent either. */
    public function test_a_customer_with_no_number_is_skipped_not_sent(): void
    {
        $noPhone = Customer::create([
            'business_id' => $this->business->id,
            'name'        => 'No number',
            'phone'       => null,
        ]);

        $this->subscribe($noPhone);

        $result = LoyaltyRenewalReminder::run('2026-01-23', $this->business->id);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        Queue::assertNothingPushed();
    }

    // ── The message ──────────────────────────────────────────────────────────

    /** "Expires soon" gives someone nothing to act on. */
    public function test_the_message_names_the_package_and_the_date(): void
    {
        $subscription = $this->subscribe();

        $before = LoyaltyRenewalReminder::message($subscription, 7);
        $this->assertStringContainsString('Gold', $before);
        $this->assertStringContainsString('2026-01-30', $before);
        $this->assertStringContainsString('7 days', $before);

        $this->assertStringContainsString(
            'expires today',
            LoyaltyRenewalReminder::message($subscription, 0),
        );
        $this->assertStringContainsString(
            'expired on',
            LoyaltyRenewalReminder::message($subscription, -7),
        );
    }

    // ── Command ──────────────────────────────────────────────────────────────

    public function test_the_dry_run_sends_nothing(): void
    {
        $this->subscribe();

        $this->artisan('pos:loyalty-reminders --dry-run --date=2026-01-23')
            ->expectsOutputToContain('Nothing was sent')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('loyalty_reminders', 0);
    }

    public function test_the_command_sends_and_expires_in_one_pass(): void
    {
        $this->subscribe();

        $this->artisan('pos:loyalty-reminders --date=2026-02-06')
            ->assertSuccessful();

        Queue::assertPushed(SendSmsMessageJob::class, 1);
        $this->assertDatabaseCount('loyalty_reminders', 1);
    }

    public function test_another_businesses_customers_are_not_messaged(): void
    {
        $this->subscribe();

        $result = LoyaltyRenewalReminder::run('2026-01-23', $this->business->id + 999);

        $this->assertSame(0, $result['sent']);
    }
}
