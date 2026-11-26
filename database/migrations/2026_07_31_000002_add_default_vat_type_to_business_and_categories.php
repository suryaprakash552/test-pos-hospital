<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Default tax mode, so a new shop starts out charging the way its trade does.
 *
 * `products.vat_type` (inclusive/exclusive) was the only place this was recorded, and
 * it defaulted to 'exclusive' for everyone. In practice the convention follows the
 * trade: a pharmacy or grocery prices inclusive of tax because the shelf price is what
 * the customer pays, while a wholesaler quotes exclusive and adds tax on the invoice.
 * Getting it wrong means every product has to be corrected by hand.
 *
 * `business_categories` is the existing "shop type" master, so the convention hangs
 * there. A business inherits it at creation and can override it; products then default
 * from the business rather than from a hardcoded constant.
 *
 * Both columns are nullable on purpose: null means "not decided", which falls back to
 * the previous 'exclusive' behaviour, so nothing changes for existing shops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_categories', function (Blueprint $table) {
            $table->string('default_vat_type')->nullable()->after('description');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->string('default_vat_type')->nullable()->after('vat_no');
        });

        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->string('default_vat_type')->nullable()->after('description');
            });
        }
    }

    public function down(): void
    {
        Schema::table('business_categories', function (Blueprint $table) {
            $table->dropColumn('default_vat_type');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('default_vat_type');
        });

        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropColumn('default_vat_type');
            });
        }
    }
};
