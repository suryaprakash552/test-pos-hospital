<?php

namespace App\Support\Api;

use App\Exceptions\BatchIdentityProtected;
use App\Services\InventoryChangeService;
use Illuminate\Support\Facades\DB;

use App\Models\Stock;

class PurchaseLineBuilder
{
    public static function stockFor(array $productData, int $businessId): Stock
    {
        $batchNo = isset($productData['batch_no'])
            ? trim((string) $productData['batch_no'])
            : null;
        $batchNo = $batchNo === '' ? null : $batchNo;

        $values = [
            'product_id' => $productData['product_id'],
            'profit_percent' => $productData['profit_percent'] ?? 0,
            'productSalePrice' => $productData['productSalePrice'] ?? 0,
            'productDealerPrice' => $productData['productDealerPrice'] ?? $productData['productPurchasePrice'] ?? 0,
            'productPurchasePrice' => $productData['productPurchasePrice'] ?? 0,
            'conversionPurchasePrice' => $productData['conversion_purchase_price'] ?? 0,
            'productWholeSalePrice' => $productData['productWholeSalePrice'] ?? 0,
        ];

        // A top-up of an existing batch is allowed to omit dates. Do not turn
        // that omission into an empty-string update which erases dates already
        // stored on the batch. New batches simply retain the nullable defaults.
        foreach (['mfg_date', 'expire_date'] as $dateField) {
            $date = trim((string) ($productData[$dateField] ?? ''));
            if ($date !== '') {
                $values[$dateField] = $date;
            }
        }

        // The batch's own conversion, written alongside the prices it belongs
        // with.
        //
        // It was the one figure on the line that never reached the stock row:
        // `detailPayload` below records it on the lot, but the batch itself kept
        // whatever it had, which for a batch a purchase created was nothing. A
        // backfill fixed the history and the next purchase reintroduced it.
        //
        // Everything that prices or counts a converted unit reads it from the
        // stock row — `CartCalculationService` divides by it to turn a strip
        // price into a tablet price — and a missing value silently means "one",
        // which bills a tablet at the price of a whole strip.
        //
        // Only written when the line actually carries one, so a purchase that
        // omits it cannot erase a value the batch already knows.
        $conversionValue = (float) ($productData['conversion_value'] ?? 0);

        if ($conversionValue > 0) {
            $values['conversionValue'] = $conversionValue;
        }

        // Selecting an existing batch in the app sends its stock id. Prefer that
        // stable identity over matching display text: whitespace/case changes in
        // a batch number must never create a second stock row and leave the
        // original quantity unchanged.
        if (! empty($productData['stock_id'])) {
            $stock = Stock::query()
                ->whereKey($productData['stock_id'])
                ->where('business_id', $businessId)
                ->where('product_id', $productData['product_id'])
                ->lockForUpdate()
                ->firstOrFail();

            // A renamed batch is a different batch, unless nothing has left it.
            //
            // `$values` never carried `batch_no`, so editing the number on an
            // existing line simply did nothing: the row kept its old identity
            // and the app showed the old value again on reload. Correcting a
            // mistyped number is a reasonable thing to want, so it is allowed -
            // but only while the batch has no history under the name it is
            // losing.
            $incoming = trim((string) ($productData['batch_no'] ?? ''));

            if ($incoming !== '' && $incoming !== trim((string) $stock->batch_no)) {
                $lineage = self::batchLineage($stock->id);

                if ($lineage !== []) {
                    throw new BatchIdentityProtected(
                        (int) $stock->id,
                        (string) $stock->batch_no,
                        $incoming,
                        $lineage,
                    );
                }

                $values['batch_no'] = $incoming;
            }

            $stock->fill($values)->save();

            return $stock;
        }

        $stock = Stock::updateOrCreate(
            [
                'batch_no' => $batchNo,
                'business_id' => $businessId,
                'product_id' => $productData['product_id'],
            ],
            $values
        );

        return Stock::whereKey($stock->id)->lockForUpdate()->firstOrFail();
    }


    /**
     * What has already happened under this batch's name.
     *
     * Deliberately not "does it still hold stock". A batch sold down to zero and
     * then restocked has the strongest possible reason not to be renamed, and a
     * full batch nobody has touched has none - current quantity answers neither
     * question.
     *
     * Inventory events are included because they are the per-lot record a sale
     * writes, so they catch movement even where the sale line itself has since
     * been edited away.
     *
     * @return array<string,int> non-zero counts only
     */
    public static function batchLineage(int $stockId): array
    {
        // Only movement *after* the batch existed counts.
        //
        // The purchase that created the batch writes an event against it too,
        // and counting that would make every batch unrenameable the moment it
        // was received - including the mistyped one the operator is trying to
        // correct.
        $movementEvents = [
            InventoryChangeService::SALE_OUT,
            InventoryChangeService::SALE_RETURN_IN,
            InventoryChangeService::SALE_REVERSAL_IN,
            InventoryChangeService::PURCHASE_RETURN_OUT,
            InventoryChangeService::DISPOSAL_OUT,
            InventoryChangeService::STOCK_AUDIT_ADJUSTMENT,
        ];

        $counts = [
            'sales' => DB::table('sale_details')->where('stock_id', $stockId)->count(),
            'inventory_events' => DB::table('inventory_change_logs')
                ->where('stock_id', $stockId)
                ->whereIn('event_type', $movementEvents)
                ->count(),
            'disposals' => DB::table('stock_disposal_lines')->where('stock_id', $stockId)->count(),
            'audits' => DB::table('stock_audits')->where('stock_id', $stockId)->count(),
        ];

        return array_filter($counts, static fn (int $n): bool => $n > 0);
    }

    public static function detailPayload(array $productData, int $purchaseId, int $stockId): array
    {
        return [
            'stock_id' => $stockId,
            'purchase_id' => $purchaseId,
            'product_id' => $productData['product_id'],
            'quantities' => $productData['quantities'],
            'free_quantity' => $productData['free_quantity'] ?? 0,
            'productSalePrice' => $productData['productSalePrice'],
            'productDealerPrice' => $productData['productDealerPrice'] ?? $productData['productPurchasePrice'] ?? 0,
            'productPurchasePrice' => $productData['productPurchasePrice'],
            'conversionPurchasePrice' => $productData['conversion_purchase_price'],
            'conversionQuantity' => $productData['conversion_quantities'],
            'conversionValue' => $productData['conversion_value'],
            'conversionPrice' => $productData['conversion_price'],
            'totalPurchasePrice' => $productData['total_purchase_price'],
            'productWholeSalePrice' => $productData['productWholeSalePrice'] ?? 0,
            'profit_percent' => $productData['profit_percent'] ?? 0,
            'purchaseUpperLimit' => $productData['upper_selected_unit'] ?? null,
            'purchaseLowerLimit' => $productData['lower_selected_unit'] ?? null,
            'totalPrice' => $productData['total_price'],
            'expire_date' => $productData['expire_date'],
            'mfg_date' => $productData['mfg_date'],
        ];
    }
}
