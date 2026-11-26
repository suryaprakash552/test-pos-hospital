<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §2: the loyalty package master.
 *
 * Distinct from `customer_tiers`, which is a label printed on a bill and carries no
 * pricing. A package is bought, has a validity, and changes what a customer is
 * charged — so it needs its own identity, its own history, and its own audit.
 *
 * The configuration is copied onto each subscription when it is sold (§11: "package
 * benefits shall be determined using the configuration applicable at the time of the
 * transaction"). Editing a package later must not silently re-price the customers
 * already on it, which is why the subscription carries its own copy rather than
 * reading through to this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('code');
            $table->string('name');
            $table->string('description')->nullable();

            $table->decimal('cost', 15, 2)->default(0);

            // §2.1: days / months / years. Fixed start-end dates are expressed as
            // an explicit period on the subscription instead of a validity unit.
            $table->string('validity_type', 10)->default('days');
            $table->unsignedInteger('validity_period')->default(0);

            $table->string('discount_type', 10)->default('percentage');
            $table->decimal('discount_value', 15, 2)->default(0);

            // Null means no ceiling (§6.4) — deliberately distinct from 0, which
            // would mean "no discount at all".
            $table->decimal('max_discount_amount', 15, 2)->nullable();

            $table->string('discount_mode', 10)->default('automatic');

            $table->boolean('renewal_allowed')->default(true);
            $table->boolean('upgrade_allowed')->default(true);

            // §2.2: the upgrade hierarchy. Higher wins; an upgrade may only move up.
            $table->unsignedInteger('priority')->default(0);

            $table->date('effective_start_date')->nullable();
            $table->date('effective_end_date')->nullable();

            $table->boolean('status')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('modified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // §2.2: unique per shop, not globally — two businesses may both sell
            // a package called "Gold".
            $table->unique(['business_id', 'code']);
            $table->unique(['business_id', 'name']);

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_packages');
    }
};
