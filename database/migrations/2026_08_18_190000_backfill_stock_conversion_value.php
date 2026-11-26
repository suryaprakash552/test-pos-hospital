<?php

use Illuminate\Database\Migrations\Migration;
use App\Support\StockConversionBackfill;
use Illuminate\Support\Facades\Log;

/**
 * Give every batch its own conversion value.
 *
 * `stocks.conversionValue` says how many lower units (tablets) make an upper one
 * (a strip). It was never written: all 4,139 rows held NULL, and the real figure
 * lived only on the purchase lots that filled the batch.
 *
 * Readers papered over it with a fallback chain — the expired-products report
 * divides by `COALESCE(NULLIF(stocks.conversionValue,0), (SELECT MAX(conversionValue)
 * FROM purchase_details ...), 1)` — and that chain ends in **1**. A batch with no
 * surviving lot rows therefore divides by one and reports tablets as though they
 * were strips: at a conversion of 50, fifty times the stock that is on the shelf.
 *
 * Resolved per batch, preferring the lots that actually filled it over the
 * product's current setting, because the product's value can be edited after the
 * fact while the lot records what was bought.
 */
return new class extends Migration
{
    public function up(): void
    {
        Log::info('Backfilled stocks.conversionValue', StockConversionBackfill::run());
    }

    public function down(): void
    {
        // Deliberately not reversible. The column held NULL for every row, so
        // there is no prior state worth restoring, and clearing it again would
        // reintroduce the divide-by-one that misreports stock.
    }
};
