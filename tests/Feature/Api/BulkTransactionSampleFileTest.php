<?php

namespace Tests\Feature\Api;

use App\Imports\PurchaseImport;
use App\Imports\SaleImport;
use App\Models\Business;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * The sample .xlsx the app hands the shop for bulk sale / purchase upload must
 * actually import.
 *
 * The importers read by column position, so the sample's column order has to
 * match `SaleImport` / `PurchaseImport`. These build a real workbook with the
 * exact headers and example rows the Flutter "Download sample file" button
 * produces (see BulkTransactionKind), then run it through the importers.
 */
class BulkTransactionSampleFileTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
    }

    private function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $sheet->setCellValueExplicitByColumnAndRow(
                    $c + 1,
                    $r + 1,
                    (string) $value,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
                );
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'sample_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        return $path;
    }

    private function product(string $code, string $name): Product
    {
        $product = Product::create([
            'business_id' => $this->business->id,
            'productName' => $name,
            'productCode' => $code,
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'conversionValue' => 1,
        ]);

        $stock = Stock::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => 'B-2231',
            'conversionValue' => 1,
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
        ]);

        $purchaseId = DB::table('purchases')->insertGetId([
            'business_id' => $this->business->id,
            'totalAmount' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_details')->insert([
            'purchase_id' => $purchaseId,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => 500,
            'conversionValue' => 1,
            'conversionQuantity' => 500,
            'purchaseUpperLimit' => '',
            'purchaseLowerLimit' => '',
        ]);

        return $product;
    }

    public function test_the_sale_sample_file_imports(): void
    {
        $this->product('MED001', 'Cough Syrup');
        $this->product('MED002', 'Paracetamol');

        // Exactly BulkTransactionKind.sale.sampleHeaders + sampleRows.
        $path = $this->xlsx([
            ['Product Code', 'Quantity', 'Unit Price (optional)', 'Batch No (optional)'],
            ['MED001', '10', '25.00', ''],
            ['MED002', '2', '', 'B-2231'],
        ]);

        $import = new SaleImport($this->business->id, $this->user->id);
        Excel::import($import, $path);

        $this->assertNotNull($import->saleId(), 'a sale should have been created');
        $this->assertSame(2, DB::table('sale_details')->count());
        // Row 1 priced from the sheet, row 2 fell back to the batch.
        $prices = DB::table('sale_details')->pluck('price')->map(fn ($p) => (float) $p)->sort()->values();
        $this->assertEqualsWithDelta(25.0, $prices[0], 0.001);
        $this->assertEqualsWithDelta(100.0, $prices[1], 0.001);

        @unlink($path);
    }

    public function test_the_purchase_sample_file_imports(): void
    {
        $this->product('MED001', 'Cough Syrup');
        $this->product('MED002', 'Paracetamol');

        // Exactly BulkTransactionKind.purchase.sampleHeaders + sampleRows.
        $path = $this->xlsx([
            ['Product Code', 'Quantity', 'Purchase Price', 'Sale Price', 'Batch No', 'Expire Date (YYYY-MM-DD)', 'Conversion Value'],
            ['MED001', '100', '18.00', '25.00', 'B-2231', '2027-06-30', '10'],
            ['MED002', '50', '9.50', '14.00', '', '', ''],
        ]);

        $import = new PurchaseImport($this->business->id, $this->user->id);
        Excel::import($import, $path);

        $this->assertSame([], $import->errors(), 'the sample rows should import cleanly');
        $this->assertSame(1, DB::table('purchases')->where('user_id', $this->user->id)->count());
        $this->assertGreaterThanOrEqual(2, DB::table('purchase_details')->count());

        @unlink($path);
    }

    // ── The whole HTTP → queued job → import chain, against local MySQL ──────

    public function test_the_sale_sample_uploads_through_the_endpoint(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->product('MED001', 'Cough Syrup');
        $this->product('MED002', 'Paracetamol');

        $path = $this->xlsx([
            ['Product Code', 'Quantity', 'Unit Price (optional)', 'Batch No (optional)'],
            ['MED001', '10', '25.00', ''],
            ['MED002', '2', '', 'B-2231'],
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/bulk-uploads/sales', [
                'file' => new UploadedFile($path, 'sales.xlsx', null, null, true),
                'invoiceNumber' => 'BULK-S-1',
            ])
            ->assertStatus(202);

        // QUEUE_CONNECTION=sync, so the import has already run.
        $this->assertSame(1, DB::table('sales')->where('invoiceNumber', 'BULK-S-1')->count());
        $this->assertSame(2, DB::table('sale_details')->count());

        @unlink($path);
    }

    public function test_the_purchase_sample_uploads_through_the_endpoint(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->product('MED001', 'Cough Syrup');
        $this->product('MED002', 'Paracetamol');

        $path = $this->xlsx([
            ['Product Code', 'Quantity', 'Purchase Price', 'Sale Price', 'Batch No', 'Expire Date (YYYY-MM-DD)', 'Conversion Value'],
            ['MED001', '100', '18.00', '25.00', 'B-2231', '2027-06-30', '10'],
            ['MED002', '50', '9.50', '14.00', '', '', ''],
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/bulk-uploads/purchases', [
                'file' => new UploadedFile($path, 'purchases.xlsx', null, null, true),
                'invoiceNumber' => 'BULK-P-1',
            ])
            ->assertStatus(202);

        $this->assertSame(1, DB::table('purchases')->where('user_id', $this->user->id)->count());
        $this->assertGreaterThanOrEqual(2, DB::table('purchase_details')->count());

        @unlink($path);
    }
}
