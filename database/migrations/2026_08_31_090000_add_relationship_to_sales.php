<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the medicine on this bill was for.
 *
 * The till asks for a relationship on every sale — "self", "wife", "father" —
 * and the app has always sent it, but `sales` had no column to put it in, so it
 * was silently dropped and the invoice fell back to the *customer's* stored
 * relationship. A customer buying for their wife got a bill that read "self".
 *
 * It belongs on the sale, not the customer: the same person buys for themselves
 * one day and for a relative the next, and a pharmacy bill records which.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sales', 'relationship')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->string('relationship', 100)->nullable()->after('customer_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sales', 'relationship')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('relationship');
        });
    }
};
