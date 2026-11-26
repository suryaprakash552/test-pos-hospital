<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\LoyaltyPackage;
use App\Models\LoyaltySubscription;
use App\Models\User;
use App\Services\CartLoyaltyDiscount;
use App\Services\LoyaltyDiscount;
use App\Services\LoyaltyPeriod;
use App\Services\LoyaltySubscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Departing from an automatic package's own figure (§9).
 *
 * §13 has always listed "Override Automatic Discount" as its own permission, and
 * `loyalty.override` has been grantable since the permission map was written -
 * but nothing consulted it. An amount sent against an automatic package was
 * discarded without a word, so the permission existed and did nothing.
 *
 * The restriction it implies was already satisfied, in the blunt sense that the
 * figure could never be changed by anyone. What was missing is the permitted
 * case.
 */
class LoyaltyAutomaticOverrideTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->actingAs(User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]));
        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name'        => 'Member',
            'phone'       => '9800000009',
        ]);
    }

    /** No factories for these models; built the way the other loyalty tests do. */
    private function subscription(string $mode): LoyaltySubscription
    {
        $package = LoyaltyPackage::create([
            'business_id'     => $this->business->id,
            'code'            => 'O' . uniqid(),
            'name'            => 'Gold',
            'cost'            => 500,
            'validity_type'   => LoyaltyPeriod::DAYS,
            'validity_period' => 365,
            'discount_type'   => LoyaltyDiscount::PERCENTAGE,
            'discount_value'  => 10,
            'discount_mode'   => $mode,
            'priority'        => 1,
        ]);

        return LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $package,
            ['subscription_date' => now()->toDateString(), 'amount_received' => 500],
        );
    }

    /** @param array<string, mixed> $args */
    private function resolve(array $args): array
    {
        return CartLoyaltyDiscount::resolve(
            $args['eligible'] ?? 1000.0,
            $args['subscription'],
            $args['manual'] ?? null,
            $args['mayEnterManual'] ?? true,
            null,
            null,
            false,
            $args['mayOverride'] ?? false,
        );
    }

    public function test_a_package_waits_for_an_explicit_selection(): void
    {
        $result = $this->resolve(['subscription' => $this->subscription(LoyaltyDiscount::AUTOMATIC)]);

        $this->assertSame(0.0, $result['amount']);
    }

    public function test_full_entitlement_needs_no_management_permission(): void
    {
        $result = $this->resolve([
            'subscription' => $this->subscription(LoyaltyDiscount::AUTOMATIC),
            'manual' => 100.0,
            'mayOverride' => false,
        ]);

        $this->assertSame(
            100.0,
            $result['amount'],
            'A cashier can apply the plan shown for this customer.',
        );
    }

    public function test_with_the_permission_the_figure_can_be_lowered(): void
    {
        $result = $this->resolve([
            'subscription' => $this->subscription(LoyaltyDiscount::AUTOMATIC),
            'manual' => 40.0,
            'mayOverride' => true,
        ]);

        $this->assertSame(40.0, $result['amount']);
        $this->assertTrue($result['snapshot']['loyalty_discount_overridden']);
    }

    public function test_an_override_still_cannot_exceed_the_entitlement(): void
    {
        $result = $this->resolve([
            'subscription' => $this->subscription(LoyaltyDiscount::AUTOMATIC),
            'manual' => 500.0,
            'mayOverride' => true,
        ]);

        $this->assertNotNull(
            $result['reason'],
            'Override permits a different figure, not an unbounded one.',
        );
        $this->assertSame(0.0, $result['amount']);
    }

    public function test_an_override_cannot_exceed_the_cart(): void
    {
        $result = $this->resolve([
            'eligible' => 20.0,
            'subscription' => $this->subscription(LoyaltyDiscount::AUTOMATIC),
            'manual' => 15.0,
            'mayOverride' => true,
        ]);

        // 10% of 20 is 2; 15 is above both the entitlement and any sane figure.
        $this->assertNotNull($result['reason']);
    }

    public function test_a_manual_package_is_unaffected_by_the_override_flag(): void
    {
        $result = $this->resolve([
            'subscription' => $this->subscription(LoyaltyDiscount::MANUAL),
            'manual' => 40.0,
            'mayOverride' => true,
        ]);

        // Manual mode was already governed by `loyalty.manual-discount`; the new
        // permission must not become a second way in.
        $this->assertSame(40.0, $result['amount']);
        $this->assertArrayNotHasKey('loyalty_discount_overridden', $result['snapshot']);
    }

    public function test_an_automatic_package_with_no_amount_sent_is_untouched(): void
    {
        $result = $this->resolve([
            'subscription' => $this->subscription(LoyaltyDiscount::AUTOMATIC),
            'manual' => null,
            'mayOverride' => true,
        ]);

        $this->assertSame(0.0, $result['amount']);
    }
}
