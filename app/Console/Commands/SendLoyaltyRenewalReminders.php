<?php

namespace App\Console\Commands;

use App\Services\LoyaltyRenewalReminder;
use App\Services\LoyaltySubscriptionManager;
use Illuminate\Console\Command;

/**
 * §5.3: sends renewal reminders, and marks lapsed terms on the way past.
 *
 * Scheduled daily. Safe to run more than once — the reminder table's unique index
 * means a second run in the same day sends nothing again.
 */
class SendLoyaltyRenewalReminders extends Command
{
    protected $signature = 'pos:loyalty-reminders
                            {--date= : Run as if it were this date}
                            {--business= : Restrict to one business}
                            {--dry-run : Report what would be sent without sending}';

    protected $description = 'Send loyalty renewal reminders and expire lapsed packages';

    public function handle(): int
    {
        $date = $this->option('date');
        $businessId = $this->option('business') ? (int) $this->option('business') : null;

        if ($this->option('dry-run')) {
            $total = 0;

            foreach (LoyaltyRenewalReminder::offsets() as $offset) {
                $due = LoyaltyRenewalReminder::dueFor($offset, $date, $businessId);
                $total += $due->count();

                $this->line(sprintf(
                    '  %+d days: %d subscription(s)',
                    $offset,
                    $due->count(),
                ));
            }

            $this->info("Would send {$total} reminder(s). Nothing was sent.");

            return self::SUCCESS;
        }

        $result = LoyaltyRenewalReminder::run($date, $businessId);

        $this->info("Reminders sent: {$result['sent']}");

        if ($result['skipped'] > 0) {
            // Already sent, or the customer has no number on file. Reported rather
            // than hidden, because a shop expecting reminders should be able to see
            // why one did not arrive.
            $this->warn("Skipped: {$result['skipped']}");
        }

        // Housekeeping while we are here: the read path already treats a lapsed
        // term as inactive, so this only keeps the reports honest.
        $expired = LoyaltySubscriptionManager::expireLapsed($businessId, $date);

        if ($expired > 0) {
            $this->info("Marked expired: {$expired}");
        }

        return self::SUCCESS;
    }
}
