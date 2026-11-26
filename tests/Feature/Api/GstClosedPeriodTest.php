<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\User;
use App\Services\GstConfiguration;
use App\Services\GstPricingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §17: a GST change cannot be dated into a period already filed.
 *
 * Backdating past the close would mean invoices already reported under one method
 * are retrospectively said to have used another. The returns and the ledger stop
 * agreeing, and the discrepancy surfaces at assessment rather than at the moment
 * someone typed the date.
 *
 * The system had no notion of a closed period, so this uses the smallest one that
 * answers the question: a single "books are closed up to" date on the business.
 */
class GstClosedPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $owner;
    protected User $staff;

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
            // The first quarter has been filed.
            'books_closed_upto'    => '2026-06-30',
        ]);

        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        $this->staff = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'staff',
            'visibility'  => ['gst' => ['update' => '1']],
        ]);
    }

    private function update(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->staff)
            ->putJson('/api/v1/gst-configuration', $payload);
    }

    // ── The rule itself ──────────────────────────────────────────────────────

    public function test_a_date_inside_the_closed_period_is_refused(): void
    {
        $this->update([
            'default_vat_type' => GstPricingMethod::EXCLUDED,
            'reason'           => 'correcting the quarter',
            'effective_from'   => '2026-05-01',
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'closed_period');

        $this->assertSame(
            GstPricingMethod::INCLUDED,
            $this->business->fresh()->default_vat_type,
        );
    }

    /** The closing date is itself closed. */
    public function test_the_closing_date_itself_is_refused(): void
    {
        $this->update([
            'default_vat_type' => GstPricingMethod::EXCLUDED,
            'reason'           => 'switching',
            'effective_from'   => '2026-06-30',
        ])->assertStatus(422)->assertJsonPath('reason', 'closed_period');
    }

    public function test_the_day_after_the_close_is_allowed(): void
    {
        $this->update([
            'default_vat_type' => GstPricingMethod::EXCLUDED,
            'reason'           => 'switching',
            'effective_from'   => '2026-07-01',
        ])->assertStatus(200);

        $this->assertSame(
            GstPricingMethod::EXCLUDED,
            $this->business->fresh()->default_vat_type,
        );
    }

    /** §17: "unless the user has special permission". */
    public function test_the_backdate_permission_allows_a_correction(): void
    {
        $this->staff->update([
            'visibility' => ['gst' => ['update' => '1', 'backdate' => '1']],
        ]);

        $this->update([
            'default_vat_type' => GstPricingMethod::EXCLUDED,
            'reason'           => 'Assessment correction',
            'effective_from'   => '2026-05-01',
        ])->assertStatus(200);
    }

    /** The owner carries every permission, so a genuine mistake stays fixable. */
    public function test_the_owner_can_still_correct_a_closed_period(): void
    {
        $this->update([
            'default_vat_type' => GstPricingMethod::EXCLUDED,
            'reason'           => 'Assessment correction',
            'effective_from'   => '2026-05-01',
        ], $this->owner)->assertStatus(200);
    }

    /** Every existing shop, which has never closed anything. */
    public function test_a_shop_that_has_closed_nothing_is_unaffected(): void
    {
        $this->business->update(['books_closed_upto' => null]);

        $this->update([
            'default_vat_type' => GstPricingMethod::EXCLUDED,
            'reason'           => 'switching',
            'effective_from'   => '2020-01-01',
        ])->assertStatus(200);
    }

    // ── The rule in isolation ────────────────────────────────────────────────

    public function test_the_comparison_is_by_date_not_time(): void
    {
        $this->assertTrue(
            GstConfiguration::isBeforeClosedPeriod('2026-06-30 23:59:00', $this->business),
        );
        $this->assertFalse(
            GstConfiguration::isBeforeClosedPeriod('2026-07-01 00:01:00', $this->business),
        );
    }

    public function test_no_date_is_not_a_backdate(): void
    {
        $this->assertFalse(GstConfiguration::isBeforeClosedPeriod(null, $this->business));
        $this->assertFalse(GstConfiguration::isBeforeClosedPeriod('', $this->business));
    }

    /** An unparseable date is caught by the `date` validation rule, not here. */
    public function test_an_unreadable_date_is_not_treated_as_a_backdate(): void
    {
        $this->assertFalse(
            GstConfiguration::isBeforeClosedPeriod('not a date', $this->business),
        );
    }

    public function test_a_branch_change_obeys_the_same_close(): void
    {
        $branch = \App\Models\Branch::withoutEvents(
            fn () => \App\Models\Branch::create([
                'business_id'        => $this->business->id,
                'name'               => 'Second Branch',
                'default_vat_type'   => GstPricingMethod::INCLUDED,
                'allow_gst_override' => true,
            ])
        );

        $this->actingAs($this->staff)
            ->putJson("/api/v1/branches/{$branch->id}", [
                'name'               => 'Second Branch',
                'default_vat_type'   => GstPricingMethod::EXCLUDED,
                'gst_effective_from' => '2026-05-01',
                'gst_change_reason'  => 'switching',
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'closed_period');

        $this->assertSame(
            GstPricingMethod::INCLUDED,
            $branch->fresh()->default_vat_type,
        );
    }
}
