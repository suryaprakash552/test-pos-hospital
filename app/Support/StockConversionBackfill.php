<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Gives every batch its own conversion value.
 *
 * `stocks.conversionValue` says how many lower units (tablets) make an upper one
 * (a strip). It was never written: every row held NULL, and the real figure
 * lived only on the purchase lots that filled the batch.
 *
 * Readers papered over it with a fallback chain — the expired-products report
 * divides by `COALESCE(NULLIF(stocks.conversionValue,0), (SELECT MAX(...) FROM
 * purchase_details ...), 1)` — and that chain ends in **1**. A batch with no
 * surviving lot rows therefore divides by one and reports tablets as though they
 * were strips: at a conversion of 50, fifty times the stock on the shelf.
 *
 * Lives here rather than inside the migration so it can be tested directly and
 * re-run if new rows ever arrive unset.
 */
class StockConversionBackfill
{
    /**
     * @return array{from_lots:int, from_product:int, defaulted_to_one:int}
     */
    public static function run(): array
    {
        // Preferring the lot over the product's current setting: the product's
        // value can be edited after the fact, while the lot records what was
        // actually bought into this batch.
        $fromLots = self::unset()->update([
            'conversionValue' => DB::raw(
                '(SELECT MAX(pd.conversionValue) FROM purchase_details pd
                   WHERE pd.stock_id = stocks.id AND pd.conversionValue > 0)'
            ),
        ]);

        // Nothing bought into the batch yet, so the product is the only source.
        $fromProduct = self::unset()->update([
            'conversionValue' => DB::raw(
                '(SELECT p.conversionValue FROM products p
                   WHERE p.id = stocks.product_id AND p.conversionValue > 0)'
            ),
        ]);

        // No conversion recorded anywhere means the product is sold in a single
        // unit. One, not zero — zero would divide by nothing.
        $defaulted = self::unset()->update(['conversionValue' => 1]);

        return [
            'from_lots' => $fromLots,
            'from_product' => $fromProduct,
            'defaulted_to_one' => $defaulted,
        ];
    }

    /** Batches with no usable conversion value. */
    private static function unset(): \Illuminate\Database\Query\Builder
    {
        return DB::table('stocks')->where(function ($query) {
            $query->whereNull('conversionValue')->orWhere('conversionValue', 0);
        });
    }
}
