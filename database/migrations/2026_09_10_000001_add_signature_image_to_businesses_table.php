<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop's authorised signature, printed on the sales invoice.
 *
 * Stored the same way as `upi_qr_image` — a column on the business, uploaded
 * once in settings rather than drawn per sale — because it is one fixed image
 * that belongs on every bill, not something that changes from invoice to
 * invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('signature_image')->nullable()->after('upi_qr_mode');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('signature_image');
        });
    }
};
