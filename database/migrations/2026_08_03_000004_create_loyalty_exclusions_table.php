<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §11: products, brands and categories a loyalty discount does not apply to.
 *
 * "Loyalty discounts shall only apply to eligible products or categories." Without
 * this the discount applies to everything, which is how a shop ends up discounting
 * items sold at or below cost — prescription lines, gift cards, third-party
 * services — and only notices in the margin report.
 *
 * One table rather than flags on three others: the question asked at billing is
 * "is this line excluded", and answering it from one place keeps that a single
 * lookup instead of three joins per line.
 *
 * Scoped per package as well as per business. A shop may reasonably want the top
 * tier to cover something the entry tier does not, so a null `loyalty_package_id`
 * means the exclusion applies to every package.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Null applies the exclusion to every package in the shop.
            $table->foreignId('loyalty_package_id')
                ->nullable()
                ->constrained('loyalty_packages')
                ->cascadeOnDelete();

            /// 'product', 'brand' or 'category'.
            $table->string('excludable_type', 20);
            $table->unsignedBigInteger('excludable_id');

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['business_id', 'loyalty_package_id', 'excludable_type', 'excludable_id'],
                'loyalty_exclusion_unique',
            );

            $table->index(
                ['business_id', 'excludable_type', 'excludable_id'],
                'loyalty_exclusion_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_exclusions');
    }
};
