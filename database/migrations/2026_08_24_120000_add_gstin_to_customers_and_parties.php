<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The other party's GSTIN, on both sides of the ledger.
 *
 * A tax invoice issued to a registered buyer has to carry that buyer's GSTIN
 * (CGST Rules, Rule 46(e)); without it the invoice is not one they can claim
 * input credit against. We had nowhere to put it — neither `customers` nor
 * `parties` had the column — so every bill this shop wrote was effectively B2C
 * whatever the buyer's registration status.
 *
 * The supplier side is the mirror image: input credit on a purchase is claimed
 * against the *supplier's* GSTIN, and `parties` could not hold one either.
 *
 * Nullable, because most of a pharmacy's customers are walk-ins with no
 * registration at all, and a blank is a fact about them rather than missing
 * data. 15 characters: 2-digit state, 10-char PAN, entity, Z, checksum — the
 * same shape `GstConfiguration::isValidGstin` already validates for our own.
 */
return new class extends Migration
{
    private const TABLES = ['customers', 'parties'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'gstin')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('gstin', 15)->nullable()->after('email');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'gstin')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('gstin');
            });
        }
    }
};
