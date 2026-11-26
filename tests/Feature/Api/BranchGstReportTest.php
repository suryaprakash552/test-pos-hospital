<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\User;
use App\Services\GstPricingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §21: what each branch is configured to bill under.
 *
 * The question this answers is "why does that branch produce a differently shaped
 * invoice than this one" — asked when two branches of the same shop disagree, and
 * asked by an auditor before a single invoice is opened.
 */
class BranchGstReportTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $category = BusinessCategory::factory()->create([
            'name'             => 'Pharmacy ' . uniqid(),
            'default_vat_type' => GstPricingMethod::INCLUDED,
        ]);

        $this->business = Business::factory()->create([
            'business_category_id' => $category->id,
            'default_vat_type'     => GstPricingMethod::INCLUDED,
        ]);

        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);
    }

    private function branch(array $attributes): Branch
    {
        return Branch::withoutEvents(fn () => Branch::create($attributes + [
            'business_id' => $this->business->id,
        ]));
    }

    private function report(array $filters = [])
    {
        return $this->actingAs($this->owner)
            ->getJson('/api/v1/branch-gst-report?' . http_build_query($filters));
    }

    public function test_it_lists_every_branch_with_its_configuration(): void
    {
        $this->branch([
            'name'               => 'Koramangala',
            'gst_registered'     => true,
            'gstin'              => '29ABCDE1234F1Z5',
            'gst_state'          => 'Karnataka',
            'default_vat_type'   => GstPricingMethod::INCLUDED,
            'gst_effective_from' => '2026-04-01',
        ]);

        $response = $this->report()->assertStatus(200);

        $row = $response->json('data.branches.0');

        $this->assertSame('Koramangala', $row['branch_name']);
        $this->assertTrue($row['gst_registered']);
        $this->assertSame('29ABCDE1234F1Z5', $row['gstin']);
        $this->assertSame('Karnataka', $row['gst_state']);
        $this->assertSame('29', $row['gst_state_code']);
        $this->assertSame(GstPricingMethod::INCLUDED, $row['gst_pricing_method']);
        $this->assertSame('GST Included', $row['gst_pricing_label']);
        $this->assertSame('2026-04-01', $row['effective_from']);
    }

    /**
     * A branch with nothing of its own still bills under something, and the report
     * has to say what — otherwise it looks unconfigured when it is merely inheriting.
     */
    public function test_a_branch_without_its_own_method_shows_what_it_inherits(): void
    {
        $this->branch(['name' => 'Inherits', 'default_vat_type' => null]);

        $row = $this->report()->json('data.branches.0');

        $this->assertNull($row['gst_pricing_method']);
        $this->assertSame(GstPricingMethod::INCLUDED, $row['effective_method']);
    }

    public function test_it_filters_by_pricing_method(): void
    {
        $this->branch(['name' => 'Inclusive', 'default_vat_type' => GstPricingMethod::INCLUDED]);
        $this->branch(['name' => 'Exclusive', 'default_vat_type' => GstPricingMethod::EXCLUDED]);

        $rows = $this->report(['gst_pricing_method' => GstPricingMethod::EXCLUDED])
            ->assertStatus(200)
            ->json('data.branches');

        $this->assertCount(1, $rows);
        $this->assertSame('Exclusive', $rows[0]['branch_name']);
    }

    public function test_it_filters_by_registration_status(): void
    {
        $this->branch(['name' => 'Registered', 'gst_registered' => true, 'gstin' => '29ABCDE1234F1Z5']);
        $this->branch(['name' => 'Unregistered', 'gst_registered' => false]);

        $rows = $this->report(['gst_registered' => 0])->json('data.branches');

        $this->assertCount(1, $rows);
        $this->assertSame('Unregistered', $rows[0]['branch_name']);
    }

    public function test_it_filters_by_gstin_and_state(): void
    {
        $this->branch(['name' => 'Karnataka', 'gstin' => '29ABCDE1234F1Z5', 'gst_state' => 'Karnataka']);
        $this->branch(['name' => 'Maharashtra', 'gstin' => '27ABCDE1234F1Z5', 'gst_state' => 'Maharashtra']);

        $this->assertCount(1, $this->report(['gstin' => '27ABCDE'])->json('data.branches'));
        $this->assertCount(1, $this->report(['state' => 'Karnataka'])->json('data.branches'));
    }

    public function test_it_filters_by_effective_date_range(): void
    {
        $this->branch(['name' => 'April', 'gst_effective_from' => '2026-04-01']);
        $this->branch(['name' => 'October', 'gst_effective_from' => '2026-10-01']);

        $rows = $this->report([
            'effective_from' => '2026-09-01',
            'effective_to'   => '2026-12-31',
        ])->json('data.branches');

        $this->assertCount(1, $rows);
        $this->assertSame('October', $rows[0]['branch_name']);
    }

    public function test_it_filters_by_active_status(): void
    {
        $this->branch(['name' => 'Open', 'status' => 1]);
        $this->branch(['name' => 'Closed', 'status' => 0]);

        $rows = $this->report(['status' => 'inactive'])->json('data.branches');

        $this->assertCount(1, $rows);
        $this->assertSame('Closed', $rows[0]['branch_name']);
    }

    public function test_an_inverted_date_range_is_rejected(): void
    {
        $this->report(['effective_from' => '2026-12-31', 'effective_to' => '2026-01-01'])
            ->assertStatus(422);
    }

    public function test_an_unknown_method_is_rejected(): void
    {
        $this->report(['gst_pricing_method' => 'sometimes'])->assertStatus(422);
    }

    public function test_another_businesses_branches_are_never_listed(): void
    {
        $other = Business::factory()->create();

        Branch::withoutEvents(fn () => Branch::create([
            'business_id' => $other->id,
            'name'        => 'Someone Elses',
        ]));

        $this->branch(['name' => 'Mine']);

        $rows = $this->report()->json('data.branches');

        $this->assertCount(1, $rows);
        $this->assertSame('Mine', $rows[0]['branch_name']);
    }

    public function test_an_empty_shop_reports_no_branches(): void
    {
        $this->report()->assertStatus(200)->assertJsonPath('data.branches', []);
    }
}
