<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('demo:restore-public-images')->everyThirtyMinutes();

        // §5.3: renewal reminders, once a day. Safe to run again — the reminder
        // table's unique index stops a customer being messaged twice.
        $schedule->command('pos:loyalty-reminders')->dailyAt('09:00');

        // Hourly rather than daily: the expired half only needs a daily sweep,
        // but the stuck-claim half decides how long a till that crashed
        // mid-checkout is locked out of retrying the same basket.
        //
        // `withoutOverlapping` because a table left unpruned for months takes
        // more than an hour to work through in batches, and a second copy would
        // fight the first for the same rows.
        $schedule->command('pos:prune-idempotency-keys')
            ->hourly()
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
