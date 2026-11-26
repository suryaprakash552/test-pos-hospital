<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {

            $table->string('zip_code')
                  ->nullable()
                  ->after('upi_id');

            $table->string('state')
                  ->nullable()
                  ->after('zip_code');

            $table->string('city')
                  ->nullable()
                  ->after('state');

            $table->string('country')
                  ->nullable()
                  ->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn([
                'zip_code',
                'state',
                'city',
                'country'
            ]);
        });
    }
};