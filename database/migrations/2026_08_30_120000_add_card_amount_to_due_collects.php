<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card is a third tender on a due collection, alongside cash and UPI.
 *
 * The till already offered a Cash/UPI/Card split and the app posted
 * `card_amount`, but there was nowhere to put it: the controller dropped the
 * field and the table had no column. A ₹48,485 due settled as ₹20,000 UPI plus
 * ₹28,485 card was stored as UPI alone, so the money receipt named one method
 * and the card takings were invisible in the day's split.
 */
return new class extends Migration
{
    private const TABLES = ['customer_due_collects', 'due_collects'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'card_amount')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                // Mirrors `upi_amount` so the three tenders sum the same way.
                $blueprint->decimal('card_amount', 20, 2)->default(0)->after('upi_amount');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'card_amount')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('card_amount');
            });
        }
    }
};
