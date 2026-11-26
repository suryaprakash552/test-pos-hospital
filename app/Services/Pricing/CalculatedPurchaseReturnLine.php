<?php

namespace App\Services\Pricing;

use App\Support\Money;

/**
 * One line going back to the supplier, priced from the original purchase.
 */
final class CalculatedPurchaseReturnLine
{
    public function __construct(
        public readonly int $purchaseDetailId,
        public readonly ?int $stockId,
        public readonly int $productId,
        public readonly string $productName,
        public readonly float $returnQuantity,
        public readonly float $freeReturnQuantity,
        /** Quantity left on the line after this return. */
        public readonly float $remainingQuantity,
        public readonly float $remainingFreeQuantity,
        /** Stock this return removes, in the unit the batch is held in. */
        public readonly float $stockReduction,
        /** Lower units contained in one returned upper unit. */
        public readonly float $conversionValue,
        /** What the supplier charged per unit. */
        public readonly Money $unitCost,
        /** unitCost x returnQuantity - what the supplier owes back. */
        public readonly Money $refund,
    ) {
    }
}
