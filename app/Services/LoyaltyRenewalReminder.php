<?php

namespace App\Services;

use App\Models\LoyaltySubscription;
use App\Services\LoyaltyReminderChannels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Renewal reminders (§5.3).
 *
 * Finds the subscriptions due a reminder today and sends one, exactly once per
 * offset. The "exactly once" is the whole difficulty: this runs on a schedule and
 * may run repeatedly after a failure or a redeploy, and a customer messaged five
 * times about the same expiry is worse than one not messaged at all.
 *
 * That guarantee comes from a unique index rather than a check-then-send, because
 * two workers can both pass a check before either writes.
 */
class LoyaltyRenewalReminder
{
    /**
     * Offsets in days before expiry. 0 is the expiry date; negative is after it.
     *
     * Configurable per §5.3, with the specification's own list as the default.
     *
     * @return array<int, int>
     */
    public static function offsets(): array
    {
        $configured = config('pos.loyalty.reminder_days');

        return is_array($configured) && $configured !== []
            ? array_map('intval', $configured)
            : [30, 15, 7, 0, -7];
    }

    /**
     * Subscriptions whose expiry falls exactly [$daysBefore] days from [$onDate].
     *
     * Only Active terms: a renewed or upgraded one has already been replaced, and
     * reminding that customer would be telling them to buy what they just bought.
     */
    public static function dueFor(int $daysBefore, ?string $onDate = null, ?int $businessId = null)
    {
        $day = Carbon::parse($onDate ?? now())->startOfDay();

        // A reminder 30 days before expiry is sent when the end date is 30 days
        // away; a reminder 7 days *after* expiry when it is 7 days behind.
        $target = $day->copy()->addDays($daysBefore)->toDateString();

        return LoyaltySubscription::query()
            ->where('status', LoyaltyPeriod::ACTIVE)
            ->whereDate('end_date', $target)
            ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
            // `email` is selected because a reminder may go by mail (§5.3). Omitting
            // it does not fail — the relation simply reports null — so every
            // customer would silently read as having no address and no email
            // would ever be sent.
            ->with(['customer:id,name,phone,email', 'package:id,name'])
            ->get();
    }

    /**
     * Sends every reminder due today.
     *
     * @return array{sent: int, skipped: int}
     */
    public static function run(?string $onDate = null, ?int $businessId = null): array
    {
        $sent = 0;
        $skipped = 0;
        $day = Carbon::parse($onDate ?? now())->toDateString();

        $channels = LoyaltyReminderChannels::enabled();

        foreach (self::offsets() as $offset) {
            foreach (self::dueFor($offset, $day, $businessId) as $subscription) {
                $message = self::message($subscription, $offset);

                foreach ($channels as $channel) {
                    // The insert is the claim, and it is made per channel: the
                    // unique index covers (subscription, offset, channel), so an
                    // email and an SMS for the same reminder are two rows and a
                    // second run of either is refused. Claiming before sending is
                    // what stops a crash mid-run messaging everyone twice.
                    try {
                        DB::table('loyalty_reminders')->insert([
                            'business_id'             => $subscription->business_id,
                            'loyalty_subscription_id' => $subscription->id,
                            'days_before'             => $offset,
                            'channel'                 => $channel,
                            'sent_on'                 => $day,
                            'created_at'              => now(),
                            'updated_at'              => now(),
                        ]);
                    } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                        $skipped++;
                        continue;
                    }

                    // Nothing to send to — no number, no address. Not an error, a
                    // shop may hold customers without either, but it is not a send.
                    // The claim is released so a later run can try again once the
                    // shop has the detail.
                    if (! LoyaltyReminderChannels::send($channel, $subscription, $message)) {
                        DB::table('loyalty_reminders')
                            ->where('loyalty_subscription_id', $subscription->id)
                            ->where('days_before', $offset)
                            ->where('channel', $channel)
                            ->delete();

                        $skipped++;
                        continue;
                    }

                    $sent++;
                }
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * What the customer reads.
     *
     * Names the package and the date, because "your membership expires soon" gives
     * someone nothing to act on.
     */
    public static function message(LoyaltySubscription $subscription, int $daysBefore): string
    {
        $package = $subscription->package?->name ?? 'loyalty';
        $expiry  = $subscription->end_date->toDateString();

        return match (true) {
            $daysBefore > 0 => "Your {$package} membership expires on {$expiry} "
                . "({$daysBefore} days away). Renew to keep your benefits.",
            $daysBefore === 0 => "Your {$package} membership expires today ({$expiry}). "
                . 'Renew to keep your benefits.',
            default => "Your {$package} membership expired on {$expiry}. "
                . 'Renew to restore your benefits.',
        };
    }
}
