<?php

namespace App\Services;

use App\Models\Stock;
use App\Services\Inventory\MovementContext;
use Illuminate\Support\Facades\DB;

/**
 * Draws stock out of a batch's purchase lots, and puts it back where it came from.
 *
 * Lifted verbatim out of `AcnooSaleController` so a sale created by the till and a
 * sale created by an import consume stock through the same code. Two implementations
 * of this would be two chances to get FIFO wrong, and the failure mode is silent —
 * stock that reads correct in one place and not another, which is exactly the class of
 * bug that made items unsellable while showing stock.
 *
 * Lots are drawn oldest first (`orderBy('id')`), matching the order purchases were
 * recorded in, and locked for update so two concurrent sales cannot both consume the
 * last unit.
 *
 * ## Lineage
 *
 * Every lot actually moved writes an event naming that lot, the document behind it,
 * and a correlation id shared by the whole operation. Those events *are* the
 * allocation record — there is no second table holding the same fact.
 *
 * This matters because reversal used to return the entire quantity to the newest
 * lot. A sale taking 30 from one lot and 20 from another gave back 50 to the second:
 * the batch total came out right, so nothing looked wrong, while the lots underneath
 * it now misattributed both what remained and what it had cost. Reversal reads the
 * recorded allocation instead and gives each lot back exactly what it lent.
 */
class BatchConsumption
{
    /**
     * Takes [quantity] out of the batch, oldest lot first.
     *
     * Returns false and changes nothing when the batch does not hold enough — the
     * caller is expected to abort. Checking the total *before* deducting is what makes
     * that safe: a partial deduction followed by a failure would leave the batch short
     * with no sale to account for it.
     *
     * Without a [context] no events are written, which keeps the older two-argument
     * call working. Such a movement leaves no lineage and cannot be reversed per lot,
     * so callers that own a source document should always pass one.
     */
    public static function consume(int $stockId, float $quantity, ?MovementContext $context = null): bool
    {
        $remainingQty = $quantity;

        $purchases = DB::table('purchase_details')
            ->where('stock_id', $stockId)
            ->where('conversionQuantity', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($purchases->sum('conversionQuantity') < $quantity) {
            return false;
        }

        $stock = $context ? self::stockFor($stockId) : null;

        foreach ($purchases as $purchase) {
            if ($remainingQty <= 0) {
                break;
            }

            $deductQty = min($purchase->conversionQuantity, $remainingQty);

            DB::table('purchase_details')
                ->where('id', $purchase->id)
                ->update([
                    'conversionQuantity' => $purchase->conversionQuantity - $deductQty,
                ]);

            if ($context && $stock) {
                app(InventoryChangeService::class)->recordLotMovement(
                    $context,
                    InventoryChangeService::SALE_OUT,
                    $stock,
                    (int) $purchase->id,
                    -$deductQty,
                    ['conversionQuantity' => (float) $purchase->conversionQuantity],
                    ['conversionQuantity' => (float) $purchase->conversionQuantity - $deductQty],
                );
            }

            $remainingQty -= $deductQty;
        }

        return true;
    }

    /**
     * Gives back exactly what this document took, lot by lot.
     *
     * Reads the allocation recorded when the stock went out and reverses it. No
     * fresh FIFO pass: re-allocating on the way back is what put 50 units onto a
     * lot that had only lent 20.
     *
     * Restoring twice is not possible. The outstanding figure is the net of every
     * movement this document has made against each lot, so once a lot has been given
     * back it nets to zero and is skipped — the ledger is the guard, rather than a
     * separate flag that could drift out of step with it.
     *
     * Returns the quantity actually restored.
     */
    public static function restoreAllocation(int $stockId, MovementContext $context): float
    {
        $service = app(InventoryChangeService::class);
        $outstanding = $service->outstandingAllocation($context, $stockId);

        if ($outstanding === []) {
            return 0.0;
        }

        $stock = self::stockFor($stockId);
        $restored = 0.0;

        // Newest lot first: undoing a FIFO draw in reverse consumption order, so a
        // partially reversed sale gives back the most recently taken units first.
        krsort($outstanding);

        foreach ($outstanding as $purchaseDetailId => $quantity) {
            $lot = DB::table('purchase_details')
                ->where('id', $purchaseDetailId)
                ->lockForUpdate()
                ->first();

            if (! $lot) {
                continue;
            }

            DB::table('purchase_details')
                ->where('id', $purchaseDetailId)
                ->update(['conversionQuantity' => $lot->conversionQuantity + $quantity]);

            $service->recordLotMovement(
                $context,
                InventoryChangeService::SALE_REVERSAL_IN,
                $stock,
                (int) $purchaseDetailId,
                $quantity,
                ['conversionQuantity' => (float) $lot->conversionQuantity],
                ['conversionQuantity' => (float) $lot->conversionQuantity + $quantity],
            );

            $restored += $quantity;
        }

        return round($restored, 4);
    }

    /**
     * Puts [quantity] back, onto the newest lot.
     *
     * @deprecated Cannot restore per-lot lineage. Use {@see restoreAllocation()} for
     *             anything with a source document; this remains only for a movement
     *             that was made without one and so has no allocation to read back.
     *
     * @throws \RuntimeException
     */
    public static function restore(int $stockId, float $quantity): void
    {
        $purchaseDetail = DB::table('purchase_details')
            ->where('stock_id', $stockId)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if (! $purchaseDetail) {
            throw new \RuntimeException('Purchase detail not found for selected batch');
        }

        DB::table('purchase_details')
            ->where('id', $purchaseDetail->id)
            ->update([
                'conversionQuantity' => $purchaseDetail->conversionQuantity + $quantity,
            ]);
    }

    /** How much the batch currently holds across all its lots. */
    public static function available(int $stockId): float
    {
        return (float) DB::table('purchase_details')
            ->where('stock_id', $stockId)
            ->where('conversionQuantity', '>', 0)
            ->sum('conversionQuantity');
    }

    /** Locked, so a rebuild cannot race the movement that triggered it. */
    private static function stockFor(int $stockId): Stock
    {
        return Stock::query()->lockForUpdate()->findOrFail($stockId);
    }
}
