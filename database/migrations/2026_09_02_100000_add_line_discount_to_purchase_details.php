<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the per-line purchase discount that was being thrown away.
 *
 * `PurchaseCalculationService` reads `discount_percent`/`discount_flat` off each
 * incoming line, works out the money, subtracts it from the line net - and then
 * `CalculatedPurchaseLine::toPurchaseDetail()` writes everything about the line
 * except that. There was nowhere to put it.
 *
 * So the discount survived only inside the totals it had already changed. Open
 * the purchase again and every line showed no discount, because the API had
 * none to send; save it back and the discount was gone for real, with the line
 * total quietly reverting to gross.
 *
 * Named to match the columns beside them, which is also what the app already
 * reads, so no client change is needed to see them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            // As typed by the operator: one of the two, or neither.
            $table->decimal('discountPercent', 8, 2)->nullable()->after('totalPrice');
            $table->decimal('discountFlat', 20, 4)->nullable()->after('discountPercent');
            // What those actually came to for this line, so a report does not
            // have to re-derive it from a percentage of a price that may since
            // have been corrected.
            $table->decimal('discountAmount', 20, 4)->nullable()->after('discountFlat');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->dropColumn(['discountPercent', 'discountFlat', 'discountAmount']);
        });
    }
};
