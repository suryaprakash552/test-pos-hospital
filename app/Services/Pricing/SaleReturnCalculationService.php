<?php

namespace App\Services\Pricing;

use App\Models\Sale;
use App\Models\SaleDetails;
use App\Support\Money;

/**
 * What a sale return is worth.
 *
 * A refund is money leaving the business, so the amount cannot come from the
 * client. It is derived from the original `sale_details` row - the price the item
 * actually sold at, times the quantity coming back. The request states which lines
 * and how many; everything monetary follows from the sale itself.
 *
 * Previously `return_amount` was taken straight from the request and written to
 * both the refund and the customer's balance, so a caller could refund any figure
 * they liked against a sale of any size, and clear an outstanding due with it.
 */
class SaleReturnCalculationService
{
    /**
     * Price a return against its sale.
     *
     * @param  array<int, array<string, mixed>>  $lines  [{sale_detail_id, return_qty}]
     */
    public function calculate(Sale $sale, array $lines): CalculatedSaleReturn
    {
        if ($lines === []) {
            throw CartCalculationException::reject('empty_return', 'A return must contain at least one item.');
        }

        $details = $this->loadDetails($sale, $lines);

        $calculated = [];
        $totalRefund = Money::zero();
        $totalProfitReversal = Money::zero();

        foreach ($lines as $line) {
            $calculated[] = $result = $this->calculateLine($line, $details);

            $totalRefund = $totalRefund->plus($result->refund);
            $totalProfitReversal = $totalProfitReversal->plus($result->profitReversal);
        }

        return $this->applySaleLevel($sale, $calculated, $totalRefund, $totalProfitReversal);
    }

    /**
     * Fetch the referenced lines, constrained to this sale.
     *
     * The constraint is the tenant check: a `sale_detail_id` from someone else's
     * sale simply is not found, so a return cannot reach across sales or businesses.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return \Illuminate\Support\Collection<int, SaleDetails>
     */
    private function loadDetails(Sale $sale, array $lines)
    {
        $ids = collect($lines)
            ->pluck('sale_detail_id')
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw CartCalculationException::reject('invalid_return_line', 'Every return line must reference a sale line.');
        }

        $details = SaleDetails::query()
            ->where('sale_id', $sale->id)
            ->whereIn('id', $ids)
            ->with('product:id,productName')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $missing = $ids->diff($details->keys());

