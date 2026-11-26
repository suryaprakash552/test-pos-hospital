<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
        });

        // Clean invalid data
        DB::statement("
            UPDATE sales 
            SET customer_id = NULL
            WHERE customer_id NOT IN (SELECT id FROM customers)
        ");

        Schema::table('sales', function (Blueprint $table) {
            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {

            $table->dropForeign(['customer_id']);

        });

        Schema::table('sales', function (Blueprint $table) {

            $table->foreign('customer_id')
                ->references('id')
                ->on('parties')
                ->nullOnDelete();

        });
    }
};
