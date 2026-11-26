<?php

namespace App\Services;

use App\Models\PurchaseDetails;
use App\Models\SaleDetails;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a batch's purchase lots consistent with what has actually been sold from it.
 *
 * ## The invariant
 *
 * `purchase_details.conversionQuantity` is what *remains* of a lot, drawn down FIFO
 * as items sell. For any batch:
 *
 *     sum(remaining across lots) = sum(purchased across lots) - sold
 *
 * ## Why this is needed
 *
 * Editing a purchase deletes its detail rows and re-inserts them at the quantities
 * the client sent. That wipes the FIFO draw-down: a lot whose stock had been sold
 * comes back at its full quantity, silently un-selling it and inflating stock.
 *
 * Because of that, purchase edits used to be refused outright as soon as anything
 * from the batch had sold. Replaying consumption after the re-insert restores the
 * invariant, so an edit only has to be refused when it would take a batch *below*
 * what has already left the shelf — which is genuinely impossible rather than merely
 * inconvenient.
 *
 * Quantities here are in the conversion (lower) unit, matching `sale_details.quantities`
 * and `purchase_details.conversionQuantity`. `purchase_details.quantities` is in the
 * upper unit, so it is scaled by `conversionValue`.
 */
class BatchLotReconciler
{
    /** Quantity sold from a batch, straight from sales history. */
    public static function soldFor(int $stockId): float
    {
        return (float) SaleDetails::where('stock_id', $stockId)->sum('quantities');
    }

    /**
     * Quantity ever purchased into a batch, across every purchase that feeds it.
     *
     * A batch is keyed by (batch_no, business, product), so several purchases can
     * contribute to one — the comparison has to be batch-wide, not per purchase.
     */
    public static function purchasedFor(int $stockId): float
    {
        return (float) self::lots($stockId)->sum(
            static fn (PurchaseDetails $lot): float => self::lotSize($lot)
        );
    }

    /**
     * Returns null when the batch is consistent, or the numbers describing the
     * shortfall when its lots no longer cover what was sold.
     *
     * @return array{sold: float, purchased: float, shortfall: float}|null
     */
    public static function shortfall(int $stockId): ?array
    {
        $sold      = self::soldFor($stockId);
        $purchased = self::purchasedFor($stockId);

        if ($purchased >= $sold) {
            return null;
        }

        return [
            'sold'      => round($sold, 4),
            'purchased' => round($purchased, 4),
            'shortfall' => round($sold - $purchased, 4),
        ];
    }

    /**
     * Rewrites the batch's lot remainders so they again reflect purchases minus sales.
     *
     * Draws the sold quantity down the lots oldest-first, matching the order the sale
     * endpoint consumes them in. Safe to run repeatedly: it derives each remainder
     * from stored quantities rather than adjusting whatever is currently there.
     */
    public static function replay(int $stockId): void
    {
        $outstanding = self::soldFor($stockId);

        foreach (self::lots($stockId) as $lot) {
            $size = self::lotSize($lot);
            $take = min($size, $outstanding);

            $remaining = round($size - $take, 4);

            if ((float) $lot->conversionQuantity !== $remaining) {
                DB::table('purchase_details')
                    ->where('id', $lot->id)
                    ->update(['conversionQuantity' => $remaining]);
            }

            $outstanding = round($outstanding - $take, 4);
        }
    }

    /**
     * Lots feeding a batch, oldest first — the order sales consume them in.
     *
     * @return \Illuminate\Support\Collection<int, PurchaseDetails>
     */
    private static function lots(int $stockId)
    {
        return PurchaseDetails::where('stock_id', $stockId)
            ->orderBy('id')
            ->get();
    }

    /** A lot's full size in conversion units. */
    private static function lotSize(PurchaseDetails $lot): float
    {
        $conversionValue = (float) ($lot->conversionValue ?? 0);

        $received = (float) $lot->quantities + (float) ($lot->free_quantity ?? 0);

        return $received * ($conversionValue > 0 ? $conversionValue : 1);
    }
}
