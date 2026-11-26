<?php

namespace Tests\Feature\Api;

use App\Jobs\ParsePurchaseImportJob;
use App\Models\Business;
use App\Models\Party;
use App\Models\PurchaseImportBatch;
use App\Models\User;
use App\Services\Import\PurchasePdfParser;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Reading a supplier's bill when it did not arrive as a spreadsheet.
 *
 * Neither a PDF nor a photograph has columns, so neither can be tested the way
 * the CSV import is — there is no header to assert against. What is worth asserting is the part
 * that costs money if it is wrong: that totals and tax lines are not imported
 * as goods, that the figures land in the right fields, and that accepting PDFs
 * did not quietly widen what the CSV route accepts.
 *
 * The fixtures are real PDFs, rendered here rather than checked in, so the test
 * exercises actual text extraction instead of a string that was never in a PDF.
 */
class PurchaseDocumentImportTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Party $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

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
    }

    /** A bill as a supplier's system prints it. */
    private function billPdf(string $body): string
    {
        $dompdf = new Dompdf();
        $dompdf->loadHtml('<pre style="font-family:monospace">'.$body.'</pre>');
        $dompdf->render();

        return $dompdf->output();
    }

    public function test_it_reads_product_rows_from_a_pdf_bill(): void
    {
        $rows = (new PurchasePdfParser())->parse($this->billPdf(
            "TAX INVOICE\n"
            ."1. AMOXYCILLIN 500MG CAP AMX2401 08/27 10 85.50 120.00\n"
            ."2. PARACETAMOL 650 TAB PC9922 12/26 20 12.40 22.00"
        ));

        $this->assertCount(2, $rows);

        $this->assertSame('AMOXYCILLIN 500MG CAP', $rows[0]['product_name']);
        $this->assertSame('AMX2401', $rows[0]['batch_no']);
        $this->assertSame('08/27', $rows[0]['expire_date']);
        $this->assertSame('10', $rows[0]['quantity']);
        $this->assertSame('85.50', $rows[0]['purchase_price']);
        $this->assertSame('120.00', $rows[0]['mrp']);
    }

    /** Localwell's A4 PDF exposes table cells by column, not by visual row. */
    public function test_it_reads_the_attached_localwell_columnar_invoice_layout(): void
    {
        $text = "BILL OF SUPPLY\n"
            ."1\nDESCRIPTION\nDOLO SYP 60ML\nPACK\n1'S\nCOMP\nMICRO\n"
            ."SCH\nH\nRACK\n-\nHSN\n3004\nBATCH\nDRSL431\n"
            ."EXP\n05/28\nQTY\n1\nMRP\n57.11\nRATE\n57.11\n"
            ."DIS %\n-\nAMT\n57.11\nREMARKS\nSUBTOTAL\n57.11";

        $rows = (new PurchasePdfParser())->parseExtractedText($text);

        $this->assertCount(1, $rows);
        $this->assertSame('DOLO SYP 60ML', $rows[0]['product_name']);
        $this->assertSame('DRSL431', $rows[0]['batch_no']);
        $this->assertSame('05/28', $rows[0]['expire_date']);
        $this->assertSame('1', $rows[0]['quantity']);
        $this->assertSame('57.11', $rows[0]['purchase_price']);
        $this->assertSame('57.11', $rows[0]['mrp']);
    }

    public function test_it_prefers_complete_versioned_invoice_rows_over_visual_pdf_text(): void
    {
        $expected = [
            'product_name' => 'DOLO SYP 60ML',
            'product_code' => 'MED-42',
            'batch_no' => 'DRSL431',
            'expire_date' => '2028-05-31',
            'mfg_date' => '2026-01-01',
            'quantity' => 2,
            'free_quantity' => 1,
            'purchase_price' => 57.11,
            'mrp' => 60,
            'discount_percent' => 5,
            'tax_percent' => 12,
            'hsn_code' => '3004',
            'reference_invoice_no' => '38420',
            'document_date' => '2026-08-25',
            'document_type' => 'sale',
        ];
        $encoded = rtrim(strtr(base64_encode(json_encode($expected)), '+/', '-_'), '=');

        $rows = (new PurchasePdfParser())->parseExtractedText(
            "Invoice visual text that may be reordered\nPOSIMPORT1:{$encoded}\nGrand Total 114.22"
        );

        $this->assertCount(1, $rows);
        $this->assertSame($expected, array_intersect_key($rows[0], $expected));
        $this->assertSame('POSIMPORT1', $rows[0]['source_line']);
    }

    public function test_a_multi_product_sample_pdf_round_trips_every_mapping_field(): void
    {
        $samples = [
            [
                'product_name' => 'Paracetamol 500mg',
                'product_code' => 'MED001',
                'batch_no' => 'PCM-2601',
                'quantity' => 10,
                'free_quantity' => 1,
                'purchase_price' => 18.00,
                'mrp' => 25.00,
                'discount_percent' => 5,
                'tax_percent' => 12,
                'expire_date' => '2028-06-30',
                'mfg_date' => '2026-07-01',
                'reference_invoice_no' => 'PUR-SAMPLE-001',
                'document_date' => '2026-09-16',
                'document_type' => 'purchase',
            ],
            [
                'product_name' => 'Cetirizine 10mg',
                'product_code' => 'MED002',
                'batch_no' => 'CTZ-2602',
                'quantity' => 5,
                'free_quantity' => 0,
                'purchase_price' => 8.50,
                'mrp' => 14.00,
                'discount_percent' => 0,
                'tax_percent' => 5,
                'expire_date' => '2028-12-31',
                'mfg_date' => '2026-08-01',
                'reference_invoice_no' => 'PUR-SAMPLE-001',
                'document_date' => '2026-09-16',
                'document_type' => 'purchase',
            ],
        ];
        $tokens = array_map(
            static fn (array $row): string => 'POSIMPORT1:'.rtrim(
                strtr(base64_encode(json_encode($row)), '+/', '-_'), '='
            ),
            $samples,
        );

        $rows = (new PurchasePdfParser())->parse($this->billPdf(implode("\n", $tokens)));

        $this->assertCount(2, $rows);
        foreach ($samples as $index => $sample) {
            // JSON may decode 18.0 as integer 18; the monetary value is
            // identical and the importer normalises it to decimal storage.
            $this->assertEquals($sample, array_intersect_key($rows[$index], $sample));
        }
    }

    /**
     * The row that would be silently wrong.
     *
     * A totals line has exactly the shape of a product row. Importing one adds
     * stock of a product called "Total" and throws the bill's arithmetic out,
     * and nobody reviewing 40 lines would necessarily catch it.
     */
    public function test_it_does_not_import_totals_and_tax_lines_as_goods(): void
    {
        $rows = (new PurchasePdfParser())->parse($this->billPdf(
            "1. AMOXYCILLIN 500MG CAP 10 85.50 120.00\n"
            ."CGST 6.00 51.30 51.30\n"
            ."SGST 6.00 51.30 51.30\n"
            ."Discount 2.00 17.10 17.10\n"
            ."Grand Total 10 855.00 1200.00\n"
            ."Page 1 of 1 2 3"
        ));

        $this->assertCount(1, $rows);
        $this->assertSame('AMOXYCILLIN 500MG CAP', $rows[0]['product_name']);
    }

    /** A short code is part of a medicine's name far more often than it is a batch. */
    public function test_it_does_not_mistake_a_strength_for_a_batch_number(): void
    {
        $rows = (new PurchasePdfParser())->parse($this->billPdf(
            '1. VITAMIN B12 TAB 5 12.40 22.00'
        ));

        $this->assertSame('VITAMIN B12 TAB', $rows[0]['product_name']);
        $this->assertNull($rows[0]['batch_no']);
    }

    public function test_it_refuses_a_file_that_is_not_a_pdf(): void
    {
        $this->expectExceptionMessage('not a PDF');

        (new PurchasePdfParser())->parse("name,qty\nAmoxy,10\n");
    }

    public function test_it_accepts_a_pdf_upload_and_queues_it_as_a_pdf_batch(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->postJson('/api/v1/purchase-imports', [
            'file' => UploadedFile::fake()->createWithContent(
                'bill.pdf',
                $this->billPdf('1. AMOXYCILLIN 500MG CAP 10 85.50 120.00')
            ),
            'party_id' => $this->supplier->id,
            'source' => 'pdf',
        ]);

        $response->assertStatus(202);

        $this->assertSame('pdf', PurchaseImportBatch::first()->source);
        Bus::assertDispatched(ParsePurchaseImportJob::class);
    }

    /**
     * Accepting PDFs must not widen the CSV route.
     *
     * The file rules are chosen per source rather than merged, so asking for a
     * CSV still refuses everything that is not one.
     */
    public function test_a_pdf_is_refused_when_the_source_is_csv(): void
    {
        Bus::fake();

        $this->actingAs($this->user)->postJson('/api/v1/purchase-imports', [
            'file' => UploadedFile::fake()->createWithContent(
                'bill.pdf',
                $this->billPdf('1. AMOXYCILLIN 500MG CAP 10 85.50 120.00')
            ),
            'party_id' => $this->supplier->id,
            'source' => 'csv',
        ])->assertStatus(422);

        Bus::assertNothingDispatched();
    }

    /** And the reverse: the PDF route is not a way to post anything else. */
    public function test_a_csv_is_refused_when_the_source_is_pdf(): void
    {
        Bus::fake();

        $this->actingAs($this->user)->postJson('/api/v1/purchase-imports', [
            'file' => UploadedFile::fake()->createWithContent('bill.csv', "name,qty\nAmoxy,10\n"),
            'party_id' => $this->supplier->id,
            'source' => 'pdf',
        ])->assertStatus(422);

        Bus::assertNothingDispatched();
    }

    /**
     * The end-to-end shape: upload, parse on the worker, reviewable lines.
     *
     * Nothing is committed, and the uploaded bill is not kept afterwards — it
     * holds a supplier's prices and `raw` already preserves what it said.
     */
    public function test_the_job_turns_an_uploaded_pdf_into_reviewable_lines(): void
    {
        $row = [
            'product_name' => 'AMOXYCILLIN 500MG CAP',
            'batch_no' => 'AMX2401',
            'expire_date' => '2027-08-31',
            'quantity' => 10,
            'purchase_price' => 85.50,
            'mrp' => 120,
            'reference_invoice_no' => 'SALE-38420',
            'document_date' => '2026-08-25',
            'document_type' => 'sale',
        ];
        $encoded = rtrim(strtr(base64_encode(json_encode($row)), '+/', '-_'), '=');
        $response = $this->actingAs($this->user)->postJson('/api/v1/purchase-imports', [
            'file' => UploadedFile::fake()->createWithContent(
                'bill.pdf',
                $this->billPdf(
                    "POSIMPORT1:{$encoded}\n"
                    ."Grand Total 10 855.00 1200.00"
                )
            ),
            'party_id' => $this->supplier->id,
            'source' => 'pdf',
        ]);

        $response->assertStatus(202);

        $batch = PurchaseImportBatch::first()->fresh();

        $this->assertSame('ready', $batch->status);
        $this->assertSame(1, $batch->total_rows);
        $this->assertSame('AMOXYCILLIN 500MG CAP', $batch->lines()->first()->product_name);
        $this->assertSame('SALE-38420', $batch->reference_invoice_no);
        $this->assertSame('2026-08-25', $batch->bill_date->toDateString());
        $this->assertNull($batch->path);
    }
    // ── Photographed bills ──────────────────────────────────────────────────

    /**
     * The picture stays on the phone; only the text is posted.
     *
     * That is the whole design of this path: a shop's bills are its trading
     * position, and there is no reason to ship photographs of them anywhere.
     * It also means nothing here decodes untrusted image data.
     */
    public function test_it_reads_a_photographed_bill_from_recognised_text(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/purchase-imports', [
            'party_id' => $this->supplier->id,
            'source' => 'photo',
            'text' => "TAX INVOICE\n"
                ."AMOXYCILLIN 500MG CAP AMX2401 08/27 10 85.50 120.00\n"
                ."Grand Total 10 855.00 1200.00",
        ]);

        $response->assertStatus(202);

        $batch = PurchaseImportBatch::first()->fresh();

        $this->assertSame('photo', $batch->source);
        $this->assertSame('ready', $batch->status);
        $this->assertSame(1, $batch->total_rows);

        $line = $batch->lines()->first();
        $this->assertSame('AMOXYCILLIN 500MG CAP', $line->product_name);
        $this->assertSame('AMX2401', $line->batch_no);
    }

    /** A photographed bill has no file, and a file route has no text. */
    public function test_the_photo_route_does_not_accept_a_file(): void
    {
        Bus::fake();

        $this->actingAs($this->user)->postJson('/api/v1/purchase-imports', [
            'file' => UploadedFile::fake()->createWithContent('bill.csv', "name,qty\nAmoxy,10\n"),
            'party_id' => $this->supplier->id,
            'source' => 'photo',
            'text' => 'AMOXYCILLIN 10 85.50 120.00',
        ])->assertStatus(422);

        Bus::assertNothingDispatched();
    }

    public function test_the_file_routes_do_not_accept_posted_text(): void
    {
        Bus::fake();

        $this->actingAs($this->user)->postJson('/api/v1/purchase-imports', [
            'file' => UploadedFile::fake()->createWithContent('bill.csv', "name,qty\nAmoxy,10\n"),
            'party_id' => $this->supplier->id,
            'source' => 'csv',
            'text' => 'AMOXYCILLIN 10 85.50 120.00',
        ])->assertStatus(422);

        Bus::assertNothingDispatched();
    }

    /** The cap is what stops this being a way to post a megabyte of anything. */
    public function test_it_refuses_an_implausibly_large_body_of_text(): void
    {
        Bus::fake();

        $this->actingAs($this->user)->postJson('/api/v1/purchase-imports', [
            'party_id' => $this->supplier->id,
            'source' => 'photo',
            'text' => str_repeat('A', 120001),
        ])->assertStatus(422);

        Bus::assertNothingDispatched();
    }

    /**
     * A picture that read as nothing usable fails with something to act on.
     *
     * "It didn't work" leaves a shop retaking the same unusable photograph.
     */
    public function test_a_picture_with_no_rows_says_what_to_do_about_it(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/purchase-imports', [
            'party_id' => $this->supplier->id,
            'source' => 'photo',
            'text' => "TAX INVOICE\nAcme Supplies\nThank you for your business",
        ])->assertStatus(202);

        $batch = PurchaseImportBatch::first()->fresh();

        $this->assertSame('failed', $batch->status);
        $this->assertStringContainsString('flat, well lit', $batch->error);
    }
    // ── The "Sr." column ────────────────────────────────────────────────────

    /**
     * A serial number in its own cell arrives glued to the item name.
     *
     * Found running a real supplier PDF against the server: every row came back
     * as "1 Product 25", "2 Product 29" — and each one failed to match a product
     * that plainly exists, so a bill that should have imported cleanly arrived
     * with nothing matched and every line needing to be picked by hand.
     */
    public function test_a_serial_column_is_not_part_of_the_product_name(): void
    {
        $rows = (new PurchasePdfParser())->parse($this->billPdf(
            "1 Product 25 BJ4471 08/27 10 25.00 30.00\n"
            ."2 Product 29 BJ4472 11/27 20 8.50 10.00\n"
            ."3 PARACETAMOL 650 TAB PC9922 03/28 50 12.40 22.00"
        ));

        $this->assertSame(
            ['Product 25', 'Product 29', 'PARACETAMOL 650 TAB'],
            array_column($rows, 'product_name'),
        );
    }

    /**
     * The reason this is decided over the whole bill rather than line by line.
     *
     * Plenty of medicines are named for their strength or size. Stripping a
     * leading number from "5 ML SYRINGE" changes what was ordered, and it would
     * do so silently — so a number is only a serial when the rows *count*.
     */
    public function test_a_name_that_begins_with_a_number_is_left_alone(): void
    {
        $rows = (new PurchasePdfParser())->parse($this->billPdf(
            "5 ML SYRINGE DISPOSABLE SY221 06/28 100 4.50 7.00\n"
            ."500 MG PARACETAMOL PC9922 03/28 50 12.40 22.00"
        ));

        $this->assertSame(
            ['5 ML SYRINGE DISPOSABLE', '500 MG PARACETAMOL'],
            array_column($rows, 'product_name'),
        );
    }

    /** One row is not a sequence, so nothing is assumed about it. */
    public function test_a_single_row_keeps_its_leading_number(): void
    {
        $rows = (new PurchasePdfParser())->parse($this->billPdf(
            '1 Product 25 BJ4471 08/27 10 25.00 30.00'
        ));

        $this->assertSame('1 Product 25', $rows[0]['product_name']);
    }

    /**
     * A gap keeps the numbers.
     *
     * Erring this way costs a reviewer an edit; erring the other way cuts a
     * strength off a medicine name and nobody notices.
     */
    public function test_numbers_that_do_not_count_are_kept(): void
    {
        $rows = (new PurchasePdfParser())->parse($this->billPdf(
            "1 Product 25 BJ4471 08/27 10 25.00 30.00\n"
            ."3 Product 29 BJ4472 11/27 20 8.50 10.00"
        ));

        $this->assertSame(
            ['1 Product 25', '3 Product 29'],
            array_column($rows, 'product_name'),
        );
    }

    /** The whole point: with the serial gone, the row finds its product. */
    public function test_a_serialled_bill_matches_products_that_exist(): void
    {
        $product = \App\Models\Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Amoxycillin 500mg Cap',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/purchase-imports', [
            'file' => UploadedFile::fake()->createWithContent(
                'bill.pdf',
                $this->billPdf(
                    "1 Amoxycillin 500mg Cap AMX2401 08/27 10 85.50 120.00\n"
                    ."2 Pantoprazole 40mg Tab PT5510 11/26 20 42.75 68.00"
                )
            ),
            'party_id' => $this->supplier->id,
            'source' => 'pdf',
        ]);

        $response->assertStatus(202);

        $line = PurchaseImportBatch::first()->lines()->first();

        $this->assertSame('matched', $line->match_status);
        $this->assertSame($product->id, $line->product_id);
    }
}
