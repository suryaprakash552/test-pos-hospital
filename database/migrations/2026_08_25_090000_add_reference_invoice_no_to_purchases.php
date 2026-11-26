<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The supplier's own bill number, kept beside ours.
 *
 * `purchases.invoiceNumber` is the number *we* generate — the `#P202` printed at
 * the top of our purchase invoice. The number on the paper the supplier actually
 * delivered had nowhere to live, so once a purchase was saved there was no way
 * back to the document it came from: reconciling a supplier statement, or
 * answering "which bill was this stock on", meant going through them by date and
 * amount.
 *
 * Nullable, because a purchase entered from a phone call or a counter delivery
 * has no reference to record, and a blank is a fact about that purchase rather
 * than missing data. Indexed because looking a purchase up *by* the supplier's
 * number is the whole reason it is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchases')
            || Schema::hasColumn('purchases', 'reference_invoice_no')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->string('reference_invoice_no', 100)
                ->nullable()
                ->after('invoiceNumber');

            $table->index(['business_id', 'reference_invoice_no']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchases')
            || ! Schema::hasColumn('purchases', 'reference_invoice_no')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'reference_invoice_no']);
            $table->dropColumn('reference_invoice_no');
        });
    }
};
