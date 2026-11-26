<?php

namespace Tests\Feature\Api;

use App\Jobs\SendSmsMessageJob;
use App\Jobs\SendUserNotificationJob;
use App\Mail\LoyaltyRenewalReminderMail;
use App\Models\Business;
use App\Models\Customer;
use App\Models\LoyaltyPackage;
use App\Models\User;
use App\Services\LoyaltyDiscount;
use App\Services\LoyaltyPeriod;
use App\Services\LoyaltyReminderChannels;
use App\Services\LoyaltyRenewalReminder;
use App\Services\LoyaltySubscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * How a renewal reminder reaches someone (§5.3).
 *
 * The property worth protecting is honesty about delivery. A channel that reports
 * success while sending nothing is worse than one that plainly says it is not
 * configured — a shop that believes reminders went out stops chasing the renewals
 * itself, and only finds out when the customers do not come back.
 */
class LoyaltyReminderChannelTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name'        => 'Reminded customer',
            'phone'       => '9800001111',
            'email'       => 'customer@example.test',
        ]);
    }

    private function expiringSubscription(): void
    {
        $package = LoyaltyPackage::create([
            'business_id'     => $this->business->id,
            'code'            => 'R1',
            'name'            => 'Gold',
            'cost'            => 100,
            'validity_type'   => LoyaltyPeriod::DAYS,
            'validity_period' => 30,
            'discount_type'   => LoyaltyDiscount::PERCENTAGE,
            'discount_value'  => 10,
            'discount_mode'   => LoyaltyDiscount::AUTOMATIC,
            'priority'        => 1,
        ]);

        // Fixed dates, matching the existing reminder tests: a 30-day term bought
        // on the 1st ends on the 30th, so the 7-day offset falls on the 23rd.
        LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $package,
            ['subscription_date' => '2026-01-01'],
        );
    }

    /** Runs the reminder on the day the 7-day offset falls. */
    private function runOnOffsetDay(): array
    {
        return LoyaltyRenewalReminder::run('2026-01-23', $this->business->id);
    }

    // ── What the build can actually do ───────────────────────────────────────

    /**
     * WhatsApp is the honest false. There is no provider wired to it, and a
     * deployment that switched it on would otherwise record sends that never left
     * the building.
     */
    public function test_whatsapp_reports_itself_unavailable(): void
    {
        $this->assertFalse(
            LoyaltyReminderChannels::isAvailable(LoyaltyReminderChannels::WHATSAPP),
        );
        $this->assertNotNull(
            LoyaltyReminderChannels::unavailableReason(LoyaltyReminderChannels::WHATSAPP),
        );
    }

    public function test_sms_and_in_app_need_nothing_external(): void
    {
        $this->assertTrue(
            LoyaltyReminderChannels::isAvailable(LoyaltyReminderChannels::SMS),
        );
        $this->assertTrue(
            LoyaltyReminderChannels::isAvailable(LoyaltyReminderChannels::IN_APP),
        );
    }

    /** An unavailable channel cannot be enabled by configuring it. */
    public function test_enabling_whatsapp_does_not_enable_it(): void
    {
        config(['pos.loyalty.reminder_channels' => ['sms', 'whatsapp']]);

        $this->assertNotContains(
            LoyaltyReminderChannels::WHATSAPP,
            LoyaltyReminderChannels::enabled(),
        );
        $this->assertContains(
            LoyaltyReminderChannels::SMS,
            LoyaltyReminderChannels::enabled(),
        );
    }

    // ── Sending ──────────────────────────────────────────────────────────────

    /** The default is what reminders did before the other channels existed. */
    public function test_the_default_is_sms_alone(): void
    {
        $this->assertSame(['sms'], LoyaltyReminderChannels::enabled());
    }

    public function test_in_app_notifies_the_shop(): void
    {
        Queue::fake();
        config(['pos.loyalty.reminder_channels' => ['in_app']]);

        $this->expiringSubscription();
        $result = $this->runOnOffsetDay();

        $this->assertSame(1, $result['sent']);
        // The customer does not have this app; the shop does, and what it needs is
        // a list of people to call.
        Queue::assertPushed(SendUserNotificationJob::class);
        Queue::assertNotPushed(SendSmsMessageJob::class);
    }

    public function test_email_goes_to_the_customer(): void
    {
        Mail::fake();
        config([
            'pos.loyalty.reminder_channels' => ['email'],
            'mail.default' => 'log',
        ]);

        $this->expiringSubscription();
        $result = $this->runOnOffsetDay();

        $this->assertSame(1, $result['sent']);
        Mail::assertQueued(
            LoyaltyRenewalReminderMail::class,
            fn ($mail) => $mail->hasTo('customer@example.test'),
        );
    }

    /** Two channels are two reminders, and two rows in the log. */
    public function test_each_channel_is_recorded_separately(): void
    {
        Queue::fake();
        config(['pos.loyalty.reminder_channels' => ['sms', 'in_app']]);

        $this->expiringSubscription();
        $result = $this->runOnOffsetDay();

        $this->assertSame(2, $result['sent']);
        $this->assertSame(
            2,
            DB::table('loyalty_reminders')->count(),
            'one row per channel, so neither can be sent twice',
        );
    }

    /** A second run repeats nothing, on any channel. */
    public function test_running_twice_sends_nothing_twice(): void
    {
        Queue::fake();
        config(['pos.loyalty.reminder_channels' => ['sms', 'in_app']]);

        $this->expiringSubscription();

        $this->assertSame(2, $this->runOnOffsetDay()['sent']);
        $this->assertSame(0, $this->runOnOffsetDay()['sent']);
        $this->assertSame(2, DB::table('loyalty_reminders')->count());
    }

    // ── Nothing to send to ───────────────────────────────────────────────────

    /**
     * A customer with no address is skipped, and — importantly — leaves no row
     * behind. A claim that was never delivered must not block the reminder once
     * the shop records an address.
     */
    public function test_a_customer_with_no_email_is_skipped_and_can_be_retried(): void
    {
        Mail::fake();
        config([
            'pos.loyalty.reminder_channels' => ['email'],
            'mail.default' => 'log',
        ]);

        $this->customer->update(['email' => null]);
        $this->expiringSubscription();

        $result = $this->runOnOffsetDay();

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        Mail::assertNothingQueued();

        $this->assertSame(
            0,
            DB::table('loyalty_reminders')->count(),
            'an undelivered reminder must not claim the slot it never used',
        );

        // Once there is an address, the reminder goes.
        $this->customer->update(['email' => 'later@example.test']);

        $this->assertSame(1, $this->runOnOffsetDay()['sent']);
    }

    public function test_a_malformed_email_is_not_sent_to(): void
    {
        Mail::fake();
        config([
            'pos.loyalty.reminder_channels' => ['email'],
            'mail.default' => 'log',
        ]);

        $this->customer->update(['email' => 'not-an-address']);
        $this->expiringSubscription();

        $this->assertSame(0, $this->runOnOffsetDay()['sent']);
        Mail::assertNothingQueued();
    }

    /** One channel failing must not stop the other. */
    public function test_a_missing_email_does_not_stop_the_sms(): void
    {
        Queue::fake();
        Mail::fake();
        config([
            'pos.loyalty.reminder_channels' => ['email', 'sms'],
            'mail.default' => 'log',
        ]);

        $this->customer->update(['email' => null]);
        $this->expiringSubscription();

        $result = $this->runOnOffsetDay();

        $this->assertSame(1, $result['sent'], 'the SMS still went');
        $this->assertSame(1, $result['skipped'], 'the email did not');
        Queue::assertPushed(SendSmsMessageJob::class);
    }
}
