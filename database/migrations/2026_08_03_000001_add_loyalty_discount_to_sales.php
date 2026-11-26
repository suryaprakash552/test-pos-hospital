<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §8, §9: the loyalty discount as it was applied to an invoice.
 *
 * The package's terms are copied onto the sale, not referenced. §9 requires the
 * configuration used at billing to be stored, and §11 forbids a later edit to the
 * package master from changing a completed invoice — reading the discount back
 * through the subscription would break both, and would make a bill impossible to
 * explain to the customer holding it.
 *
 * `loyalty_eligible_amount` is recorded alongside the discount because the discount
 * alone cannot be checked: 180 is only correct if the base was 1,800, and §12.6's
 * utilisation report has to show both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Nulled rather than cascaded: removing a subscription must not erase
            // the record of a discount that was actually given.
            $table->foreignId('loyalty_subscription_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('loyalty_subscriptions')
                ->nullOnDelete();

            $table->string('loyalty_package_name', 100)->nullable();

            // The base the discount was calculated on — after row discounts and
            // with ineligible lines removed (§8.1).
            $table->decimal('loyalty_eligible_amount', 15, 2)->nullable();
            $table->decimal('loyalty_discount_amount', 15, 2)->default(0);

            // Terms as they stood at billing.
            $table->string('loyalty_discount_type', 10)->nullable();
            $table->decimal('loyalty_discount_value', 15, 2)->nullable();
            $table->decimal('loyalty_max_discount_amount', 15, 2)->nullable();
            $table->string('loyalty_discount_mode', 10)->nullable();

            // §10: who typed a manual discount, so an override has a name against it.
            $table->foreignId('loyalty_discount_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loyalty_subscription_id');
            $table->dropConstrainedForeignId('loyalty_discount_by');

            $table->dropColumn([
                'loyalty_package_name',
                'loyalty_eligible_amount',
                'loyalty_discount_amount',
                'loyalty_discount_type',
                'loyalty_discount_value',
                'loyalty_max_discount_amount',
                'loyalty_discount_mode',
            ]);
        });
    }
};
