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
    Schema::table('counter_sessions', function (Blueprint $table) {
        $table->decimal('upi_amount',10,2)->default(0)->after('amount');
    });
}

public function down()
{
    Schema::table('counter_sessions', function (Blueprint $table) {
        $table->dropColumn('upi_amount');
    });
}
};
