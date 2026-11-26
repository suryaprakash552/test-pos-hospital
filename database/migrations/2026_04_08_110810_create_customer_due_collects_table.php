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
        Schema::create('customer_due_collects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->string('invoiceNumber')->nullable();
            $table->double('totalDue')->default(0);
            $table->double('dueAmountAfterPay')->default(0);
            $table->double('payDueAmount')->default(0);
            $table->unsignedBigInteger('payment_type_id');
            $table->date('paymentDate');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_due_collects');
    }
};
