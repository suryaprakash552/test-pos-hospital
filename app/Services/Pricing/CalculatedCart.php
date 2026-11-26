<?php

namespace App\Services\Pricing;

use App\Support\Money;

/**
 * A fully priced cart. Every monetary figure here was computed by the server from
 * current database state; none of it came from the request.
 */
final class CalculatedCart
{
    /**
     * @param  list<CalculatedLine>  $lines
     */
    public function __construct(
        public readonly array $lines,
        /** Sum of line nets, after line discounts, before order discount and tax. */
        public readonly Money $subtotal,
        public readonly Money $lineDiscountTotal,
        public readonly Money $orderDiscount,
        public readonly Money $loyaltyDiscount,
        public readonly Money $taxTotal,
        public readonly Money $shippingCharge,
        /** Total before rounding. */
        public readonly Money $actualTotal,
        /** Signed adjustment applied by the rounding option. */
        public readonly Money $roundingAmount,
        public readonly string $roundingOption,
        /** What the customer owes. */
        public readonly Money $grandTotal,
        public readonly Money $paid,
        public readonly Money $due,
        public readonly Money $change,
        public readonly Money $totalProfit,
    ) {
    }

    /**
     * Every discount applied to the bill, however it arose.
     */
    public function totalDiscount(): Money
    {
        return $this->lineDiscountTotal
            ->plus($this->orderDiscount)
            ->plus($this->loyaltyDiscount);
    }

    /**
     * The server-computed money columns for the `sales` row.
     *
     * These overwrite whatever the client sent, which is the entire point: the
     * request states intent, this states the bill.
     *
     * @return array<string, mixed>
     */
    /**
     * The rate to file this sale under, or 0 when its lines disagree.
     *
     * GST is filed rate by rate, and `sales.vat_percent` is what the GST
     * report groups and filters on. Nothing ever wrote it, so every sale was
     * stored at 0: the report bucketed the whole period under "0% GST" while
     * still showing the tax collected, and picking 5% or 18% in the filter
     * matched nothing at all.
     *
     * A mixed-rate bill has no single rate to file under and stays 0 rather
     * than claiming one of its rates; the rate-wise breakdown for those has to
     * come from the lines.
     */
    public function taxRate(): float
    {
        $rates = [];

        foreach ($this->lines as $line) {
            if ($line->tax->isZero()) {
                continue;
            }

            $rates[(string) $line->taxRate] = $line->taxRate;
        }

        return count($rates) === 1 ? (float) reset($rates) : 0.0;
    }

    public function saleColumns(): array
    {
        return [
            'totalAmount' => $this->grandTotal->toFloat(),
            'vat_percent' => $this->taxRate(),
            'actual_total_amount' => $this->actualTotal->toFloat(),
            'rounding_amount' => $this->roundingAmount->toFloat(),
            'rounding_option' => $this->roundingOption,
            'vat_amount' => $this->taxTotal->toFloat(),
            'discountAmount' => $this->orderDiscount->toFloat(),
            'rowDiscount' => $this->lineDiscountTotal->toFloat(),
            'shipping_charge' => $this->shippingCharge->toFloat(),
            'paidAmount' => $this->paid->toFloat(),
            'dueAmount' => $this->due->toFloat(),
            'change_amount' => $this->change->toFloat(),
            'lossProfit' => $this->totalProfit->toFloat(),
        ];
    }

    /**
     * The shape returned to the client so it can render authoritative figures.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (CalculatedLine $line) => [
                'stock_id' => $line->stockId,
                'product_id' => $line->productId,
                'product_name' => $line->productName,
                'quantity' => $line->quantity,
                'unit_price' => $line->unitPrice->toFloat(),
                'gross' => $line->gross->toFloat(),
                'discount' => $line->discount->toFloat(),
                'net' => $line->net->toFloat(),
                'tax' => $line->tax->toFloat(),
                'tax_inclusive' => $line->taxInclusive,
                'payable' => $line->payable->toFloat(),
            ], $this->lines),
            'subtotal' => $this->subtotal->toFloat(),
            'line_discount_total' => $this->lineDiscountTotal->toFloat(),
            'order_discount' => $this->orderDiscount->toFloat(),
            'loyalty_discount' => $this->loyaltyDiscount->toFloat(),
            'discount_total' => $this->totalDiscount()->toFloat(),
            'tax_total' => $this->taxTotal->toFloat(),
            'shipping_charge' => $this->shippingCharge->toFloat(),
            'actual_total' => $this->actualTotal->toFloat(),
            'rounding_amount' => $this->roundingAmount->toFloat(),
            'rounding_option' => $this->roundingOption,
            'grand_total' => $this->grandTotal->toFloat(),
            'paid' => $this->paid->toFloat(),
            'due' => $this->due->toFloat(),
            'change' => $this->change->toFloat(),
        ];
    }
}
