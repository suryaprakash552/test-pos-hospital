<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('due_collects', function (Blueprint $table) {
            $table->double('cash_amount')->default(0)->after('payDueAmount');
            $table->double('upi_amount')->default(0)->after('cash_amount');
        });
    }

    public function down(): void
    {
        Schema::table('due_collects', function (Blueprint $table) {
            $table->dropColumn(['cash_amount','upi_amount']);
        });
    }
};
