<?php

namespace Tests\Feature\Api;

use App\Jobs\ParseSaleImportJob;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Sale;
use App\Models\SaleImportBatch;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The sale-side mirror of PurchaseImportTest.
 *
 * A sale draws down stock that already exists rather than creating a
 * batch, so besides matching a product, a line also has to resolve which
 * of that product's batches it sells from — the tests specific to that are
 * grouped at the end.
 */
class SaleImportReviewTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
        $this->customer = Customer::factory()->create([
            'business_id' => $this->business->id,
        ]);
    }

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('bill.csv', $body);
    }

    private function upload(UploadedFile $file, array $overrides = [])
    {
        return $this->actingAs($this->user)->postJson('/api/v1/sale-imports', array_merge([
            'file' => $file,
            'customer_id' => $this->customer->id,
        ], $overrides));
    }

    public function test_old_internal_failure_text_is_hidden_in_list_and_detail(): void
    {
        Bus::fake();
        $this->upload($this->csv("Item,Qty,Rate\nX,1,10"))->assertStatus(202);
        $batch = SaleImportBatch::firstOrFail();
        $batch->update(['status' => 'failed', 'error' => 'SQLSTATE secret at /var/www/app']);

        $this->actingAs($this->user)
            ->getJson('/api/v1/sale-imports')
            ->assertOk()
            ->assertJsonMissing(['error' => 'SQLSTATE secret at /var/www/app'])
            ->assertJsonPath('data.data.0.error', 'This document could not be imported. Check its format and try again, or use another import method.');

        $this->actingAs($this->user)
            ->getJson("/api/v1/sale-imports/{$batch->id}")
            ->assertOk()
            ->assertJsonMissing(['error' => 'SQLSTATE secret at /var/www/app']);
    }

    /** A product with one batch holding [quantity] sellable units. */
    private function stockedProduct(string $name, float $quantity, string $batchNo = 'B1'): Product
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => $name,
        ]);

        $stock = Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => $batchNo,
            'productStock' => $quantity,
            'conversionValue' => 1,
        ]);

        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'purchaseDate' => now(),
            'totalAmount' => 0,
        ]);

        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => $quantity,
            'conversionQuantity' => $quantity,
            'conversionValue' => 1,
            'productPurchasePrice' => 10,
            'productSalePrice' => 20,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);

        return $product;
    }

    public function test_name_only_spreadsheet_row_defaults_to_one_and_uses_inventory_price(): void
    {
        $product = $this->stockedProduct('Amox', 5);
        $stock = Stock::where('product_id', $product->id)->firstOrFail();
        $stock->update(['productSalePrice' => 42, 'productPurchasePrice' => 20]);

        $this->upload($this->csv("Product Name\nAmox\n"));
        $batch = SaleImportBatch::firstOrFail();
        $this->assertSame(1.0, (float) $batch->lines()->firstOrFail()->quantity);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/sale-imports/{$batch->id}/commit-payload?auto_allocate=true")
            ->assertOk();
        $response->assertJsonPath('data.products.0.price', 42);
        $response->assertJsonPath('data.products.0.quantities', 1);
        $payload = $response->json('data');
        $this->actingAs($this->user)->postJson('/api/v1/sales', $payload + ['totalAmount' => 0])
            ->assertSuccessful();
        $this->assertSame(42.0, (float) \Illuminate\Support\Facades\DB::table('sale_details')->value('price'));
    }

    // ── Security ────────────────────────────────────────────────────────────

    public function test_a_file_pretending_to_be_a_csv_is_refused(): void
    {
        $disguised = UploadedFile::fake()->create('bill.csv', 8, 'application/x-php');

        $this->upload($disguised)
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, SaleImportBatch::count());
    }

    public function test_a_customer_from_another_business_is_refused(): void
    {
        $foreign = Customer::factory()->create(['business_id' => Business::factory()->create()->id]);

        $this->upload($this->csv("Item,Qty,Rate\nX,1,10"), ['customer_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');
    }

    /** Unlike a purchase's supplier, a walk-in sale needs no customer at all. */
    public function test_no_customer_is_a_valid_walk_in_sale(): void
    {
        $this->upload($this->csv("Item,Qty,Rate\nX,1,10"), ['customer_id' => null])
            ->assertStatus(202);
    }

    public function test_another_business_cannot_read_an_import(): void
    {
        Bus::fake();
        $this->upload($this->csv("Item,Qty,Rate\nX,1,10"))->assertStatus(202);
        $batch = SaleImportBatch::first();

        $outsider = User::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'role' => 'shop-owner',
        ]);

        $this->actingAs($outsider)
            ->getJson("/api/v1/sale-imports/{$batch->id}")
            ->assertStatus(404);
    }

    public function test_an_upload_is_queued_rather_than_parsed_in_the_request(): void
    {
        Bus::fake();

        $this->upload($this->csv("Item,Qty,Rate\nX,1,10"))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued');

        Bus::assertDispatched(ParseSaleImportJob::class);
    }

    // ── Parsing and product matching ───────────────────────────────────────

    public function test_a_bill_is_parsed_into_reviewable_lines(): void
    {
        $this->stockedProduct('Paracetamol 500', 20, 'B24A');

        $this->upload($this->csv(
            "Item,Qty,Rate,Batch\nParacetamol 500,10,45.50,B24A\n"
        ))->assertStatus(202);

        $batch = SaleImportBatch::first()->fresh();

        $this->assertSame('ready', $batch->status);
        $this->assertSame(1, $batch->total_rows);
        $this->assertSame(1, $batch->matched_rows);

        $line = $batch->lines()->first();
        $this->assertSame('matched', $line->match_status);
        $this->assertSame(10.0, (float) $line->quantity);
        $this->assertSame(45.50, (float) $line->price);
        $this->assertNotNull($line->stock_id);
    }

    public function test_numeric_pdf_like_rows_without_a_product_are_not_review_items(): void
    {
        $this->stockedProduct('Paracetamol 500', 20);
        $this->upload($this->csv(
            "Item,Qty,Rate\nParacetamol 500,2,50\n,1987.94,0\n,0,0\n"
        ))->assertStatus(202);

        $batch = SaleImportBatch::first()->fresh();
        $this->assertSame(1, $batch->total_rows);
        $this->assertSame(1, $batch->lines()->count());
        $this->assertSame('Paracetamol 500', $batch->lines()->first()->product_name);
    }

    public function test_a_document_with_only_unnamed_rows_fails_clearly(): void
    {
        $this->upload($this->csv("Item,Qty,Rate\n,1987.94,0\n,0,0\n"))
            ->assertStatus(202);

        $batch = SaleImportBatch::first()->fresh();
        $this->assertSame('failed', $batch->status);
        $this->assertSame(0, $batch->lines()->count());
        $this->actingAs($this->user)
            ->getJson("/api/v1/sale-imports/{$batch->id}")
            ->assertOk()
            ->assertJsonPath('data.error', 'No named products were found. Remove empty rows or use a text PDF, Excel or CSV.');
    }

    public function test_an_excel_workbook_preserves_invoice_number_and_date(): void
    {
        $this->stockedProduct('Paracetamol 500', 20, 'B24A');

        $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet->getActiveSheet()->fromArray([
            ['Invoice Number', 'Document Date', 'Item', 'Qty', 'Rate', 'Batch'],
            ['SALE-SAMPLE-001', '2026-09-16', 'Paracetamol 500', 10, 45.50, 'B24A'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'sale-xlsx-test-').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($path);

        $this->upload(new UploadedFile(
            $path,
            'sale.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        ), ['source' => 'xlsx'])->assertStatus(202);

        @unlink($path);

        $batch = SaleImportBatch::first()->fresh();
        $this->assertSame('ready', $batch->status);
        $this->assertSame('xlsx', $batch->source);
        $this->assertSame('SALE-SAMPLE-001', $batch->reference_invoice_no);
        $this->assertSame('2026-09-16', $batch->sale_date->toDateString());
        $this->assertSame('Paracetamol 500', $batch->lines()->first()->product_name);
        $this->assertNotNull($batch->lines()->first()->stock_id);
    }

    public function test_an_unknown_product_is_left_for_a_person(): void
    {
        $this->upload($this->csv("Item,Qty,Rate\nNothing We Stock,1,10\n"));

        $line = SaleImportBatch::first()->lines()->first();

        $this->assertSame('unmatched', $line->match_status);
        $this->assertNull($line->product_id);
        $this->assertNull($line->stock_id);
    }

    // ── Review and commit ───────────────────────────────────────────────────

    public function test_choosing_a_product_and_stock_confirms_the_line(): void
    {
        $product = $this->stockedProduct('Amox', 10);
        $stock = Stock::where('product_id', $product->id)->firstOrFail();

        $this->upload($this->csv("Item,Qty,Rate\nUnknown Thing,5,10\n"));

        $batch = SaleImportBatch::first();
        $line = $batch->lines()->first();

        $this->actingAs($this->user)
            ->patchJson("/api/v1/sale-imports/{$batch->id}/lines/{$line->id}", [
                'product_id' => $product->id,
                'stock_id' => $stock->id,
            ])
            ->assertSuccessful();

        $updated = $line->fresh();
        $this->assertSame('confirmed', $updated->match_status);
        $this->assertSame($stock->id, $updated->stock_id);
        $this->assertSame('Amox', $updated->product_name);
    }

    public function test_a_stock_row_from_another_product_cannot_be_chosen(): void
    {
        $product = $this->stockedProduct('Amox', 10);
        $otherProduct = $this->stockedProduct('Something Else', 10);
        $otherStock = Stock::where('product_id', $otherProduct->id)->firstOrFail();

        $this->upload($this->csv("Item,Qty,Rate\nUnknown,5,10\n"));
        $batch = SaleImportBatch::first();
        $line = $batch->lines()->first();

        $this->actingAs($this->user)
            ->patchJson("/api/v1/sale-imports/{$batch->id}/lines/{$line->id}", [
                'product_id' => $product->id,
                'stock_id' => $otherStock->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stock_id');
    }

    public function test_the_commit_payload_is_accepted_by_the_sale_endpoint(): void
    {
        $product = $this->stockedProduct('Amox', 20);
        $unit = Unit::factory()->create(['business_id' => $this->business->id]);
        $product->update(['productUpperUnit' => $unit->id, 'productLowerUnit' => $unit->id]);

        $this->upload($this->csv("Item,Qty,Rate\nAmox,3,100\n"));

        $payload = $this->actingAs($this->user)
            ->getJson('/api/v1/sale-imports/'.SaleImportBatch::first()->id.'/commit-payload')
            ->assertSuccessful()
            ->json('data');

        $this->assertCount(1, $payload['products']);
        $this->assertSame(3.0, (float) $payload['products'][0]['quantities']);
        $this->assertSame($product->id, $payload['products'][0]['product_id']);
        $this->assertSame(20.0, (float) $payload['products'][0]['available_quantity']);
        $this->assertNotEmpty($payload['products'][0]['batch_no']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales', $payload + ['totalAmount' => 0])
            ->assertSuccessful();
    }

    public function test_the_commit_payload_preserves_the_reviewed_line_discount(): void
    {
        $this->stockedProduct('Amox', 20);

        $this->upload($this->csv("Item,Qty,Rate,Discount %\nAmox,2,100,7.5\n"));

        $payload = $this->actingAs($this->user)
            ->getJson('/api/v1/sale-imports/'.SaleImportBatch::first()->id.'/commit-payload')
            ->assertSuccessful()
            ->json('data');

        $this->assertSame(7.5, (float) $payload['products'][0]['discount_percent']);
    }

    public function test_sale_import_reports_inventory_tax_adjustment_without_blocking(): void
    {
        $product = $this->stockedProduct('Amox', 20);
        $vat = Vat::create([
            'business_id' => $this->business->id,
            'name' => 'GST 5%',
            'rate' => 5,
            'status' => true,
        ]);
        $product->update(['vat_id' => $vat->id]);
        $this->upload($this->csv("Item,Qty,Rate,Tax %\nAmox,2,100,12\n"));

        $this->actingAs($this->user)
            ->getJson('/api/v1/sale-imports/'.SaleImportBatch::first()->id.'/commit-payload')
            ->assertOk()
            ->assertJsonPath('data.tax_adjustments.0.document_tax_percent', 12)
            ->assertJsonPath('data.tax_adjustments.0.inventory_tax_percent', 5);
    }

    public function test_sale_review_can_correct_tax_and_discount_before_checkout(): void
    {
        $product = $this->stockedProduct('Amox', 20);
        $vat = Vat::create([
            'business_id' => $this->business->id,
            'name' => 'GST 5%',
            'rate' => 5,
            'status' => true,
        ]);
        $product->update(['vat_id' => $vat->id]);
        $this->upload($this->csv("Item,Qty,Rate,Discount %,Tax %\nAmox,2,100,2,12\n"));
        $batch = SaleImportBatch::first();
        $line = $batch->lines()->first();

        $this->actingAs($this->user)
            ->patchJson("/api/v1/sale-imports/{$batch->id}/lines/{$line->id}", [
                'discount_percent' => 7.5,
                'tax_percent' => 5,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.discount_percent', 7.5)
            ->assertJsonPath('data.tax_percent', 5);

        $this->actingAs($this->user)
            ->getJson("/api/v1/sale-imports/{$batch->id}/commit-payload")
            ->assertSuccessful()
            ->assertJsonPath('data.products.0.discount_percent', 7.5);
    }

    public function test_an_unmatched_line_is_left_out_of_the_payload(): void
    {
        $this->stockedProduct('Amox', 20);

        $this->upload($this->csv("Item,Qty,Rate\nAmox,1,10\nGhost,2,20\n"));

        $payload = $this->actingAs($this->user)
            ->getJson('/api/v1/sale-imports/'.SaleImportBatch::first()->id.'/commit-payload')
            ->assertSuccessful()
            ->json('data');

        $this->assertCount(1, $payload['products']);
        $this->assertSame(1, $payload['skipped']);
    }

    public function test_a_batch_with_nothing_matched_cannot_be_committed(): void
    {
        $this->upload($this->csv("Item,Qty,Rate\nGhost,2,20\n"));

        $this->actingAs($this->user)
            ->getJson('/api/v1/sale-imports/'.SaleImportBatch::first()->id.'/commit-payload')
            ->assertStatus(422)
            ->assertJsonPath('reason', 'nothing_to_commit');
    }

    /** Mirrors the purchase-side atomic close: dispatched from /sales, not a second call. */
    public function test_committing_an_import_closes_its_batch_atomically(): void
    {
        $product = $this->stockedProduct('Amox', 20);

        $this->upload($this->csv("Item,Qty,Rate\nAmox,3,100\n"));
        $batchId = SaleImportBatch::first()->id;

        $payload = $this->actingAs($this->user)
            ->getJson("/api/v1/sale-imports/{$batchId}/commit-payload")
            ->assertSuccessful()
            ->json('data');

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales', $payload + ['totalAmount' => 0, 'import_batch_id' => $batchId])
            ->assertSuccessful();

        $batch = SaleImportBatch::find($batchId);

        $this->assertSame('committed', $batch->status);
        $this->assertNotNull($batch->sale_id);
    }

    public function test_a_failed_import_with_partial_lines_cannot_create_a_sale(): void
    {
        $this->stockedProduct('Amox', 20);
        $this->upload($this->csv("Item,Qty,Rate\nAmox,3,100\n"));
        $batch = SaleImportBatch::firstOrFail();
        $payload = $this->actingAs($this->user)
            ->getJson("/api/v1/sale-imports/{$batch->id}/commit-payload")
            ->assertSuccessful()
            ->json('data');
        $batch->update(['status' => 'failed']);
        $before = Sale::count();

        $this->actingAs($this->user)
            ->getJson("/api/v1/sale-imports/{$batch->id}/commit-payload")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'import_not_ready');

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales', $payload + ['totalAmount' => 0, 'import_batch_id' => $batch->id])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'import_not_ready');

        $this->assertSame($before, Sale::count());
        $this->assertSame('failed', $batch->fresh()->status);
    }

    public function test_commit_payload_rechecks_stock_that_changed_after_review(): void
    {
        $product = $this->stockedProduct('Amox', 20);
        $this->upload($this->csv("Item,Qty,Rate\nAmox,3,100\n"));
        $batchId = SaleImportBatch::first()->id;

        PurchaseDetails::where('product_id', $product->id)
            ->update(['conversionQuantity' => 2]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/sale-imports/{$batchId}/commit-payload")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'insufficient_stock')
            ->assertJsonPath('lines.0.requested_quantity', 3)
            ->assertJsonPath('lines.0.available_quantity', 2);
    }

    public function test_expired_selected_stock_is_rejected_in_both_allocation_modes(): void
    {
        $product = $this->stockedProduct('Expiry check', 20);
        $this->upload($this->csv("Item,Qty,Rate\nExpiry check,3,100\n"));
        $batchId = SaleImportBatch::first()->id;
        Stock::where('product_id', $product->id)
            ->update(['expire_date' => now()->subDay()->toDateString()]);

        foreach (['', '?auto_allocate=1'] as $mode) {
            $this->actingAs($this->user)
                ->getJson("/api/v1/sale-imports/{$batchId}/commit-payload{$mode}")
                ->assertStatus(409)
                ->assertJsonPath('reason', 'insufficient_stock')
                ->assertJsonPath('lines.0.available_quantity', 0);
        }
    }

    public function test_automatic_allocation_splits_earliest_expiry_and_reserves_duplicate_rows(): void
    {
        $product = $this->stockedProduct('Amox', 2, 'EARLY');
        $early = Stock::where('product_id', $product->id)->firstOrFail();
        $early->update(['expire_date' => now()->addMonth()->toDateString()]);
        $later = Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => 'LATER',
            'expire_date' => now()->addMonths(2)->toDateString(),
            'productStock' => 4,
            'conversionValue' => 1,
        ]);
        $lot = PurchaseDetails::where('stock_id', $early->id)->firstOrFail()->replicate();
        $lot->stock_id = $later->id;
        $lot->quantities = 4;
        $lot->conversionQuantity = 4;
        $lot->save();

        $this->upload($this->csv("Item,Qty,Rate\nAmox,3,100\nAmox,2,100\n"));
        $batch = SaleImportBatch::firstOrFail();
        $before = Sale::count();
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/sale-imports/{$batch->id}/commit-payload?auto_allocate=true")
            ->assertOk();
        $rows = $response->json('data.products');
        $this->assertCount(3, $rows);
        $this->assertEquals([$early->id, $later->id, $later->id], array_column($rows, 'stock_id'));
        $this->assertEquals([2, 1, 2], array_column($rows, 'quantities'));
        $response->assertJsonPath('data.skipped', 0);
        $this->assertSame($before, Sale::count());

        $batch->lines()->orderBy('row_number')->first()->update(['quantity' => 5]);
        $this->actingAs($this->user)
            ->getJson("/api/v1/sale-imports/{$batch->id}/commit-payload?auto_allocate=true")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'insufficient_stock')
            ->assertJsonPath('lines.0.available_quantity', 1);
    }

    public function test_the_same_import_cannot_be_committed_twice(): void
    {
        $product = $this->stockedProduct('Amox', 20);
        $this->upload($this->csv("Item,Qty,Rate\nAmox,3,100\n"));
        $batchId = SaleImportBatch::first()->id;

        $payload = $this->actingAs($this->user)
            ->getJson("/api/v1/sale-imports/{$batchId}/commit-payload")
            ->assertSuccessful()
            ->json('data');

        $firstSaleId = $this->actingAs($this->user)
            ->postJson('/api/v1/sales', $payload + ['totalAmount' => 0, 'import_batch_id' => $batchId])
            ->assertSuccessful()
            ->json('data.id');

        $salesAfterFirst = Sale::count();
        $stockAfterFirst = PurchaseDetails::where('product_id', $product->id)
            ->sum('conversionQuantity');

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales', $payload + ['totalAmount' => 0, 'import_batch_id' => $batchId])
            ->assertSuccessful()
            ->assertJsonPath('reason', 'already_committed')
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('data.id', $firstSaleId);

        $this->assertSame($salesAfterFirst, Sale::count());
        $this->assertSame(
            (float) $stockAfterFirst,
            (float) PurchaseDetails::where('product_id', $product->id)
                ->sum('conversionQuantity'),
            'the recovered retry must not consume stock a second time',
        );
    }

    // ── Batch resolution: the part with no purchase-side equivalent ─────────

    public function test_a_product_with_one_batch_auto_selects_it(): void
    {
        $product = $this->stockedProduct('Amox', 10, 'B1');
        $stock = Stock::where('product_id', $product->id)->firstOrFail();

        $this->upload($this->csv("Item,Qty,Rate\nAmox,1,10\n"));

        $line = SaleImportBatch::first()->lines()->first();

        $this->assertSame('matched', $line->match_status);
        $this->assertSame($stock->id, $line->stock_id);
    }

    public function test_a_product_with_several_batches_defers_selection_to_automatic_allocation(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);

        foreach (['B1', 'B2'] as $batchNo) {
            $stock = Stock::factory()->create([
                'business_id' => $this->business->id,
                'product_id' => $product->id,
                'batch_no' => $batchNo,
                'conversionValue' => 1,
            ]);
            $purchase = Purchase::create([
                'business_id' => $this->business->id,
                'user_id' => $this->user->id,
                'purchaseDate' => now(),
                'totalAmount' => 0,
            ]);
            PurchaseDetails::create([
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'stock_id' => $stock->id,
                'quantities' => 5,
                'conversionQuantity' => 5,
                'conversionValue' => 1,
                'productPurchasePrice' => 10,
                'productSalePrice' => 20,
                'purchaseLowerLimit' => 0,
                'purchaseUpperLimit' => 0,
            ]);
        }

        $this->upload($this->csv("Item,Qty,Rate\n{$product->productName},1,10\n"));

        $line = SaleImportBatch::first()->lines()->first();

        $this->assertSame('ambiguous', $line->match_status);
        $this->assertNull($line->stock_id);
        $this->assertStringContainsString('2 batches', $line->note);
        $this->assertStringContainsString('allocated automatically', $line->note);
        $this->assertFalse($line->isCommittable());
    }

    public function test_a_bill_naming_the_batch_uses_it_even_with_others_in_stock(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        $wanted = null;

        foreach (['B1', 'B2'] as $batchNo) {
            $stock = Stock::factory()->create([
                'business_id' => $this->business->id,
                'product_id' => $product->id,
                'batch_no' => $batchNo,
                'conversionValue' => 1,
            ]);
            if ($batchNo === 'B2') {
                $wanted = $stock;
            }
            $purchase = Purchase::create([
                'business_id' => $this->business->id,
                'user_id' => $this->user->id,
                'purchaseDate' => now(),
                'totalAmount' => 0,
            ]);
            PurchaseDetails::create([
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'stock_id' => $stock->id,
                'quantities' => 5,
                'conversionQuantity' => 5,
                'conversionValue' => 1,
                'productPurchasePrice' => 10,
                'productSalePrice' => 20,
                'purchaseLowerLimit' => 0,
                'purchaseUpperLimit' => 0,
            ]);
        }

        $this->upload($this->csv("Item,Qty,Rate,Batch\n{$product->productName},1,10,B2\n"));

        $line = SaleImportBatch::first()->lines()->first();

        $this->assertSame('matched', $line->match_status);
        $this->assertSame($wanted->id, $line->stock_id);
    }

    public function test_a_product_with_no_stock_is_left_for_a_person(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Out Of Stock Item',
        ]);

        $this->upload($this->csv("Item,Qty,Rate\nOut Of Stock Item,1,10\n"));

        $line = SaleImportBatch::first()->lines()->first();

        $this->assertSame('unmatched', $line->match_status);
        $this->assertNull($line->stock_id);
        $this->assertStringContainsString('no stock', $line->note);
    }

    // ── Non-spreadsheet sources ──────────────────────────────────────────────
    //
    // The PDF/photo parsers themselves are already covered by
    // BillTextParserTest and PurchaseDocumentImportTest — they are shared,
    // unchanged, format readers. What is worth pinning here is only that
    // this endpoint and job actually reach them for a sale import too.

    public function test_a_pdf_upload_is_accepted_and_reaches_review(): void
    {
        $this->stockedProduct('AMOXYCILLIN 500MG CAP', 20, 'AMX2401');

        $row = [
            'product_name' => 'AMOXYCILLIN 500MG CAP',
            'batch_no' => 'AMX2401',
            'quantity' => 10,
            'purchase_price' => 85.50,
            'reference_invoice_no' => 'PURCHASE-173',
            'document_date' => '2026-08-24',
            'document_type' => 'purchase',
        ];
        $encoded = rtrim(strtr(base64_encode(json_encode($row)), '+/', '-_'), '=');

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml(
            '<pre style="font-family:monospace">'
            ."POSIMPORT1:{$encoded}"
            .'</pre>'
        );
        $dompdf->render();

        $response = $this->actingAs($this->user)->postJson('/api/v1/sale-imports', [
            'file' => UploadedFile::fake()->createWithContent('bill.pdf', $dompdf->output()),
            'customer_id' => $this->customer->id,
            'source' => 'pdf',
        ]);

        $response->assertStatus(202);

        $batch = SaleImportBatch::first()->fresh();

        $this->assertSame('pdf', $batch->source);
        $this->assertSame('ready', $batch->status);
        $this->assertSame(1, $batch->total_rows);
        $this->assertSame('AMOXYCILLIN 500MG CAP', $batch->lines()->first()->product_name);
        $this->assertNotNull($batch->lines()->first()->stock_id);
        $this->assertSame('PURCHASE-173', $batch->reference_invoice_no);
        $this->assertSame('2026-08-24', $batch->sale_date->toDateString());
    }

    public function test_stock_options_lists_every_batch_holding_stock(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);

        foreach (['B1', 'B2'] as $batchNo) {
            $stock = Stock::factory()->create([
                'business_id' => $this->business->id,
                'product_id' => $product->id,
                'batch_no' => $batchNo,
                'conversionValue' => 1,
            ]);
            $purchase = Purchase::create([
                'business_id' => $this->business->id,
                'user_id' => $this->user->id,
                'purchaseDate' => now(),
                'totalAmount' => 0,
            ]);
            PurchaseDetails::create([
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'stock_id' => $stock->id,
                'quantities' => 5,
                'conversionQuantity' => 5,
                'conversionValue' => 1,
                'productPurchasePrice' => 10,
                'productSalePrice' => 20,
                'purchaseLowerLimit' => 0,
                'purchaseUpperLimit' => 0,
            ]);
        }

        $this->upload($this->csv("Item,Qty,Rate\n{$product->productName},1,10\n"));
        $batch = SaleImportBatch::first();
        $line = $batch->lines()->first();

        $options = $this->actingAs($this->user)
            ->getJson("/api/v1/sale-imports/{$batch->id}/lines/{$line->id}/stock-options")
            ->assertSuccessful()
            ->json('data');

        $this->assertCount(2, $options);
    }
}
