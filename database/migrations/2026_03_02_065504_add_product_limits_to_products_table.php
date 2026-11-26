<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {

            $table->string('product_upper_limit')
                  ->nullable()
                  ->after('vat_amount');

            $table->string('product_lower_limit')
                  ->nullable()
                  ->after('product_upper_limit');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'product_upper_limit',
                'product_lower_limit'
            ]);
        });
    }
};