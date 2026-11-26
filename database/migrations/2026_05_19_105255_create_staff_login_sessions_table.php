<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('staff_login_sessions', function (Blueprint $table) {

            $table->id();

            $table->unsignedBigInteger('business_id');

            $table->unsignedBigInteger('branch_id')->nullable();

            $table->unsignedBigInteger('user_id');

            $table->timestamp('login_at')->nullable();

            $table->timestamp('logout_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_login_sessions');
    }
};
