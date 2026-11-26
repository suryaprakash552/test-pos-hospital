<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A permanent record of expired stock taken off the shelf.
 *
 * A drug inspector asks two things: that expired medicine cannot be sold, and
 * that the shop can show what it destroyed, when, and what it was worth. The
 * first is already enforced. This is the second, and it is a *document* — so
 * the product name, batch number and expiry are snapshotted onto each line.
 * A record that changes when someone later edits the batch is not a record.
 *
 * There is deliberately no "pending disposal" table. A batch is awaiting
 * disposal when its expiry has passed and its purchase lots still hold
 * quantity — both facts already exist, and storing a second copy of them is how
 * `stocks.productStock` came to disagree with the lots on 131 of 158 expired
 * batches.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_disposals')) {
            Schema::create('stock_disposals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('user_id');
                $table->string('disposal_no', 50);
                $table->date('disposal_date')->index();
                $table->string('reason', 100)->default('Expired Stock');
                $table->text('note')->nullable();
                $table->decimal('total_quantity', 20, 2)->default(0);
                $table->decimal('total_value', 20, 2)->default(0);
                $table->timestamps();

                $table->unique(['business_id', 'disposal_no']);
            });
        }

        if (! Schema::hasTable('stock_disposal_lines')) {
            Schema::create('stock_disposal_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('stock_disposal_id')->index();
                $table->unsignedBigInteger('business_id')->index();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->unsignedBigInteger('stock_id')->nullable();

                // Snapshotted, so a later edit to the product or batch cannot
                // rewrite what the shop reported having destroyed.
                $table->string('product_name')->nullable();
                $table->string('batch_no', 100)->nullable();
                $table->date('expire_date')->nullable();

                $table->decimal('quantity', 20, 2)->default(0);
                $table->decimal('unit_cost', 20, 4)->default(0);
                $table->decimal('value', 20, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_disposal_lines');
        Schema::dropIfExists('stock_disposals');
    }
};
