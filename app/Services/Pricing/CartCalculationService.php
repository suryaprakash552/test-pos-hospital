<?php

namespace App\Services\Pricing;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Stock;
use App\Services\GstConfiguration;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The one place a POS bill is worked out.
 *
 * Everything monetary is derived here from database state: the price comes from
 * the batch, the tax rate from the product's VAT record, and the totals from those.
 * The request supplies intent only - which batch, how many, what discount is being
 * asked for - and any price, subtotal, tax or total it happens to send is ignored.
 *
 * Both the cart preview and the checkout call this, so the figure quoted to the
 * cashier and the figure written to the sale come from the same arithmetic. Checkout
 * calls it again inside the locked transaction rather than trusting the preview,
 * because prices and stock can move in between.
 */
class CartCalculationService
{
    /**
     * A quantity beyond this is not a sale, it is an overflow attempt. Large enough
     * that no genuine line reaches it, small enough that qty x price stays exact.
     */
    private const MAX_QUANTITY = 1_000_000;

    public function __construct(private readonly PriceResolver $priceResolver)
    {
    }

    /**
     * Price a cart.
     *
     * @param  int  $businessId  The authenticated tenant - never taken from input.
     * @param  array<int, array<string, mixed>>  $lines  Client intent per line.
     * @param  array<string, mixed>  $options  Order-level intent.
     */
    public function calculate(
        int $businessId,
        array $lines,
        array $options = [],
        ?Customer $customer = null,
        bool $lockStock = false,
    ): CalculatedCart {
        if ($lines === []) {
            throw CartCalculationException::reject('empty_cart', 'A sale must contain at least one item.');
        }

        $stocks = $this->loadStocks($businessId, $lines, $lockStock);
        $business = Business::query()
            ->with('category:id,default_vat_type')
            ->findOrFail($businessId);

        $calculatedLines = [];
        $subtotal = Money::zero();
        $lineDiscountTotal = Money::zero();
        $taxTotal = Money::zero();
        $totalProfit = Money::zero();

        foreach ($lines as $line) {
            $calculated = $this->calculateLine(
                $line,
                $stocks,
                $customer,
                $business,
                (string) ($options['transaction_date'] ?? now()->toDateString()),
            );

            $calculatedLines[] = $calculated;
            $subtotal = $subtotal->plus($calculated->net);
            $lineDiscountTotal = $lineDiscountTotal->plus($calculated->discount);
            $taxTotal = $taxTotal->plus($calculated->tax);
            $totalProfit = $totalProfit->plus($calculated->lossProfit);
        }

        return $this->applyOrderLevel(
            $calculatedLines,
            $subtotal,
            $lineDiscountTotal,
            $taxTotal,
            $totalProfit,
            $options,
        );
    }

    /**
     * Fetch every referenced batch in one query, scoped to the tenant.
     *
     * Scoping here is what stops a caller pricing - and then selling - another
     * shop's stock by sending its id. A missing id means it was not theirs.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return Collection<int, Stock>
     */
    private function loadStocks(int $businessId, array $lines, bool $lock): Collection
    {
        $stockIds = collect($lines)
            ->pluck('stock_id')
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($stockIds->isEmpty()) {
            throw CartCalculationException::reject('invalid_stock', 'Every line must reference a stock batch.');
        }

        $query = Stock::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $stockIds)
            ->with('product.vat');

        if ($lock) {
            // Ordered so concurrent carts touching the same batches take the rows
            // in the same sequence; taking them in cart order deadlocks instead.
            $query->orderBy('id')->lockForUpdate();
        }

        $stocks = $query->get()->keyBy('id');

        $missing = $stockIds->diff($stocks->keys());

        if ($missing->isNotEmpty()) {
            throw CartCalculationException::reject(
                'unknown_stock',
                'One or more items are not available for this business.',
                ['stock_ids' => $missing->values()->all()],
                404,
            );
        }

