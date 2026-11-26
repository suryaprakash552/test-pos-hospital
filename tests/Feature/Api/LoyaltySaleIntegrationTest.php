<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\LoyaltyPackage;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Services\LoyaltyDiscount;
use App\Services\LoyaltyPeriod;
use App\Services\LoyaltySubscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The loyalty discount reaching a real invoice through the sale endpoint.
 *
 * The service is covered separately; this is the wiring, which is where a feature
 * like this usually fails — the arithmetic is right but nothing ever calls it, or
 * the client's own figure is trusted straight onto the bill.
 */
class LoyaltySaleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $user;
    protected Customer $customer;
    protected Stock $stock;
    protected Product $product;
    protected int $purchaseId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        $category = Category::factory()->create(['business_id' => $this->business->id]);

        $this->product = Product::create([
            'productName'      => 'IV Set',
            'productCode'      => 'IV-1',
            'business_id'      => $this->business->id,
            'category_id'      => $category->id,
            'productSalePrice' => 100,
            'conversionValue'  => 1,
        ]);

        $this->stock = Stock::create([
            'business_id'      => $this->business->id,
            'product_id'       => $this->product->id,
            'batch_no'         => 'B1',
            'productStock'     => 100,
            'productSalePrice' => 100,
            'conversionValue'  => 1,
        ]);

        $this->purchaseId = DB::table('purchases')->insertGetId([
            'business_id'   => $this->business->id,
            'invoiceNumber' => 'P-1',
            'purchaseDate'  => '2026-07-01',
            'discountAmount' => 0, 'shipping_charge' => 0, 'discount_percent' => 0,
            'discount_type' => 'fixed', 'rowDiscount' => 0, 'dueAmount' => 0,
            'paidAmount' => 0, 'cash_amount' => 0, 'upi_amount' => 0,
            'card_amount' => 0, 'change_amount' => 0, 'totalAmount' => 0,
            'isPaid' => 1, 'vat_percent' => 0, 'vat_amount' => 0,
        ]);

        DB::table('purchase_details')->insert([
            'purchase_id' => $this->purchaseId,
            'product_id'  => $this->product->id,
            'stock_id'    => $this->stock->id,
            'quantities'  => 100,
            'conversionQuantity' => 100,
            'productDealerPrice' => 0, 'productPurchasePrice' => 0,
            'conversionPurchasePrice' => 0, 'productSalePrice' => 100,
            'productWholeSalePrice' => 0, 'purchaseUpperLimit' => '',
            'purchaseLowerLimit' => '', 'totalPrice' => 0, 'totalPurchasePrice' => 0,
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name'        => 'Loyal customer',
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
            'name'            => 'Gold ' . $n,
            'cost'            => 100,
            'validity_type'   => LoyaltyPeriod::DAYS,
            'validity_period' => 365,
            'discount_type'   => LoyaltyDiscount::PERCENTAGE,
            'discount_value'  => 10,
            'discount_mode'   => LoyaltyDiscount::AUTOMATIC,
            'priority'        => $n,
        ]);
    }

    private function sell(array $over = [])
    {
        return $this->actingAs($this->user)->postJson('/api/v1/sales', array_merge([
            'saleDate'    => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'totalAmount' => 1000,
            'paidAmount'  => 1000,
            'products'    => json_encode([[
                'stock_id'   => $this->stock->id,
                'quantities' => 10,
                'price'      => 100,
                'lossProfit' => 100,
            ]]),
        ], $over));
    }

    public function test_a_sale_to_a_customer_with_no_package_carries_no_discount(): void
    {
        $this->sell()->assertStatus(200);

        $sale = DB::table('sales')->latest('id')->first();

        $this->assertEquals(0, $sale->loyalty_discount_amount);
        $this->assertNull($sale->loyalty_subscription_id);
    }

    /** Loyalty reaches an invoice only after an explicit checkout selection. */
    public function test_a_selected_loyalty_discount_lands_on_the_invoice(): void
    {
        $subscription = LoyaltySubscriptionManager::subscribe(
            $this->customer->id, $this->package(),
        );

        $this->sell([
            'loyalty_eligible_amount' => 1000,
            'loyalty_discount_amount' => 100,
        ])->assertStatus(200);

        $sale = DB::table('sales')->latest('id')->first();

        $this->assertEquals(100, $sale->loyalty_discount_amount);
        $this->assertEquals(1000, $sale->loyalty_eligible_amount);
        $this->assertSame($subscription->id, $sale->loyalty_subscription_id);
    }

    /**
     * §9, §11: the invoice keeps the terms, so editing the package master
     * afterwards cannot change what this bill says.
     */
    public function test_the_invoice_keeps_the_terms_it_was_billed_under(): void
    {
        $package = $this->package(['discount_value' => 10]);
        LoyaltySubscriptionManager::subscribe($this->customer->id, $package);

        $this->sell([
            'loyalty_eligible_amount' => 1000,
            'loyalty_discount_amount' => 100,
        ])->assertStatus(200);

        $package->update(['discount_value' => 50]);

        $sale = DB::table('sales')->latest('id')->first();

        $this->assertEquals(10, $sale->loyalty_discount_value);
        $this->assertEquals(100, $sale->loyalty_discount_amount);
    }

    /** §7: a lapsed package is billed as a non-loyalty customer. */
    public function test_an_expired_package_gives_no_discount_on_the_invoice(): void
    {
        LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['validity_period' => 1]),
            ['subscription_date' => '2020-01-01'],
        );

        $this->sell(['loyalty_eligible_amount' => 1000])->assertStatus(200);

        $sale = DB::table('sales')->latest('id')->first();

        $this->assertEquals(0, $sale->loyalty_discount_amount);
    }

    /**
     * The figure the client sends is a request, not a decision.
     *
     * Trusting it would let anyone with the API post themselves any discount they
     * liked, which is the whole reason the server resolves it.
     */
    public function test_a_client_supplied_discount_cannot_exceed_the_entitlement(): void
    {
        LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['discount_mode' => LoyaltyDiscount::MANUAL]),
        );

        $this->sell([
            'loyalty_eligible_amount' => 1000,
            'loyalty_discount_amount' => 900,
        ])->assertStatus(422);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_a_permitted_manual_discount_within_the_entitlement_is_stored(): void
    {
        LoyaltySubscriptionManager::subscribe(
            $this->customer->id,
            $this->package(['discount_mode' => LoyaltyDiscount::MANUAL]),
        );

        $this->sell([
            'loyalty_eligible_amount' => 1000,
            'loyalty_discount_amount' => 60,
        ])->assertStatus(200);

        $sale = DB::table('sales')->latest('id')->first();

        $this->assertEquals(60, $sale->loyalty_discount_amount);
        // §10: an override has a name against it.
        $this->assertSame($this->user->id, $sale->loyalty_discount_by);
    }
}
