<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('business_id');
            $table->string('email')->nullable();
            $table->string('type')->default('Retailer');
            $table->string('phone')->nullable();
            $table->string('relationship')->nullable();
            $table->double('due',10,2)->default(0);
            $table->string('opening_balance_type')->nullable();
            $table->double('opening_balance')->default(0);
            $table->double('wallet')->default(0);
            $table->double('loyalty_points')->default(0);
            $table->double('credit_limit')->default(0);
            $table->string('address')->nullable();
            $table->string('image')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->text('meta')->nullable();
            $table->text('shipping_address')->nullable();
            $table->text('billing_address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
