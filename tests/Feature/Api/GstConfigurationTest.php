<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\BusinessCategory;
use App\Services\GstConfiguration;
use App\Services\GstPricingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GST pricing configuration: which method applies, and whether a branch's GST identity
 * is usable.
 *
 * The arithmetic cases below are the worked examples from the specification, so a
 * change that breaks a bill breaks a test with a recognisable number in it.
 *
 * Configuration only — nothing here splits a total into CGST/SGST/IGST.
 */
class GstConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private function businessWith(array $attributes = [], ?string $shopTypeDefault = null): Business
    {
        $category = BusinessCategory::factory()->create([
            'default_vat_type' => $shopTypeDefault,
        ]);

        return Business::factory()->create($attributes + [
            'business_category_id' => $category->id,
        ]);
    }

    // ── §7 GST-included arithmetic ───────────────────────────────────────────

    public function test_an_inclusive_price_has_its_tax_extracted_not_added(): void
    {
        // Spec §7: ₹1,180 at 18% → taxable ₹1,000, GST ₹180, total unchanged.
        $result = GstPricingMethod::split(1180, 18, GstPricingMethod::INCLUDED);

        $this->assertSame(1000.00, $result['taxable']);
        $this->assertSame(180.00, $result['tax']);
        $this->assertSame(1180.00, $result['total'],
            'an inclusive price is what the customer already pays');
    }

    // ── §8 GST-excluded arithmetic ───────────────────────────────────────────

    public function test_an_exclusive_price_has_tax_added_on_top(): void
    {
        // Spec §8: ₹1,000 at 18% → taxable ₹1,000, GST ₹180, total ₹1,180.
        $result = GstPricingMethod::split(1000, 18, GstPricingMethod::EXCLUDED);

        $this->assertSame(1000.00, $result['taxable']);
        $this->assertSame(180.00, $result['tax']);
        $this->assertSame(1180.00, $result['total']);
    }

    // ── §13 discount before tax ──────────────────────────────────────────────

    public function test_tax_follows_the_discount_on_an_inclusive_price(): void
    {
        // Spec §13: ₹1,180 less ₹118 = ₹1,062 → taxable ₹900, GST ₹162.
        $result = GstPricingMethod::split(1062, 18, GstPricingMethod::INCLUDED);

        $this->assertSame(900.00, $result['taxable']);
        $this->assertSame(162.00, $result['tax']);
        $this->assertSame(1062.00, $result['total']);
    }

    public function test_tax_follows_the_discount_on_an_exclusive_price(): void
    {
        // Spec §13: ₹1,000 less ₹100 = ₹900 → GST ₹162, total ₹1,062.
        $result = GstPricingMethod::split(900, 18, GstPricingMethod::EXCLUDED);

        $this->assertSame(162.00, $result['tax']);
        $this->assertSame(1062.00, $result['total']);
    }

    public function test_not_applicable_charges_nothing_even_at_a_rate(): void
    {
        $result = GstPricingMethod::split(1000, 18, GstPricingMethod::NOT_APPLICABLE);

        $this->assertSame(0.0, $result['tax']);
        $this->assertSame(1000.00, $result['total']);
    }

    public function test_a_zero_rate_is_not_the_same_as_out_of_scope(): void
    {
        // Both charge nothing, but one is a taxable supply and the other is not —
        // which is why the method has three values, not a nullable rate.
        $zeroRated = GstPricingMethod::split(1000, 0, GstPricingMethod::EXCLUDED);

        $this->assertSame(0.0, $zeroRated['tax']);
        $this->assertTrue(GstPricingMethod::chargesTax(GstPricingMethod::EXCLUDED));
        $this->assertFalse(GstPricingMethod::chargesTax(GstPricingMethod::NOT_APPLICABLE));
    }

    public function test_inclusive_and_exclusive_agree_on_the_same_taxable_value(): void
    {
        $inclusive = GstPricingMethod::split(1180, 18, GstPricingMethod::INCLUDED);
        $exclusive = GstPricingMethod::split(1000, 18, GstPricingMethod::EXCLUDED);

        $this->assertSame($inclusive['taxable'], $exclusive['taxable']);
        $this->assertSame($inclusive['tax'], $exclusive['tax']);
        $this->assertSame($inclusive['total'], $exclusive['total']);
    }

    // ── §23 resolution hierarchy ─────────────────────────────────────────────

    public function test_an_item_override_beats_everything(): void
    {
        $business = $this->businessWith(
            ['default_vat_type' => GstPricingMethod::EXCLUDED],
            GstPricingMethod::EXCLUDED,
        );

        $this->assertSame(
            GstPricingMethod::INCLUDED,
            GstConfiguration::resolve(GstPricingMethod::INCLUDED, null, $business),
        );
    }

    public function test_a_branch_beats_the_business_and_the_shop_type(): void
    {
        $business = $this->businessWith(
            ['default_vat_type' => GstPricingMethod::EXCLUDED],
            GstPricingMethod::EXCLUDED,
        );
        $branch = (object) ['default_vat_type' => GstPricingMethod::INCLUDED];

        $this->assertSame(
            GstPricingMethod::INCLUDED,
            GstConfiguration::resolve(null, $branch, $business),
        );
    }

    public function test_the_business_beats_the_shop_type(): void
    {
        $business = $this->businessWith(
            ['default_vat_type' => GstPricingMethod::INCLUDED],
            GstPricingMethod::EXCLUDED,
        );

        $this->assertSame(
            GstPricingMethod::INCLUDED,
            GstConfiguration::resolve(null, null, $business),
        );
    }

    public function test_the_shop_type_supplies_the_default(): void
    {
        $business = $this->businessWith([], GstPricingMethod::INCLUDED);

        $this->assertSame(
            GstPricingMethod::INCLUDED,
            GstConfiguration::resolve(null, null, $business),
        );
    }

    /** The rule that keeps existing shops working. */
    public function test_a_shop_that_configured_nothing_behaves_as_before(): void
    {
        $business = $this->businessWith();

        $this->assertSame(
            GstPricingMethod::EXCLUDED,
            GstConfiguration::resolve(null, null, $business),
        );
    }

    public function test_an_unregistered_branch_charges_no_tax(): void
    {
        $business = $this->businessWith([], GstPricingMethod::INCLUDED);
        $branch = (object) ['default_vat_type' => null, 'gst_registered' => false];

        $this->assertSame(
            GstPricingMethod::NOT_APPLICABLE,
            GstConfiguration::resolve(null, $branch, $business),
            'not being registered outranks the shop type convention',
        );
    }

    public function test_unregistered_shop_outweighs_an_item_tax_method(): void
    {
        $business = $this->businessWith([
            'gst_registered' => false,
            'default_vat_type' => GstPricingMethod::EXCLUDED,
        ]);

        $this->assertSame(
            GstPricingMethod::NOT_APPLICABLE,
            GstConfiguration::resolve(GstPricingMethod::INCLUDED, null, $business),
            'an inventory tax method must not charge GST while registration is off',
        );
    }

    // ── Strict mode ──────────────────────────────────────────────────────────

    public function test_a_registered_shop_with_nothing_configured_is_refused(): void
    {
        $business = $this->businessWith(['gst_registered' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GST pricing configuration is unavailable');

        GstConfiguration::resolveStrict(null, null, $business);
    }

    public function test_an_unregistered_shop_is_never_refused(): void
    {
        $business = $this->businessWith(['gst_registered' => false]);

        $this->assertSame(
            GstPricingMethod::NOT_APPLICABLE,
            GstConfiguration::resolveStrict(null, null, $business),
        );
    }

    // ── §17 GSTIN validation ─────────────────────────────────────────────────

    public function test_a_well_formed_gstin_is_accepted(): void
    {
        $this->assertTrue(GstConfiguration::isValidGstin('29ABCDE1234F1Z5'));
    }

    public function test_a_malformed_gstin_is_rejected(): void
    {
        foreach ([
            '29ABCDE1234F1Z',    // too short
            'AAABCDE1234F1Z5',   // state code not numeric
            '29ABCDE1234F1X5',   // the fixed Z is wrong
            '',
        ] as $bad) {
            $this->assertFalse(
                GstConfiguration::isValidGstin($bad),
                "'$bad' should not be accepted",
            );
        }
    }

    public function test_the_state_code_is_read_from_the_gstin(): void
    {
        $this->assertSame('29', GstConfiguration::stateCodeOf('29ABCDE1234F1Z5'));
        $this->assertNull(GstConfiguration::stateCodeOf('nonsense'));
    }

    public function test_a_registered_branch_must_supply_a_gstin_and_state(): void
    {
        $errors = GstConfiguration::validate(['gst_registered' => true]);

        $this->assertArrayHasKey('gstin', $errors);
        $this->assertArrayHasKey('gst_state', $errors);
    }

    public function test_a_registered_branch_cannot_be_not_applicable(): void
    {
        $errors = GstConfiguration::validate([
            'gst_registered'   => true,
            'gstin'            => '29ABCDE1234F1Z5',
            'gst_state'        => 'Karnataka',
            'default_vat_type' => GstPricingMethod::NOT_APPLICABLE,
        ]);

        $this->assertArrayHasKey('default_vat_type', $errors);
    }

    public function test_a_complete_registered_configuration_is_valid(): void
    {
        $errors = GstConfiguration::validate([
            'gst_registered'   => true,
            'gstin'            => '29ABCDE1234F1Z5',
            'gst_state'        => 'Karnataka',
            'default_vat_type' => GstPricingMethod::INCLUDED,
        ]);

        $this->assertSame([], $errors);
    }

    public function test_an_unregistered_branch_needs_no_gstin(): void
    {
        $this->assertSame([], GstConfiguration::validate(['gst_registered' => false]));
    }

    // ── §14 place of supply ──────────────────────────────────────────────────

    public function test_the_same_state_is_an_intra_state_supply(): void
    {
        $this->assertTrue(GstConfiguration::isIntraState('Karnataka', 'Karnataka'));
        $this->assertTrue(GstConfiguration::isIntraState('Karnataka', ' karnataka '));
    }

    public function test_a_different_state_is_inter_state(): void
    {
        $this->assertFalse(GstConfiguration::isIntraState('Karnataka', 'Kerala'));
    }

    public function test_an_unknown_place_of_supply_is_treated_as_local(): void
    {
        $this->assertTrue(
            GstConfiguration::isIntraState('Karnataka', null),
            'IGST charged wrongly on a local sale is harder to unwind than the reverse',
        );
    }
}
