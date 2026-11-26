<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UPI transaction reference and the time the payment cleared.
 *
 * A UPI payment previously recorded only an amount (`upi_amount`) and the sale's own
 * date. The customer's app shows a UTR / reference number and a timestamp, and the
 * bank statement is keyed on them — with neither stored, a disputed or missing payment
 * could not be matched against the statement at all, which is what makes reconciliation
 * after a UPI payment unreliable.
 *
 * Both columns are nullable: cash and card sales have no reference, and existing UPI
 * rows have none to backfill.
 *
 * Also adds a place to keep the shop's own QR image. The QR shown at the till is
 * currently fetched from a third-party renderer (`api.qrserver.com`) on every payment,
 * so a slow or unreachable third party stops the till taking money. Storing the shop's
 * real QR removes that dependency from the payment path.
 */
return new class extends Migration
{
    /** Tables that record a payment and therefore may carry a UPI reference. */
    private const PAYMENT_TABLES = [
        'sales',
        'purchases',
        'customer_due_collects',
        'due_collects',
    ];

    public function up(): void
    {
        foreach (self::PAYMENT_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'upi_reference')) {
                    // UTR strings are 12-22 characters today; 100 leaves room for
                    // other rails without another migration.
                    $blueprint->string('upi_reference', 100)->nullable();
                    $blueprint->index('upi_reference');
                }

                if (! Schema::hasColumn($table, 'upi_paid_at')) {
                    // When the payment cleared, which is not necessarily when the
                    // sale was recorded — the two differ whenever a sale is entered
                    // offline and synced later.
                    $blueprint->timestamp('upi_paid_at')->nullable();
                }
            });
        }

        Schema::table('businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('businesses', 'upi_qr_image')) {
                $table->string('upi_qr_image')->nullable()->after('upi_id');
            }
        });
    }

    public function down(): void
    {
        foreach (self::PAYMENT_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (Schema::hasColumn($table, 'upi_reference')) {
                    // Passing the column lets Laravel derive the same name it
                    // generated in up(); passing a name would have it append
                    // another '_index' suffix.
                    $blueprint->dropIndex(['upi_reference']);
                    $blueprint->dropColumn('upi_reference');
                }

                if (Schema::hasColumn($table, 'upi_paid_at')) {
                    $blueprint->dropColumn('upi_paid_at');
                }
            });
        }

        Schema::table('businesses', function (Blueprint $table) {
            if (Schema::hasColumn('businesses', 'upi_qr_image')) {
                $table->dropColumn('upi_qr_image');
            }
        });
    }
};
