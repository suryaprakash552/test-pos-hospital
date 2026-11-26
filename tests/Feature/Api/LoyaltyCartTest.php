<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\LoyaltyPackage;
use App\Models\LoyaltySubscription;
use App\Services\CartLoyaltyDiscount;
use App\Services\LoyaltyDiscount;
use App\Services\LoyaltyPeriod;
use App\Services\LoyaltySubscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The loyalty discount as it reaches a cart (§7, §9, §10).
 *
 * This is the layer that decides *whether* a discount applies at all, as opposed to
 * the arithmetic of how big it is. Getting it wrong in either direction is a real
 * loss: applying a lapsed package gives away money, and withholding a live one
 * short-changes a customer who paid for it.
 */
class LoyaltyCartTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name'        => 'Walk-in',
            'phone'       => '9999900000',
        ]);
    }

    private function package(array $overrides = []): LoyaltyPackage
    {
        static $n = 0;
        $n++;

        return LoyaltyPackage::create($overrides + [
            'business_id'     => $this->business->id,
            'code'            => 'P' . $n,
            'name'            => $overrides['name'] ?? 'Gold ' . $n,
            'cost'            => 100,
            'validity_type'   => LoyaltyPeriod::DAYS,
            'validity_period' => 365,
            'discount_type'   => LoyaltyDiscount::PERCENTAGE,
            'discount_value'  => 10,
            'discount_mode'   => LoyaltyDiscount::AUTOMATIC,
            'priority'        => $n,
        ]);
    }

    private function subscribe(array $packageOverrides = []): LoyaltySubscription
    {
        return LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package($packageOverrides),
        );
    }

    // ── §1: not everyone is in the programme ─────────────────────────────────

    public function test_a_customer_without_a_package_gets_nothing_and_no_error(): void
    {
        $result = CartLoyaltyDiscount::resolve(1000, null);

        $this->assertFalse($result['applied']);
        $this->assertSame(0.0, $result['amount']);
        $this->assertNull($result['reason'], 'having no package is not an error');
        $this->assertSame('none', $result['status']);
    }

    // ── §9: automatic ────────────────────────────────────────────────────────

    public function test_loyalty_does_not_apply_without_checkout_selection(): void
    {
        $result = CartLoyaltyDiscount::resolve(1800, $this->subscribe());

        $this->assertFalse($result['applied']);
        $this->assertSame(0.0, $result['amount']);
        $this->assertStringStartsWith('Gold', $result['package_name']);
    }

    public function test_automatic_mode_respects_the_package_maximum(): void
    {
        $result = CartLoyaltyDiscount::resolve(
            5000,
            $this->subscribe(['max_discount_amount' => 300]),
            300,
        );

        $this->assertSame(300.0, $result['amount']);
    }

    /** §9: the cart has to show these, so they must come back resolved. */
    public function test_the_cart_is_told_what_it_is_showing(): void
    {
        $result = CartLoyaltyDiscount::resolve(1800, $this->subscribe());

        $this->assertStringStartsWith('Gold', $result['package_name']);
        $this->assertSame(LoyaltyPeriod::ACTIVE, $result['status']);
        $this->assertSame(1800.0, $result['eligible_amount']);
        $this->assertSame(180.0, $result['maximum_eligible']);
        $this->assertSame(
            LoyaltyDiscount::PERCENTAGE,
            $result['snapshot']['loyalty_discount_type'],
        );
    }

    // ── §7: expiry ───────────────────────────────────────────────────────────

    public function test_an_expired_package_grants_nothing_but_still_reports_itself(): void
    {
        $subscription = LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['validity_period' => 30]),
            ['subscription_date' => '2026-01-01'],
        );

        $result = CartLoyaltyDiscount::resolve(1800, $subscription, onDate: '2026-03-01');

        $this->assertFalse($result['applied']);
        $this->assertSame(0.0, $result['amount']);
        // §7: the cart shows the status and the expiry date so a renewal can be
        // offered rather than the customer simply being charged more in silence.
        $this->assertSame(LoyaltyPeriod::EXPIRED, $result['status']);
        $this->assertSame('2026-01-30', $result['expired_on']);
    }

    // ── §10: manual ──────────────────────────────────────────────────────────

    public function test_manual_mode_applies_nothing_until_a_figure_is_entered(): void
    {
        $result = CartLoyaltyDiscount::resolve(
            1800,
            $this->subscribe(['discount_mode' => LoyaltyDiscount::MANUAL]),
        );

        $this->assertFalse($result['applied']);
        $this->assertSame(0.0, $result['amount']);
        // The entitlement is still reported, because §10 requires the user to be
        // shown the maximum they may enter.
        $this->assertSame(180.0, $result['maximum_eligible']);
    }

    public function test_a_manual_figure_within_the_entitlement_is_applied(): void
    {
        $result = CartLoyaltyDiscount::resolve(
            1800,
            $this->subscribe(['discount_mode' => LoyaltyDiscount::MANUAL]),
            manualAmount: 120,
        );

        $this->assertTrue($result['applied']);
        $this->assertSame(120.0, $result['amount']);
    }

    /** Refused outright, not silently reduced — the cashier has to be told. */
    public function test_a_manual_figure_above_the_entitlement_is_refused(): void
    {
        $result = CartLoyaltyDiscount::resolve(
            1800,
            $this->subscribe(['discount_mode' => LoyaltyDiscount::MANUAL]),
            manualAmount: 500,
        );

        $this->assertFalse($result['applied']);
        $this->assertSame(0.0, $result['amount']);
        $this->assertStringContainsString('maximum eligible discount', $result['reason']);
    }

    /** §10: the field is only editable by someone permitted to use it. */
    public function test_manual_entry_without_permission_is_refused(): void
    {
        $result = CartLoyaltyDiscount::resolve(
            1800,
            $this->subscribe(['discount_mode' => LoyaltyDiscount::MANUAL]),
            manualAmount: 100,
            mayEnterManual: false,
        );

        $this->assertFalse($result['applied']);
        $this->assertStringContainsString('not authorised', $result['reason']);
    }

    // ── §9, §11: what the invoice keeps ──────────────────────────────────────

    /**
     * §11: a later edit to the package master must not change a completed invoice,
     * which is only possible if the invoice kept its own copy of the terms.
     */
    public function test_the_invoice_stores_the_terms_used(): void
    {
        $subscription = $this->subscribe();
        $result = CartLoyaltyDiscount::resolve(1800, $subscription, 180);

        $columns = CartLoyaltyDiscount::saleColumns($result, enteredBy: 7);

        $this->assertSame($subscription->id, $columns['loyalty_subscription_id']);
        $this->assertStringStartsWith('Gold', $columns['loyalty_package_name']);
        $this->assertSame(1800.0, $columns['loyalty_eligible_amount']);
        $this->assertSame(180.0, $columns['loyalty_discount_amount']);
        $this->assertSame(10.0, $columns['loyalty_discount_value']);
    }

    /**
     * An automatic discount was nobody's decision, so attributing it to whoever
     * happened to be on the till would be misleading in an audit.
     */
    public function test_only_a_manual_discount_records_who_entered_it(): void
    {
        $automatic = CartLoyaltyDiscount::saleColumns(
            CartLoyaltyDiscount::resolve(1800, $this->subscribe()),
            enteredBy: 7,
        );
        $this->assertNull($automatic['loyalty_discount_by']);

        $other = Customer::create([
            'business_id' => $this->business->id,
            'name'        => 'Second customer',
            'phone'       => '9999911111',
        ]);

        $manualSubscription = LoyaltySubscriptionManager::subscribe(
            $other->id,
            $this->package(['discount_mode' => LoyaltyDiscount::MANUAL]),
        );

        $manual = CartLoyaltyDiscount::saleColumns(
            CartLoyaltyDiscount::resolve(1800, $manualSubscription, manualAmount: 100),
            enteredBy: 7,
        );
        $this->assertSame(7, $manual['loyalty_discount_by']);
    }

    public function test_a_cart_with_no_package_stores_a_zero_discount(): void
    {
        $columns = CartLoyaltyDiscount::saleColumns(
            CartLoyaltyDiscount::resolve(1800, null),
        );

        $this->assertSame(['loyalty_discount_amount' => 0], $columns);
    }
    // ── A zero is not a decision ────────────────────────────────────────────

    /**
     * A cashier must be able to bill a loyalty customer without the right to
     * enter a loyalty discount.
     *
     * Found at the counter: the app posts `loyalty_discount_amount` whether or
     * not the loyalty switch is on, and Laravel's `filled()` counts "0" as a
     * value — so the request looked like someone entering a manual discount.
     * Every sale for a customer holding a package was refused with "You are not
     * authorised to enter a loyalty discount", over a bill that had none.
     */
    public function test_a_zero_manual_amount_is_not_a_manual_discount(): void
    {
        $result = CartLoyaltyDiscount::resolve(
            1800,
            $this->subscribe(['discount_mode' => LoyaltyDiscount::MANUAL]),
            0.0,
            // No right to enter one.
            false,
        );

        // `reason` is always present in the shape; what matters is that it
        // carries no refusal.
        $this->assertNull($result['reason'] ?? null);
        $this->assertFalse($result['applied']);
        $this->assertSame(0.0, $result['amount']);
    }

    /** And an actual figure still needs the right. */
    public function test_a_real_manual_figure_still_needs_permission(): void
    {
        $result = CartLoyaltyDiscount::resolve(
            1800,
            $this->subscribe(['discount_mode' => LoyaltyDiscount::MANUAL]),
            120.0,
            false,
        );

        $this->assertSame(
            'You are not authorised to enter a loyalty discount.',
            $result['reason'],
        );
        $this->assertFalse($result['applied']);
    }

    /** With the right, the same figure goes through. */
    public function test_the_authorised_user_may_enter_it(): void
    {
        $result = CartLoyaltyDiscount::resolve(
            1800,
            $this->subscribe(['discount_mode' => LoyaltyDiscount::MANUAL]),
            120.0,
            true,
        );

        $this->assertTrue($result['applied']);
        $this->assertSame(120.0, $result['amount']);
    }
}
