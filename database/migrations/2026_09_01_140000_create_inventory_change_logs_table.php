<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable inventory business-event history.
     *
     * This is deliberately not a balance table.  Current availability continues
     * to come from purchase_details.conversionQuantity; these rows explain how
     * a controlled source-document change arrived at that state.
     */
    public function up(): void
    {
        Schema::create('inventory_change_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event_type', 64);
            $table->string('source_type', 120);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->unsignedBigInteger('purchase_detail_id')->nullable();
            $table->unsignedBigInteger('stock_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->decimal('quantity_delta', 20, 4)->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['business_id', 'stock_id', 'created_at'], 'inventory_log_business_stock_created');
            $table->index(['business_id', 'purchase_id', 'created_at'], 'inventory_log_business_purchase_created');
            $table->index(['business_id', 'event_type', 'created_at'], 'inventory_log_business_event_created');
            $table->index(['correlation_id'], 'inventory_log_correlation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_change_logs');
    }
};
