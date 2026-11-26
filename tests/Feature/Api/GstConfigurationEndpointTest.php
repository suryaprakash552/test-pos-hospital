<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\GstConfigurationHistory;
use App\Models\User;
use App\Services\GstPricingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Changing how a shop bills tax.
 *
 * Two rules carry the weight: changing it needs an explicit permission (§19), because
 * the method appears on every invoice issued afterwards; and a change never touches
 * transactions already completed, which is what makes it safe to do part-way through
 * a year.
 */
class GstConfigurationEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $owner;
    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $category = BusinessCategory::factory()->create([
            'name'             => 'Pharmacy',
            'default_vat_type' => GstPricingMethod::INCLUDED,
        ]);

        $this->business = Business::factory()->create([
            'business_category_id' => $category->id,
        ]);

        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        $this->staff = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'staff',
        ]);
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    public function test_it_reports_the_shop_type_default_and_the_effective_method(): void
    {
        $this->actingAs($this->owner)
            ->getJson('/api/v1/gst-configuration')
            ->assertStatus(200)
            ->assertJsonPath('data.shop_type', 'Pharmacy')
            ->assertJsonPath('data.shop_type_default', GstPricingMethod::INCLUDED)
            // What billing would use right now, so the screen need not work it out.
            ->assertJsonPath('data.effective_method', GstPricingMethod::INCLUDED);
    }

    public function test_it_offers_all_three_methods(): void
    {
        $response = $this->actingAs($this->owner)
            ->getJson('/api/v1/gst-configuration')
            ->assertStatus(200);

        $values = array_column($response->json('data.available_methods'), 'value');

        $this->assertEqualsCanonicalizing(GstPricingMethod::all(), $values);
    }

    // ── Permission ───────────────────────────────────────────────────────────

    public function test_staff_cannot_change_the_configuration(): void
    {
        $this->actingAs($this->staff)
            ->putJson('/api/v1/gst-configuration', [
                'default_vat_type' => GstPricingMethod::EXCLUDED,
                'reason'           => 'trying it on',
            ])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'not_permitted');

        $this->assertNull($this->business->fresh()->default_vat_type);
    }

    /**
     * §19: the permission is grantable, so a shop can delegate this without handing
     * over the owner account.
     */
    public function test_granted_staff_can_change_the_configuration(): void
    {
        $this->staff->update([
            'visibility' => ['gst' => ['update' => '1']],
        ]);

        $this->actingAs($this->staff)
            ->putJson('/api/v1/gst-configuration', [
                'default_vat_type' => GstPricingMethod::EXCLUDED,
                'reason'           => 'Delegated to the accounts manager',
                'effective_from'   => '2026-10-01',
            ])
            ->assertStatus(200);

        $this->assertSame(
            GstPricingMethod::EXCLUDED,
            $this->business->fresh()->default_vat_type,
        );
    }

    /** §10: a locked configuration is read-only even to someone who may edit it. */
    public function test_a_locked_configuration_refuses_a_granted_staff_member(): void
    {
        $this->business->update(['allow_gst_override' => false]);

        $this->staff->update([
            'visibility' => ['gst' => ['update' => '1']],
        ]);

        $this->actingAs($this->staff)
            ->putJson('/api/v1/gst-configuration', [
                'default_vat_type' => GstPricingMethod::EXCLUDED,
                'reason'           => 'trying it on',
                'effective_from'   => '2026-10-01',
            ])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'override_not_allowed');
    }

    /** The separate permission §19 names for exactly this. */
    public function test_the_override_permission_unlocks_it(): void
    {
        $this->business->update(['allow_gst_override' => false]);

        $this->staff->update([
            'visibility' => [
                'gst' => ['update' => '1', 'override' => '1'],
            ],
        ]);

        $this->actingAs($this->staff)
            ->putJson('/api/v1/gst-configuration', [
                'default_vat_type' => GstPricingMethod::EXCLUDED,
                'reason'           => 'Authorised override',
                'effective_from'   => '2026-10-01',
            ])
            ->assertStatus(200);
    }

    // ── Overriding the shop type ─────────────────────────────────────────────

    public function test_deviating_from_the_shop_type_requires_a_reason(): void
    {
        // Pharmacy defaults to inclusive; switching to exclusive is a deviation.
        $this->actingAs($this->owner)
            ->putJson('/api/v1/gst-configuration', [
                'default_vat_type' => GstPricingMethod::EXCLUDED,
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'reason_required');
    }

    public function test_matching_the_shop_type_needs_no_reason(): void
    {
        $this->actingAs($this->owner)
            ->putJson('/api/v1/gst-configuration', [
                'default_vat_type' => GstPricingMethod::INCLUDED,
                'effective_from'   => '2026-10-01',
            ])
            ->assertStatus(200);
    }

    /**
     * §17: a change to the pricing method takes effect from a stated date.
     *
     * Without one there is no answer to "which method was this invoice billed
     * under" — the question a return is reconciled against, and the reason
     * [GstConfiguration::resolveAsOf] has a date to work with at all.
     */
    public function test_changing_the_method_needs_an_effective_date(): void
    {
        $this->actingAs($this->owner)
            ->putJson('/api/v1/gst-configuration', [
                'default_vat_type' => GstPricingMethod::EXCLUDED,
                'reason'           => 'switching',
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'effective_date_required');

        $this->assertNull($this->business->fresh()->default_vat_type);
    }

    /** Changing something else is not a method change and needs no date. */
    public function test_an_unrelated_field_needs_no_effective_date(): void
    {
        $this->actingAs($this->owner)
            ->putJson('/api/v1/gst-configuration', [
                'place_of_supply' => 'Karnataka',
            ])
            ->assertStatus(200);

        $this->assertSame('Karnataka', $this->business->fresh()->place_of_supply);
    }

    public function test_a_deviation_with_a_reason_is_accepted_and_recorded(): void
    {
        $this->actingAs($this->owner)
            ->putJson('/api/v1/gst-configuration', [
                'default_vat_type' => GstPricingMethod::EXCLUDED,
                'reason'           => 'We quote trade prices before tax',
                'effective_from'   => '2026-10-01',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.effective_method', GstPricingMethod::EXCLUDED);

        $entry = GstConfigurationHistory::where('field', 'default_vat_type')->first();

        $this->assertNotNull($entry);
        $this->assertSame(GstPricingMethod::EXCLUDED, $entry->new_value);
        $this->assertSame('We quote trade prices before tax', $entry->reason);
        $this->assertSame($this->owner->id, $entry->changed_by);
    }

    // ── Validation ───────────────────────────────────────────────────────────

    public function test_a_registered_shop_must_supply_a_gstin(): void
    {
        $this->actingAs($this->owner)
            ->putJson('/api/v1/gst-configuration', ['gst_registered' => true])
            ->assertStatus(422)
            ->assertJsonPath('errors.gstin', 'GSTIN is mandatory for a GST-registered branch.');
    }

    public function test_a_malformed_gstin_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->putJson('/api/v1/gst-configuration', [
                'gst_registered' => true,
                'gstin'          => 'NOTAGSTIN',
                'gst_state'      => 'Karnataka',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.gstin', 'The entered GSTIN format is invalid.');
    }

    public function test_a_registered_shop_cannot_be_not_applicable(): void
    {
        $this->actingAs($this->owner)
            ->putJson('/api/v1/gst-configuration', [
                'gst_registered'   => true,
                'gstin'            => '29ABCDE1234F1Z5',
                'gst_state'        => 'Karnataka',
                'default_vat_type' => GstPricingMethod::NOT_APPLICABLE,
                'reason'           => 'no tax please',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(["errors" => ["default_vat_type"]]);
    }

    /**
     * A partial update that *results* in an invalid state is just as wrong as one
     * that says so outright.
     */
    public function test_validation_runs_against_the_resulting_state(): void
    {
        $this->business->update([
            'gst_registered' => true,
            'gstin'          => '29ABCDE1234F1Z5',
            'gst_state'      => 'Karnataka',
        ]);

        // Clearing only the GSTIN would leave a registered shop without one.
        $this->actingAs($this->owner)
            ->putJson('/api/v1/gst-configuration', ['gstin' => null])
            ->assertStatus(422);
    }

    public function test_a_complete_registration_is_saved(): void
    {
        $this->actingAs($this->owner)
            ->putJson('/api/v1/gst-configuration', [
                'gst_registered' => true,
                'gstin'          => '29ABCDE1234F1Z5',
                'gst_state'      => 'Karnataka',
            ])
            ->assertStatus(200);

        $fresh = $this->business->fresh();

        $this->assertTrue((bool) $fresh->gst_registered);
        $this->assertSame('29ABCDE1234F1Z5', $fresh->gstin);
    }

    // ── Effective dating and history ─────────────────────────────────────────

    public function test_a_change_does_not_touch_completed_invoices(): void
    {
        $saleId = DB::table('sales')->insertGetId([
            'business_id'   => $this->business->id,
            'invoiceNumber' => 'S-1',
            'totalAmount'   => 1180,
            'vat_amount'    => 180,
            'vat_percent'   => 18,
            'saleDate'      => now()->toDateString(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $before = DB::table('sales')->where('id', $saleId)->first();

        $this->actingAs($this->owner)
            ->putJson('/api/v1/gst-configuration', [
                'default_vat_type' => GstPricingMethod::EXCLUDED,
                'reason'           => 'switching',
                'effective_from'   => now()->addDay()->toDateString(),
            ])
            ->assertStatus(200);

        $after = DB::table('sales')->where('id', $saleId)->first();

        $this->assertEquals($before->totalAmount, $after->totalAmount);
        $this->assertEquals($before->vat_amount, $after->vat_amount,
            'an invoice keeps the tax it was billed with');
    }

    public function test_the_response_says_existing_invoices_are_unchanged(): void
    {
        $this->actingAs($this->owner)
            ->putJson('/api/v1/gst-configuration', [
                'default_vat_type' => GstPricingMethod::EXCLUDED,
                'reason'           => 'switching',
                'effective_from'   => '2026-10-01',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.note', 'Existing invoices are unchanged. This applies to new transactions only.');
    }

    public function test_an_unchanged_value_records_no_history(): void
    {
        $this->business->update(['default_vat_type' => GstPricingMethod::INCLUDED]);

        $this->actingAs($this->owner)
            ->putJson('/api/v1/gst-configuration', [
                'default_vat_type' => GstPricingMethod::INCLUDED,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.changed', []);

        $this->assertSame(0, GstConfigurationHistory::count());
    }

    public function test_the_history_is_readable_and_scoped_to_the_business(): void
    {
        $this->actingAs($this->owner)
            ->putJson('/api/v1/gst-configuration', [
                'default_vat_type' => GstPricingMethod::EXCLUDED,
                'reason'           => 'switching',
                'effective_from'   => '2026-10-01',
            ])->assertStatus(200);

        GstConfigurationHistory::create([
            'business_id'       => Business::factory()->create()->id,
            'configurable_type' => Business::class,
            'configurable_id'   => 999,
            'field'             => 'default_vat_type',
            'new_value'         => 'inclusive',
        ]);

        $this->actingAs($this->owner)
            ->getJson('/api/v1/gst-configuration/history')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_the_history_keeps_the_previous_value(): void
    {
        $this->business->update(['default_vat_type' => GstPricingMethod::INCLUDED]);

        $this->actingAs($this->owner)
            ->putJson('/api/v1/gst-configuration', [
                'default_vat_type' => GstPricingMethod::EXCLUDED,
                'reason'           => 'switching',
                'effective_from'   => '2026-10-01',
            ])->assertStatus(200);

        $entry = GstConfigurationHistory::where('field', 'default_vat_type')->first();

        $this->assertSame(GstPricingMethod::INCLUDED, $entry->old_value,
            'the answer to "what was it before" must outlive the overwrite');
    }
}
