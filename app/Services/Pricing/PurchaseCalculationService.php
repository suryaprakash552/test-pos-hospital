<?php

namespace App\Services\Pricing;

use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The one place a purchase bill is worked out.
 *
 * ## What is trusted, and what is not
 *
 * A purchase differs from a sale. On a sale the price is the shop's own, held in
 * the database, so a client-supplied price is always wrong to accept. On a purchase
 * the unit cost is what the *supplier* charged - the operator is recording a fact
 * the server has no way to look up. So the unit cost is an input here, legitimately.
 *
 * Everything derived from it is not. Line totals, conversion prices, the subtotal,
 * tax, discounts, rounding, the grand total and the due are all recomputed from
 * (quantity x cost). A client that sends its own `total_purchase_price`,
 * `totalAmount` or `dueAmount` has those ignored - they are results, and results
 * are the server's to produce.
 *
 * This mirrors `purchaseLineAmounts()` in the app so an honest till sees the same
 * figures it always did, but in exact integer arithmetic rather than floats.
 */
class PurchaseCalculationService
{
    /**
     * Beyond this a quantity is an overflow attempt rather than a delivery.
     */
    private const MAX_QUANTITY = 1_000_000;

    /**
     * No supplier invoice reaches this per unit; past it the arithmetic is a probe.
     */
    private const MAX_UNIT_COST = 100_000_000;

    /**
     * Price a purchase.
     *
     * @param  int  $businessId  The authenticated tenant - never taken from input.
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $options
     */
    public function calculate(int $businessId, array $lines, array $options = []): CalculatedPurchase
    {
        if ($lines === []) {
            throw CartCalculationException::reject('empty_cart', 'A purchase must contain at least one item.');
        }

        $products = $this->loadProducts($businessId, $lines);

        $calculated = [];
        $subtotal = Money::zero();
        $lineDiscountTotal = Money::zero();
        $taxTotal = Money::zero();

        foreach ($lines as $line) {
            $result = $this->calculateLine($line, $products);

            $calculated[] = $result;
            $subtotal = $subtotal->plus($result->net);
            $lineDiscountTotal = $lineDiscountTotal->plus($result->discount);
            $taxTotal = $taxTotal->plus($result->tax);
        }

        return $this->applyOrderLevel($calculated, $subtotal, $lineDiscountTotal, $taxTotal, $options);
    }

    /**
     * Fetch every referenced product in one query, scoped to the tenant.
     *
     * A product id that is not this business's simply will not come back, which is
     * what stops a caller booking stock into another shop.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return Collection<int, Product>
     */
    private function loadProducts(int $businessId, array $lines): Collection
    {
        $productIds = collect($lines)
            ->pluck('product_id')
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            throw CartCalculationException::reject('invalid_product', 'Every line must reference a product.');
        }

