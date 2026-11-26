<?php

namespace App\Services\Pricing;

use App\Support\Money;

/**
 * A fully priced sale return.
 *
 * Every figure is derived from the original `sale_details` rows - the price the
 * item actually sold at, times the quantity coming back. The request says which
 * lines and how many; it does not get to say what they are worth.
 */
final class CalculatedSaleReturn
{
    /**
     * @param  list<CalculatedSaleReturnLine>  $lines
     */
    public function __construct(
        public readonly array $lines,
        /** What the customer is owed. */
        public readonly Money $totalRefund,
        public readonly Money $totalProfitReversal,
        /** The sale's total once these lines are removed. */
        public readonly Money $saleTotalAfterReturn,
        /** The sale's remaining due once the refund is applied. */
        public readonly Money $saleDueAfterReturn,
        /** The sale's profit once the returned lines are reversed. */
        public readonly Money $saleProfitAfterReturn,
        /** What stays paid against the sale once the cash is handed back. */
        public readonly Money $salePaidAfterReturn,
        /** How much of the refund comes off the customer's outstanding balance. */
        public readonly Money $customerDueReduction,
        /** The part of the refund actually handed back in cash. */
        public readonly Money $cashRefund,
    ) {
    }

    /**
     * The server-computed columns for the amended `sales` row.
     *
     * @return array<string, mixed>
     */
    public function saleColumns(): array
    {
        return [
            'totalAmount' => $this->saleTotalAfterReturn->toFloat(),
            'dueAmount' => $this->saleDueAfterReturn->toFloat(),
            'lossProfit' => $this->saleProfitAfterReturn->toFloat(),
            // Money handed back is no longer money the sale holds. Left alone,
            // a fully-paid ₹1800 sale with ₹900 returned still read "Cash paid
            // ₹1800" beside a ₹900 bill, and the day's takings counted the
            // refunded cash as revenue.
            'paidAmount' => $this->salePaidAfterReturn->toFloat(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (CalculatedSaleReturnLine $line) => [
                'sale_detail_id' => $line->saleDetailId,
                'product_id' => $line->productId,
                'product_name' => $line->productName,
                'return_quantity' => $line->returnQuantity,
                'remaining_quantity' => $line->remainingQuantity,
                'unit_price' => $line->unitPrice->toFloat(),
                'refund' => $line->refund->toFloat(),
            ], $this->lines),
            'total_refund' => $this->totalRefund->toFloat(),
            'customer_due_reduction' => $this->customerDueReduction->toFloat(),
            'cash_refund' => $this->cashRefund->toFloat(),
            'sale_total_after_return' => $this->saleTotalAfterReturn->toFloat(),
            'sale_due_after_return' => $this->saleDueAfterReturn->toFloat(),
        ];
    }
}
