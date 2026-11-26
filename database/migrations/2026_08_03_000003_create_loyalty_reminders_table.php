<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §5.3: which renewal reminders have already gone out.
 *
 * The reminder job runs on a schedule and may run more than once a day — after a
 * failure, a redeploy, or a manual invocation. Without a record of what was sent, a
 * customer would be messaged again on every run, which is how a helpful reminder
 * becomes a complaint.
 *
 * The unique index is the guarantee: one reminder per subscription per offset, no
 * matter how often the command runs or how many workers pick it up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->foreignId('loyalty_subscription_id')
                ->constrained('loyalty_subscriptions')
                ->cascadeOnDelete();

            /// Days before expiry this reminder was for; 0 is the expiry date
            /// itself and a negative value is after it (§5.3).
            $table->integer('days_before');

            $table->string('channel', 20);
            $table->date('sent_on');

            $table->timestamps();

            // What makes a re-run safe.
            $table->unique(
                ['loyalty_subscription_id', 'days_before', 'channel'],
                'loyalty_reminder_once',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_reminders');
    }
};
