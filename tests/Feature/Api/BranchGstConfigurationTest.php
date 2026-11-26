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
 * GST configuration at branch creation — the case the specification is named for.
 *
 * The point of §4 is that the user picks a shop type and the branch's GST pricing
 * method follows from it. Before this, branch creation ignored GST entirely: the
 * columns existed but nothing wrote them, so every branch billed under the
 * organisation default no matter what kind of shop it was.
 */
class BranchGstConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeBusiness(GstPricingMethod::INCLUDED);
    }

    /** @param string|null $shopTypeDefault the shop type's configured method */
    private function makeBusiness(?string $shopTypeDefault): void
    {
        // Shop-type names are unique, and a test may set up a second shop.
        $category = BusinessCategory::factory()->create([
            'name'             => 'Restaurant ' . uniqid(),
            'default_vat_type' => $shopTypeDefault,
        ]);

        $this->business = Business::factory()->create([
            'business_category_id' => $category->id,
            'default_vat_type'     => null,
        ]);

        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        // A main branch already exists, so creation does not divert into the
        // first-branch backfill; this test is about GST, not that path.
        Branch::withoutEvents(fn () => Branch::create([
            'business_id' => $this->business->id,
            'name'        => 'Head Office',
            'is_main'     => 1,
        ]));
    }

    private function createBranch(array $payload = [])
    {
        return $this->actingAs($this->owner)
            ->postJson('/api/v1/branches', $payload + ['name' => 'Second Branch']);
    }

    /** §22 Scenario 1. */
    public function test_a_branch_inherits_the_shop_types_method(): void
    {
        $this->createBranch()->assertStatus(200);

        $this->assertSame(
            GstPricingMethod::INCLUDED,
            Branch::where('name', 'Second Branch')->value('default_vat_type'),
        );
    }

    /** §22 Scenario 2: the same mechanism, a different shop type. */
    public function test_an_excluded_shop_type_produces_an_excluded_branch(): void
    {
        $this->makeBusiness(GstPricingMethod::EXCLUDED);

        $this->createBranch()->assertStatus(200);

        $this->assertSame(
            GstPricingMethod::EXCLUDED,
            Branch::where('name', 'Second Branch')->value('default_vat_type'),
        );
    }

    /** §22 Scenario 3. */
    public function test_an_unregistered_branch_is_not_applicable(): void
    {
        $this->createBranch(['gst_registered' => false])->assertStatus(200);

        $branch = Branch::where('name', 'Second Branch')->first();

        $this->assertSame(GstPricingMethod::NOT_APPLICABLE, $branch->default_vat_type);
        $this->assertFalse($branch->gst_registered);
    }

    /**
     * An unregistered branch cannot also be GST-Included: the two together are a
     * contradiction, and the registration flag is the one the user just set.
     */
    public function test_unregistered_overrides_a_supplied_method(): void
    {
        $this->createBranch([
            'gst_registered'   => false,
            'default_vat_type' => GstPricingMethod::INCLUDED,
        ])->assertStatus(200);

        $this->assertSame(
            GstPricingMethod::NOT_APPLICABLE,
            Branch::where('name', 'Second Branch')->value('default_vat_type'),
        );
    }

    public function test_a_registered_branch_needs_a_gstin(): void
    {
        $this->createBranch(['gst_registered' => true])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['gstin']]);

        $this->assertDatabaseMissing('branches', ['name' => 'Second Branch']);
    }

    public function test_a_malformed_gstin_is_rejected(): void
    {
        $this->createBranch([
            'gst_registered' => true,
            'gstin'          => 'NOTAGSTIN',
            'gst_state'      => 'Karnataka',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['gstin']]);
    }

    /** §17: the GSTIN's own state code must agree with the stated state. */
    public function test_a_gstin_from_another_state_is_rejected(): void
    {
        $this->createBranch([
            'gst_registered' => true,
            // 29 is Karnataka.
            'gstin'          => '29ABCDE1234F1Z5',
            'gst_state'      => 'Maharashtra',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['gstin']]);
    }

    public function test_a_matching_gstin_and_state_is_accepted(): void
    {
        $this->createBranch([
            'gst_registered' => true,
            'gstin'          => '29ABCDE1234F1Z5',
            'gst_state'      => 'Karnataka',
        ])->assertStatus(200);

        $this->assertSame(
            '29ABCDE1234F1Z5',
            Branch::where('name', 'Second Branch')->value('gstin'),
        );
    }

    /**
     * §17: a registered branch cannot be created with no method resolvable anywhere.
     * A GST invoice has to state one, and guessing puts a wrong figure on a legal
     * document.
     */
    public function test_a_registered_branch_needs_a_resolvable_method(): void
    {
        $this->makeBusiness(null);

        $this->createBranch([
            'gst_registered' => true,
            'gstin'          => '29ABCDE1234F1Z5',
            'gst_state'      => 'Karnataka',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['default_vat_type']]);
    }

    /**
     * An unregistered branch is still created when nothing is configured: "no GST"
     * is a complete answer, and refusing here would block most existing shops.
     */
    public function test_an_unconfigured_shop_can_still_create_a_branch(): void
    {
        $this->makeBusiness(null);

        $this->createBranch()->assertStatus(200);
    }

    /** §16. */
    public function test_deviating_from_the_shop_type_needs_a_reason(): void
    {
        $this->createBranch(['default_vat_type' => GstPricingMethod::EXCLUDED])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'reason_required');
    }

    public function test_deviating_with_a_reason_is_allowed(): void
    {
        $this->createBranch([
            'default_vat_type'  => GstPricingMethod::EXCLUDED,
            'gst_change_reason' => 'Franchise bills exclusive of tax.',
        ])->assertStatus(200);

        $this->assertSame(
            GstPricingMethod::EXCLUDED,
            Branch::where('name', 'Second Branch')->value('default_vat_type'),
        );
    }

    /** §20: including the method that was populated rather than typed. */
    public function test_creation_is_recorded_in_the_audit_trail(): void
    {
        $this->createBranch()->assertStatus(200);

        $branch = Branch::where('name', 'Second Branch')->first();

        $entry = GstConfigurationHistory::where('configurable_type', Branch::class)
            ->where('configurable_id', $branch->id)
            ->where('field', 'default_vat_type')
            ->first();

        $this->assertNotNull($entry, 'a branch must record what it was created with');
        $this->assertSame(GstPricingMethod::INCLUDED, $entry->new_value);
        $this->assertSame($this->owner->id, $entry->changed_by);
    }

    /** A branch to exercise the change rules against. */
    private function existingBranch(array $attributes = []): Branch
    {
        return Branch::withoutEvents(fn () => Branch::create($attributes + [
            'business_id'        => $this->business->id,
            'name'               => 'Second Branch',
            'default_vat_type'   => GstPricingMethod::INCLUDED,
            'allow_gst_override' => true,
        ]));
    }

    private function updateBranch(Branch $branch, array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)
            ->putJson("/api/v1/branches/{$branch->id}", $payload + ['name' => $branch->name]);
    }

    /** §17: without an effective date there is no answer to "as of when". */
    public function test_changing_the_method_needs_an_effective_date(): void
    {
        $branch = $this->existingBranch();

        $this->updateBranch($branch, [
            'default_vat_type'  => GstPricingMethod::EXCLUDED,
            'gst_change_reason' => 'Switching to exclusive pricing.',
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'effective_date_required');

        $this->assertSame(
            GstPricingMethod::INCLUDED,
            $branch->fresh()->default_vat_type,
            'the change must not be half-applied',
        );
    }

    public function test_a_dated_change_is_applied_and_recorded(): void
    {
        $branch = $this->existingBranch();

        $this->updateBranch($branch, [
            'default_vat_type'   => GstPricingMethod::EXCLUDED,
            'gst_effective_from' => '2026-10-01',
            'gst_change_reason'  => 'Switching to exclusive pricing.',
        ])->assertStatus(200);

        $this->assertSame(GstPricingMethod::EXCLUDED, $branch->fresh()->default_vat_type);

        $entry = GstConfigurationHistory::where('configurable_type', Branch::class)
            ->where('configurable_id', $branch->id)
            ->where('field', 'default_vat_type')
            ->latest('id')
            ->first();

        $this->assertSame(GstPricingMethod::INCLUDED, $entry->old_value);
        $this->assertSame(GstPricingMethod::EXCLUDED, $entry->new_value);
        $this->assertSame('Switching to exclusive pricing.', $entry->reason);
        $this->assertSame('2026-10-01', $entry->effective_from->toDateString());
    }

    /** §10: when override is not allowed the method is read-only to staff. */
    public function test_a_locked_branch_refuses_a_staff_change(): void
    {
        $branch = $this->existingBranch(['allow_gst_override' => false]);

        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'staff',
        ]);

        $this->updateBranch($branch, [
            'default_vat_type'   => GstPricingMethod::EXCLUDED,
            'gst_effective_from' => '2026-10-01',
            'gst_change_reason'  => 'Because.',
        ], $staff)
            ->assertStatus(403)
            ->assertJsonPath('reason', 'override_not_allowed');

        $this->assertSame(GstPricingMethod::INCLUDED, $branch->fresh()->default_vat_type);
    }

    /** §10: only an administrator may change it. */
    public function test_a_locked_branch_still_yields_to_the_owner(): void
    {
        $branch = $this->existingBranch(['allow_gst_override' => false]);

        $this->updateBranch($branch, [
            'default_vat_type'   => GstPricingMethod::EXCLUDED,
            'gst_effective_from' => '2026-10-01',
            'gst_change_reason'  => 'Corrected after registration.',
        ])->assertStatus(200);

        $this->assertSame(GstPricingMethod::EXCLUDED, $branch->fresh()->default_vat_type);
    }

    /**
     * Editing the name must not quietly rewrite the branch's tax identity, which is
     * what mass assignment would have done once these columns became fillable.
     */
    public function test_an_unrelated_edit_leaves_gst_alone(): void
    {
        $branch = $this->existingBranch(['gstin' => '29ABCDE1234F1Z5']);

        $this->actingAs($this->owner)
            ->putJson("/api/v1/branches/{$branch->id}", ['name' => 'Renamed'])
            ->assertStatus(200);

        $fresh = $branch->fresh();

        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame(GstPricingMethod::INCLUDED, $fresh->default_vat_type);
        $this->assertSame('29ABCDE1234F1Z5', $fresh->gstin);
        $this->assertSame(
            0,
            GstConfigurationHistory::where('configurable_id', $branch->id)->count(),
            'an unrelated edit is not a GST change and must not be logged as one',
        );
    }

    public function test_the_override_flag_defaults_to_allowed(): void
    {
        // Defaulting this to false would lock every branch at creation, and the only
        // way out would be a database edit.
        $this->createBranch()->assertStatus(200);

        $this->assertTrue(
            (bool) Branch::where('name', 'Second Branch')->value('allow_gst_override'),
        );
    }
}
