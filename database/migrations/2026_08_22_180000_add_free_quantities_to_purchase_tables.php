<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->decimal('free_quantity', 20, 4)->default(0)->after('quantities');
        });

        Schema::table('purchase_return_details', function (Blueprint $table) {
            $table->decimal('free_return_qty', 20, 4)->default(0)->after('return_qty');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_return_details', function (Blueprint $table) {
            $table->dropColumn('free_return_qty');
        });

        Schema::table('purchase_details', function (Blueprint $table) {
            $table->dropColumn('free_quantity');
        });
    }
};
