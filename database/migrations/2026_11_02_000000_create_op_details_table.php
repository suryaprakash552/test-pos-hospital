<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('op_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('doctor_id');
            $table->unsignedBigInteger('customer_id');
            $table->text('reason')->nullable();
            $table->dateTime('appointment_time')->nullable();
            $table->string('documents')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('payment_type')->nullable();
            $table->enum('payment_status', ['Pending', 'Completed'])->default('Pending');
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->onDelete('cascade');
            $table->foreign('doctor_id')->references('id')->on('app_doctors')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('op_details');
    }
};
