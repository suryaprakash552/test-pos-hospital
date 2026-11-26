<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records money returned to a customer for a loyalty package.
 *
 * Kept on the subscription rather than in a separate ledger because a refund is a
 * fact about one subscription, and the money reports already read that row — a
 * separate table would need joining into every one of them to answer "what did we
 * actually keep".
 *
 * `refund_amount` is nullable rather than defaulted to zero: a package that was
 * never refunded is a different thing from one refunded for nothing, and the
 * reports distinguish them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_subscriptions', function (Blueprint $table) {
            $table->decimal('refund_amount', 15, 2)->nullable()->after('amount_received');
            $table->timestamp('refunded_at')->nullable()->after('refund_amount');
            $table->unsignedBigInteger('refunded_by')->nullable()->after('refunded_at');
            $table->string('refund_reason')->nullable()->after('refunded_by');

            // The money reports filter on it, and a chain has a great many rows.
            $table->index(['business_id', 'refunded_at'], 'loyalty_subs_refund_idx');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_subscriptions', function (Blueprint $table) {
            $table->dropIndex('loyalty_subs_refund_idx');
            $table->dropColumn([
                'refund_amount',
                'refunded_at',
                'refunded_by',
                'refund_reason',
            ]);
        });
    }
};
