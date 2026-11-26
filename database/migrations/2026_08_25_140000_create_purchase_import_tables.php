<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier bill on its way in, before it becomes a purchase.
 *
 * The existing bulk upload parses a spreadsheet and writes the purchase straight
 * away — its own note says it "creates a real purchase and increments stock".
 * That is defensible for a clean CSV a shop prepared itself. It is not
 * defensible for a photographed bill: OCR misreads a quantity or a rate, nobody
 * ever sees the parsed lines, and wrong stock is written with no way to notice
 * later except an audit.
 *
 * So an import lands here first. The batch holds the file and how far it got;
 * the lines hold what was read out of it, each one matched to a product or
 * explicitly not matched. Nothing touches stock until someone has looked at the
 * lines and confirmed them, and the confirmation then goes through the ordinary
 * purchase endpoint — there is no second way to write a purchase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_import_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();

            // Known up front for a CSV the shop prepared; read off the document
            // for a PDF or a photo, and confirmed by the user either way.
            $table->unsignedBigInteger('party_id')->nullable();

            // How the bill arrived. The parser differs per source; everything
            // after parsing is identical.
            $table->enum('source', ['csv', 'excel', 'pdf', 'photo'])->default('csv');

            $table->string('original_filename')->nullable();
            $table->string('disk', 40)->nullable();
            $table->string('path')->nullable();

            /*
             * queued      — accepted, waiting for a worker
             * processing  — a worker has it
             * ready       — parsed, waiting for someone to review it
             * committed   — turned into a purchase
             * failed      — could not be parsed; `error` says why
             * discarded   — the user threw it away
             */
            $table->enum('status', [
                'queued', 'processing', 'ready', 'committed', 'failed', 'discarded',
            ])->default('queued');

            // Shown to the user rather than only logged: an import that failed
            // silently is one the shop keeps retrying.
            $table->text('error')->nullable();

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('matched_rows')->default(0);

            // Read off the document where we can, so the user is correcting a
            // filled-in form rather than typing one.
            $table->string('reference_invoice_no', 100)->nullable();
            $table->date('bill_date')->nullable();

            // The purchase it became, so a committed import can be traced to it.
            $table->unsignedBigInteger('purchase_id')->nullable();

            $table->timestamp('parsed_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::create('purchase_import_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_import_batch_id')
                ->constrained()
                ->cascadeOnDelete();

            // Where it came from in the file, so a correction can be pointed at
            // the row the user is looking at on the paper.
            $table->unsignedInteger('row_number')->default(0);

            // Exactly what the parser read, kept even after the user edits the
            // parsed fields. When a supplier's layout is misread, this is the
            // only record of what the document actually said.
            $table->json('raw')->nullable();

            $table->unsignedBigInteger('product_id')->nullable();

            /*
             * matched     — resolved to one product with confidence
             * ambiguous   — more than one candidate; the user picks
             * unmatched   — nothing found; the user picks or skips
             * confirmed   — the user settled it
             * skipped     — deliberately left out of the purchase
             */
            $table->enum('match_status', [
                'matched', 'ambiguous', 'unmatched', 'confirmed', 'skipped',
            ])->default('unmatched');

            // What the document said, before matching. Kept as text because a
            // bill may name a product we do not stock under that name.
            $table->string('product_name')->nullable();
            $table->string('product_code', 100)->nullable();
            $table->string('batch_no', 100)->nullable();

            $table->decimal('quantity', 15, 3)->nullable();
            $table->decimal('free_quantity', 15, 3)->nullable();
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->decimal('mrp', 15, 2)->nullable();
            $table->decimal('discount_percent', 8, 2)->nullable();
            $table->decimal('tax_percent', 8, 2)->nullable();

            $table->date('expire_date')->nullable();
            $table->date('mfg_date')->nullable();

            // Why a line could not be matched or looks wrong, in words the user
            // can act on rather than a code.
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(
                ['purchase_import_batch_id', 'match_status'],
                'import_lines_batch_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_import_lines');
        Schema::dropIfExists('purchase_import_batches');
    }
};
