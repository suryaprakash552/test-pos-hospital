<?php

namespace App\Services\Pricing;

use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Stock;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * What a purchase return is worth, and whether the stock exists to send back.
 *
 * The refund is derived from the original `purchase_details` row - what the
 * supplier charged, times the quantity going back. `return_amount` used to be taken
 * from the request and written to both the credit and the supplier's balance, and
 * the return also rewrote the purchase's own totals from client figures.
 *
 * The extra concern here that a sale return does not have: returning goods to a
 * supplier *removes* stock. If some of the delivery has already been sold, the
 * stock to send back is not there, and decrementing anyway drives the batch
 * negative. That is checked before anything is written.
 */
class PurchaseReturnCalculationService
{
    /**
     * Price a return against its purchase.
     *
     * @param  array<int, array<string, mixed>>  $lines  [{purchase_detail_id, return_qty, free_return_qty}]
     */
    public function calculate(Purchase $purchase, array $lines): CalculatedPurchaseReturn
    {
        if ($lines === []) {
            throw CartCalculationException::reject('empty_return', 'A return must contain at least one item.');
        }

        $details = $this->loadDetails($purchase, $lines);

        $calculated = [];
        $totalRefund = Money::zero();

        // Accumulated per batch: two lines can draw on the same stock row, and each
        // checked alone would pass while together they overdraw it.
        $reductionByStock = [];

        foreach ($lines as $line) {
            $calculated[] = $result = $this->calculateLine($line, $details);

            $totalRefund = $totalRefund->plus($result->refund);

            if ($result->stockId !== null) {
                $reductionByStock[$result->stockId] =
                    ($reductionByStock[$result->stockId] ?? 0) + $result->stockReduction;
            }
        }

        $this->assertStockAvailable($purchase, $reductionByStock, $calculated);

        return $this->applyPurchaseLevel($purchase, $calculated, $totalRefund);
    }

    /**
     * Fetch the referenced lines, constrained to this purchase.
     *
     * The constraint is the tenant check: a `purchase_detail_id` from another
     * purchase is simply not found.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return Collection<int, PurchaseDetails>
     */
    private function loadDetails(Purchase $purchase, array $lines): Collection
    {
        $ids = collect($lines)
            ->pluck('purchase_detail_id')
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw CartCalculationException::reject(
                'invalid_return_line',
                'Every return line must reference a purchase line.',
            );
        }

        $details = PurchaseDetails::query()
            ->where('purchase_id', $purchase->id)
            ->whereIn('id', $ids)
            ->with('product:id,productName')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $missing = $ids->diff($details->keys());

