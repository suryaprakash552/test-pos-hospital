<?php

namespace Tests\Unit;

use App\Services\Import\PurchaseCsvParser;
use App\Services\Import\PurchaseSpreadsheetParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class PurchaseSpreadsheetParserTest extends TestCase
{
    public function test_client_name_only_thirteen_column_layout_preserves_rows(): void
    {
        $sheet = new Spreadsheet();
        $sheet->getActiveSheet()->setTitle('Import Sample');
        $sheet->getActiveSheet()->fromArray([
            ['Invoice Number', 'Document Date', 'Product Name', 'Product Code',
                'Batch No', 'Quantity', 'Free Qty', 'Rate', 'MRP', 'Discount %',
                'GST %', 'Expiry Date', 'Mfg Date'],
            ['', '', 'GLANCE PCOD PROFILE'],
            ['', '', 'SERUM POTASSIUM'],
            ['', '', 'SERUM ALKALINE PHOSPHATE'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'client-layout-');
        try {
            (new Xlsx($sheet))->save($path);
            $rows = (new PurchaseSpreadsheetParser(new PurchaseCsvParser()))
                ->parse(file_get_contents($path));
            $this->assertCount(3, $rows);
            $this->assertSame('GLANCE PCOD PROFILE', $rows[0]['product_name']);
            $this->assertSame('SERUM POTASSIUM', $rows[1]['product_name']);
            $this->assertSame('', $rows[0]['quantity']);
            $this->assertSame('', $rows[0]['purchase_price']);
        } finally {
            unlink($path);
            $sheet->disconnectWorksheets();
        }
    }

    public function test_interchange_workbook_is_read_into_reviewable_purchase_rows(): void
    {
        $sheet = new Spreadsheet();
        $sheet->getActiveSheet()->fromArray([
            ['Product Code', 'Item Name', 'Batch', 'Expiry', 'Qty', 'Free', 'Rate', 'Discount %', 'GST %'],
            ['MED-10', 'Medicine Ten', 'B-100', '12/28', 10, 5, 50, 2, 5],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'interchange-test-');
        (new Xlsx($sheet))->save($path);
        $contents = file_get_contents($path);
        unlink($path);

        $rows = (new PurchaseSpreadsheetParser(new PurchaseCsvParser()))->parse($contents);

        $this->assertSame('MED-10', $rows[0]['product_code']);
        $this->assertSame('Medicine Ten', $rows[0]['product_name']);
        $this->assertSame('B-100', $rows[0]['batch_no']);
        $this->assertSame('10', $rows[0]['quantity']);
        $this->assertSame('5', $rows[0]['free_quantity']);
        $this->assertSame('50', $rows[0]['purchase_price']);
        $this->assertSame('5', $rows[0]['tax_percent']);
    }

    public function test_app_sample_workbook_preserves_every_supported_mapping_field(): void
    {
        $sheet = new Spreadsheet();
        $sheet->getActiveSheet()->fromArray([
            [
                'Invoice Number', 'Document Date',
                'Product Name', 'Product Code', 'Batch No', 'Quantity',
                'Free Qty', 'Rate', 'MRP', 'Discount %', 'GST %',
                'Expiry Date', 'Mfg Date',
            ],
            [
                'PUR-SAMPLE-001', '2026-09-16',
                'Paracetamol 500mg', 'MED001', 'PCM-2601', 10,
                1, 18.00, 25.00, 5, 12,
                '2028-06-30', '2026-07-01',
            ],
            [
                'PUR-SAMPLE-001', '2026-09-16',
                'Cetirizine 10mg', 'MED002', 'CTZ-2602', 5,
                0, 8.50, 14.00, 0, 5,
                '2028-12-31', '2026-08-01',
            ],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'sample-contract-');
        (new Xlsx($sheet))->save($path);
        $contents = file_get_contents($path);
        unlink($path);

        $rows = (new PurchaseSpreadsheetParser(new PurchaseCsvParser()))->parse($contents);

        $this->assertCount(2, $rows);
        $this->assertSame([
            'reference_invoice_no' => 'PUR-SAMPLE-001',
            'document_date' => '2026-09-16',
            'product_name' => 'Paracetamol 500mg',
            'product_code' => 'MED001',
            'batch_no' => 'PCM-2601',
            'quantity' => '10',
            'free_quantity' => '1',
            'purchase_price' => '18',
            'mrp' => '25',
            'discount_percent' => '5',
            'tax_percent' => '12',
            'expire_date' => '2028-06-30',
            'mfg_date' => '2026-07-01',
        ], $rows[0]);
        $this->assertSame('MED002', $rows[1]['product_code']);
        $this->assertSame('CTZ-2602', $rows[1]['batch_no']);
        $this->assertSame('8.5', $rows[1]['purchase_price']);
    }
}