        if ($missing->isNotEmpty()) {
            throw CartCalculationException::reject(
                'unknown_return_line',
                'One or more returned items do not belong to this sale.',
                ['sale_detail_ids' => $missing->values()->all()],
                404,
            );
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  \Illuminate\Support\Collection<int, SaleDetails>  $details
     */
    private function calculateLine(array $line, $details): CalculatedSaleReturnLine
    {
        $detail = $details->get((int) ($line['sale_detail_id'] ?? 0));
        $name = (string) ($detail->product?->productName ?? '');

        $quantity = $this->validateQuantity($line['return_qty'] ?? null, $name);
        $sold = (float) ($detail->quantities ?? 0);

        if ($quantity > $sold) {
            throw CartCalculationException::reject(
                'return_exceeds_sold',
                'Cannot return '.$this->trim($quantity).' of '.($name !== '' ? $name : 'this item')
                    .'; only '.$this->trim($sold).' remain on the sale.',
                [
                    'sale_detail_id' => (int) $detail->id,
                    'returnable' => $sold,
                    'requested' => $quantity,
                ],
            );
        }

        // The price it actually sold at, from the sale itself.
        $unitPrice = Money::fromFloat($detail->price ?? 0);
        $refund = $unitPrice->times($quantity);

        // The share of this line's profit that goes back with the goods.
        $lineProfit = Money::fromFloat($detail->lossProfit ?? 0);
        $profitReversal = $sold > 0
            ? Money::fromMinor((int) round($lineProfit->toMinor() * $quantity / $sold))
            : Money::zero();

        return new CalculatedSaleReturnLine(
            saleDetailId: (int) $detail->id,
            stockId: (int) $detail->stock_id,
            productId: (int) $detail->product_id,
            productName: $name,
            returnQuantity: $quantity,
            remainingQuantity: $sold - $quantity,
            unitPrice: $unitPrice,
            refund: $refund,
            profitReversal: $profitReversal,
        );
    }

    /**
     * How the refund lands on the sale and on the customer's balance.
     *
     * A refund settles the outstanding due first and only the remainder is money
     * handed back. Refunding cash while a due stands would otherwise pay a customer
     * for goods they had not finished paying for.
     *
     * @param  list<CalculatedSaleReturnLine>  $lines
     */
    private function applySaleLevel(
        Sale $sale,
        array $lines,
        Money $totalRefund,
        Money $totalProfitReversal,
    ): CalculatedSaleReturn {
        $saleTotal = Money::fromFloat($sale->totalAmount ?? 0);
        $saleDue = Money::fromFloat($sale->dueAmount ?? 0);
        $saleProfit = Money::fromFloat($sale->lossProfit ?? 0);
        $salePaid = Money::fromFloat($sale->paidAmount ?? 0);

        // The *unrounded* sum of the lines, which is what a refund is built
        // from. `totalAmount` is the rounded figure the customer was billed:
        // on a ₹70.30 sale rounded down to ₹70, returning the only line
        // produced a ₹70.30 refund and this guard refused it outright — so a
        // full return became impossible on every bill rounding had reduced.
        $actualTotal = Money::fromFloat(
            $sale->actual_total_amount ?: ($sale->totalAmount ?? 0)
        );

        if ($totalRefund->greaterThan($actualTotal)) {
            throw CartCalculationException::reject(
                'refund_exceeds_sale',
                'The refund cannot be greater than the sale it belongs to.',
                [
                    'refund' => $totalRefund->toFloat(),
                    'sale_total' => $actualTotal->toFloat(),
                ],
            );
        }

        // Never hand back more than was taken. Where rounding reduced the bill,
        // the shop collected ₹70 for goods priced ₹70.30; the refund is the ₹70.
        $totalRefund = $totalRefund->cappedAt($saleTotal);

        // A refund always rounds *down* — never to nearest, never up.
        //
        // Rounding a ₹32.81 return up to ₹33 hands back 19 paise the customer
        // never paid, on every return. The sale's own rounding option is
        // deliberately not consulted: it governs what is *charged*, where
        // rounding up is the shop's call, while this governs what is *paid
        // out*, where the shop absorbs the fraction instead.
        //
        // Rounding here, on the server, is what makes the figure the counter
        // sees the figure that is stored. The app used to round for display
        // only and call it a preview, so a ₹32.81 return showed one number and
        // saved another.
        $rawRefund = $totalRefund;
        $totalRefund = $totalRefund->rounded('round_down');

        // Spread the rounding across the lines so they still add up to it.
        //
        // The lines are what the returned-items list, the return report and the
        // invoice all print. Left at their raw values they summed to ₹32.81
        // beside a ₹32 total, so a return of ₹32 displayed the original price
        // of the goods rather than the credit actually given.
        $lines = $this->apportion($lines, $rawRefund, $totalRefund);

        $dueReduction = $totalRefund->cappedAt($saleDue);
        $cashRefund = $totalRefund->minus($dueReduction)->clampToZero();

        return new CalculatedSaleReturn(
            lines: $lines,
            totalRefund: $totalRefund,
            totalProfitReversal: $totalProfitReversal,
            saleTotalAfterReturn: $saleTotal->minus($totalRefund)->clampToZero(),
            saleDueAfterReturn: $saleDue->minus($dueReduction)->clampToZero(),
            saleProfitAfterReturn: $saleProfit->minus($totalProfitReversal),
            // Only the cash actually handed over leaves the sale. The part of
            // the refund that merely cancelled an outstanding due was never
            // paid in the first place.
            salePaidAfterReturn: $salePaid->minus($cashRefund)->clampToZero(),
            customerDueReduction: $dueReduction,
            cashRefund: $cashRefund,
        );
    }

    /**
     * Rescale line refunds so they sum exactly to [$rounded].
     *
     * Pro-rata on each line's own share, in integer minor units, with the
     * remainder given to the largest line — the same rule the cart uses to
     * apportion an order discount. Scaling in floats and rounding each part
     * independently would leave the sum a paisa or two off the total, which is
     * the defect this exists to avoid.
     *
     * @param  list<CalculatedSaleReturnLine>  $lines
     * @return list<CalculatedSaleReturnLine>
     */
    private function apportion(array $lines, Money $raw, Money $rounded): array
    {
        if ($lines === [] || $raw->isZero() || $raw->equals($rounded)) {
            return $lines;
        }

        $target = $rounded->toMinor();
        $source = $raw->toMinor();

        $allocated = 0;
        $shares = [];

        foreach ($lines as $index => $line) {
            $share = (int) floor($line->refund->toMinor() * $target / $source);
            $shares[$index] = $share;
            $allocated += $share;
        }

        // Whatever the flooring left over goes to the largest line, so the
        // adjustment lands where it is least visible per unit.
        $remainder = $target - $allocated;

        if ($remainder !== 0 && $shares !== []) {
            $largest = array_keys($shares, max($shares), true)[0];
            $shares[$largest] += $remainder;
        }

        return array_map(
            fn (int $index) => new CalculatedSaleReturnLine(
                saleDetailId: $lines[$index]->saleDetailId,
                stockId: $lines[$index]->stockId,
                productId: $lines[$index]->productId,
                productName: $lines[$index]->productName,
                returnQuantity: $lines[$index]->returnQuantity,
                remainingQuantity: $lines[$index]->remainingQuantity,
                unitPrice: $lines[$index]->unitPrice,
                refund: Money::fromMinor($shares[$index]),
                profitReversal: $lines[$index]->profitReversal,
            ),
            array_keys($lines),
        );
    }

    private function validateQuantity(mixed $value, string $productName): float
    {
        $label = $productName !== '' ? ' for '.$productName : '';

        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw CartCalculationException::reject(
                'invalid_quantity',
                'Return quantity'.$label.' is not a valid number.',
            );
        }

        $quantity = (float) $value;

        if (! is_finite($quantity)) {
            throw CartCalculationException::reject(
                'invalid_quantity',
                'Return quantity'.$label.' is not a valid number.',
            );
        }

        if ($quantity <= 0) {
            throw CartCalculationException::reject(
                'invalid_quantity',
                'Return quantity'.$label.' must be greater than zero.',
            );
        }

        return $quantity;
    }

    /**
     * Quantities read back to people, without a pointless trailing ".0000".
     */
    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
