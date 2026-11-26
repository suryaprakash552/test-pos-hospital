<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer tiers / grades.
 *
 * A label the shop assigns to a customer — "Wholesale", "Staff", "Gold" — that is
 * printed on the bill for reference. It deliberately carries **no discount**: pricing
 * stays a manual decision at the till, so a tier can be introduced or renamed without
 * silently changing what anyone is charged.
 *
 * `customers.type` already exists as free text defaulting to 'Retailer', but it is
 * unconstrained and unmanaged — every row can spell a tier differently, so it cannot
 * be reported on. This gives tiers an identity while leaving that column untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();

            /// Printed order on any list; lower sorts first.
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();

            // One shop cannot have two tiers of the same name; two shops can.
            $table->unique(['business_id', 'name']);
        });

        Schema::table('customers', function (Blueprint $table) {
            // Nulled rather than deleted: removing a tier must never remove customers.
            $table->foreignId('customer_tier_id')
                ->nullable()
                ->after('type')
                ->constrained('customer_tiers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['customer_tier_id']);
            $table->dropColumn('customer_tier_id');
        });

        Schema::dropIfExists('customer_tiers');
    }
};
