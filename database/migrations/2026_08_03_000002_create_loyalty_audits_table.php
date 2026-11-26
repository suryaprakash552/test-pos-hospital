<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §14: an audit trail for every loyalty activity that moves money or entitlement.
 *
 * Separate from `gst_configuration_histories` despite the similar shape, because the
 * questions differ: that table answers "what was this shop billing under", this one
 * answers "who gave this customer that discount, and why". Merging them would make
 * both harder to query and neither easier to keep.
 *
 * Append-only by intent — nothing updates or deletes a row here. An audit that can be
 * edited answers nothing, so a correction is another row rather than a rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            /// What happened: package.created, subscription.upgraded, discount.manual…
            $table->string('event', 60);

            // Polymorphic: a package, a subscription, or a sale carrying a discount.
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            $table->foreignId('customer_id')->nullable()
                ->constrained('customers')->nullOnDelete();

            $table->string('field', 60)->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            $table->string('reason', 255)->nullable();

            // Nulled rather than cascaded: removing a user must not erase the record
            // that they granted a discount.
            $table->foreignId('changed_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->unsignedBigInteger('branch_id')->nullable();

            // §14: "device or IP details, where available".
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['business_id', 'event']);
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['business_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_audits');
    }
};
