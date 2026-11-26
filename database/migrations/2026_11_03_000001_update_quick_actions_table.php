<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quick_actions', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
            $table->unsignedBigInteger('customer_user_id')->after('business_id');
            $table->foreign('customer_user_id')->references('id')->on('customer_users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('quick_actions', function (Blueprint $table) {
            $table->dropForeign(['customer_user_id']);
            $table->dropColumn('customer_user_id');
            $table->unsignedBigInteger('customer_id')->after('business_id');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
        });
    }
};
