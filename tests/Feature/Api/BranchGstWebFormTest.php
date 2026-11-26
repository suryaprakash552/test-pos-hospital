<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\GstConfigurationHistory;
use App\Models\User;
use App\Services\GstPricingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Branch GST configuration from the admin web form.
 *
 * A branch can be created from two places, each with its own controller. The API
 * path was covered first and the web path was not — so a branch created from the
 * admin skipped every rule the API enforced. That is how two branches of one shop
 * end up billing differently, and the shared trait exists to stop it.
 *
 * These tests target the web routes specifically; [BranchGstConfigurationTest]
 * covers the same rules over the API.
 */
class BranchGstWebFormTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $category = BusinessCategory::factory()->create([
            'name'             => 'Restaurant ' . uniqid(),
            'default_vat_type' => GstPricingMethod::INCLUDED,
        ]);

        $this->business = Business::factory()->create([
            'business_category_id' => $category->id,
            'default_vat_type'     => null,
        ]);

        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        Branch::withoutEvents(fn () => Branch::create([
            'business_id' => $this->business->id,
            'name'        => 'Head Office',
            'is_main'     => 1,
        ]));
    }

    private function createBranch(array $payload = [])
    {
        return $this->actingAs($this->owner)
            ->postJson(route('multibranch.branches.store'), $payload + [
                'name' => 'Second Branch',
            ]);
    }

    public function test_the_web_form_populates_the_method_from_the_shop_type(): void
    {
        $this->createBranch()->assertStatus(200);

        $this->assertSame(
            GstPricingMethod::INCLUDED,
            Branch::where('name', 'Second Branch')->value('default_vat_type'),
            'a branch created from the admin must inherit the shop type, as the API does',
        );
    }

    public function test_the_web_form_refuses_a_registered_branch_without_a_gstin(): void
    {
        $this->createBranch(['gst_registered' => 1])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['gstin']]);

        $this->assertDatabaseMissing('branches', ['name' => 'Second Branch']);
    }

    public function test_the_web_form_refuses_a_gstin_from_another_state(): void
    {
        $this->createBranch([
            'gst_registered' => 1,
            'gstin'          => '29ABCDE1234F1Z5',
            'gst_state'      => 'Maharashtra',
        ])->assertStatus(422);
    }

    public function test_the_web_form_requires_a_reason_to_deviate(): void
    {
        $this->createBranch(['default_vat_type' => GstPricingMethod::EXCLUDED])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'reason_required');
    }

    public function test_the_web_form_records_the_creation(): void
    {
        $this->createBranch()->assertStatus(200);

        $branch = Branch::where('name', 'Second Branch')->first();

        $this->assertDatabaseHas('gst_configuration_histories', [
            'configurable_type' => Branch::class,
            'configurable_id'   => $branch->id,
            'field'             => 'default_vat_type',
            'new_value'         => GstPricingMethod::INCLUDED,
        ]);
    }

    /**
     * The form posts every field it renders, empty ones as "". Those must read as
     * "leave it alone", not as a request to blank the branch's tax identity.
     */
    public function test_blank_form_fields_do_not_disturb_the_configuration(): void
    {
        $branch = Branch::withoutEvents(fn () => Branch::create([
            'business_id'      => $this->business->id,
            'name'             => 'Second Branch',
            'gstin'            => '29ABCDE1234F1Z5',
            'gst_state'        => 'Karnataka',
            'default_vat_type' => GstPricingMethod::INCLUDED,
        ]));

        $this->actingAs($this->owner)
            ->putJson(route('multibranch.branches.update', $branch->id), [
                'name'               => 'Renamed',
                'gst_registered'     => '',
                'gstin'              => '',
                'gst_state'          => '',
                'place_of_supply'    => '',
                'default_vat_type'   => '',
                'gst_effective_from' => '',
                'gst_change_reason'  => '',
            ])
            ->assertStatus(200);

        $fresh = $branch->fresh();

        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame('29ABCDE1234F1Z5', $fresh->gstin);
        $this->assertSame(GstPricingMethod::INCLUDED, $fresh->default_vat_type);
        $this->assertSame(
            0,
            GstConfigurationHistory::where('configurable_id', $branch->id)->count(),
            'leaving the section untouched is not a GST change',
        );
    }

    public function test_the_web_form_requires_an_effective_date_to_change_the_method(): void
    {
        $branch = Branch::withoutEvents(fn () => Branch::create([
            'business_id'        => $this->business->id,
            'name'               => 'Second Branch',
            'default_vat_type'   => GstPricingMethod::INCLUDED,
            'allow_gst_override' => true,
        ]));

        $this->actingAs($this->owner)
            ->putJson(route('multibranch.branches.update', $branch->id), [
                'name'              => 'Second Branch',
                'default_vat_type'  => GstPricingMethod::EXCLUDED,
                'gst_change_reason' => 'Switching to exclusive pricing.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'effective_date_required');

        $this->assertSame(
            GstPricingMethod::INCLUDED,
            $branch->fresh()->default_vat_type,
        );
    }

    public function test_a_dated_change_from_the_web_form_is_recorded(): void
    {
        $branch = Branch::withoutEvents(fn () => Branch::create([
            'business_id'        => $this->business->id,
            'name'               => 'Second Branch',
            'default_vat_type'   => GstPricingMethod::INCLUDED,
            'allow_gst_override' => true,
        ]));

        $this->actingAs($this->owner)
            ->putJson(route('multibranch.branches.update', $branch->id), [
                'name'               => 'Second Branch',
                'default_vat_type'   => GstPricingMethod::EXCLUDED,
                'gst_effective_from' => '2026-10-01',
                'gst_change_reason'  => 'Switching to exclusive pricing.',
            ])
            ->assertStatus(200);

        $this->assertSame(GstPricingMethod::EXCLUDED, $branch->fresh()->default_vat_type);

        $entry = GstConfigurationHistory::where('configurable_id', $branch->id)
            ->where('field', 'default_vat_type')
            ->latest('id')
            ->first();

        $this->assertSame(GstPricingMethod::INCLUDED, $entry->old_value);
        $this->assertSame('Switching to exclusive pricing.', $entry->reason);
    }
}