        $products = Product::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $productIds)
            ->with('vat')
            ->get()
            ->keyBy('id');

        $missing = $productIds->diff($products->keys());

        if ($missing->isNotEmpty()) {
            throw CartCalculationException::reject(
                'unknown_product',
                'One or more products are not available for this business.',
                ['product_ids' => $missing->values()->all()],
                404,
            );
        }

        return $products;
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  Collection<int, Product>  $products
     */
    private function calculateLine(array $line, Collection $products): CalculatedPurchaseLine
    {
        $product = $products->get((int) ($line['product_id'] ?? 0));
        $name = (string) ($product->productName ?? '');

        $quantity = $this->validateQuantity($line['quantities'] ?? null, $name);
        $freeQuantity = $this->validateFreeQuantity($line['free_quantity'] ?? 0, $name);
        $receivedQuantity = $quantity + $freeQuantity;
        if ($receivedQuantity > self::MAX_QUANTITY) {
            throw CartCalculationException::reject(
                'quantity_too_large',
                'The paid and free quantity'.($name !== '' ? ' for '.$name : '').' exceeds the maximum of '.self::MAX_QUANTITY.'.',
            );
        }
        $unitCost = $this->validateUnitCost($line['productPurchasePrice'] ?? 0, $name);

        // How many lower units one upper unit holds; 1 when not a converted line.
        $conversionValue = $this->conversionValue($line, $product);

        // The count actually delivered, which is an input rather than a product of
        // the other two. Three strips of ten plus five loose tablets is thirty-five,
        // not thirty, so deriving this would quietly discard the loose units.
        // The floor rule (>= quantity x conversionValue) is enforced separately.
        $conversionQuantity = $this->conversionQuantity($line, $receivedQuantity, $conversionValue);

        $gross = $unitCost->times($quantity);
        $discount = $this->lineDiscount($line, $gross);
        $net = $gross->minus($discount)->clampToZero();

        [$tax, $inclusive, $taxRate] = $this->lineTax($net, $product);

        $payable = $inclusive ? $net : $net->plus($tax);

        // Cost per lower unit, derived rather than accepted. This is what later
        // values the stock, so a client-supplied figure here would misprice every
        // subsequent sale of the batch.
        $conversionPurchasePrice = $conversionQuantity > 0
            ? Money::fromMinor((int) round($net->toMinor() / $conversionQuantity))
            : Money::zero();

        // The intended resale price is the operator's decision, not a derivation.
        $salePrice = Money::fromFloat($this->nonNegativeNumber($line['productSalePrice'] ?? 0));
        $totalSaleValue = $salePrice->times($receivedQuantity);

        $conversionSalePrice = $conversionQuantity > 0
            ? Money::fromMinor((int) round($totalSaleValue->toMinor() / $conversionQuantity))
            : Money::zero();

        return new CalculatedPurchaseLine(
            productId: (int) $product->id,
            productName: $name,
            quantity: $quantity,
            freeQuantity: $freeQuantity,
            conversionValue: $conversionValue,
            conversionQuantity: $conversionQuantity,
            unitCost: $unitCost,
            salePrice: $salePrice,
            gross: $gross,
            discount: $discount,
            discountPercent: $this->nonNegativeNumber($line['discount_percent'] ?? $line['discountPercent'] ?? 0),
            discountFlat: $this->nonNegativeNumber($line['discount_flat'] ?? $line['discountFlat'] ?? 0),
            net: $net,
            tax: $tax,
            taxInclusive: $inclusive,
            taxRate: $taxRate,
            payable: $payable,
            totalSaleValue: $totalSaleValue,
            conversionPurchasePrice: $conversionPurchasePrice,
            conversionSalePrice: $conversionSalePrice,
        );
    }

    /**
     * The number of lower units this line delivers.
     *
     * Taken from the request because it is a fact about the delivery, not something
     * derivable: a line can carry loose units on top of whole packs. Falls back to
     * the derived figure when the client does not state one.
     *
     * @param  array<string, mixed>  $line
     */
    private function conversionQuantity(array $line, float $quantity, float $conversionValue): float
    {
        $stated = $line['conversion_quantities'] ?? null;

        if ($stated === null || $stated === '' || ! is_numeric($stated)) {
            return $quantity * $conversionValue;
        }

        $stated = (float) $stated;

        if (! is_finite($stated) || $stated <= 0) {
            return $quantity * $conversionValue;
        }

        return $stated;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function conversionValue(array $line, Product $product): float
    {
        $value = $line['conversion_value'] ?? $product->conversionValue ?? 1;
        $value = is_numeric($value) ? (float) $value : 1.0;

        // Zero would make the per-unit cost a division by zero; treat it as "no
        // conversion" rather than refusing an otherwise valid delivery.
        return $value > 0 ? $value : 1.0;
    }

    private function validateQuantity(mixed $value, string $productName): float
    {
        $label = $productName !== '' ? ' for '.$productName : '';

        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw CartCalculationException::reject(
                'invalid_quantity',
                'Quantity'.$label.' is not a valid number.',
            );
        }

        $quantity = (float) $value;

        if (! is_finite($quantity)) {
            throw CartCalculationException::reject(
                'invalid_quantity',
                'Quantity'.$label.' is not a valid number.',
            );
        }

        if ($quantity <= 0) {
            throw CartCalculationException::reject(
                'invalid_quantity',
                'Quantity'.$label.' must be greater than zero.',
            );
        }

        if ($quantity > self::MAX_QUANTITY) {
            throw CartCalculationException::reject(
                'quantity_too_large',
                'Quantity'.$label.' exceeds the maximum of '.self::MAX_QUANTITY.'.',
            );
        }

        return $quantity;
    }

    private function validateFreeQuantity(mixed $value, string $productName): float
    {
        $label = $productName !== '' ? ' for '.$productName : '';

        if ($value === null || $value === '') {
            return 0.0;
        }

        if (! is_numeric($value)) {
            throw CartCalculationException::reject('invalid_free_quantity', 'Free quantity'.$label.' is not a valid number.');
        }

        $quantity = (float) $value;
        if (! is_finite($quantity) || $quantity < 0) {
            throw CartCalculationException::reject('invalid_free_quantity', 'Free quantity'.$label.' must be zero or more.');
        }

        if ($quantity > self::MAX_QUANTITY) {
            throw CartCalculationException::reject('quantity_too_large', 'Free quantity'.$label.' exceeds the maximum of '.self::MAX_QUANTITY.'.');
        }

        return $quantity;
    }

    private function validateUnitCost(mixed $value, string $productName): Money
    {
        $label = $productName !== '' ? ' for '.$productName : '';

        $cost = $this->nonNegativeNumber($value);

        if ($cost > self::MAX_UNIT_COST) {
            throw CartCalculationException::reject(
                'cost_too_large',
                'The unit cost'.$label.' is implausibly large.',
            );
        }

        return Money::fromFloat($cost);
    }

    /**
     * A percentage discount takes precedence over a flat one, matching the order
     * the purchase cart has always applied them in. Never negative, never more
     * than the line.
     *
     * @param  array<string, mixed>  $line
     */
    private function lineDiscount(array $line, Money $gross): Money
    {
        $percent = $this->nonNegativeNumber($line['discount_percent'] ?? $line['discountPercent'] ?? 0);
        $flat = $this->nonNegativeNumber($line['discount_flat'] ?? $line['discountFlat'] ?? 0);

        if ($percent > 100) {
            throw CartCalculationException::reject(
                'discount_too_large',
                'A discount percentage cannot exceed 100%.',
                ['discount_percent' => $percent],
            );
        }

        // A row whose discount was typed as a plain amount is still a discount.
        //
        // Only percent and flat were read, so a line carrying `discount_amount`
        // — which is what the cart records when neither box was used — was
        // priced at its gross. The client honours that field, so the two
        // disagreed on the same cart.
        $amount = $this->nonNegativeNumber(
            $line['discount_amount'] ?? $line['discountAmount'] ?? 0
        );

        $discount = match (true) {
            $percent > 0 => $gross->percentage($percent),
            $flat > 0 => Money::fromFloat($flat),
            default => Money::fromFloat($amount),
        };

        return $discount->clampToZero()->cappedAt($gross);
    }

    /**
     * @return array{0: Money, 1: bool}
     */
    private function lineTax(Money $net, Product $product): array
    {
        $rate = (float) ($product->vat?->rate ?? 0);
        // A supplier's purchase rate is always the taxable value before GST.
        // The product tax mode belongs to future sales and must not reinterpret
        // the rate entered on a purchase bill as tax-inclusive.
        $inclusive = false;

        if ($rate <= 0 || ! config('pos.tax.enable_vat', true)) {
            return [Money::zero(), $inclusive, 0.0];
        }

        return [
            $inclusive ? $net->taxIncludedAt($rate) : $net->percentage($rate),
            $inclusive,
            $rate,
        ];
    }

    /**
     * @param  list<CalculatedPurchaseLine>  $lines
     * @param  array<string, mixed>  $options
     */
    /**
     * Re-tax each line against its share of the order-level discount.
     *
     * Pro-rata on net in integer minor units, remainder to the largest line, so
     * the shares sum to exactly the discount rather than losing a paisa.
     *
     * `net` is deliberately left alone: `subtotal` is built from it and the order
     * discount is subtracted from that subtotal separately, so reducing both would
     * deduct twice.
     *
     * @param  list<CalculatedPurchaseLine>  $lines
     * @return list<CalculatedPurchaseLine>
     */
    private function reprice(array $lines, Money $subtotal, Money $reduction): array
    {
        if ($reduction->isZero() || $subtotal->isZero() || $lines === []) {
            return $lines;
        }

        $subtotalMinor = $subtotal->toMinor();
        $reductionMinor = $reduction->toMinor();

        $shares = [];
        $allocated = 0;
        $largest = 0;

        foreach ($lines as $index => $line) {
            $shares[$index] = intdiv($line->net->toMinor() * $reductionMinor, $subtotalMinor);
            $allocated += $shares[$index];

            if ($line->net->toMinor() > $lines[$largest]->net->toMinor()) {
                $largest = $index;
            }
        }

        $shares[$largest] += $reductionMinor - $allocated;

        return array_map(function (int $index) use ($lines, $shares) {
            $line = $lines[$index];
            $base = $line->net->minus(Money::fromMinor($shares[$index]))->clampToZero();

            return new CalculatedPurchaseLine(
                productId: $line->productId,
                productName: $line->productName,
                quantity: $line->quantity,
                freeQuantity: $line->freeQuantity,
                conversionValue: $line->conversionValue,
                conversionQuantity: $line->conversionQuantity,
                unitCost: $line->unitCost,
                salePrice: $line->salePrice,
                gross: $line->gross,
                discount: $line->discount,
                net: $line->net,
                tax: $this->scaledTax($line, $base),
                taxInclusive: $line->taxInclusive,
                taxRate: $line->taxRate,
                payable: $line->taxInclusive
                    ? $line->net
                    : $line->net->plus($this->scaledTax($line, $base)),
                totalSaleValue: $line->totalSaleValue,
                conversionPurchasePrice: $line->conversionPurchasePrice,
                conversionSalePrice: $line->conversionSalePrice,
            );
        }, array_keys($lines));
    }

    /**
     * Tax on a reduced base, scaled from the tax already computed on net.
     *
     * Scaling preserves the line's original rate exactly, inclusive or exclusive,
     * without having to re-read the VAT record.
     */
    private function scaledTax(CalculatedPurchaseLine $line, Money $base): Money
    {
        if ($line->net->isZero() || $line->tax->isZero()) {
            return Money::zero();
        }

        return Money::fromMinor(
            (int) round($line->tax->toMinor() * $base->toMinor() / $line->net->toMinor()),
        );
    }

    private function applyOrderLevel(
        array $lines,
        Money $subtotal,
        Money $lineDiscountTotal,
        Money $taxTotal,
        array $options,
    ): CalculatedPurchase {
        $orderDiscount = $this->orderDiscount($options, $subtotal);
        $shipping = Money::fromFloat($this->nonNegativeNumber($options['shipping_charge'] ?? 0));

        // As on the sale side: an order-level discount has to reach the taxable
        // base, or the same money off bills differently depending on whether it
        // was entered against a row or against the order. Apportioned pro-rata on
        // net so each line keeps its own rate and inclusive/exclusive treatment.
        $lines = $this->reprice($lines, $subtotal, $orderDiscount->cappedAt($subtotal));

        $taxTotal = Money::zero();
        $exclusiveTax = Money::zero();
        foreach ($lines as $line) {
            $taxTotal = $taxTotal->plus($line->tax);

            // Inclusive tax already sits inside the line nets; only exclusive adds.
            if (! $line->taxInclusive) {
                $exclusiveTax = $exclusiveTax->plus($line->tax);
            }
        }

        $actualTotal = $subtotal
            ->minus($orderDiscount)
            ->clampToZero()
            ->plus($exclusiveTax)
            ->plus($shipping);

        $roundingOption = $this->roundingOption($options);
        $grandTotal = $actualTotal->rounded($roundingOption);
        $roundingAmount = $grandTotal->minus($actualTotal);

        // Paid, due and change are derived from what was actually handed over. The
        // single total above is the only one they are measured against, which is
        // what stops the cart's two competing totals disagreeing.
        $tendered = Money::fromFloat($this->nonNegativeNumber($options['paid_amount'] ?? 0));
        $due = $grandTotal->minus($tendered)->clampToZero();
        $change = $tendered->minus($grandTotal)->clampToZero();
        $paid = $tendered->minus($change);

        return new CalculatedPurchase(
            lines: $lines,
            subtotal: $subtotal,
            lineDiscountTotal: $lineDiscountTotal,
            orderDiscount: $orderDiscount,
            taxTotal: $taxTotal,
            shippingCharge: $shipping,
            actualTotal: $actualTotal,
            roundingAmount: $roundingAmount,
            roundingOption: $roundingOption,
            grandTotal: $grandTotal,
            paid: $paid,
            due: $due,
            change: $change,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function orderDiscount(array $options, Money $subtotal): Money
    {
        $type = (string) ($options['discount_type'] ?? 'flat');
        $percent = $this->nonNegativeNumber($options['discount_percent'] ?? 0);
        $amount = $this->nonNegativeNumber($options['discount_amount'] ?? 0);

        if ($type === 'percent' || ($percent > 0 && $amount <= 0)) {
            if ($percent > 100) {
                throw CartCalculationException::reject(
                    'discount_too_large',
                    'A discount percentage cannot exceed 100%.',
                    ['discount_percent' => $percent],
                );
            }

            $discount = $subtotal->percentage($percent);
        } else {
            $discount = Money::fromFloat($amount);
        }

        if ($discount->greaterThan($subtotal)) {
            throw CartCalculationException::reject(
                'discount_exceeds_subtotal',
                'The discount cannot be greater than the purchase subtotal.',
                [
                    'discount' => $discount->toFloat(),
                    'subtotal' => $subtotal->toFloat(),
                ],
            );
        }

        return $discount;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function roundingOption(array $options): string
    {
        $option = (string) ($options['rounding_option'] ?? config('pos.rounding.default', 'none'));
        $allowed = array_keys((array) config('pos.rounding.options', []));

        return in_array($option, $allowed, true) ? $option : 'none';
    }

    private function nonNegativeNumber(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (! is_numeric($value)) {
            throw CartCalculationException::reject(
                'invalid_amount',
                'A monetary value in the request is not a valid number.',
            );
        }

        $number = (float) $value;

        if (! is_finite($number)) {
            throw CartCalculationException::reject(
                'invalid_amount',
                'A monetary value in the request is not a valid number.',
            );
        }

        if ($number < 0) {
            throw CartCalculationException::reject(
                'negative_amount',
                'A monetary value in the request cannot be negative.',
            );
        }

        return $number;
    }
}
