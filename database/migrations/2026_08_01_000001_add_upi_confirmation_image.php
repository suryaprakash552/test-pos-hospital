<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A screenshot of the customer's payment confirmation.
 *
 * The UTR and the timestamp (2026_07_31_000003) are what a bank statement is matched
 * on, but they are typed by hand off a customer's phone under time pressure and a
 * mistyped digit is indistinguishable from a missing payment. A screenshot settles the
 * question without relying on the cashier having read twelve characters correctly.
 *
 * Nullable and additive: it is evidence, never a requirement. A queue at the counter
 * must never be held up because a customer will not hand over their phone.
 */
return new class extends Migration
{
    private const PAYMENT_TABLES = [
        'sales',
        'purchases',
        'customer_due_collects',
        'due_collects',
    ];

    public function up(): void
    {
        foreach (self::PAYMENT_TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'upi_confirmation_image')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('upi_confirmation_image')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::PAYMENT_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'upi_confirmation_image')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('upi_confirmation_image');
            });
        }
    }
};
