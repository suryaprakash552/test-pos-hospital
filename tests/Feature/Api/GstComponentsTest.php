<?php

namespace Tests\Feature\Api;

use App\Services\GstComponents;
use App\Services\GstPricingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The CGST / SGST / IGST breakdown shown on a GST invoice.
 *
 * The property everything else depends on: the components always sum back to the tax
 * total they came from. `vat_amount` stays authoritative and unchanged, so an invoice
 * and a report can never disagree about how much tax was charged — only about how it
 * was labelled.
 *
 * A supply is intra-state or inter-state, never both.
 */
class GstComponentsTest extends TestCase
{
    use RefreshDatabase;

    // ── §7/§8/§14 the split ──────────────────────────────────────────────────

    public function test_an_intra_state_supply_is_halved_into_cgst_and_sgst(): void
    {
        // Spec §7: ₹180 within one state → ₹90 CGST + ₹90 SGST.
        $split = GstComponents::split(180, isInterState: false);

        $this->assertSame(90.0, $split['cgst']);
        $this->assertSame(90.0, $split['sgst']);
        $this->assertSame(0.0, $split['igst']);
    }

    public function test_an_inter_state_supply_is_all_igst(): void
    {
        // Spec §7: ₹180 across states → ₹180 IGST.
        $split = GstComponents::split(180, isInterState: true);

        $this->assertSame(180.0, $split['igst']);
        $this->assertSame(0.0, $split['cgst']);
        $this->assertSame(0.0, $split['sgst']);
    }

    public function test_a_supply_is_never_both(): void
    {
        foreach ([true, false] as $interState) {
            $split = GstComponents::split(180, isInterState: $interState);

            $hasLocal = $split['cgst'] > 0 || $split['sgst'] > 0;
            $hasInterState = $split['igst'] > 0;

            $this->assertFalse($hasLocal && $hasInterState,
                'CGST/SGST and IGST are mutually exclusive');
        }
    }

    // ── The invariant ────────────────────────────────────────────────────────

    public function test_the_components_always_sum_back_to_the_total(): void
    {
        // Deliberately includes odd paise, where a naive halving drifts.
        foreach ([0.01, 0.05, 1.0, 17.99, 90.0, 180.0, 1234.57, 99999.99] as $total) {
            foreach ([true, false] as $interState) {
                $split = GstComponents::split($total, isInterState: $interState);
                $sum = round($split['cgst'] + $split['sgst'] + $split['igst'], 2);

                $this->assertSame(
                    round($total, 2),
                    $sum,
                    "components must reconcile for $total",
                );
            }
        }
    }

    /**
     * Halving an odd number of paise leaves a remainder. Rounding both halves up
     * would overstate the liability by a paisa on every such invoice.
     */
    public function test_an_odd_remainder_does_not_inflate_the_total(): void
    {
        $split = GstComponents::split(0.05, isInterState: false);

        $this->assertSame(0.03, $split['cgst']);
        $this->assertSame(0.02, $split['sgst']);
        $this->assertSame(0.05, round($split['cgst'] + $split['sgst'], 2));
    }

    public function test_zero_tax_produces_zero_components(): void
    {
        $split = GstComponents::split(0, isInterState: false);

        $this->assertSame(0.0, $split['cgst']);
        $this->assertSame(0.0, $split['sgst']);
        $this->assertSame(0.0, $split['igst']);
    }

    public function test_cess_sits_outside_the_three(): void
    {
        $split = GstComponents::split(180, isInterState: false, cess: 20);

        $this->assertSame(20.0, $split['cess']);
        // Still reconciles: cess is added on top, not carved out.
        $this->assertSame(180.0, round($split['cgst'] + $split['sgst'], 2));
    }

    // ── Persisted columns ────────────────────────────────────────────────────

    public function test_the_columns_exist_on_both_transaction_tables(): void
    {
        foreach (['sales', 'purchases'] as $table) {
            foreach (['cgst_amount', 'sgst_amount', 'igst_amount', 'cess_amount',
                      'is_inter_state', 'gst_method'] as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "$table.$column must exist",
                );
            }
        }

        $this->assertTrue(Schema::hasColumn('products', 'hsn_code'));
        $this->assertTrue(Schema::hasColumn('products', 'cess_rate'));
    }

    public function test_same_state_produces_a_local_breakdown(): void
    {
        $columns = GstComponents::columnsFor(
            180,
            'Karnataka',
            'Karnataka',
            GstPricingMethod::EXCLUDED,
        );

        $this->assertSame(90.0, $columns['cgst_amount']);
        $this->assertSame(90.0, $columns['sgst_amount']);
        $this->assertFalse($columns['is_inter_state']);
    }

    public function test_different_states_produce_igst(): void
    {
        $columns = GstComponents::columnsFor(
            180,
            'Karnataka',
            'Kerala',
            GstPricingMethod::EXCLUDED,
        );

        $this->assertSame(180.0, $columns['igst_amount']);
        $this->assertTrue($columns['is_inter_state']);
    }

    /**
     * Zeroes would claim tax was calculated and came to nil, which is a different
     * statement from "GST does not apply here".
     */
    public function test_not_applicable_records_nulls_not_zeroes(): void
    {
        $columns = GstComponents::columnsFor(
            0,
            'Karnataka',
            'Karnataka',
            GstPricingMethod::NOT_APPLICABLE,
        );

        $this->assertNull($columns['cgst_amount']);
        $this->assertNull($columns['igst_amount']);
        $this->assertNull($columns['is_inter_state']);
        $this->assertSame(GstPricingMethod::NOT_APPLICABLE, $columns['gst_method']);
    }

    /** §17: a completed invoice keeps the method it was billed under. */
    public function test_the_billing_method_is_frozen_on_the_document(): void
    {
        $columns = GstComponents::columnsFor(
            180,
            'Karnataka',
            'Karnataka',
            GstPricingMethod::INCLUDED,
        );

        $this->assertSame(GstPricingMethod::INCLUDED, $columns['gst_method']);
    }

    // ── Reconciliation of stored rows ────────────────────────────────────────

    public function test_a_legacy_row_without_components_still_reconciles(): void
    {
        // Nothing was backfilled, so historical invoices carry nulls. That is a
        // legacy invoice, not a broken one.
        $this->assertTrue(GstComponents::reconciles(null, null, null, 180));
    }

    public function test_a_tampered_breakdown_is_detected(): void
    {
        $this->assertFalse(GstComponents::reconciles(90, 90, 0, 200));
        $this->assertTrue(GstComponents::reconciles(90, 90, 0, 180));
    }

    public function test_everything_the_splitter_produces_reconciles(): void
    {
        foreach ([1.0, 17.99, 180.0, 1234.57] as $total) {
            foreach ([true, false] as $interState) {
                $split = GstComponents::split($total, isInterState: $interState);

                $this->assertTrue(
                    GstComponents::reconciles(
                        $split['cgst'], $split['sgst'], $split['igst'], $total,
                    ),
                    "the splitter must produce a reconciling breakdown for $total",
                );
            }
        }
    }
}
