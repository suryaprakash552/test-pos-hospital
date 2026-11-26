<?php

namespace Tests\Feature\Api;

use App\Imports\SaleImport;
use App\Models\Business;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Services\BatchConsumption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Importing a sale from a spreadsheet.
 *
 * A sale *consumes* stock, so this is stricter than the purchase importer. The two
 * properties that matter:
 *
 *  * **All or nothing.** A failure leaves stock exactly as it was — a half-imported
 *    sale under-bills the customer while over-consuming stock.
 *  * **Same consumption path as the till.** Stock is drawn down through
 *    `BatchConsumption`, so an imported sale and a counter sale behave identically.
 */
class SaleImportTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);
    }

    /** A product with one batch holding [available] units. */
    private function stockedProduct(
        string $name,
        float $available,
        string $batchNo = 'B1',
        ?string $expiry = null,
    ): Product {
        $product = Product::firstOrCreate(
            ['productCode' => strtoupper(substr(md5($name), 0, 6))],
            [
                'productName'      => $name,
                'business_id'      => $this->business->id,
                'productSalePrice' => 100,
                'conversionValue'  => 1,
            ],
        );

        $this->addBatch($product, $available, $batchNo, $expiry);

        return $product;
    }

    private function addBatch(
        Product $product,
        float $available,
        string $batchNo,
        ?string $expiry = null,
    ): Stock {
        $stock = Stock::create([
            'business_id'      => $this->business->id,
            'product_id'       => $product->id,
            'batch_no'         => $batchNo,
            'conversionValue'  => 1,
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'expire_date'      => $expiry,
        ]);

        if ($available > 0) {
            $purchaseId = DB::table('purchases')->insertGetId([
                'business_id' => $this->business->id,
                'totalAmount' => 100,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            DB::table('purchase_details')->insert([
                'purchase_id'        => $purchaseId,
                'product_id'         => $product->id,
                'stock_id'           => $stock->id,
                'quantities'         => $available,
                'conversionValue'    => 1,
                'conversionQuantity' => $available,
                'purchaseUpperLimit' => '',
                'purchaseLowerLimit' => '',
            ]);
        }

        return $stock;
    }

    private function sheet(array $rows): Collection
    {
        return collect([
            ['product code', 'quantity', 'price', 'batch'],
            ...$rows,
        ])->map(fn ($row) => collect($row));
    }

    private function import(array $rows): SaleImport
    {
        $import = new SaleImport(
            $this->business->id,
            $this->user->id,
            null,
            '2026-08-01',
            'INV-S-1',
        );

        $import->collection($this->sheet($rows));

        return $import;
    }

    private function codeFor(string $name): string
    {
        return strtoupper(substr(md5($name), 0, 6));
    }

    public function test_it_creates_one_sale_for_the_whole_file(): void
    {
        $this->stockedProduct('Cough', 50);
        $this->stockedProduct('Cold', 50);

        $this->import([
            [$this->codeFor('Cough'), 5, 100, null],
            [$this->codeFor('Cold'), 3, 100, null],
        ]);

        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(2, DB::table('sale_details')->count());
    }

    public function test_it_consumes_stock_through_the_shared_path(): void
    {
        $product = $this->stockedProduct('Cough', 50);
        $stock = Stock::where('product_id', $product->id)->first();

        $this->import([[$this->codeFor('Cough'), 20, 100, null]]);

        $this->assertSame(30.0, BatchConsumption::available($stock->id),
            'the batch must be drawn down exactly as a counter sale would');
    }

    /**
     * The property that makes bulk selling safe.
     */
    public function test_a_shortfall_on_a_later_row_consumes_nothing(): void
    {
        $plenty = $this->stockedProduct('Cough', 50);
        $scarce = $this->stockedProduct('Cold', 2);

        $plentyStock = Stock::where('product_id', $plenty->id)->first();
        $scarceStock = Stock::where('product_id', $scarce->id)->first();

        try {
            $this->import([
                [$this->codeFor('Cough'), 10, 100, null],
                // More than exists — the whole import must be abandoned.
                [$this->codeFor('Cold'), 99, 100, null],
            ]);
            $this->fail('the import should have been abandoned');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(50.0, BatchConsumption::available($plentyStock->id),
            'row 1 must be returned when row 2 fails');
        $this->assertSame(2.0, BatchConsumption::available($scarceStock->id));
        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_an_unknown_product_abandons_the_import(): void
    {
        $product = $this->stockedProduct('Cough', 50);
        $stock = Stock::where('product_id', $product->id)->first();

        try {
            $this->import([
                [$this->codeFor('Cough'), 5, 100, null],
                ['NOPE', 1, 100, null],
            ]);
            $this->fail('the import should have been abandoned');
        } catch (\RuntimeException) {
        }

        $this->assertSame(50.0, BatchConsumption::available($stock->id));
        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_a_zero_quantity_abandons_the_import(): void
    {
        $this->stockedProduct('Cough', 50);

        $this->expectException(\RuntimeException::class);
        $this->import([[$this->codeFor('Cough'), 0, 100, null]]);
    }

    public function test_a_named_batch_is_honoured(): void
    {
        $product = $this->stockedProduct('Cough', 10, 'OLD');
        $newer = $this->addBatch($product, 10, 'NEW');

        $this->import([[$this->codeFor('Cough'), 4, 100, 'NEW']]);

        $this->assertSame(6.0, BatchConsumption::available($newer->id));
        $this->assertSame(10.0, BatchConsumption::available(
            Stock::where('batch_no', 'OLD')->first()->id,
        ), 'a named batch must not spill into another');
    }

    /** Matching what the POS offers, so an import consumes the same lots. */
    public function test_the_nearest_expiry_batch_is_used_by_default(): void
    {
        $product = $this->stockedProduct('Cough', 10, 'LATER', '2027-01-01');
        $sooner = $this->addBatch($product, 10, 'SOONER', '2026-09-01');

        $this->import([[$this->codeFor('Cough'), 4, 100, null]]);

        $this->assertSame(6.0, BatchConsumption::available($sooner->id));
    }

    public function test_an_empty_batch_is_skipped_when_choosing(): void
    {
        $product = $this->stockedProduct('Cough', 0, 'EMPTY', '2026-01-01');
        $stocked = $this->addBatch($product, 10, 'STOCKED', '2027-01-01');

        $this->import([[$this->codeFor('Cough'), 4, 100, null]]);

        $this->assertSame(6.0, BatchConsumption::available($stocked->id));
    }

    public function test_a_price_falls_back_to_the_batch(): void
    {
        $this->stockedProduct('Cough', 50);

        $this->import([[$this->codeFor('Cough'), 5, null, null]]);

        $this->assertEquals(100, DB::table('sale_details')->first()->price);
    }

    public function test_the_sale_is_recorded_as_unsettled(): void
    {
        $this->stockedProduct('Cough', 50);

        $this->import([[$this->codeFor('Cough'), 5, 100, null]]);

        $sale = DB::table('sales')->first();

        $this->assertEquals(500, $sale->totalAmount);
        $this->assertEquals(500, $sale->dueAmount);
        $this->assertEquals(0, $sale->paidAmount);
    }

    public function test_an_empty_file_creates_nothing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->import([]);
    }

    // ── Endpoint ─────────────────────────────────────────────────────────────

    public function test_the_endpoint_queues_the_import(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Storage::fake(config('filesystems.default'));

        $this->actingAs($this->user)
            ->postJson('/api/v1/bulk-uploads/sales', [
                'file' => \Illuminate\Http\UploadedFile::fake()->create('sales.xlsx', 20),
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.type', 'sale');

        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\ProcessSaleBulkUploadJob::class
        );
    }

    public function test_another_businesses_customer_is_rejected(): void
    {
        \Illuminate\Support\Facades\Storage::fake(config('filesystems.default'));

        $foreign = DB::table('customers')->insertGetId([
            'business_id' => Business::factory()->create()->id,
            'name'        => 'Someone else',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/bulk-uploads/sales', [
                'file'        => \Illuminate\Http\UploadedFile::fake()->create('sales.xlsx', 20),
                'customer_id' => $foreign,
            ])
            ->assertStatus(422);
    }
}
