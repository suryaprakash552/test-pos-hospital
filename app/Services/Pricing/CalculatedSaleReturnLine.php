<?php

namespace App\Services\Pricing;

use App\Support\Money;

/**
 * One returned line, priced from the original sale rather than the request.
 */
final class CalculatedSaleReturnLine
{
    public function __construct(
        public readonly int $saleDetailId,
        public readonly int $stockId,
        public readonly int $productId,
        public readonly string $productName,
        public readonly float $returnQuantity,
        /** Quantity left on the line after this return. */
        public readonly float $remainingQuantity,
        /** The price the item actually sold at. */
        public readonly Money $unitPrice,
        /** unitPrice x returnQuantity. */
        public readonly Money $refund,
        /** The share of the line's profit being reversed. */
        public readonly Money $profitReversal,
    ) {
    }
}
