<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('account_number')
                  ->nullable()
                  ->after('phone');

            $table->string('upi_id')
                  ->nullable()
                  ->after('account_number');
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn(['account_number', 'upi_id']);
        });
    }
};
