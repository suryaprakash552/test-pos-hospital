<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\LoyaltyAudit;
use App\Models\LoyaltyPackage;
use App\Models\User;
use App\Services\LoyaltyDiscount;
use App\Services\LoyaltyPeriod;
use App\Services\LoyaltySubscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §14: the record of who changed a customer's entitlement, and why.
 *
 * The activities audited here are the ones that move money or benefit. Without them
 * a discount can be granted, a package upgraded, or a term extended with no way
 * afterwards to say who did it — which is exactly the question asked when the
 * takings do not reconcile.
 */
class LoyaltyAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $user;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name'        => 'Loyal customer',
            'phone'       => '9999900000',
        ]);

        $this->actingAs($this->user);
    }

    private function package(array $overrides = []): LoyaltyPackage
    {
        static $n = 0;
        $n++;

        return LoyaltyPackage::create($overrides + [
            'business_id'     => $this->business->id,
            'code'            => 'P' . $n,
            'name'            => 'Tier ' . $n,
            'cost'            => 100,
            'validity_type'   => LoyaltyPeriod::DAYS,
            'validity_period' => 365,
            'discount_type'   => LoyaltyDiscount::PERCENTAGE,
            'discount_value'  => 10,
            'discount_mode'   => LoyaltyDiscount::AUTOMATIC,
            'priority'        => $n,
        ]);
    }

    public function test_selling_a_package_is_recorded(): void
    {
        $package = $this->package();

        LoyaltySubscriptionManager::subscribe($this->customer->id, $package);

        $audit = LoyaltyAudit::where('event', LoyaltyAudit::SUBSCRIBED)->first();

        $this->assertNotNull($audit);
        $this->assertSame($this->customer->id, $audit->customer_id);
        $this->assertSame($package->name, $audit->new_value);
        $this->assertSame($this->user->id, $audit->changed_by);
    }

    /** §14 wants the previous value as well as the new one. */
    public function test_a_renewal_records_the_term_it_replaced(): void
    {
        $original = LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['validity_period' => 30]),
            ['subscription_date' => '2026-01-01'],
        );

        LoyaltySubscriptionManager::renew($original, ['subscription_date' => '2026-01-20']);

        $audit = LoyaltyAudit::where('event', LoyaltyAudit::RENEWED)->first();

        $this->assertSame('2026-01-30', $audit->old_value);
        $this->assertSame('2026-03-01', $audit->new_value);
    }

    /** §4.4: which package was left, and which was moved to. */
    public function test_an_upgrade_records_both_packages(): void
    {
        $silver = $this->package(['priority' => 1]);
        $gold   = $this->package(['priority' => 2]);

        $current = LoyaltySubscriptionManager::subscribe($this->customer->id, $silver);

        LoyaltySubscriptionManager::upgrade(
            $current, $gold, ['reason' => 'Customer requested'],
        );

        $audit = LoyaltyAudit::where('event', LoyaltyAudit::UPGRADED)->first();

        $this->assertSame($silver->name, $audit->old_value);
        $this->assertSame($gold->name, $audit->new_value);
        $this->assertSame('Customer requested', $audit->reason);
    }

    public function test_the_record_captures_who_and_from_where(): void
    {
        LoyaltySubscriptionManager::subscribe($this->customer->id, $this->package());

        $audit = LoyaltyAudit::first();

        $this->assertSame($this->user->id, $audit->changed_by);
        // §14: "device or IP details, where available".
        $this->assertNotNull($audit->ip_address);
        $this->assertNotNull($audit->created_at);
    }

    /** An audit that can be edited answers nothing. */
    public function test_a_correction_is_another_row_not_a_rewrite(): void
    {
        LoyaltySubscriptionManager::subscribe($this->customer->id, $this->package());

        $first = LoyaltyAudit::first();

        LoyaltyAudit::record(LoyaltyAudit::CANCELLED, null, [
            'business_id' => $this->business->id,
            'customer_id' => $this->customer->id,
            'reason'      => 'Entered in error',
        ]);

        $this->assertDatabaseCount('loyalty_audits', 2);
        $this->assertSame(
            LoyaltyAudit::SUBSCRIBED,
            $first->fresh()->event,
            'the original entry is untouched',
        );
    }

    /**
     * §14 lists package creation and modification as auditable. The constants for
     * them existed for a while without a single call site — declared and never
     * recorded, which is worse than absent because it reads as covered.
     */
    public function test_creating_a_package_is_recorded(): void
    {
        $this->postJson('/api/v1/loyalty-packages', [
            'code' => 'GOLD', 'name' => 'Gold', 'cost' => 500,
            'validity_type' => LoyaltyPeriod::DAYS, 'validity_period' => 365,
            'discount_type' => LoyaltyDiscount::PERCENTAGE, 'discount_value' => 10,
            'discount_mode' => LoyaltyDiscount::AUTOMATIC, 'priority' => 1,
        ])->assertStatus(200);

        $this->assertDatabaseHas('loyalty_audits', [
            'event'     => LoyaltyAudit::PACKAGE_CREATED,
            'new_value' => 'Gold',
        ]);
    }

    public function test_editing_a_package_records_each_changed_field(): void
    {
        $package = $this->package(['discount_value' => 10]);

        $this->putJson('/api/v1/loyalty-packages/' . $package->id, [
            'code' => $package->code, 'name' => $package->name, 'cost' => 500,
            'validity_type' => LoyaltyPeriod::DAYS, 'validity_period' => 365,
            'discount_type' => LoyaltyDiscount::PERCENTAGE, 'discount_value' => 20,
            'discount_mode' => LoyaltyDiscount::AUTOMATIC, 'priority' => 1,
        ])->assertStatus(200);

        $this->assertDatabaseHas('loyalty_audits', [
            'event'     => LoyaltyAudit::PACKAGE_UPDATED,
            'field'     => 'discount_value',
            'old_value' => '10.00',
            'new_value' => '20',
        ]);
    }

    public function test_cancelling_a_subscription_is_recorded(): void
    {
        $subscription = LoyaltySubscriptionManager::subscribe(
            $this->customer->id, $this->package(),
        );

        $this->postJson('/api/v1/loyalty-subscriptions/' . $subscription->id . '/cancel', [
            'reason' => 'Customer request',
        ])->assertStatus(200);

        $this->assertDatabaseHas('loyalty_audits', [
            'event'     => LoyaltyAudit::CANCELLED,
            'new_value' => LoyaltyPeriod::CANCELLED,
            'reason'    => 'Customer request',
        ]);
    }

    public function test_values_of_any_shape_are_stored_readably(): void
    {
        $this->assertSame('true', LoyaltyAudit::stringify(true));
        $this->assertSame('12.5', LoyaltyAudit::stringify(12.5));
        $this->assertNull(LoyaltyAudit::stringify(null));
        $this->assertSame('2026-01-01', LoyaltyAudit::stringify(
            new \DateTimeImmutable('2026-01-01'),
        ));
    }

    public function test_another_businesses_audit_is_separate(): void
    {
        LoyaltySubscriptionManager::subscribe($this->customer->id, $this->package());

        $this->assertSame(
            0,
            LoyaltyAudit::where('business_id', '!=', $this->business->id)->count(),
        );
    }
}