        $expired = $stocks->filter(fn (Stock $stock) => $stock->expire_date?->lt(today()));
        if ($expired->isNotEmpty()) {
            throw CartCalculationException::reject(
                'expired_stock',
                'One or more selected batches have expired. Refresh stock or remove these items before billing.',
                ['stock_ids' => $expired->keys()->values()->all()],
            );
        }

        return $stocks;
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  Collection<int, Stock>  $stocks
     */
    private function calculateLine(
        array $line,
        Collection $stocks,
        ?Customer $customer,
        Business $business,
        string $transactionDate,
    ): CalculatedLine
    {
        $stock = $stocks->get((int) ($line['stock_id'] ?? 0));
        $product = $stock->product;

        $quantity = $this->validateQuantity($line['quantities'] ?? $line['quantity'] ?? null, $product?->productName);

        // `quantities` is always a count of the batch's lower unit (tablets,
        // not strips) once a product has a second unit - the same convention
        // the client's `saleLineUnitPrice` uses, and the one `productStock`
        // decrements against elsewhere in the sale controller. The resolved
        // price is per upper unit, so it has to come down to match before it
        // meets that quantity: left alone, a ten-tablet strip priced the sale
        // at ten times over.
        // The batch's own value first, then the product's.
        //
        // Falling straight to 1 when the batch has none is what makes this
        // dangerous: 1 is not "unknown", it is "sold in a single unit", and it
        // prices a tablet at the price of a strip. Batches reached here with no
        // conversion of their own — a purchase used to create them that way —
        // so the product's setting is consulted before giving up.
        $conversionValue = (float) ($stock->conversionValue ?? 0);

        if ($conversionValue <= 0) {
            $conversionValue = (float) ($product?->conversionValue ?? 0);
        }

        $conversionValue = $conversionValue > 0 ? $conversionValue : 1.0;

        $packPrice = $this->priceResolver->forStock($stock, $customer);

        if ($packPrice->isZero()) {
            throw CartCalculationException::reject(
                'missing_sale_price',
                'The selected batch has no selling price. Update its inventory sale price before billing.',
                ['stock_id' => $stock->id],
            );
        }

        if ($conversionValue > 1.0) {
            // The line total is divided once, at the end, rather than rounding a
            // per-unit price and multiplying it back up: 50 over 12 units is
            // 4.166..., and a rounded 4.17 x 12 bills 50.04 for a pack that
            // costs 50. The unit price is still rounded, because it is what the
            // receipt prints and what `sale_details.price` records - only the
            // money is derived at full precision.
            $gross = $packPrice->times($quantity / $conversionValue);
            $unitPrice = Money::fromMinor((int) round($packPrice->toMinor() / $conversionValue));
        } else {
            $unitPrice = $packPrice;
            $gross = $unitPrice->times($quantity);
        }

        $discount = $this->lineDiscount($line, $gross);
        $net = $gross->minus($discount)->clampToZero();

        [$tax, $inclusive, $taxRate] = $this->lineTax(
            $net,
            $product,
            $business,
            $transactionDate,
        );

        // An inclusive line's tax is already inside net; an exclusive one adds it.
        $payable = $inclusive ? $net : $net->plus($tax);

        // Cost per unit *sold*, which is not the same as cost per unit bought.
        //
        // `stocks.productPurchasePrice` is what a whole strip cost, while
        // `$quantity` counts the tablets going over the counter. Multiplying
        // one by the other charged a strip's cost to every loose tablet:
        // 7 tablets of a 200-a-strip medicine costing 120 a strip reported a
        // loss of 700 instead of a profit of 56, and every converted product in
        // the loss-and-profit report was wrong by the conversion factor.
        //
        // Unconverted products divide by one and are unaffected.
        $conversionValue = (float) ($stock->conversionValue ?? 0);
        $conversionValue = $conversionValue > 0 ? $conversionValue : 1.0;

        $purchasePrice = Money::fromFloat(
            (float) ($stock->productPurchasePrice ?? 0) / $conversionValue
        );
        $cost = $purchasePrice->times($quantity);

        // Profit is measured against the money actually kept, which for an
        // inclusive line excludes the tax collected on the shop's behalf.
        $revenue = $inclusive ? $net->minus($tax) : $net;

        return new CalculatedLine(
            stockId: (int) $stock->id,
            productId: (int) $stock->product_id,
            productName: (string) ($product?->productName ?? ''),
            quantity: $quantity,
            unitPrice: $unitPrice,
            gross: $gross,
            discount: $discount,
            discountPercent: $this->nonNegativeNumber($line['discount_percent'] ?? $line['discountPercent'] ?? 0),
            discountFlat: $this->nonNegativeNumber($line['discount_flat'] ?? $line['discountFlat'] ?? 0),
            net: $net,
            tax: $tax,
            taxInclusive: $inclusive,
            taxRate: $taxRate,
            payable: $payable,
            purchasePrice: $purchasePrice,
            lossProfit: $revenue->minus($cost),
        );
    }

