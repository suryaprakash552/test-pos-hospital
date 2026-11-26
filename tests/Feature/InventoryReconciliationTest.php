<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\InventoryChangeLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Stock;
use App\Models\User;
use App\Services\InventoryReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_detects_mixed_unit_drift_without_mutation_and_apply_repairs_only_tallies(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'productStock' => 99,
        ]);
        $stock = Stock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'batch_no' => 'DRIFT-1',
            'productStock' => 7,
            'conversionValue' => 10,
        ]);
        $purchase = Purchase::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'purchaseDate' => now(),
            'totalAmount' => 100,
        ]);
        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => 2,
            'conversionQuantity' => 15,
            'conversionValue' => 10,
            'productPurchasePrice' => 100,
            'productSalePrice' => 150,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);

        $service = app(InventoryReconciliationService::class);
        $rows = $service->inspect($business->id);
        $row = $rows->firstWhere('stock_id', $stock->id);

        $this->assertSame(15.0, $row['authoritative_lower_quantity']);
        $this->assertSame(1.5, $row['expected_batch_quantity']);
        $this->assertTrue($row['batch_mismatch']);
        $this->assertTrue($row['product_mismatch']);
        $this->assertSame(7.0, (float) $stock->fresh()->productStock, 'Dry run must not write.');
        $this->assertSame(99.0, (float) $product->fresh()->productStock, 'Dry run must not write.');

        $service->apply($rows);

        $this->assertSame(1.5, (float) $stock->fresh()->productStock);
        $this->assertSame(1.5, (float) $product->fresh()->productStock);
        $this->assertSame(15.0, (float) PurchaseDetails::firstOrFail()->conversionQuantity);
        $this->assertDatabaseCount('inventory_change_logs', 2);
        $this->assertDatabaseHas('inventory_change_logs', [
            'event_type' => 'derived_tally_reconciled',
            'business_id' => $business->id,
        ]);
    }
}
