<?php

namespace App\Services\Pricing;

use App\Support\Money;

/**
 * One priced purchase line, as the server computed it.
 */
final class CalculatedPurchaseLine
{
    public function __construct(
        public readonly int $productId,
        public readonly string $productName,
        /** Quantity in the upper (purchased) unit. */
        public readonly float $quantity,
        /** Bonus upper units received without supplier charge. */
        public readonly float $freeQuantity,
        /** Lower units per upper unit. */
        public readonly float $conversionValue,
        /** quantity x conversionValue - the stock this line adds. */
        public readonly float $conversionQuantity,
        /** What the supplier charged per upper unit; an operator input. */
        public readonly Money $unitCost,
        /** Intended resale price per upper unit; an operator input. */
        public readonly Money $salePrice,
        public readonly Money $gross,
        public readonly Money $discount,
        /** As typed: a percentage, a flat amount, or neither. */
        public readonly Money $net,
        public readonly Money $tax,
        public readonly bool $taxInclusive,
        /** The percentage the tax was computed at, for rate-wise GST filing. */
        public readonly float $taxRate,
        public readonly Money $payable,
        public readonly Money $totalSaleValue,
        /** Cost per lower unit - what later values the batch. */
        public readonly Money $conversionPurchasePrice,
        public readonly Money $conversionSalePrice,
        /** As typed on the line: a percentage, a flat amount, or neither. */
        public readonly float $discountPercent = 0.0,
        public readonly float $discountFlat = 0.0,
    ) {
    }

    /**
     * The row to persist to `purchase_details`.
     *
     * Every derived figure here is the server's. The client's
     * `total_purchase_price`, `total_price` and `conversion_price` never reach it.
     *
     * @return array<string, mixed>
     */
    public function toPurchaseDetail(int $purchaseId, ?int $stockId = null): array
    {
        return [
            'purchase_id' => $purchaseId,
            'tax_rate_snapshot' => $this->taxRate,
            'tax_inclusive_snapshot' => $this->taxInclusive,
            'product_id' => $this->productId,
            'stock_id' => $stockId,
            'quantities' => $this->quantity,
            'free_quantity' => $this->freeQuantity,
            'conversionQuantity' => $this->conversionQuantity,
            'conversionValue' => $this->conversionValue,
            'productPurchasePrice' => $this->unitCost->toFloat(),
            'productSalePrice' => $this->salePrice->toFloat(),
            'conversionPurchasePrice' => $this->conversionPurchasePrice->toFloat(),
            'conversionPrice' => $this->conversionSalePrice->toFloat(),
            'totalPurchasePrice' => $this->net->toFloat(),
            'totalPrice' => $this->totalSaleValue->toFloat(),
            // Kept so re-opening the purchase can show what was given, rather
            // than a line that looks as though it never had a discount.
            'discountPercent' => $this->discountPercent,
            'discountFlat' => $this->discountFlat,
            'discountAmount' => $this->discount->toFloat(),
        ];
    }
}
