<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\User;
use App\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pricing a cart without saving it.
 *
 * The till worked its own totals out for the billing screen and only learned the
 * server's once the sale came back saved. Every rule the client does not model -
 * the taxable base, rounding, a capped line discount, a refused order discount -
 * surfaced as the invoice disagreeing with the screen the cashier had just read.
 *
 * The point of these is the last test: preview and save must produce the same
 * numbers, because they run the same sequence against the same service.
 */
class CartPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);

        $vat = Vat::create([
            'business_id' => $business->id,
            'name' => 'GST 18',
            'rate' => 18,
        ]);

        $product = Product::create([
            'business_id' => $business->id,
            'productName' => 'Widget',
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'vat_id' => $vat->id,
            'vat_type' => 'exclusive',
        ]);

        $this->stock = Stock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'batch_no' => 'B1',
            'productStock' => 100,
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'conversionValue' => 1,
        ]);

        // FIFO consumption walks purchase_details, so a batch needs a purchase
        // behind it or there is nothing available to sell.
        $purchase = Purchase::create([
            'business_id' => $business->id,
            'user_id' => $this->user->id,
            'purchaseDate' => now(),
            'totalAmount' => 6000,
        ]);

        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $this->stock->id,
            'quantities' => 100,
            'conversionQuantity' => 100,
            'conversionValue' => 1,
            'productPurchasePrice' => 60,
            'productSalePrice' => 100,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);
    }

    /** JSON numbers lose their .0, so compare by value rather than by type. */
    private static function isMoney(float $expected): \Closure
    {
        return fn ($actual) => abs(((float) $actual) - $expected) < 0.001;
    }

    /** @param array<string, mixed> $overrides */
    private function preview(array $overrides = [])
    {
        return $this->actingAs($this->user)->postJson('/api/v1/sales/calculate', $overrides + [
            'products_payload' => [['stock_id' => $this->stock->id, 'quantities' => 2]],
        ]);
    }

    public function test_it_prices_a_cart_without_saving_anything(): void
    {
        $this->preview()
            ->assertSuccessful()
            ->assertJsonPath('data.subtotal', self::isMoney(200.0))
            ->assertJsonPath('data.tax_total', self::isMoney(36.0))
            ->assertJsonPath('data.grand_total', self::isMoney(236.0));

        $this->assertSame(0, Sale::count(), 'A preview must not create a sale.');
    }

    public function test_it_ignores_a_client_supplied_price(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/sales/calculate', [
                'products_payload' => [[
                    'stock_id' => $this->stock->id,
                    'quantities' => 2,
                    // The whole point of asking the server.
                    'price' => 1,
                    'subtotal' => 2,
                ]],
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.subtotal', self::isMoney(200.0))
            ->assertJsonPath('data.grand_total', self::isMoney(236.0));
    }

    public function test_it_applies_the_order_discount_to_the_taxable_base(): void
    {
        $this->preview(['discountAmount' => 40, 'discount_type' => 'flat'])
            ->assertSuccessful()
            ->assertJsonPath('data.order_discount', self::isMoney(40.0))
            ->assertJsonPath('data.tax_total', self::isMoney(28.8))
            ->assertJsonPath('data.grand_total', self::isMoney(188.8));
    }

    public function test_it_derives_due_and_change_from_what_was_tendered(): void
    {
        $this->preview(['paidAmount' => 300])
            ->assertSuccessful()
            ->assertJsonPath('data.paid', self::isMoney(236.0))
            ->assertJsonPath('data.due', self::isMoney(0.0))
            ->assertJsonPath('data.change', self::isMoney(64.0));
    }

    public function test_a_refused_discount_is_reported_before_the_cashier_commits(): void
    {
        $this->preview(['discountAmount' => 5000, 'discount_type' => 'flat'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'discount_exceeds_subtotal');
    }

    public function test_another_tenants_stock_cannot_be_priced(): void
    {
        $foreign = User::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'role' => 'shop-owner',
        ]);

        $this->actingAs($foreign)
            ->postJson('/api/v1/sales/calculate', [
                'products_payload' => [['stock_id' => $this->stock->id, 'quantities' => 1]],
            ])
            // Not found rather than refused: another tenant's batch does not
            // exist as far as this business is concerned, and saying otherwise
            // would confirm the id belongs to someone.
            ->assertStatus(404);
    }

    /**
     * The property the whole endpoint exists for.
     */
    public function test_the_preview_matches_what_gets_saved(): void
    {
        $options = ['discountAmount' => 40, 'discount_type' => 'flat', 'paidAmount' => 200];
        $products = [['stock_id' => $this->stock->id, 'quantities' => 2, 'lossProfit' => 0]];

        $preview = $this->actingAs($this->user)
            ->postJson('/api/v1/sales/calculate', $options + ['products_payload' => $products])
            ->assertSuccessful()
            ->json('data');

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales', $options + [
                // The checkout takes the lines as a JSON string.
                'products' => json_encode($products),
                'saleDate' => now()->toDateString(),
                'paymentType' => 'cash',
                'totalAmount' => 0,
            ])
            ->assertSuccessful();

        $sale = Sale::first();

        // Cast both sides: JSON renders a whole float as an int.
        $this->assertSame((float) $preview['grand_total'], (float) $sale->totalAmount);
        $this->assertSame((float) $preview['tax_total'], (float) $sale->vat_amount);
        $this->assertSame((float) $preview['order_discount'], (float) $sale->discountAmount);
        $this->assertSame((float) $preview['paid'], (float) $sale->paidAmount);
        $this->assertSame((float) $preview['due'], (float) $sale->dueAmount);
    }

    public function test_the_purchase_preview_prices_without_saving(): void
    {
        // setUp already made one, to back the sellable batch.
        $before = Purchase::count();

        $product = Product::create([
            'business_id' => $this->user->business_id,
            'productName' => 'Bought',
            'productSalePrice' => 80,
            'productPurchasePrice' => 50,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/purchase/calculate', [
                'products' => [[
                    'product_id' => $product->id,
                    'quantities' => 10,
                    'productPurchasePrice' => 50,
                    'conversion_quantities' => 10,
                    'conversion_value' => 1,
                ]],
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.subtotal', self::isMoney(500.0))
            ->assertJsonPath('data.grand_total', self::isMoney(500.0));

        $this->assertSame($before, Purchase::count(), 'A preview must not create a purchase.');
    }
}
