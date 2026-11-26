<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CGST / SGST / IGST breakdown a GST invoice has to show.
 *
 * ## The safety property
 *
 * `vat_amount` stays the authoritative total and keeps its current meaning. These
 * columns are an additive *breakdown* of it, constrained by:
 *
 *     cgst_amount + sgst_amount + igst_amount == vat_amount
 *
 * That is what makes this migration safe on a live database. Every existing report,
 * invoice and receipt reads `vat_amount`, and none of them change. Nothing is
 * backfilled: historical rows keep NULL components, which reads as "billed before the
 * breakdown was recorded" rather than as three zeroes, so a legacy invoice is never
 * mistaken for one with no tax.
 *
 * A supply is either intra-state (CGST + SGST, half each) or inter-state (IGST alone).
 * Never both, which the service enforces and the tests pin.
 *
 * `cess` is separate from the three: it sits outside the CGST/SGST/IGST split and is
 * added on top, so it is deliberately not part of the sum above.
 */
return new class extends Migration
{
    private const TRANSACTION_TABLES = ['sales', 'purchases'];

    public function up(): void
    {
        foreach (self::TRANSACTION_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                foreach (['cgst_amount', 'sgst_amount', 'igst_amount', 'cess_amount'] as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        // Nullable, not defaulted to 0: null means "not recorded",
                        // zero would claim the tax was genuinely nil.
                        $blueprint->double($column, 10, 2)->nullable();
                    }
                }

                if (! Schema::hasColumn($table, 'is_inter_state')) {
                    $blueprint->boolean('is_inter_state')->nullable();
                }

                // The method the document was billed under, frozen at the time.
                // §17: a completed invoice keeps its method even after the shop's
                // configuration changes.
                if (! Schema::hasColumn($table, 'gst_method')) {
                    $blueprint->string('gst_method', 20)->nullable();
                }

                if (! Schema::hasColumn($table, 'place_of_supply')) {
                    $blueprint->string('place_of_supply', 100)->nullable();
                }
            });
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'hsn_code')) {
                // HSN for goods, SAC for services; the same column serves both.
                $table->string('hsn_code', 20)->nullable();
                $table->index('hsn_code');
            }

            if (! Schema::hasColumn('products', 'cess_rate')) {
                $table->double('cess_rate', 8, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (self::TRANSACTION_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                foreach ([
                    'cgst_amount', 'sgst_amount', 'igst_amount', 'cess_amount',
                    'is_inter_state', 'gst_method', 'place_of_supply',
                ] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'hsn_code')) {
                $table->dropIndex(['hsn_code']);
                $table->dropColumn('hsn_code');
            }

            if (Schema::hasColumn('products', 'cess_rate')) {
                $table->dropColumn('cess_rate');
            }
        });
    }
};
