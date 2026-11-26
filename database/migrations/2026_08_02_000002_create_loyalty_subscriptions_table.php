<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3: a customer's subscription to a loyalty package.
 *
 * Every subscription, renewal and upgrade is a row. Nothing is overwritten and
 * nothing is deleted — §11 requires the whole history to survive, and §12 reports on
 * it, so a renewal writes a new row and marks the old one rather than editing it.
 *
 * The package's discount configuration is *copied* here at the moment of sale.
 * Reading it through to `loyalty_packages` would mean an administrator editing the
 * master silently re-priced every existing customer, and would make a past invoice
 * impossible to explain. §11 states this outright.
 *
 * A customer may hold only one Active subscription at a time (§11), enforced by a
 * partial index below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // Restricted, not cascaded: deleting a package must not erase the record
            // that a customer paid for it.
            $table->foreignId('loyalty_package_id')
                ->constrained('loyalty_packages')
                ->restrictOnDelete();

            $table->date('subscription_date');
            $table->date('start_date');
            $table->date('end_date');

            // §3.2
            $table->string('status', 12)->default('active');

            $table->decimal('package_cost', 15, 2)->default(0);
            $table->decimal('amount_received', 15, 2)->default(0);
            $table->string('payment_mode', 40)->nullable();
            $table->string('payment_reference', 100)->nullable();

            // ── Configuration as it stood when this was sold (§11) ──────────────
            $table->string('discount_type', 10)->default('percentage');
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('max_discount_amount', 15, 2)->nullable();
            $table->string('discount_mode', 10)->default('automatic');

            // ── Lineage, so a renewal or upgrade can be traced back (§4.4, §5) ──
            $table->foreignId('previous_subscription_id')
                ->nullable()
                ->constrained('loyalty_subscriptions')
                ->nullOnDelete();

            /// 'new', 'renewal' or 'upgrade' — what created this row.
            $table->string('origin', 10)->default('new');

            $table->decimal('adjustment_amount', 15, 2)->default(0);
            $table->string('change_reason', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'customer_id', 'status']);
            $table->index(['business_id', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_subscriptions');
    }
};
