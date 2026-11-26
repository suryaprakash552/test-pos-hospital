<?php

namespace App\Services\Pricing;

use App\Support\Money;

/**
 * A fully priced purchase return.
 *
 * Goods going back to the supplier: stock falls, and what the shop owes the
 * supplier falls with it. Every figure is derived from the original
 * `purchase_details` rows rather than taken from the request.
 */
final class CalculatedPurchaseReturn
{
    /**
     * @param  list<CalculatedPurchaseReturnLine>  $lines
     */
    public function __construct(
        public readonly array $lines,
        /** What the supplier owes back. */
        public readonly Money $totalRefund,
        /** The purchase's total once these lines are removed. */
        public readonly Money $purchaseTotalAfterReturn,
        /** The purchase's remaining due once the refund is applied. */
        public readonly Money $purchaseDueAfterReturn,
        /** How much of the refund comes off what the shop owes the supplier. */
        public readonly Money $supplierDueReduction,
        /** The part of the refund expected back as money. */
        public readonly Money $cashRefund,
    ) {
    }

    /**
     * The server-computed columns for the amended `purchases` row.
     *
     * @return array<string, mixed>
     */
    public function purchaseColumns(): array
    {
        return [
            'totalAmount' => $this->purchaseTotalAfterReturn->toFloat(),
            'dueAmount' => $this->purchaseDueAfterReturn->toFloat(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (CalculatedPurchaseReturnLine $line) => [
                'purchase_detail_id' => $line->purchaseDetailId,
                'product_id' => $line->productId,
                'product_name' => $line->productName,
                'return_quantity' => $line->returnQuantity,
                'remaining_quantity' => $line->remainingQuantity,
                'stock_reduction' => $line->stockReduction,
                'unit_cost' => $line->unitCost->toFloat(),
                'refund' => $line->refund->toFloat(),
            ], $this->lines),
            'total_refund' => $this->totalRefund->toFloat(),
            'supplier_due_reduction' => $this->supplierDueReduction->toFloat(),
            'cash_refund' => $this->cashRefund->toFloat(),
            'purchase_total_after_return' => $this->purchaseTotalAfterReturn->toFloat(),
            'purchase_due_after_return' => $this->purchaseDueAfterReturn->toFloat(),
        ];
    }
}
