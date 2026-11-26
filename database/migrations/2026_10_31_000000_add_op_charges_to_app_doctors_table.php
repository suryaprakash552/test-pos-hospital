<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_doctors', function (Blueprint $table) {
            $table->decimal('op_charges', 10, 2)->default(0)->after('extra_charges');
        });
    }

    public function down(): void
    {
        Schema::table('app_doctors', function (Blueprint $table) {
            $table->dropColumn('op_charges');
        });
    }
};
