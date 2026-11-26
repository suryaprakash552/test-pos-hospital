<?php

namespace App\Services\Pricing;

use App\Support\Money;

/**
 * A fully priced purchase. Every figure here was computed by the server from the
 * quantities and unit costs supplied; none of the totals came from the request.
 */
final class CalculatedPurchase
{
    /**
     * @param  list<CalculatedPurchaseLine>  $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly Money $subtotal,
        public readonly Money $lineDiscountTotal,
        public readonly Money $orderDiscount,
        public readonly Money $taxTotal,
        public readonly Money $shippingCharge,
        /** Total before rounding. */
        public readonly Money $actualTotal,
        public readonly Money $roundingAmount,
        public readonly string $roundingOption,
        /** What the shop owes the supplier. */
        public readonly Money $grandTotal,
        public readonly Money $paid,
        public readonly Money $due,
        public readonly Money $change,
    ) {
    }

    /**
     * The server-computed money columns for the `purchases` row.
     *
     * Only columns `purchases` actually has. Unlike `sales` it carries no
     * `actual_total_amount` / `rounding_amount` / `rounding_option`, so the
     * rounding is folded into `totalAmount` and the breakdown is available on the
     * response (`toArray`) rather than stored.
     *
     * @return array<string, mixed>
     */
    /**
     * The rate to file this purchase under, or 0 when its lines disagree.
     *
     * `purchases.vat_percent` is what the GST report groups and filters input
     * tax on, and nothing ever wrote it — the same gap the sale side had, so
     * every purchase filed under "0% GST" and the rate filter matched none.
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

    public function purchaseColumns(): array
    {
        return [
            'totalAmount' => $this->grandTotal->toFloat(),
            'vat_amount' => $this->taxTotal->toFloat(),
            'vat_percent' => $this->taxRate(),
            'discountAmount' => $this->orderDiscount->toFloat(),
            'rowDiscount' => $this->lineDiscountTotal->toFloat(),
            'shipping_charge' => $this->shippingCharge->toFloat(),
            'paidAmount' => $this->paid->toFloat(),
            'dueAmount' => $this->due->toFloat(),
            'change_amount' => $this->change->toFloat(),
        ];
    }

    /**
     * The shape returned to the client so it can render authoritative figures
     * instead of its own estimate.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (CalculatedPurchaseLine $line) => [
                'product_id' => $line->productId,
                'product_name' => $line->productName,
                'quantity' => $line->quantity,
                'conversion_quantity' => $line->conversionQuantity,
                'unit_cost' => $line->unitCost->toFloat(),
                'gross' => $line->gross->toFloat(),
                'discount' => $line->discount->toFloat(),
                'net' => $line->net->toFloat(),
                'tax' => $line->tax->toFloat(),
                'tax_inclusive' => $line->taxInclusive,
                'payable' => $line->payable->toFloat(),
                'conversion_purchase_price' => $line->conversionPurchasePrice->toFloat(),
            ], $this->lines),
            // What the supplier's lines cost before any row discount or GST.
            // The billing screen labels this "Sub Total"; returning only the
            // net subtotal made that row change after server preview arrived.
            'gross_subtotal' => $this->subtotal->plus($this->lineDiscountTotal)->toFloat(),
            'subtotal' => $this->subtotal->toFloat(),
            'line_discount_total' => $this->lineDiscountTotal->toFloat(),
            'order_discount' => $this->orderDiscount->toFloat(),
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
