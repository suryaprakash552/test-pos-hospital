<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which QR the till shows (§11).
 *
 * The server used to decide: an uploaded image if one existed, otherwise a
 * rendered one. That is a reasonable default and a poor rule - a shop that
 * uploads a QR for one counter cannot go back to dynamic codes without deleting
 * it, and the two behave differently at the till because only a dynamic code
 * carries the amount.
 *
 * `auto` preserves exactly the previous behaviour, so nothing changes for a shop
 * that has never expressed a preference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('upi_qr_mode', 20)->default('auto')->after('upi_qr_image');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('upi_qr_mode');
        });
    }
};
