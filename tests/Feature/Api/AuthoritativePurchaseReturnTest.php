<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\InventoryChangeLog;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\PurchaseReturnDetail;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Goods going back to a supplier: the credit is derived from the purchase, and the
 * stock has to actually be there to send back.
 */
class AuthoritativePurchaseReturnTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Party $supplier;
    private Purchase $purchase;
    private PurchaseDetails $detail;
    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
        $this->supplier = Party::create([
            'business_id' => $this->business->id,
            'name' => 'Acme Supplies',
            'type' => 'Supplier',
            'due' => 0,
        ]);

        $product = Product::factory()->create(['business_id' => $this->business->id]);

        $this->stock = Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'productStock' => 10,
        ]);

        // 10 units bought at 50, settled.
        $this->purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'party_id' => $this->supplier->id,
            'purchaseDate' => now(),
            'totalAmount' => 500,
            'paidAmount' => 500,
            'dueAmount' => 0,
        ]);

        $this->detail = PurchaseDetails::create([
            'purchase_id' => $this->purchase->id,
            'product_id' => $product->id,
            'stock_id' => $this->stock->id,
            'quantities' => 10,
            'conversionQuantity' => 10,
            'conversionValue' => 1,
            'productPurchasePrice' => 50,
            'productSalePrice' => 80,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function returnPurchase(array $overrides = [])
    {
        return $this->actingAs($this->user)->postJson('/api/v1/purchases-return', array_merge([
            'purchase_id' => $this->purchase->id,
            'return_date' => now()->toDateString(),
            'purchase_detail_id' => [$this->detail->id],
            'return_qty' => [2],
            'return_amount' => [100],
        ], $overrides));
    }

    public function test_the_credit_comes_from_the_purchase_not_the_request(): void
    {
        // Claims each returned unit is worth 5000.
        $this->returnPurchase(['return_amount' => [5000]])->assertStatus(200);

        // 2 units at the 50 they were bought at.
        $this->assertSame(100.0, (float) PurchaseReturnDetail::first()->return_amount);
    }

    public function test_a_return_cannot_exceed_what_was_purchased(): void
    {
        $this->returnPurchase(['return_qty' => [99]])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'return_exceeds_purchased');

        $this->assertSame(0, PurchaseReturnDetail::count());
        $this->assertSame(10.0, (float) $this->stock->fresh()->productStock);
    }

    /**
     * Eight of the ten have been sold on, so the lot holds two.
     *
     * `stocks.productStock` is deliberately left at its original ten. It is a
     * second, redundant tally that has drifted badly on live data — 2,635 of
     * 4,924 batches hold a negative figure — and gating on it refused
     * legitimate returns with "only -110.67 remain in stock". The lots are what
     * a sale actually draws down, so they are what a return is checked against.
     */
    public function test_stock_already_sold_cannot_be_returned_to_the_supplier(): void
    {
        $this->detail->update(['conversionQuantity' => 2]);

        $this->returnPurchase(['return_qty' => [5]])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'insufficient_stock_to_return');

        // And nothing moved.
        $this->assertSame(2.0, (float) $this->detail->fresh()->conversionQuantity);
        $this->assertSame(0, PurchaseReturnDetail::count());
        $this->assertSame(10.0, (float) $this->detail->fresh()->quantities);
    }

    public function test_stock_never_goes_negative(): void
    {
        $this->detail->update(['conversionQuantity' => 0]);

        $this->returnPurchase(['return_qty' => [1]])->assertStatus(422);

        $this->assertSame(0.0, (float) $this->detail->fresh()->conversionQuantity);
        $this->assertSame(0, PurchaseReturnDetail::count());
    }

    /**
     * The reported shape: a batch whose `productStock` has gone negative while
     * its lots still hold stock. Every return on such a batch was refused.
     */
    public function test_a_drifted_negative_tally_does_not_block_a_real_return(): void
    {
        $this->stock->update(['productStock' => -110.67]);

        $this->returnPurchase(['return_qty' => [2]])->assertStatus(200);

        $this->assertSame(1, PurchaseReturnDetail::count());
    }

    public function test_the_purchase_total_is_reduced_by_the_calculated_credit(): void
    {
        $this->returnPurchase([
            'return_qty' => [2],
            // Claims the purchase should be rewritten to something else.
            'totalAmount' => 1,
            'dueAmount' => 9999,
            'paidAmount' => 0,
        ])->assertStatus(200);

        $purchase = $this->purchase->fresh();

        // 500 less two units at 50.
        $this->assertSame(400.0, (float) $purchase->totalAmount);
        $this->assertSame(0.0, (float) $purchase->dueAmount);
    }

    public function test_stock_is_reduced_exactly_once(): void
    {
        $this->returnPurchase(['return_qty' => [3]])->assertStatus(200);

        $this->assertSame(7.0, (float) $this->stock->fresh()->productStock);
        $this->assertDatabaseHas('inventory_change_logs', [
            'event_type' => 'purchase_return_out',
            'stock_id' => $this->stock->id,
            'quantity_delta' => -3,
        ]);
    }

    public function test_the_returned_quantity_comes_off_the_purchase_line(): void
    {
        $this->returnPurchase(['return_qty' => [4]])->assertStatus(200);

        $this->assertSame(6.0, (float) $this->detail->fresh()->quantities);
    }

    public function test_free_units_can_be_returned_without_supplier_credit(): void
    {
        $this->stock->update(['productStock' => 15, 'conversionValue' => 10]);
        $this->detail->update([
            'free_quantity' => 5,
            'conversionValue' => 10,
            'conversionQuantity' => 150,
        ]);

        $this->returnPurchase([
            'return_qty' => [0],
            'free_return_qty' => [2],
            'return_amount' => [9999],
        ])->assertOk();

        $detail = $this->detail->fresh();
        $return = PurchaseReturnDetail::firstOrFail();

        $this->assertSame(10.0, (float) $detail->quantities);
        $this->assertSame(3.0, (float) $detail->free_quantity);
        $this->assertSame(130.0, (float) $detail->conversionQuantity);
        $this->assertSame(13.0, (float) $this->stock->fresh()->productStock);
        $this->assertSame(0.0, (float) $return->return_amount);
        $this->assertSame(2.0, (float) $return->free_return_qty);
        $this->assertSame(500.0, (float) $this->purchase->fresh()->totalAmount);
    }

    public function test_a_credit_clears_what_is_still_owed_first(): void
    {
        $this->purchase->update(['dueAmount' => 60, 'paidAmount' => 440]);
        $this->supplier->update(['due' => 60]);

        // Returning 2 units = 100 credit against a 60 due.
        $this->returnPurchase(['return_qty' => [2]])->assertStatus(200);

        $this->assertSame(0.0, (float) $this->supplier->fresh()->due);
        $this->assertSame(0.0, (float) $this->purchase->fresh()->dueAmount);
        // Only the surplus is money expected back.
        $this->assertSame(40.0, (float) PurchaseReturnDetail::first()->return_cash);
    }

    public function test_a_return_keeps_its_cash_upi_and_card_refund_split(): void
    {
        $this->returnPurchase([
            'cash_amount' => 20,
            'upi_amount' => 30,
            'card_amount' => 50,
        ])->assertOk();

        $return = PurchaseReturnDetail::firstOrFail();

        $this->assertSame(20.0, (float) $return->return_cash);
        $this->assertSame(30.0, (float) $return->return_upi);
        $this->assertSame(50.0, (float) $return->return_card);
    }

    public function test_another_purchases_line_cannot_be_returned(): void
    {
        $otherPurchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'party_id' => $this->supplier->id,
            'purchaseDate' => now(),
            'totalAmount' => 50,
        ]);

        $foreignDetail = PurchaseDetails::create([
            'purchase_id' => $otherPurchase->id,
            'product_id' => $this->detail->product_id,
            'stock_id' => $this->stock->id,
            'quantities' => 1,
            'conversionQuantity' => 1,
            'conversionValue' => 1,
            'productPurchasePrice' => 50,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);

        $this->returnPurchase(['purchase_detail_id' => [$foreignDetail->id]])
            ->assertStatus(404)
            ->assertJsonPath('reason', 'unknown_return_line');

        $this->assertSame(0, PurchaseReturnDetail::count());
    }

    public function test_another_tenants_purchase_cannot_be_returned(): void
    {
        $otherBusiness = Business::factory()->create();
        $foreignPurchase = Purchase::create([
            'business_id' => $otherBusiness->id,
            'user_id' => $this->user->id,
            'purchaseDate' => now(),
            'totalAmount' => 100,
        ]);

        $this->returnPurchase(['purchase_id' => $foreignPurchase->id])->assertStatus(404);

        $this->assertSame(0, PurchaseReturnDetail::count());
    }

    /** @return array<string, array{mixed}> */
    public static function provideInvalidQuantities(): array
    {
        return [
            'zero' => [0],
            'negative' => [-2],
            'not a number' => ['abc'],
        ];
    }

    /** @dataProvider provideInvalidQuantities */
    public function test_invalid_return_quantities_are_rejected(mixed $quantity): void
    {
        $this->returnPurchase(['return_qty' => [$quantity]])->assertStatus(422);

        $this->assertSame(0, PurchaseReturnDetail::count());
        $this->assertSame(10.0, (float) $this->stock->fresh()->productStock);
    }

    public function test_a_retried_return_reduces_stock_once(): void
    {
        $headers = ['Idempotency-Key' => 'purchase-return-retry-001'];
        $body = [
            'purchase_id' => $this->purchase->id,
            'return_date' => now()->toDateString(),
            'purchase_detail_id' => [$this->detail->id],
            'return_qty' => [2],
            'return_amount' => [100],
        ];

        $this->actingAs($this->user)->postJson('/api/v1/purchases-return', $body, $headers)->assertStatus(200);
        $this->actingAs($this->user)->postJson('/api/v1/purchases-return', $body, $headers)->assertStatus(200);

        $this->assertSame(1, PurchaseReturnDetail::count());
        $this->assertSame(8.0, (float) $this->stock->fresh()->productStock);
    }
}