        if ($missing->isNotEmpty()) {
            throw CartCalculationException::reject(
                'unknown_return_line',
                'One or more returned items do not belong to this purchase.',
                ['purchase_detail_ids' => $missing->values()->all()],
                404,
            );
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  Collection<int, PurchaseDetails>  $details
     */
    private function calculateLine(array $line, Collection $details): CalculatedPurchaseReturnLine
    {
        $detail = $details->get((int) ($line['purchase_detail_id'] ?? 0));
        $name = (string) ($detail->product?->productName ?? '');

        $quantity = $this->validateQuantity($line['return_qty'] ?? null, $name);
        $freeQuantity = $this->validateFreeReturnQuantity($line['free_return_qty'] ?? 0, $name);
        if ($quantity + $freeQuantity <= 0) {
            throw CartCalculationException::reject(
                'invalid_quantity',
                'Return at least one paid or free unit'.($name !== '' ? ' for '.$name : '').'.',
            );
        }
        $purchased = (float) ($detail->quantities ?? 0);
        $freePurchased = (float) ($detail->free_quantity ?? 0);

        if ($quantity > $purchased) {
            throw CartCalculationException::reject(
                'return_exceeds_purchased',
                'Cannot return '.$this->trim($quantity).' of '.($name !== '' ? $name : 'this item')
                    .'; only '.$this->trim($purchased).' remain on the purchase.',
                [
                    'purchase_detail_id' => (int) $detail->id,
                    'returnable' => $purchased,
                    'requested' => $quantity,
                ],
            );
        }

        if ($freeQuantity > $freePurchased) {
            throw CartCalculationException::reject(
                'free_return_exceeds_received',
                'Cannot return '.$this->trim($freeQuantity).' free units of '.($name !== '' ? $name : 'this item')
                    .'; only '.$this->trim($freePurchased).' free units remain.',
                [
                    'purchase_detail_id' => (int) $detail->id,
                    'free_returnable' => $freePurchased,
                    'requested' => $freeQuantity,
                ],
            );
        }

        $unitCost = Money::fromFloat($detail->productPurchasePrice ?? 0);

        // Stock is held in the lower unit, so a returned pack removes its whole
        // conversion, not one unit.
        $conversionValue = (float) ($detail->conversionValue ?? 1);
        $conversionValue = $conversionValue > 0 ? $conversionValue : 1.0;

        return new CalculatedPurchaseReturnLine(
            purchaseDetailId: (int) $detail->id,
            stockId: $detail->stock_id !== null ? (int) $detail->stock_id : null,
            productId: (int) $detail->product_id,
            productName: $name,
            returnQuantity: $quantity,
            freeReturnQuantity: $freeQuantity,
            remainingQuantity: $purchased - $quantity,
            remainingFreeQuantity: $freePurchased - $freeQuantity,
            stockReduction: $quantity + $freeQuantity,
            conversionValue: $conversionValue,
            unitCost: $unitCost,
            refund: $unitCost->times($quantity),
        );
    }

    /**
     * Refuse a return the stock cannot cover.
     *
     * Goods already sold on cannot also go back to the supplier. Without this the
     * decrement runs anyway and leaves the batch negative, which then reads as
     * available stock nobody has.
     *
     * @param  array<int, float>  $reductionByStock
     * @param  list<CalculatedPurchaseReturnLine>  $lines
     */
    private function assertStockAvailable(Purchase $purchase, array $reductionByStock, array $lines): void
    {
        if ($reductionByStock === []) {
            return;
        }

        $stocks = Stock::query()
            ->where('business_id', $purchase->business_id)
            ->whereIn('id', array_keys($reductionByStock))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($reductionByStock as $stockId => $required) {
            $stock = $stocks->get($stockId);

            if ($stock === null) {
                throw CartCalculationException::reject(
                    'unknown_stock',
                    'The batch for one of these items is not available for this business.',
                    ['stock_id' => $stockId],
                    404,
                );
            }

            // Measured from the purchase lots, not from `stocks.productStock`.
            //
            // `productStock` is a second, redundant tally kept by increment and
            // decrement, and it has drifted badly: on the live data 2,635 of
            // 4,924 batches hold a *negative* figure, and 4,363 of them
            // disagree with the lots. Gating on it refused legitimate returns
            // with "only -110.67 remain in stock" — a sentence that cannot be
            // acted on and a number that cannot be true.
            //
            // The lots are the ledger the sale path already consumes from and
            // that `available_quantity` is derived from, so a return is now
            // checked against the same stock a sale would draw on. Lots count
            // lower units; the reduction is in upper ones, as
            // `purchase_details.quantities` is.
            $conversion = (float) ($stock->conversionValue ?? 1);
            $conversion = $conversion > 0 ? $conversion : 1.0;

            $available = ((float) $stock->available_quantity) / $conversion;

            if ($required > $available) {
                $name = collect($lines)->firstWhere('stockId', $stockId)?->productName ?? '';

                throw CartCalculationException::reject(
                    'insufficient_stock_to_return',
                    'Cannot return '.$this->trim($required).' of '
                        .($name !== '' ? $name : 'this item')
                        .'; only '.$this->trim($available).' remain in stock. '
                        .'The rest has already been sold.',
                    [
                        'stock_id' => $stockId,
                        'available' => $available,
                        'requested' => $required,
                    ],
                );
            }
        }
    }

    /**
     * How the credit lands on the purchase and on the supplier's balance.
     *
     * What the shop still owes is cleared first; only the remainder is money the
     * supplier is expected to send back.
     *
     * @param  list<CalculatedPurchaseReturnLine>  $lines
     */
    private function applyPurchaseLevel(
        Purchase $purchase,
        array $lines,
        Money $totalRefund,
    ): CalculatedPurchaseReturn {
        $purchaseTotal = Money::fromFloat($purchase->totalAmount ?? 0);
        $purchaseDue = Money::fromFloat($purchase->dueAmount ?? 0);

        if ($totalRefund->greaterThan($purchaseTotal)) {
            throw CartCalculationException::reject(
                'refund_exceeds_purchase',
                'The return cannot be worth more than the purchase it belongs to.',
                [
                    'refund' => $totalRefund->toFloat(),
                    'purchase_total' => $purchaseTotal->toFloat(),
                ],
            );
        }

        $dueReduction = $totalRefund->cappedAt($purchaseDue);
        $cashRefund = $totalRefund->minus($dueReduction)->clampToZero();

        return new CalculatedPurchaseReturn(
            lines: $lines,
            totalRefund: $totalRefund,
            purchaseTotalAfterReturn: $purchaseTotal->minus($totalRefund)->clampToZero(),
            purchaseDueAfterReturn: $purchaseDue->minus($dueReduction)->clampToZero(),
            supplierDueReduction: $dueReduction,
            cashRefund: $cashRefund,
        );
    }

    private function validateQuantity(mixed $value, string $productName): float
    {
        $label = $productName !== '' ? ' for '.$productName : '';

        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw CartCalculationException::reject(
                'invalid_quantity',
                'Return quantity'.$label.' is not a valid number.',
            );
        }

        $quantity = (float) $value;

        if (! is_finite($quantity)) {
            throw CartCalculationException::reject(
                'invalid_quantity',
                'Return quantity'.$label.' is not a valid number.',
            );
        }

        if ($quantity < 0) {
            throw CartCalculationException::reject(
                'invalid_quantity',
                'Return quantity'.$label.' must be zero or more.',
            );
        }

        return $quantity;
    }

    private function validateFreeReturnQuantity(mixed $value, string $productName): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $label = $productName !== '' ? ' for '.$productName : '';
        if (! is_numeric($value)) {
            throw CartCalculationException::reject('invalid_free_return_quantity', 'Free return quantity'.$label.' is invalid.');
        }

        $quantity = (float) $value;
        if (! is_finite($quantity) || $quantity < 0) {
            throw CartCalculationException::reject('invalid_free_return_quantity', 'Free return quantity'.$label.' must be zero or more.');
        }

        return $quantity;
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
