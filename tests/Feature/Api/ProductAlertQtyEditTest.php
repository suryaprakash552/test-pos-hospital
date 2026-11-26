<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Sale;
use App\Models\SaleDetails;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing the minimum stock level of a transaction-protected product.
 *
 * Reported: opening a product that has 1 sale + 1 purchase, changing only "Min
 * Stock", and getting "The selected upper selected unit is invalid." — Laravel's
 * default `exists` failure for `upperSelectedUnit`. The unit fields are locked in
 * the app, but the Flutter form still re-posts them, and when it posts the unit
 * *name* (or nothing usable) instead of the id, the update is rejected before it
 * ever reaches the friendly transaction-lock message.
 */
class ProductAlertQtyEditTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private Unit $strip;
    private Unit $tablet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
        $this->strip = Unit::create(['business_id' => $this->business->id, 'unitName' => 'STRIP', 'status' => 1]);
        $this->tablet = Unit::create(['business_id' => $this->business->id, 'unitName' => 'TABLET', 'status' => 1]);
    }

    private function transactedProduct(): Product
    {
        $product = Product::create([
            'business_id' => $this->business->id,
            'productName' => 'SAZOTEL AM',
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'conversionValue' => 10,
            'productUpperUnit' => $this->strip->id,
            'productLowerUnit' => $this->tablet->id,
            'alert_qty' => 10,
            'productStock' => 20,
        ]);

        $stock = Stock::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => 'B1',
            'productStock' => 100,
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'conversionValue' => 10,
        ]);

        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'purchaseDate' => now(),
            'totalAmount' => 600,
        ]);
        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => 10,
            'conversionQuantity' => 100,
            'conversionValue' => 10,
            'productPurchasePrice' => 60,
            'productSalePrice' => 100,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);

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
            'stock_id' => $stock->id,
            'price' => 100,
            'quantities' => 1,
            'lossProfit' => 40,
        ]);

        return $product;
    }

    /** The form re-posts the units by id (the correct value). Min Stock must save. */
    public function test_min_stock_can_be_changed_when_units_are_resent_by_id(): void
    {
        $product = $this->transactedProduct();

        $this->actingAs($this->owner)
            ->putJson("/api/v1/products/{$product->id}", [
                'productName' => 'SAZOTEL AM',
                'alert_qty' => 25,
                'conversionValue' => 10,
                'upperSelectedUnit' => (string) $this->strip->id,
                'lowerSelectedUnit' => (string) $this->tablet->id,
            ])
            ->assertSuccessful();

        $this->assertEquals(25, $product->fresh()->alert_qty);
    }

    /**
     * The reported failure: the form posts the unit *name* because the id was
     * lost on prefill. The min-stock edit must still go through.
     */
    public function test_min_stock_can_be_changed_when_units_are_resent_by_name(): void
    {
        $product = $this->transactedProduct();

        $this->actingAs($this->owner)
            ->putJson("/api/v1/products/{$product->id}", [
                'productName' => 'SAZOTEL AM',
                'alert_qty' => 25,
                'conversionValue' => 10,
                'upperSelectedUnit' => 'STRIP',
                'lowerSelectedUnit' => 'TABLET',
            ])
            ->assertSuccessful();

        $product->refresh();
        $this->assertEquals(25, $product->alert_qty);
        // The units are unchanged — the name was resolved back to the stored id.
        $this->assertEquals($this->strip->id, $product->productUpperUnit);
        $this->assertEquals($this->tablet->id, $product->productLowerUnit);
    }

    /** Units remain editable; only the conversion value is history-protected. */
    public function test_unit_can_change_without_changing_conversion(): void
    {
        $product = $this->transactedProduct();
        $other = Unit::create(['business_id' => $this->business->id, 'unitName' => 'BOX', 'status' => 1]);

        $this->actingAs($this->owner)
            ->putJson("/api/v1/products/{$product->id}", [
                'productName' => 'SAZOTEL AM',
                'conversionValue' => 10,
                'upperSelectedUnit' => (string) $other->id,
                'lowerSelectedUnit' => (string) $this->tablet->id,
            ])
            ->assertSuccessful();

        $this->assertEquals($other->id, $product->fresh()->productUpperUnit);
        $this->assertSame(10.0, (float) $product->fresh()->conversionValue);
    }
}
