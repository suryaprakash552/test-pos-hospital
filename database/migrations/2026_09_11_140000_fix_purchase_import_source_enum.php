<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The enum said 'excel'; every caller says 'xlsx'.
 *
 * `PurchaseImportController::store()` validates `source` against
 * `['csv', 'xlsx', 'pdf', 'photo']`, `ParsePurchaseImportJob` matches on
 * `'xlsx'`, and the app sends `'xlsx'` — but this column was created as
 * `enum('csv','excel','pdf','photo')`, which has no `'xlsx'` member. MySQL's
 * response to an enum value it does not recognise is not to reject the
 * insert outright: it substitutes the empty string and raises a *warning*
 * ("Data truncated for column 'source'"), which this app's strict
 * connection mode turns into a hard exception — so every Excel-invoice
 * import has failed at the very first insert since the feature shipped.
 * `SELECT source, COUNT(*) ... GROUP BY source` on production confirms it:
 * pdf, photo and csv batches exist; not one xlsx batch ever has.
 *
 * `'excel'` is replaced rather than kept alongside `'xlsx'` because nothing
 * has ever written it — there is no historical row to stay compatible with.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE purchase_import_batches MODIFY source ENUM('csv','xlsx','pdf','photo') NOT NULL DEFAULT 'csv'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE purchase_import_batches MODIFY source ENUM('csv','excel','pdf','photo') NOT NULL DEFAULT 'csv'"
        );
    }
};
