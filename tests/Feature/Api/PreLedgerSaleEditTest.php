<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryChangeLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetails;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Editing a sale made before movement-ledger logging existed (2026-09-07).
 *
 * Reversal on edit reads `InventoryChangeLog` to know what a sale is still
 * holding out of each lot, and gives exactly that back before the edited
 * line re-consumes. A sale created before that logging began has no such
 * rows for its original consumption - `restoreAllocation` correctly returns
 * 0 rather than guess - so nothing was ever given back, and re-consuming the
 * same quantity two steps later failed with "not enough stock in this
 * batch", on an edit that had not touched quantity at all. Reported from
 * production: a bill from 2026-08-08 could not have its discount changed
 * because its own line's batch had since been sold down to 0 by other,
 * newer sales.
 */
class PreLedgerSaleEditTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Product $product;
    private Stock $stock;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'owner',
        ]);
        $category = Category::factory()->create(['business_id' => $this->business->id]);

        $this->product = Product::create([
            'productName' => 'VILDAEASY M',
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'productCode' => 'PRE-LEDGER-1',
            'productStock' => 0,
        ]);

        $this->stock = Stock::create([
            'business_id' => $this->business->id,
            'product_id' => $this->product->id,
            'batch_no' => 'VDP25001',
            'productStock' => 0,
            'conversionValue' => 1,
            'productPurchasePrice' => 10,
            'productSalePrice' => 20,
        ]);

        // The lot this legacy sale drew from, left at 0 - exactly the state a
        // batch that has since sold through is in. No InventoryChangeLog rows
        // exist anywhere for it: this predates that table having any data.
        DB::table('purchase_details')->insertGetId([
            'purchase_id' => DB::table('purchases')->insertGetId([
                'business_id' => $this->business->id,
                'invoiceNumber' => 'P-'.uniqid(),
                'purchaseDate' => '2026-07-01',
                'totalAmount' => 0, 'paidAmount' => 0, 'dueAmount' => 0,
            ]),
            'product_id' => $this->product->id,
            'stock_id' => $this->stock->id,
            'quantities' => 10,
            'conversionQuantity' => 0,
            'conversionValue' => 1,
            'productPurchasePrice' => 10,
            'conversionPurchasePrice' => 10,
            'productSalePrice' => 20,
            'productWholeSalePrice' => 20,
            'productDealerPrice' => 20,
            'purchaseUpperLimit' => '', 'purchaseLowerLimit' => '',
            'totalPrice' => 0, 'totalPurchasePrice' => 0,
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Pulse Wave',
            'phone' => '9849361013',
        ]);

        $this->actingAs($this->user);
    }

    private function legacySale(float $quantity): Sale
    {
        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'invoiceNumber' => 'S316',
            'saleDate' => '2026-08-08',
            'totalAmount' => 14847,
            'paidAmount' => 14847,
            'dueAmount' => 0,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'stock_id' => $this->stock->id,
            'quantities' => $quantity,
            'price' => 20,
            'lossProfit' => 10,
        ]);

        return $sale;
    }

    public function test_editing_the_discount_alone_no_longer_fails_on_stock(): void
    {
        $sale = $this->legacySale(10);

        // 10 x 20 = 200 gross; a 65% line discount takes 130 off, for a net of 70.
        $response = $this->putJson("/api/v1/sales/{$sale->id}", [
            'saleDate' => '2026-08-08',
            'customer_id' => $this->customer->id,
            'totalAmount' => 70,
            'paidAmount' => 70,
            'products' => json_encode([[
                'stock_id' => $this->stock->id,
                'quantities' => 10,
                'price' => 20,
                'lossProfit' => 10,
                'discount_percent' => 65,
            ]]),
        ]);

        $response->assertSuccessful();

        // Reversed and reconsumed at the same quantity: the lot nets to
        // exactly where it started, not short by the amount that used to be
        // silently lost.
        $this->assertSame(
            0.0,
            (float) DB::table('purchase_details')->where('stock_id', $this->stock->id)->value('conversionQuantity'),
        );

        // The reconsumption step always writes through the ledger, even
        // though the sale's original consumption predates it - so an edit
        // from here on is fully lineage-tracked going forward.
        $this->assertGreaterThan(
            0,
            InventoryChangeLog::where('source_id', $sale->id)
                ->where('stock_id', $this->stock->id)
                ->count(),
        );
    }

    public function test_a_genuine_shortfall_is_still_refused(): void
    {
        // The fallback restores what the sale's own line says it took - it
        // must not manufacture stock beyond that for a larger request.
        $sale = $this->legacySale(10);

        $response = $this->putJson("/api/v1/sales/{$sale->id}", [
            'saleDate' => '2026-08-08',
            'customer_id' => $this->customer->id,
            'totalAmount' => 400,
            'paidAmount' => 400,
            'products' => json_encode([[
                'stock_id' => $this->stock->id,
                'quantities' => 20,
                'price' => 20,
                'lossProfit' => 10,
            ]]),
        ]);

        $response->assertStatus(406);
    }
}
