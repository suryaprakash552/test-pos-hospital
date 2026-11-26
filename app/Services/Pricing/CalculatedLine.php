<?php

namespace App\Services\Pricing;

use App\Support\Money;

/**
 * One priced cart line, as the server computed it.
 */
final class CalculatedLine
{
    public function __construct(
        public readonly int $stockId,
        public readonly int $productId,
        public readonly string $productName,
        /** Quantity in the unit the line was sold in. */
        public readonly float $quantity,
        /** Server-resolved unit price - never the client's. */
        public readonly Money $unitPrice,
        /** quantity x unitPrice, before discount or tax. */
        public readonly Money $gross,
        public readonly Money $discount,
        /** As typed on the line: a percentage, a flat amount, or neither. */
        /** gross - discount, floored at zero. */
        public readonly Money $net,
        /** Tax on net: contained within it when inclusive, added when exclusive. */
        public readonly Money $tax,
        public readonly bool $taxInclusive,
        /** The percentage the tax was computed at, for rate-wise GST filing. */
        public readonly float $taxRate,
        /** What this line contributes to the bill. */
        public readonly Money $payable,
        /** Cost basis, for profit reporting. */
        public readonly Money $purchasePrice,
        public readonly Money $lossProfit,
        /** As typed on the line: a percentage, a flat amount, or neither. */
        public readonly float $discountPercent = 0.0,
        public readonly float $discountFlat = 0.0,
    ) {
    }

    /**
     * The row to persist to `sale_details`.
     *
     * @return array<string, mixed>
     */
    public function toSaleDetail(int $saleId): array
    {
        return [
            'sale_id' => $saleId,
            'tax_rate_snapshot' => $this->taxRate,
            'tax_inclusive_snapshot' => $this->taxInclusive,
            'stock_id' => $this->stockId,
            'product_id' => $this->productId,
            'price' => $this->unitPrice->toFloat(),
            'quantities' => $this->quantity,
            'lossProfit' => $this->lossProfit->toFloat(),
            // Kept so re-opening the sale shows the discount that was given,
            // instead of a line that looks as though it never had one.
            'discountPercent' => $this->discountPercent,
            'discountFlat' => $this->discountFlat,
            'discountAmount' => $this->discount->toFloat(),
        ];
    }
}
