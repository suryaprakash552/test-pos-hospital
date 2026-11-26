<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sales bill on its way in, before it becomes a sale.
 *
 * The mirror of purchase_import_batches/purchase_import_lines, for the same
 * reason that pair exists: the old bulk upload writes the sale (and deducts
 * stock) from inside the job with nobody having seen the parsed lines first,
 * which is fine for a clean spreadsheet the shop prepared itself and not
 * defensible for a photographed or PDF bill where a misread quantity would
 * sell stock that was never actually sold.
 *
 * A sale has no batch to create the way a purchase does - it draws down one
 * that already exists - so a line here also carries `stock_id`: which batch
 * of the matched product this row is meant to sell from. A row can match a
 * product and still have no batch decided, if more than one of the
 * product's batches holds stock; the review screen is where that gets
 * settled, the same way an unmatched product does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_import_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();

            // Known up front when the shop chose a customer before uploading;
            // read off the document otherwise, and confirmed by the user
            // either way. Null is a walk-in sale, same as the till allows.
            $table->unsignedBigInteger('customer_id')->nullable();

            $table->enum('source', ['csv', 'xlsx', 'pdf', 'photo'])->default('csv');

            $table->string('original_filename')->nullable();
            $table->string('disk', 40)->nullable();
            $table->string('path')->nullable();

            $table->enum('status', [
                'queued', 'processing', 'ready', 'committed', 'failed', 'discarded',
            ])->default('queued');

            $table->text('error')->nullable();

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('matched_rows')->default(0);

            $table->string('reference_invoice_no', 100)->nullable();
            $table->date('sale_date')->nullable();

            // The sale it became, so a committed import can be traced to it.
            $table->unsignedBigInteger('sale_id')->nullable();

            $table->timestamp('parsed_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::create('sale_import_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sale_import_batch_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('row_number')->default(0);

            $table->json('raw')->nullable();

            $table->unsignedBigInteger('product_id')->nullable();

            // Which of the matched product's batches this line sells from.
            // Resolved automatically when exactly one batch holds stock;
            // left for the reviewer to choose when more than one does, the
            // same way an unmatched product is.
            $table->unsignedBigInteger('stock_id')->nullable();

            $table->enum('match_status', [
                'matched', 'ambiguous', 'unmatched', 'confirmed', 'skipped',
            ])->default('unmatched');

            $table->string('product_name')->nullable();
            $table->string('product_code', 100)->nullable();
            $table->string('batch_no', 100)->nullable();

            $table->decimal('quantity', 15, 3)->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->decimal('discount_percent', 8, 2)->nullable();
            $table->decimal('tax_percent', 8, 2)->nullable();

            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(
                ['sale_import_batch_id', 'match_status'],
                'sale_import_lines_batch_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_import_lines');
        Schema::dropIfExists('sale_import_batches');
    }
};
