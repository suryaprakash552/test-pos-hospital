<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\GstConfigurationHistory;
use App\Models\User;
use App\Services\GstConfiguration;
use App\Services\GstPricingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A GST change applies from its effective date, not immediately.
 *
 * Without this, a change dated for next quarter would take effect the moment it was
 * saved — so invoices raised this quarter would be billed under next quarter's method
 * and disagree with the return filed for them. That is the whole reason the effective
 * date exists.
 *
 * The previous value is read back from the configuration history, which is why that
 * table records `old_value` and not only the new one.
 */
class GstEffectiveDatingTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $category = BusinessCategory::factory()->create([
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

    /** Records a change that takes effect on [effectiveFrom]. */
    private function scheduleChange(
        string $from,
        string $to,
        string $effectiveFrom,
    ): void {
        GstConfigurationHistory::create([
            'business_id'       => $this->business->id,
            'configurable_type' => Business::class,
            'configurable_id'   => $this->business->id,
            'field'             => 'default_vat_type',
            'old_value'         => $from,
            'new_value'         => $to,
            'effective_from'    => $effectiveFrom,
            'changed_by'        => $this->owner->id,
        ]);

        $this->business->update(['default_vat_type' => $to]);
    }

    public function test_without_any_change_the_current_method_applies(): void
    {
        $this->assertSame(
            GstPricingMethod::INCLUDED,
            GstConfiguration::resolveAsOf(null, null, $this->business, '2026-08-01'),
        );
    }

    /** The case the effective date exists for. */
    public function test_a_sale_before_the_effective_date_uses_the_old_method(): void
    {
        $this->scheduleChange(
            GstPricingMethod::INCLUDED,
            GstPricingMethod::EXCLUDED,
            '2026-10-01',
        );

        $this->assertSame(
            GstPricingMethod::INCLUDED,
            GstConfiguration::resolveAsOf(null, null, $this->business, '2026-09-30'),
            'a change dated for next quarter must not apply to this one',
        );
    }

    public function test_a_sale_on_the_effective_date_uses_the_new_method(): void
    {
        $this->scheduleChange(
            GstPricingMethod::INCLUDED,
            GstPricingMethod::EXCLUDED,
            '2026-10-01',
        );

        $this->assertSame(
            GstPricingMethod::EXCLUDED,
            GstConfiguration::resolveAsOf(null, null, $this->business, '2026-10-01'),
            'the effective date is inclusive',
        );
    }

    public function test_a_sale_after_the_effective_date_uses_the_new_method(): void
    {
        $this->scheduleChange(
            GstPricingMethod::INCLUDED,
            GstPricingMethod::EXCLUDED,
            '2026-10-01',
        );

        $this->assertSame(
            GstPricingMethod::EXCLUDED,
            GstConfiguration::resolveAsOf(null, null, $this->business, '2026-12-31'),
        );
    }

    public function test_an_already_effective_change_applies_normally(): void
    {
        $this->scheduleChange(
            GstPricingMethod::INCLUDED,
            GstPricingMethod::EXCLUDED,
            '2026-01-01',
        );

        $this->assertSame(
            GstPricingMethod::EXCLUDED,
            GstConfiguration::resolveAsOf(null, null, $this->business, '2026-08-01'),
        );
    }

    /**
     * With several pending changes the earliest one is what a sale before them all
     * was billed under.
     */
    public function test_the_earliest_pending_change_decides(): void
    {
        $this->scheduleChange(
            GstPricingMethod::INCLUDED,
            GstPricingMethod::EXCLUDED,
            '2026-10-01',
        );
        $this->scheduleChange(
            GstPricingMethod::EXCLUDED,
            GstPricingMethod::NOT_APPLICABLE,
            '2026-11-01',
        );

        $this->assertSame(
            GstPricingMethod::INCLUDED,
            GstConfiguration::resolveAsOf(null, null, $this->business, '2026-09-01'),
        );
    }

    public function test_a_change_without_an_effective_date_applies_at_once(): void
    {
        GstConfigurationHistory::create([
            'business_id'       => $this->business->id,
            'configurable_type' => Business::class,
            'configurable_id'   => $this->business->id,
            'field'             => 'default_vat_type',
            'old_value'         => GstPricingMethod::INCLUDED,
            'new_value'         => GstPricingMethod::EXCLUDED,
            // No effective date: the owner wanted it now.
            'effective_from'    => null,
        ]);
        $this->business->update(['default_vat_type' => GstPricingMethod::EXCLUDED]);

        $this->assertSame(
            GstPricingMethod::EXCLUDED,
            GstConfiguration::resolveAsOf(null, null, $this->business, '2026-08-01'),
        );
    }

    /**
     * A product's own setting is a property of the product, not a policy that
     * changes on a schedule.
     */
    public function test_an_item_override_is_not_effective_dated(): void
    {
        $this->scheduleChange(
            GstPricingMethod::INCLUDED,
            GstPricingMethod::EXCLUDED,
            '2026-10-01',
        );

        $this->assertSame(
            GstPricingMethod::NOT_APPLICABLE,
            GstConfiguration::resolveAsOf(
                GstPricingMethod::NOT_APPLICABLE,
                null,
                $this->business,
                '2026-09-01',
            ),
        );
    }

    public function test_a_shop_with_nothing_configured_before_falls_back(): void
    {
        // The shop had no explicit method until this change.
        $this->business->update(['default_vat_type' => null]);

        $this->scheduleChange('', GstPricingMethod::INCLUDED, '2026-10-01');

        $this->assertSame(
            GstPricingMethod::FALLBACK,
            GstConfiguration::resolveAsOf(null, null, $this->business, '2026-09-01'),
            'before the change it was on the same fallback as any unconfigured shop',
        );
    }

    /** A sale sends a full timestamp; a purchase sends a plain date. */
    public function test_a_timestamp_is_compared_by_its_date(): void
    {
        $this->scheduleChange(
            GstPricingMethod::INCLUDED,
            GstPricingMethod::EXCLUDED,
            '2026-10-01',
        );

        $this->assertSame(
            GstPricingMethod::INCLUDED,
            GstConfiguration::resolveAsOf(
                null,
                null,
                $this->business,
                '2026-09-30 23:45:00',
            ),
        );

        $this->assertSame(
            GstPricingMethod::EXCLUDED,
            GstConfiguration::resolveAsOf(
                null,
                null,
                $this->business,
                '2026-10-01 00:15:00',
            ),
            'the change is in force from the first minute of its effective date',
        );
    }

    public function test_another_businesses_change_does_not_leak(): void
    {
        $otherBusiness = Business::factory()->create();

        GstConfigurationHistory::create([
            'business_id'       => $otherBusiness->id,
            'configurable_type' => Business::class,
            'configurable_id'   => $otherBusiness->id,
            'field'             => 'default_vat_type',
            'old_value'         => GstPricingMethod::NOT_APPLICABLE,
            'new_value'         => GstPricingMethod::EXCLUDED,
            'effective_from'    => '2026-10-01',
        ]);

        $this->assertSame(
            GstPricingMethod::INCLUDED,
            GstConfiguration::resolveAsOf(null, null, $this->business, '2026-09-01'),
        );
    }
}
