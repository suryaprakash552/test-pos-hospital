<?php

/**
 * Seeds a single sellable unit and prints the ids the racing sellers need.
 *
 * Committed for real (no RefreshDatabase transaction) so separate OS processes
 * can actually see it and contend for it.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

if (! str_contains(DB::getDatabaseName(), 'test')) {
    fwrite(STDERR, "Refusing to seed into non-test database: " . DB::getDatabaseName() . PHP_EOL);
    exit(1);
}

// Clean slate for this scenario only.
DB::table('sale_details')->delete();
DB::table('sales')->delete();
DB::table('idempotency_keys')->delete();

$business = Business::factory()->create();
$user = User::factory()->create(['business_id' => $business->id, 'role' => 'shop-owner']);

$product = Product::factory()->create([
    'business_id' => $business->id,
    'productName' => 'Last Item ' . uniqid(),
    'productStock' => 1,
]);

$stock = Stock::factory()->create([
    'business_id' => $business->id,
    'product_id' => $product->id,
    'productStock' => 1,
]);

$purchase = Purchase::create([
    'business_id' => $business->id,
    'user_id' => $user->id,
    'purchaseDate' => now(),
    'totalAmount' => 60,
]);

PurchaseDetails::create([
    'purchase_id' => $purchase->id,
    'product_id' => $product->id,
    'stock_id' => $stock->id,
    'quantities' => 1,
    // Exactly one unit available to sell.
    'conversionQuantity' => 1,
    'conversionValue' => 1,
    'productPurchasePrice' => 60,
    'productSalePrice' => 100,
    'purchaseLowerLimit' => 0,
    'purchaseUpperLimit' => 0,
]);

echo json_encode([
    'user_id' => $user->id,
    'stock_id' => $stock->id,
    'business_id' => $business->id,
]) . PHP_EOL;
