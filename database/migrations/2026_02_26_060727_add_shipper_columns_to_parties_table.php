<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {

            $table->string('shipper_phone_number')
                  ->nullable()
                  ->after('supplier_country');

            $table->string('shipper_address')
                  ->nullable()
                  ->after('shipper_phone_number');

            $table->string('shipper_lr_number')
                  ->nullable()
                  ->after('shipper_address');

            $table->string('shipper_lr_payment')
                  ->nullable()
                  ->after('shipper_lr_number');

            $table->string('shipper_zip_code')
                  ->nullable()
                  ->after('shipper_lr_payment');

            $table->string('shipper_city')
                  ->nullable()
                  ->after('shipper_zip_code');

            $table->string('shipper_state')
                  ->nullable()
                  ->after('shipper_city');

            $table->string('shipper_country')
                  ->nullable()
                  ->after('shipper_state');
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn([
                'shipper_phone_number',
                'shipper_address',
                'shipper_lr_number',
                'shipper_lr_payment',
                'shipper_zip_code',
                'shipper_city',
                'shipper_state',
                'shipper_country'
            ]);
        });
    }
};