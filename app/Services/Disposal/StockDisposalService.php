<?php

namespace App\Services\Disposal;

use App\Models\Stock;
use App\Models\StockDisposal;
use App\Models\StockDisposalLine;
use App\Services\Pricing\CartCalculationException;
use App\Services\Inventory\MovementContext;
use App\Services\InventoryChangeService;
use App\Support\ActiveBranch;
use Illuminate\Support\Facades\DB;

/**
 * Takes expired stock off the shelf and records what was taken.
 *
 * ## Where the quantity comes from
 *
 * From the **purchase lots**, never from `stocks.productStock`. The lots are the
 * ledger a sale consumes and the one `available_quantity` is summed from;
 * `productStock` is a second tally kept by increment and decrement, and it has
 * drifted badly — on the 158 expired batches in the live data, 57 hold a
 * *negative* figure and 131 disagree with their own lots.
 *
 * So a disposal drains the lots and then **sets** `productStock` to what is left,
 * rather than decrementing it. Decrementing would push those 57 further negative
 * and leave the other 131 wrong; setting it disposes the stock *and* repairs the
 * batch on the way past.
 *
 * ## Units
 *
 * Lots count lower units (tablets). `productStock` counts upper units (packs).
 * The division by `conversionValue` is the same one the sale path applies, and
 * the same one the sale return was missing until it was fixed.
 */
class StockDisposalService
{
    public function __construct(private readonly InventoryChangeService $inventoryChanges)
    {
    }
    /**
     * Dispose every expired batch named in [$stockIds].
     *
     * @param  array<int, int>  $stockIds
     */
    public function dispose(int $businessId, int $userId, array $stockIds, ?string $note = null): StockDisposal
    {
        $stockIds = array_values(array_unique(array_map('intval', $stockIds)));

        if ($stockIds === []) {
            throw CartCalculationException::reject(
                'empty_disposal',
                'Select at least one expired batch to dispose.',
            );
        }

        return DB::transaction(function () use ($businessId, $userId, $stockIds, $note) {
            $disposal = StockDisposal::create([
                'business_id' => $businessId,
                'branch_id' => ActiveBranch::forWrite(),
                'user_id' => $userId,
                'disposal_no' => $this->nextNumber($businessId),
                'disposal_date' => today(),
                'reason' => 'Expired Stock',
                'note' => $note,
            ]);

            $totalQuantity = 0.0;
            $totalValue = 0.0;
            $lines = [];

            foreach ($stockIds as $stockId) {
                $stock = Stock::query()
                    ->where('business_id', $businessId)
                    ->with('product:id,productName,conversionValue')
                    ->lockForUpdate()
                    ->find($stockId);

                if ($stock === null) {
                    throw CartCalculationException::reject(
                        'unknown_batch',
                        'One of these batches does not belong to this business.',
                        ['stock_id' => $stockId],
                        404,
                    );
                }

                // Re-checked here, inside the transaction, rather than trusted
                // from the request: this must never become a way to write off
                // stock that has not expired.
                if ($stock->expire_date === null || ! $stock->expire_date->lt(today())) {
                    throw CartCalculationException::reject(
                        'not_expired',
                        'Only expired batches can be disposed. '
                            .($stock->batch_no ? 'Batch '.$stock->batch_no : 'This batch')
                            .' has not expired.',
                        ['stock_id' => $stockId],
                    );
                }

                $drained = $this->drainLots($stockId);

                if ($drained['quantity'] <= 0) {
                    // Nothing left to dispose — another till may have sold it
                    // between the list being drawn and this running. Not an
                    // error: the shelf is already clear.
                    continue;
                }

                $conversion = (float) ($stock->conversionValue
                    ?? $stock->product?->conversionValue
                    ?? 1);
                $conversion = $conversion > 0 ? $conversion : 1.0;

                // Set, not decrement — see the class note.
                $remainingLower = (float) DB::table('purchase_details')
                    ->where('stock_id', $stockId)
                    ->sum('conversionQuantity');

                $stock->forceFill([
                    'productStock' => round($remainingLower / $conversion, 2),
                ])->save();
                $this->inventoryChanges->synchronizeDerivedTallies($stock);

                $movement = MovementContext::forSource(
                    $disposal,
                    'disposal',
                    'disposal-'.$disposal->id,
                    $note ?: 'Expired stock disposal',
                );

                foreach ($drained['lots'] as $lotId => $lotQuantity) {
                    $this->inventoryChanges->recordLotMovement(
                        $movement,
                        InventoryChangeService::DISPOSAL_OUT,
                        $stock,
                        (int) $lotId,
                        -$lotQuantity,
                        [
                            'remaining_quantity' => $lotQuantity,
                            'expire_date' => $stock->expire_date?->toDateString(),
                        ],
                        [
                            'remaining_quantity' => 0,
                            'expire_date' => $stock->expire_date?->toDateString(),
                        ],
                    );
                }

                $unitCost = $drained['quantity'] > 0
                    ? $drained['value'] / $drained['quantity']
                    : 0.0;

                $lines[] = [
                    'stock_disposal_id' => $disposal->id,
                    'business_id' => $businessId,
                    'product_id' => $stock->product_id,
                    'stock_id' => $stock->id,
                    'product_name' => $stock->product?->productName,
                    'batch_no' => $stock->batch_no,
                    'expire_date' => $stock->expire_date?->toDateString(),
                    'quantity' => round($drained['quantity'], 2),
                    'unit_cost' => round($unitCost, 4),
                    'value' => round($drained['value'], 2),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $totalQuantity += $drained['quantity'];
                $totalValue += $drained['value'];
            }

            if ($lines === []) {
                throw CartCalculationException::reject(
                    'nothing_to_dispose',
                    'None of the selected batches still holds stock.',
                );
            }

            StockDisposalLine::insert($lines);

            $disposal->forceFill([
                'total_quantity' => round($totalQuantity, 2),
                'total_value' => round($totalValue, 2),
            ])->save();

            $this->inventoryChanges->invalidateInventoryAfterCommit();

            return $disposal->load('lines');
        });
    }

    /**
     * Empty every lot behind one batch, reporting what was there.
     *
     * Oldest first, matching the order a sale consumes them, so the lots are
     * left in the state the rest of the system expects.
     *
     * @return array{quantity: float, value: float}
     */
    private function drainLots(int $stockId): array
    {
        $lots = DB::table('purchase_details')
            ->where('stock_id', $stockId)
            ->where('conversionQuantity', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'conversionQuantity', 'conversionPurchasePrice']);

        $quantity = 0.0;
        $value = 0.0;
        // What each lot gave up, so the disposal can be recorded lot by lot
        // rather than as one number for the whole batch. A batch bought over
        // three lots is three write-offs, at three different costs.
        $perLot = [];

        foreach ($lots as $lot) {
            $lotQuantity = (float) $lot->conversionQuantity;

            $quantity += $lotQuantity;
            $value += $lotQuantity * (float) ($lot->conversionPurchasePrice ?? 0);

            DB::table('purchase_details')
                ->where('id', $lot->id)
                ->update(['conversionQuantity' => 0]);

            $perLot[(int) $lot->id] = $lotQuantity;
        }

        return ['quantity' => $quantity, 'value' => $value, 'lots' => $perLot];
    }

    /** Sequential per business, so a document can be referred to by number. */
    private function nextNumber(int $businessId): string
    {
        $count = StockDisposal::query()
            ->where('business_id', $businessId)
            ->lockForUpdate()
            ->count();

        return 'DSP-'.str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }
}
