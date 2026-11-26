<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Sale;
use App\Models\SaleDetails;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Changing a product's conversion value after it has been transacted (#17).
 *
 * The number says how many sellable units a bought unit contains. Rewriting it
 * retroactively reinterprets every quantity already recorded: a lot bought as ten
 * boxes of ten becomes ten of twenty, and stock, sales history and profit all
 * shift for periods that were already closed.
 *
 * So it is locked once history exists - but locked, not forbidden. A value keyed
 * wrongly at setup has to be correctable by someone, and the alternative is
 * recreating the product and losing the history the lock exists to protect. The
 * owner may authorise it with their password, through the same path that
 * authorises a forced deletion rather than a second one.
 */
class ConversionValueLockTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private string $password = 'owner-secret-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
            'password' => Hash::make($this->password),
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'business_id' => $this->business->id,
            'productName' => 'Widget',
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'conversionValue' => 10,
        ]);
    }

    private function stockFor(Product $product): Stock
    {
        return Stock::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => 'B1',
            'productStock' => 100,
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'conversionValue' => 10,
        ]);
    }

    private function givePurchaseHistory(Product $product): void
    {
        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'purchaseDate' => now(),
            'totalAmount' => 600,
        ]);

        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $this->stockFor($product)->id,
            'quantities' => 10,
            'conversionQuantity' => 100,
            'conversionValue' => 10,
            'productPurchasePrice' => 60,
            'productSalePrice' => 100,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);
    }

    private function giveSaleHistory(Product $product): void
    {
        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'invoiceNumber' => 'S-' . uniqid(),
            'saleDate' => now(),
            'totalAmount' => 100,
            'paidAmount' => 100,
            'dueAmount' => 0,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'stock_id' => $this->stockFor($product)->id,
            'price' => 100,
            'quantities' => 1,
            'lossProfit' => 40,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function changeConversionTo(Product $product, float $value, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)
            ->putJson("/api/v1/products/{$product->id}", array_merge([
                'productName' => 'Widget',
                'conversionValue' => $value,
            ], $extra));
    }

    public function test_a_product_with_no_history_can_be_changed_freely(): void
    {
        $product = $this->product();

        $this->changeConversionTo($product, 20)->assertSuccessful();

        $this->assertSame(20.0, (float) $product->fresh()->conversionValue);
    }

    public function test_purchase_history_locks_it(): void
    {
        $product = $this->product();
        $this->givePurchaseHistory($product);

        $this->changeConversionTo($product, 20)
            ->assertStatus(409)
            ->assertJsonPath('reason', 'conversion_value_locked');

        $this->assertSame(10.0, (float) $product->fresh()->conversionValue);
    }

    public function test_sale_history_locks_it(): void
    {
        $product = $this->product();
        $this->giveSaleHistory($product);

        $this->changeConversionTo($product, 20)->assertStatus(409);

        $this->assertSame(10.0, (float) $product->fresh()->conversionValue);
    }

    public function test_both_kinds_of_history_lock_it(): void
    {
        $product = $this->product();
        $this->givePurchaseHistory($product);
        $this->giveSaleHistory($product);

        $response = $this->changeConversionTo($product, 20)->assertStatus(409);

        // The counts are returned so the till can say what is blocking it rather
        // than only that something is.
        $this->assertNotEmpty($response->json('transactions'));
        // JSON renders a whole float as an int; the value is the point.
        $this->assertSame(10.0, (float) $response->json('current_value'));
    }

    public function test_the_owner_can_authorise_the_change_with_their_password(): void
    {
        $product = $this->product();
        $this->givePurchaseHistory($product);

        $this->changeConversionTo($product, 20, ['password' => $this->password])
            ->assertSuccessful();

        $this->assertSame(20.0, (float) $product->fresh()->conversionValue);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $product = $this->product();
        $this->givePurchaseHistory($product);

        $this->changeConversionTo($product, 20, ['password' => 'not-the-password'])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'invalid_password');

        $this->assertSame(10.0, (float) $product->fresh()->conversionValue);
    }

    public function test_a_staff_member_cannot_authorise_it_even_with_a_password(): void
    {
        $product = $this->product();
        $this->givePurchaseHistory($product);

        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'staff',
            'password' => Hash::make('staff-pass'),
        ]);

        $this->changeConversionTo($product, 20, ['password' => 'staff-pass'], $staff)
            ->assertStatus(403)
            ->assertJsonPath('reason', 'not_owner');

        $this->assertSame(10.0, (float) $product->fresh()->conversionValue);
    }

    /**
     * The lock is on the change, not on the field.
     *
     * Re-sending the value it already has is not a change, and refusing it would
     * make every ordinary edit of a transacted product impossible.
     */
    public function test_resending_the_same_value_is_not_treated_as_a_change(): void
    {
        $product = $this->product();
        $this->givePurchaseHistory($product);

        $this->changeConversionTo($product, 10)->assertSuccessful();
    }

    public function test_other_fields_stay_editable_on_a_transacted_product(): void
    {
        $product = $this->product();
        $this->givePurchaseHistory($product);
        $product->forceFill(['productStock' => 37])->save();

        $this->actingAs($this->owner)
            ->putJson("/api/v1/products/{$product->id}", [
                'productName' => 'Renamed Widget',
                'productSalePrice' => 150,
                'product_percent_discount' => 12,
            ])
            ->assertSuccessful();

        $fresh = $product->fresh();
        $this->assertSame('Renamed Widget', $fresh->productName);
        $this->assertSame(150.0, (float) $fresh->productSalePrice);
        $this->assertSame(10.0, (float) $fresh->conversionValue);
        $this->assertSame(12.0, (float) $fresh->product_percent_discount);
        $this->assertSame(37.0, (float) $fresh->productStock);
    }

    public function test_partial_edit_does_not_clear_existing_master_data(): void
    {
        $product = $this->product();
        $product->forceFill([
            'productDescription' => 'Original description',
            'rack' => 'R-2',
            'shelf' => 'S-4',
            'alert_qty' => 8,
            'profit_percent' => 25,
            'product_flat_discount' => 3,
            'product_percent_discount' => 7,
            'expire_date' => '2028-12-31',
        ])->save();
        $this->givePurchaseHistory($product);

        $this->actingAs($this->owner)
            ->putJson("/api/v1/products/{$product->id}", [
                'productName' => 'Only name changed',
            ])
            ->assertSuccessful();

        $fresh = $product->fresh();
        $this->assertSame('Original description', $fresh->productDescription);
        $this->assertSame('R-2', $fresh->rack);
        $this->assertSame('S-4', $fresh->shelf);
        $this->assertSame(8.0, (float) $fresh->alert_qty);
        $this->assertSame(25.0, (float) $fresh->profit_percent);
        $this->assertSame(3.0, (float) $fresh->product_flat_discount);
        $this->assertSame(7.0, (float) $fresh->product_percent_discount);
        $this->assertSame('2028-12-31', optional($fresh->expire_date)->toDateString());
    }

    public function test_only_conversion_is_locked_after_history_exists(): void
    {
        $product = $this->product();
        $this->givePurchaseHistory($product);

        $this->actingAs($this->owner)
            ->putJson("/api/v1/products/{$product->id}", [
                'productName' => 'Widget',
                'productStock' => 999,
                'hsn_code' => '3004',
                'rack' => 'RACK-7',
                'product_percent_discount' => 14,
            ])
            ->assertSuccessful();

        $fresh = $product->fresh();
        $this->assertSame(999.0, (float) $fresh->productStock);
        $this->assertSame('3004', $fresh->hsn_code);
        $this->assertSame('RACK-7', $fresh->rack);
        $this->assertSame(14.0, (float) $fresh->product_percent_discount);
        $this->assertSame(10.0, (float) $fresh->conversionValue);
    }

    /**
     * The block has to be the server's, not the form's.
     *
     * A stale or hand-written client sending the field directly is exactly the
     * case a disabled input does not cover.
     */
    public function test_an_old_client_sending_the_field_directly_is_still_refused(): void
    {
        $product = $this->product();
        $this->givePurchaseHistory($product);

        $this->actingAs($this->owner)
            ->putJson("/api/v1/products/{$product->id}", [
                'productName' => 'Widget',
                // Snake case: not the field the API reads, which is the point.
                'conversion_value' => 2,
            ])
            ->assertSuccessful();

        $this->assertSame(
            10.0,
            (float) $product->fresh()->conversionValue,
            'An unrecognised field name must not become a way around the lock.',
        );
    }
}
