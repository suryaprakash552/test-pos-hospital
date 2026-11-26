<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('sale_return_details', function (Blueprint $table) {
            $table->double('return_discount')->default(0);
            $table->double('total_return')->default(0);
            $table->double('non_returnable')->default(0);
            $table->double('return_cash')->default(0);
            $table->double('return_upi')->default(0);
        });
    }

    public function down()
    {
        Schema::table('sale_return_details', function (Blueprint $table) {
            $table->dropColumn([
                'return_discount',
                'total_return',
                'non_returnable',
                'return_cash',
                'return_upi'
            ]);
        });
    }
};
