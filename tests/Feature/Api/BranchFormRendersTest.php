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
 * The admin branch page renders its Tax and GST Configuration section.
 *
 * A Blade section that references a variable the controller does not pass fails at
 * request time, not at deploy time — the page simply breaks for whoever opens it
 * next. This renders the real view against the real route so a missing binding is
 * caught here instead.
 */
class BranchFormRendersTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $category = BusinessCategory::factory()->create([
            'name'             => 'Restaurant',
            'default_vat_type' => GstPricingMethod::INCLUDED,
        ]);

        $this->business = Business::factory()->create([
            'business_category_id' => $category->id,
        ]);

        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        Branch::withoutEvents(fn () => Branch::create([
            'business_id'      => $this->business->id,
            'name'             => 'Head Office',
            'is_main'          => 1,
            'gstin'            => '29ABCDE1234F1Z5',
            'gst_state'        => 'Karnataka',
            'default_vat_type' => GstPricingMethod::INCLUDED,
        ]));
    }

    private function page()
    {
        return $this->actingAs($this->owner)
            ->get(route('multibranch.branches.index'));
    }

    public function test_the_page_renders(): void
    {
        $this->page()->assertStatus(200);
    }

    /** §16: its own named section, not mixed in with contact details. */
    public function test_it_shows_the_tax_and_gst_section(): void
    {
        $this->page()->assertSee('Tax and GST Configuration', false);
    }

    /**
     * §16: the form says what the shop type implies before anything is changed,
     * so a deviation is a deliberate act rather than an accident.
     */
    public function test_it_states_the_shop_types_default(): void
    {
        $this->page()->assertSee(
            'The default GST pricing method for Restaurant is GST Included.',
            false,
        );
    }

    public function test_it_offers_every_pricing_method(): void
    {
        $response = $this->page();

        foreach (GstPricingMethod::all() as $method) {
            $response->assertSee('value="' . $method . '"', false);
        }
    }

    /** A picker, not free text: a mistyped state would fail the GSTIN check. */
    public function test_it_offers_the_state_list(): void
    {
        $this->page()
            ->assertSee('29 — Karnataka', false)
            ->assertSee('27 — Maharashtra', false);
    }

    public function test_it_carries_the_gst_fields(): void
    {
        $response = $this->page();

        foreach ([
            'gst_registered',
            'gstin',
            'gst_state',
            'place_of_supply',
            'default_vat_type',
            'allow_gst_override',
            'gst_effective_from',
            'gst_change_reason',
        ] as $field) {
            $response->assertSee('name="' . $field . '"', false);
        }
    }

    /**
     * The edit modal is filled from the row's data attributes, so an edit shows the
     * branch's current tax identity rather than a blank form that would look like a
     * request to clear it.
     */
    public function test_the_row_carries_the_branches_current_configuration(): void
    {
        $this->page()
            ->assertSee('data-gstin="29ABCDE1234F1Z5"', false)
            ->assertSee('data-gst-state="Karnataka"', false)
            ->assertSee('data-gst-method="' . GstPricingMethod::INCLUDED . '"', false);
    }

    /** The edit form needs its own ids for the script to populate. */
    public function test_the_edit_form_fields_are_addressable(): void
    {
        $response = $this->page();

        foreach ([
            'brnch_gst_registered',
            'brnch_gstin',
            'brnch_gst_state',
            'brnch_gst_method',
            'brnch_gst_override',
            'brnch_gst_effective_from',
            'brnch_gst_reason',
        ] as $id) {
            $response->assertSee('id="' . $id . '"', false);
        }
    }
}
