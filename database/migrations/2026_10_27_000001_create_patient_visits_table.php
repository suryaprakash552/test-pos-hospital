<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_visits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('doctor_id')->nullable();
            $table->unsignedBigInteger('reason')->nullable();
            $table->string('location')->nullable();
            $table->enum('address_type', ['Home', 'Office', 'Other'])->default('Home');
            $table->string('door_no')->nullable();
            $table->string('building_name')->nullable();
            $table->string('area')->nullable();
            $table->string('landmark')->nullable();
            $table->text('instructions')->nullable();
            $table->enum('status', ['Pending', 'Cancelled', 'Completed'])->default('Pending');
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->onDelete('cascade');
            $table->foreign('doctor_id')->references('id')->on('app_doctors')->onDelete('set null');
            $table->foreign('reason')->references('id')->on('visit_reasons')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_visits');
    }
};
