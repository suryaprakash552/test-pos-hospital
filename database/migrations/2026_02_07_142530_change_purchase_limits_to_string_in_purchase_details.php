<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('purchase_details', function (Blueprint $table) {
        $table->string('purchaseUpperLimit')->change();
        $table->string('purchaseLowerLimit')->change();
    });
}

public function down(): void
{
    Schema::table('purchase_details', function (Blueprint $table) {
        $table->double('purchaseUpperLimit', 10, 2)->change();
        $table->double('purchaseLowerLimit', 10, 2)->change();
    });
}

};
