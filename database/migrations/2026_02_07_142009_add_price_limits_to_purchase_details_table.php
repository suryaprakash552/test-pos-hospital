<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->double('purchaseUpperLimit', 10, 2)
                  ->default(0)
                  ->after('quantities');

            $table->double('purchaseLowerLimit', 10, 2)
                  ->default(0)
                  ->after('purchaseUpperLimit');

            $table->double('totalPrice', 10, 2)
                  ->default(0)
                  ->after('purchaseLowerLimit');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->dropColumn([
                'purchaseUpperLimit',
                'purchaseLowerLimit',
                'totalPrice',
            ]);
        });
    }
};