    /**
     * Quantity must be a real, positive, sanely sized number.
     *
     * The till's own input control is not a check: the API is reachable without it.
     */
    private function validateQuantity(mixed $value, ?string $productName): float
    {
        $label = $productName !== null && $productName !== '' ? ' for ' . $productName : '';

        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw CartCalculationException::reject(
                'invalid_quantity',
                'Quantity' . $label . ' is not a valid number.',
            );
        }

        $quantity = (float) $value;

        if (! is_finite($quantity)) {
            throw CartCalculationException::reject(
                'invalid_quantity',
                'Quantity' . $label . ' is not a valid number.',
            );
        }

        if ($quantity <= 0) {
            throw CartCalculationException::reject(
                'invalid_quantity',
                'Quantity' . $label . ' must be greater than zero.',
            );
        }

        if ($quantity > self::MAX_QUANTITY) {
            throw CartCalculationException::reject(
                'quantity_too_large',
                'Quantity' . $label . ' exceeds the maximum of ' . self::MAX_QUANTITY . '.',
            );
        }

        return $quantity;
    }

    /**
     * The discount for one line.
     *
     * A percentage takes precedence over a flat amount, matching the order the cart
     * has always applied them in. Whatever is asked for, the result can never be
     * negative (which would inflate the bill) nor exceed the line itself (which
     * would turn a sale into a refund).
     *
     * @param  array<string, mixed>  $line
     */
    private function lineDiscount(array $line, Money $gross): Money
    {
        $percent = $this->nonNegativeNumber($line['discount_percent'] ?? $line['discountPercent'] ?? 0);
        $flat = $this->nonNegativeNumber($line['discount_flat'] ?? $line['discountFlat'] ?? 0);

        // A row whose discount was typed as a plain amount is still a discount
        // — see the same note in PurchaseCalculationService.
        $amount = $this->nonNegativeNumber(
            $line['discount_amount'] ?? $line['discountAmount'] ?? 0
        );

        // Nothing asked for *at all* means no discount. The figures on the
        // product (`product_percent_discount`, `product_flat_discount`) are the
        // *ceiling* the till enforces while the operator types, not a standing
        // offer: applying them here silently took ₹165 off a bill the counter
        // had approved at full price, and the billing screen still read
        // "no overall discount applied".

        if ($percent > 100) {
            throw CartCalculationException::reject(
                'discount_too_large',
                'A discount percentage cannot exceed 100%.',
                ['discount_percent' => $percent],
            );
        }

        $discount = match (true) {
            $percent > 0 => $gross->percentage($percent),
            $flat > 0 => Money::fromFloat($flat),
            default => Money::fromFloat($amount),
        };

        // Capped rather than refused: a flat discount equal to the line is a
        // legitimate write-off, and anything beyond it is meaningless rather than
        // malicious. The cap is what stops a negative line total.
        return $discount->clampToZero()->cappedAt($gross);
    }

    /**
     * Tax for a line, from the product's own VAT record.
     *
     * @return array{0: Money, 1: bool} The tax, and whether it sits inside net.
     */
    private function lineTax(
        Money $net,
        $product,
        Business $business,
        string $transactionDate,
    ): array
    {
        $rate = (float) ($product?->vat?->rate ?? 0);
        // A sale follows the inventory item's method when present, then the GST
        // configuration. The registration switch remains authoritative: turning
        // GST off must stop new sales charging it even if old product rows retain
        // inclusive/exclusive metadata for invoice history.
        $type = GstConfiguration::resolveAsOf(
            $product?->vat_type,
            null,
            $business,
            $transactionDate,
        );
        $inclusive = $type === 'inclusive';

        if ($type === 'not_applicable'
            || $rate <= 0
            || ! config('pos.tax.enable_vat', true)
        ) {
            return [Money::zero(), $inclusive, 0.0];
        }

        return [
            $inclusive ? $net->taxIncludedAt($rate) : $net->percentage($rate),
            $inclusive,
            $rate,
        ];
    }

    /**
     * Order-level discount, shipping, rounding and the payment split.
     *
     * @param  list<CalculatedLine>  $lines
     * @param  array<string, mixed>  $options
     */
    private function applyOrderLevel(
        array $lines,
        Money $subtotal,
        Money $lineDiscountTotal,
        Money $taxTotal,
        Money $totalProfit,
        array $options,
    ): CalculatedCart {
        $orderDiscount = $this->orderDiscount($options, $subtotal);

        // Resolved elsewhere against the customer's package, then passed in; it is
        // still capped here so it cannot exceed what is left to discount.
        $loyaltyDiscount = Money::fromFloat($this->nonNegativeNumber($options['loyalty_discount'] ?? 0))
            ->cappedAt($subtotal->minus($orderDiscount)->clampToZero());

        $shipping = Money::fromFloat($this->nonNegativeNumber($options['shipping_charge'] ?? 0));

        // Tax is charged on what the customer is actually billed, so an
        // order-level reduction has to reach the taxable base before the rate is
        // applied. It previously did not: line tax was summed here as already
        // computed, which meant a row discount reduced GST and an identical
        // order discount did not - the same goods, the same money off, two
        // different totals depending on which box it was typed into.
        //
        // The reduction is apportioned across lines in proportion to their net so
        // each line's own rate and inclusive/exclusive treatment still governs its
        // share, and so a mixed-rate basket cannot be shifted between rates.
        $orderLevelReduction = $orderDiscount->plus($loyaltyDiscount)->cappedAt($subtotal);
        $lines = $this->reprice($lines, $subtotal, $orderLevelReduction);

        $taxTotal = $this->taxTotal($lines);

        // Inclusive tax already sits inside the line nets, so only the exclusive
        // part is added on top.
        $exclusiveTax = $this->exclusiveTaxTotal($lines);

        $actualTotal = $subtotal
            ->minus($orderDiscount)
            ->minus($loyaltyDiscount)
            ->clampToZero()
            ->plus($exclusiveTax)
            ->plus($shipping);

        $roundingOption = $this->roundingOption($options);
        $grandTotal = $actualTotal->rounded($roundingOption);
        $roundingAmount = $grandTotal->minus($actualTotal);

        [$paid, $due, $change] = $this->settlement($options, $grandTotal);

        // Discounts come out of the shop's margin, so profit carries them.
        $profit = $totalProfit->minus($orderDiscount)->minus($loyaltyDiscount);

        return new CalculatedCart(
            lines: $lines,
            subtotal: $subtotal,
            lineDiscountTotal: $lineDiscountTotal,
            orderDiscount: $orderDiscount,
            loyaltyDiscount: $loyaltyDiscount,
            taxTotal: $taxTotal,
            shippingCharge: $shipping,
            actualTotal: $actualTotal,
            roundingAmount: $roundingAmount,
            roundingOption: $roundingOption,
            grandTotal: $grandTotal,
            paid: $paid,
            due: $due,
            change: $change,
            totalProfit: $profit,
        );
    }

    /**
     * Re-tax each line against its share of an order-level reduction.
     *
     * The share is pro-rata on net, in integer minor units, with the rounding
     * remainder handed to the largest line. That keeps the shares summing to
     * exactly the reduction - apportioning in floats and rounding each part
     * independently loses or invents a paisa on most baskets.
     *
     * Only tax and payable move. `net` is left as the line's own pre-order-discount
     * value because `subtotal` is built from it and the order discount is
     * subtracted from that subtotal separately; reducing both would deduct twice.
     *
     * @param  list<CalculatedLine>  $lines
     * @return list<CalculatedLine>
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

        return array_map(
            fn (int $index) => $this->retaxed($lines[$index], Money::fromMinor($shares[$index])),
            array_keys($lines),
        );
    }

    /**
     * One line, taxed on its net less its share of the order-level reduction.
     */
    private function retaxed(CalculatedLine $line, Money $share): CalculatedLine
    {
        $taxableBase = $line->net->minus($share)->clampToZero();

        $tax = $line->taxInclusive
            ? $taxableBase->taxIncludedAt($this->rateFrom($line, $taxableBase))
            : $this->scaledTax($line, $taxableBase);

        return new CalculatedLine(
            stockId: $line->stockId,
            productId: $line->productId,
            productName: $line->productName,
            quantity: $line->quantity,
            unitPrice: $line->unitPrice,
            gross: $line->gross,
            discount: $line->discount,
            discountPercent: $line->discountPercent,
            discountFlat: $line->discountFlat,
            net: $line->net,
            tax: $tax,
            taxInclusive: $line->taxInclusive,
            taxRate: $line->taxRate,
            payable: $line->taxInclusive ? $line->net : $line->net->plus($tax),
            purchasePrice: $line->purchasePrice,
            lossProfit: $line->lossProfit,
        );
    }

    /**
     * The line's effective rate, recovered from the tax already computed on net.
     *
     * Going back to the VAT record would mean carrying the product through, and the
     * ratio is exact enough in minor units for the rates a POS deals in.
     */
    private function rateFrom(CalculatedLine $line, Money $base): float
    {
        if ($line->net->isZero() || $line->tax->isZero()) {
            return 0.0;
        }

        // tax = net - net/(1+r)  =>  r = tax / (net - tax)
        $taxable = $line->net->toMinor() - $line->tax->toMinor();

        return $taxable > 0 ? ($line->tax->toMinor() / $taxable) * 100 : 0.0;
    }

    /**
     * Exclusive tax on a reduced base, scaled from the tax already computed on net.
     *
     * Scaling preserves the original rate exactly without re-reading the VAT record.
     */
    private function scaledTax(CalculatedLine $line, Money $base): Money
    {
        if ($line->net->isZero() || $line->tax->isZero()) {
            return Money::zero();
        }

        return Money::fromMinor(
            (int) round($line->tax->toMinor() * $base->toMinor() / $line->net->toMinor()),
        );
    }

    /**
     * @param  list<CalculatedLine>  $lines
     */
    private function taxTotal(array $lines): Money
    {
        $total = Money::zero();

        foreach ($lines as $line) {
            $total = $total->plus($line->tax);
        }

        return $total;
    }

    /**
     * @param  list<CalculatedLine>  $lines
     */
    private function exclusiveTaxTotal(array $lines): Money
    {
        $total = Money::zero();

        foreach ($lines as $line) {
            if (! $line->taxInclusive) {
                $total = $total->plus($line->tax);
            }
        }

        return $total;
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
                'The discount cannot be greater than the order subtotal.',
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

    /**
     * What was paid, and what that leaves owing or to hand back.
     *
     * Derived rather than accepted: a client that sends its own due or change can
     * otherwise record a fully paid sale against no money.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: Money, 1: Money, 2: Money}
     */
    private function settlement(array $options, Money $grandTotal): array
    {
        $tendered = Money::fromFloat($this->nonNegativeNumber($options['paid_amount'] ?? 0));

        // Overpayment is change, not a negative due.
        $due = $grandTotal->minus($tendered)->clampToZero();
        $change = $tendered->minus($grandTotal)->clampToZero();

        // What is recorded as paid is what settled the bill; the rest is change.
        $paid = $tendered->minus($change);

        return [$paid, $due, $change];
    }

    /**
     * Reject negatives outright - a negative discount or charge is an inversion
     * attempt, not a rounding artefact.
     */
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
