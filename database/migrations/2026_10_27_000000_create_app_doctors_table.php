<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_doctors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('doctor_name');
            $table->string('specialisation')->nullable();
            $table->string('qualification')->nullable();
            $table->string('experience')->nullable();
            $table->decimal('consultation_fee', 10, 2)->default(0);
            $table->decimal('extra_charges', 10, 2)->default(0);
            $table->string('image')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_doctors');
    }
};
