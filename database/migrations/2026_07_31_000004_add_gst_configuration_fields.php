<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GST identity and pricing configuration, per shop type and per branch.
 *
 * ## What this adds to what already exists
 *
 * `default_vat_type` (2026_07_31_000002) gave a shop type and a business a default of
 * inclusive or exclusive. That covers the common case but not a non-registered shop,
 * which needs a third state — GST simply does not apply — and it carried none of the
 * identity a GST invoice legally requires.
 *
 * This adds:
 *   * the GST identity a branch bills under (registration, GSTIN, state, place of supply)
 *   * whether the shop-type default may be overridden, and from when a change applies
 *   * a history table, because a completed invoice must keep the method it was billed
 *     under even after the configuration changes
 *
 * ## Deliberately not here
 *
 * CGST/SGST/IGST columns and HSN/cess are **not** added. Splitting a tax total into
 * components changes what is charged and printed, so it needs its own pass with its own
 * verification — see the notes on GstConfiguration. This migration is configuration
 * only: nothing here alters an existing calculation.
 *
 * Every column is nullable or defaulted to today's behaviour, so shops that configure
 * nothing are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Shop-type master: the default a new branch inherits.
        Schema::table('business_categories', function (Blueprint $table) {
            $table->boolean('gst_applicable')->default(true)->after('default_vat_type');
        });

        foreach (['businesses', 'branches'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                // Null means "not answered yet", which reads as not registered without
                // rewriting what existing shops do.
                $table->boolean('gst_registered')->nullable();

                // 15 characters: 2-digit state, 10-char PAN, entity, Z, checksum.
                $table->string('gstin', 15)->nullable();

                // State the registration belongs to; its code must match the GSTIN.
                $table->string('gst_state', 100)->nullable();
                $table->string('place_of_supply', 100)->nullable();

                // When false, the shop-type default is read-only for non-admins.
                $table->boolean('allow_gst_override')->default(true);

                // A change applies from this date forward; invoices before it keep the
                // method they were billed under.
                $table->date('gst_effective_from')->nullable();

                if ($tableName === 'branches' && ! Schema::hasColumn('branches', 'default_vat_type')) {
                    $table->string('default_vat_type')->nullable();
                }
            });
        }

        // Why a configuration is what it is, and what it used to be.
        //
        // Kept separate from the record it describes so history survives the current
        // value being overwritten — that is the whole point of it.
        Schema::create('gst_configuration_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Polymorphic: the same change can be made to a business or a branch.
            $table->string('configurable_type');
            $table->unsignedBigInteger('configurable_id');

            $table->string('field');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();

            // Required when overriding a shop-type default, so a deviation is always
            // explained rather than merely recorded.
            $table->string('reason')->nullable();

            $table->date('effective_from')->nullable();

            // Nulled rather than cascaded: removing a user must not erase the record
            // that a tax setting was changed.
            $table->foreignId('changed_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['configurable_type', 'configurable_id'], 'gst_history_configurable_index');
            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gst_configuration_histories');

        Schema::table('business_categories', function (Blueprint $table) {
            $table->dropColumn('gst_applicable');
        });

        foreach (['businesses', 'branches'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn([
                    'gst_registered',
                    'gstin',
                    'gst_state',
                    'place_of_supply',
                    'allow_gst_override',
                    'gst_effective_from',
                ]);
            });
        }
    }
};
