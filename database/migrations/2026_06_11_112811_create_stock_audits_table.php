<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_audits', function (Blueprint $table) {

            $table->id();

            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('user_id');

            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('stock_id');

            $table->string('batch_no')->nullable();

            $table->decimal('previous_quantity',15,2)->default(0);
            $table->decimal('updated_quantity',15,2)->default(0);
            $table->decimal('difference_quantity',15,2)->default(0);

            $table->decimal('price_per_unit',15,2)->default(0);
            $table->decimal('difference_amount',15,2)->default(0);

            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_audits');
    }
};
