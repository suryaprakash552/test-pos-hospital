<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the per-line sale discount, which was being discarded like the
 * purchase one beside it.
 *
 * `CartCalculationService` works out a discount for each line and subtracts it
 * from the line net; `CalculatedLine::toSaleDetail()` then writes the price,
 * the quantity and the profit, and nothing else. So the discount survived only
 * inside the totals it had already changed.
 *
 * Re-opening a sale to edit it therefore showed every line at full price, and
 * saving made that true - the customer's discount vanished on the second save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->decimal('discountPercent', 8, 2)->nullable()->after('lossProfit');
            $table->decimal('discountFlat', 20, 4)->nullable()->after('discountPercent');
            $table->decimal('discountAmount', 20, 4)->nullable()->after('discountFlat');
        });
    }

    public function down(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropColumn(['discountPercent', 'discountFlat', 'discountAmount']);
        });
    }
};
