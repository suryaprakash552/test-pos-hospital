<?php

namespace App\Services;

/**
 * Splits a tax total into the components a GST invoice must show.
 *
 * ## The rule
 *
 * A supply is either within one state or across two, never both:
 *
 *   * intra-state → CGST + SGST, half the total each
 *   * inter-state → IGST, the whole total
 *
 * The split is a *presentation* of `vat_amount`, not a recalculation of it. The
 * components always sum back to the total they came from, so an invoice and a report
 * can never disagree about how much tax was charged — only about how it was labelled.
 *
 * That invariant is why the total is passed in rather than recomputed here: rederiving
 * it from a rate would reintroduce the rounding drift this is meant to avoid.
 *
 * Cess sits outside the three and is added on top, so it is not part of the sum.
 */
class GstComponents
{
    /**
     * @return array{
     *   cgst: float, sgst: float, igst: float, cess: float,
     *   is_inter_state: bool, total: float
     * }
     */
    public static function split(
        float $taxTotal,
        bool $isInterState,
        float $cess = 0.0,
    ): array {
        $taxTotal = round($taxTotal, 2);

        if ($taxTotal <= 0) {
            return [
                'cgst' => 0.0,
                'sgst' => 0.0,
                'igst' => 0.0,
                'cess' => round(max($cess, 0), 2),
                'is_inter_state' => $isInterState,
                'total' => 0.0,
            ];
        }

        if ($isInterState) {
            return [
                'cgst' => 0.0,
                'sgst' => 0.0,
                'igst' => $taxTotal,
                'cess' => round(max($cess, 0), 2),
                'is_inter_state' => true,
                'total' => $taxTotal,
            ];
        }

        // Halving an odd number of paise leaves a remainder. It goes to CGST so the
        // two halves still add back to the total — splitting 0.05 into 0.03/0.02 is
        // correct; 0.03/0.03 would overstate the liability by a paisa on every such
        // invoice, and those add up across a year.
        $cgst = round($taxTotal / 2, 2);
        $sgst = round($taxTotal - $cgst, 2);

        return [
            'cgst' => $cgst,
            'sgst' => $sgst,
            'igst' => 0.0,
            'cess' => round(max($cess, 0), 2),
            'is_inter_state' => false,
            'total' => $taxTotal,
        ];
    }

    /**
     * The columns to persist alongside a transaction.
     *
     * @return array<string, mixed>
     */
    public static function columnsFor(
        float $taxTotal,
        ?string $branchState,
        ?string $placeOfSupply,
        string $gstMethod,
        float $cess = 0.0,
    ): array {
        // Not applicable means no tax at all, so there is nothing to break down —
        // recording zeroes would be a claim that tax was calculated and came to nil.
        if (! GstPricingMethod::chargesTax($gstMethod)) {
            return [
                'cgst_amount'     => null,
                'sgst_amount'     => null,
                'igst_amount'     => null,
                'cess_amount'     => null,
                'is_inter_state'  => null,
                'gst_method'      => $gstMethod,
                'place_of_supply' => $placeOfSupply,
            ];
        }

        $isInterState = ! GstConfiguration::isIntraState($branchState, $placeOfSupply);
        $split = self::split($taxTotal, $isInterState, $cess);

        return [
            'cgst_amount'     => $split['cgst'],
            'sgst_amount'     => $split['sgst'],
            'igst_amount'     => $split['igst'],
            'cess_amount'     => $split['cess'],
            'is_inter_state'  => $split['is_inter_state'],
            // Frozen here so the document keeps the method it was billed under even
            // after the shop's configuration changes.
            'gst_method'      => $gstMethod,
            'place_of_supply' => $placeOfSupply,
        ];
    }

    /**
     * Whether a stored breakdown still reconciles against its total.
     *
     * Null components mean the row predates the breakdown, which is valid — it is a
     * legacy invoice, not a broken one.
     */
    public static function reconciles(
        ?float $cgst,
        ?float $sgst,
        ?float $igst,
        float $taxTotal,
    ): bool {
        if ($cgst === null && $sgst === null && $igst === null) {
            return true;
        }

        $sum = round(($cgst ?? 0) + ($sgst ?? 0) + ($igst ?? 0), 2);

        return abs($sum - round($taxTotal, 2)) < 0.01;
    }
}
