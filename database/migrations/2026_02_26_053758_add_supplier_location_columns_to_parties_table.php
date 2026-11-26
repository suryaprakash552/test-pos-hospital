<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {

            $table->string('supplier_address')
                  ->nullable()
                  ->after('country');

            $table->string('supplier_zip_code')
                  ->nullable()
                  ->after('supplier_address');

            $table->string('supplier_city')
                  ->nullable()
                  ->after('supplier_zip_code');

            $table->string('supplier_state')
                  ->nullable()
                  ->after('supplier_city');

            $table->string('supplier_country')
                  ->nullable()
                  ->after('supplier_state');
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn([
                'supplier_address',
                'supplier_zip_code',
                'supplier_city',
                'supplier_state',
                'supplier_country'
            ]);
        });
    }
};