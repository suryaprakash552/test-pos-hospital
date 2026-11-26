<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('device_id', 191)->nullable()->after('name');
            $table->string('device_name', 191)->nullable()->after('device_id');
            $table->index(['tokenable_type', 'tokenable_id', 'device_id'], 'pat_user_device_index');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex('pat_user_device_index');
            $table->dropColumn(['device_id', 'device_name']);
        });
    }
};
