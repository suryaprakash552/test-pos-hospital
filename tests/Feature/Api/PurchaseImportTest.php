<?php

namespace Tests\Feature\Api;

use App\Jobs\ParsePurchaseImportJob;
use App\Models\Business;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Unit;
use App\Models\PurchaseImportBatch;
use App\Models\User;
use App\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bringing a supplier bill in without letting it write stock on its own.
 *
 * The existing bulk upload parses a spreadsheet and creates the purchase inside
 * the job. That cannot be extended to a photographed bill: a misread quantity
 * would become real stock with nobody having seen it. This route stops at a
 * reviewable draft, and the commit goes back through the ordinary purchase
 * endpoint so pricing, GST and stock stay in one place.
 */
class PurchaseImportTest extends TestCase
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

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('bill.csv', $body);
    }

    private function upload(UploadedFile $file, array $overrides = [])
    {
        return $this->actingAs($this->user)->postJson('/api/v1/purchase-imports', array_merge([
            'file' => $file,
            'party_id' => $this->supplier->id,
        ], $overrides));
    }

    // ── Security ────────────────────────────────────────────────────────────

    /**
     * The extension is a claim by whoever uploaded the file, so the bytes are
     * checked too.
     */
    public function test_a_file_pretending_to_be_a_csv_is_refused(): void
    {
        $disguised = UploadedFile::fake()->create('bill.csv', 8, 'application/x-php');

        $this->upload($disguised)
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, PurchaseImportBatch::count());
    }

    public function test_an_oversized_upload_is_refused(): void
    {
        $big = UploadedFile::fake()->create('bill.csv', 6000, 'text/csv');

        $this->upload($big)->assertStatus(422)->assertJsonValidationErrors('file');
    }

    /** A supplier belonging to another shop is not a supplier of this one. */
    public function test_a_supplier_from_another_business_is_refused(): void
    {
        $other = Business::factory()->create();
        $foreign = Party::create([
            'business_id' => $other->id,
            'name' => 'Someone Else',
            'type' => 'Supplier',
            'due' => 0,
        ]);

        $this->upload($this->csv("Item,Qty,Rate\nX,1,10"), ['party_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('party_id');
    }

    /**
     * An import carries a supplier's prices and a shop's stock positions, so
     * guessing an id must not reach one.
     */
    public function test_another_business_cannot_read_an_import(): void
    {
        Bus::fake();
        $this->upload($this->csv("Item,Qty,Rate\nX,1,10"))->assertStatus(202);
        $batch = PurchaseImportBatch::first();

        $outsider = User::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'role' => 'shop-owner',
        ]);

        $this->actingAs($outsider)
            ->getJson("/api/v1/purchase-imports/{$batch->id}")
            ->assertStatus(404);
    }

    public function test_old_internal_failure_text_is_hidden_in_list_and_detail(): void
    {
        Bus::fake();
        $this->upload($this->csv("Item,Qty,Rate\nX,1,10"))->assertStatus(202);
        $batch = PurchaseImportBatch::firstOrFail();
        $batch->update(['status' => 'failed', 'error' => 'SQLSTATE secret at /var/www/app']);

        $this->actingAs($this->user)
            ->getJson('/api/v1/purchase-imports')
            ->assertOk()
            ->assertJsonMissing(['error' => 'SQLSTATE secret at /var/www/app'])
            ->assertJsonPath('data.data.0.error', 'This document could not be imported. Check its format and try again, or use another import method.');

        $this->actingAs($this->user)
            ->getJson("/api/v1/purchase-imports/{$batch->id}")
            ->assertOk()
            ->assertJsonMissing(['error' => 'SQLSTATE secret at /var/www/app']);
    }

    /** The supplied filename is attacker-chosen text and never becomes a path. */
    public function test_the_uploaded_name_is_not_used_as_the_stored_path(): void
    {
        Bus::fake();

        $nasty = UploadedFile::fake()->createWithContent(
            '../../evil.csv',
            "Item,Qty,Rate\nX,1,10"
        );

        $this->upload($nasty)->assertStatus(202);

        $batch = PurchaseImportBatch::first();

        $this->assertStringNotContainsString('..', $batch->path);
        $this->assertStringStartsWith(
            "purchase-imports/{$this->business->id}/",
            $batch->path,
        );
    }

    public function test_an_upload_is_queued_rather_than_parsed_in_the_request(): void
    {
        Bus::fake();

        $this->upload($this->csv("Item,Qty,Rate\nX,1,10"))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued');

        Bus::assertDispatched(ParsePurchaseImportJob::class);
    }

    // ── Parsing ─────────────────────────────────────────────────────────────

    public function test_a_bill_is_parsed_into_reviewable_lines(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Paracetamol 500',
        ]);

        $this->upload($this->csv(
            "Item,Qty,Free,Rate,MRP,Batch,Expiry\n"
            ."Paracetamol 500,10,2,45.50,60,B24A,07/28\n"
        ))->assertStatus(202);

        $batch = PurchaseImportBatch::first()->fresh();

        $this->assertSame('ready', $batch->status);
        $this->assertSame(1, $batch->total_rows);
        $this->assertSame(1, $batch->matched_rows);

        $line = $batch->lines()->first();
        $this->assertSame('matched', $line->match_status);
        $this->assertSame(10.0, (float) $line->quantity);
        $this->assertSame(2.0, (float) $line->free_quantity);
        $this->assertSame(45.50, (float) $line->purchase_price);
        $this->assertSame('B24A', $line->batch_no);
    }

    public function test_numeric_rows_without_a_product_are_not_review_items(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Paracetamol 500',
        ]);

        $this->upload($this->csv(
            "Item,Qty,Rate\nParacetamol 500,2,50\n,1987.94,0\n,0,0\n"
        ))->assertStatus(202);

        $batch = PurchaseImportBatch::first()->fresh();
        $this->assertSame(1, $batch->total_rows);
        $this->assertSame(1, $batch->lines()->count());
        $this->assertSame('Paracetamol 500', $batch->lines()->first()->product_name);
    }

    /**
     * Pharmacy expiry is written `MM/YY` with no day. It is the *end* of that
     * month, not a day inside it.
     */
    public function test_a_short_expiry_becomes_the_end_of_its_month(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Amox',
        ]);

        $this->upload($this->csv("Item,Qty,Rate,Expiry\nAmox,1,10,07/28\n"));

        $this->assertSame(
            '2028-07-31',
            PurchaseImportBatch::first()->lines()->first()->expire_date->toDateString(),
        );
    }

    /** Suppliers write `1,250.00` and `₹45/-`. */
    public function test_money_written_the_way_a_bill_writes_it_is_read(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Amox',
        ]);

        $this->upload($this->csv("Item,Qty,Rate\nAmox,1,\"1,250.00\"\n"));

        $this->assertSame(
            1250.0,
            (float) PurchaseImportBatch::first()->lines()->first()->purchase_price,
        );
    }

    /**
     * A near match is a candidate, never a selection: "Paracetamol 500" and
     * "Paracetamol 650" are one substring apart and are different medicines.
     */
    public function test_a_similar_name_is_offered_but_not_chosen(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Paracetamol 650',
        ]);

        $this->upload($this->csv("Item,Qty,Rate\nParacetamol,1,10\n"));

        $line = PurchaseImportBatch::first()->lines()->first();

        $this->assertSame('ambiguous', $line->match_status);
        $this->assertNull($line->product_id);
        $this->assertStringContainsString('Paracetamol 650', $line->note);
    }

    public function test_an_unknown_product_is_left_for_a_person(): void
    {
        $this->upload($this->csv("Item,Qty,Rate\nNothing We Stock,1,10\n"));

        $line = PurchaseImportBatch::first()->lines()->first();

        $this->assertSame('unmatched', $line->match_status);
        $this->assertNull($line->product_id);
    }

    /** Totals and page breaks are not goods. */
    public function test_rows_with_no_product_are_skipped(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Amox',
        ]);

        $this->upload($this->csv(
            "Item,Qty,Rate\nAmox,1,10\n,,\n\"\",100,\n"
        ));

        $this->assertSame(1, PurchaseImportBatch::first()->total_rows);
    }

    public function test_a_file_with_no_recognisable_columns_fails_with_a_reason(): void
    {
        $this->upload($this->csv("alpha,beta\n1,2\n"));

        $batch = PurchaseImportBatch::first();

        $this->assertSame('failed', $batch->status);
        $this->assertNotNull($batch->error);
    }

    /**
     * The `source` column was `enum('csv','excel','pdf','photo')` while every
     * caller — this controller's own validation, the parse job's `match`, and
     * the app — says `'xlsx'`. MySQL's answer to an enum value it does not
     * recognise is not to reject the insert: it silently substitutes an empty
     * string and raises a truncation warning, which this connection's strict
     * mode turns into a hard exception. So every Excel-invoice upload failed
     * at the very first insert, for every shop, from the day the feature
     * shipped — confirmed on production by `source` never once being `xlsx`
     * despite pdf/photo/csv batches existing.
     */
    public function test_an_excel_workbook_is_accepted(): void
    {
        $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet->getActiveSheet()->fromArray([
            ['Invoice Number', 'Document Date', 'Item', 'Qty', 'Rate'],
            ['PUR-SAMPLE-001', '2026-09-16', 'Amox', 1, 10],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($path);

        $this->upload(new UploadedFile(
            $path,
            'bill.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        ), ['source' => 'xlsx'])->assertStatus(202);

        @unlink($path);

        $batch = PurchaseImportBatch::first()->fresh();
        $this->assertSame('xlsx', $batch->source);
        $this->assertSame('ready', $batch->status);
        $this->assertSame('PUR-SAMPLE-001', $batch->reference_invoice_no);
        $this->assertSame('2026-09-16', $batch->bill_date->toDateString());
        $this->assertSame('Amox', $batch->lines()->first()->product_name);
    }

    /**
     * The document holds a supplier's prices and is not kept once read; `raw`
     * on each line preserves what it said.
     */
    public function test_the_upload_is_discarded_after_parsing(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Amox',
        ]);

        $this->upload($this->csv("Item,Qty,Rate\nAmox,1,10\n"));

        $batch = PurchaseImportBatch::first();

        $this->assertNull($batch->path);
        $this->assertNotEmpty($batch->lines()->first()->raw);
    }

    // ── Review and commit ───────────────────────────────────────────────────

    public function test_choosing_a_product_confirms_the_line(): void
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);

        $this->upload($this->csv("Item,Qty,Rate\nUnknown Thing,5,10\n"));

        $batch = PurchaseImportBatch::first();
        $line = $batch->lines()->first();

        $this->actingAs($this->user)
            ->patchJson("/api/v1/purchase-imports/{$batch->id}/lines/{$line->id}", [
                'product_id' => $product->id,
            ])
            ->assertSuccessful();

        $this->assertSame('confirmed', $line->fresh()->match_status);
    }

    public function test_a_product_from_another_business_cannot_be_chosen(): void
    {
        $foreign = Product::factory()->create([
            'business_id' => Business::factory()->create()->id,
        ]);

        $this->upload($this->csv("Item,Qty,Rate\nUnknown,5,10\n"));
        $batch = PurchaseImportBatch::first();
        $line = $batch->lines()->first();

        $this->actingAs($this->user)
            ->patchJson("/api/v1/purchase-imports/{$batch->id}/lines/{$line->id}", [
                'product_id' => $foreign->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
    }

    /**
     * Conversion comes from the shop's own product record, not the bill: how
     * many tablets are in a strip is what the till sells against, and a
     * supplier's sheet does not get to change it.
     */
    public function test_the_commit_payload_takes_conversion_from_the_product(): void
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Amox',
            'conversionValue' => 10,
        ]);

        $this->upload($this->csv("Item,Qty,Free,Rate,MRP\nAmox,3,1,100,150\n"));

        $batch = PurchaseImportBatch::first();

        $payload = $this->actingAs($this->user)
            ->getJson("/api/v1/purchase-imports/{$batch->id}/commit-payload")
            ->assertSuccessful()
            ->json('data');

        $line = $payload['products'][0];

        $this->assertSame($product->id, $line['product_id']);
        $this->assertSame(10.0, (float) $line['conversion_value']);
        // 3 paid + 1 free, ten to a strip.
        $this->assertSame(40.0, (float) $line['conversion_quantities']);
        $this->assertSame(10.0, (float) $line['conversion_purchase_price']);
    }

    public function test_the_commit_payload_preserves_the_reviewed_line_discount(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Amox',
        ]);

        $this->upload($this->csv("Item,Qty,Rate,Discount %\nAmox,2,100,12.5\n"));

        $payload = $this->actingAs($this->user)
            ->getJson('/api/v1/purchase-imports/'.PurchaseImportBatch::first()->id.'/commit-payload')
            ->assertSuccessful()
            ->json('data');

        $this->assertSame(12.5, (float) $payload['products'][0]['discount_percent']);
    }

    public function test_purchase_import_reports_inventory_tax_adjustment_without_blocking(): void
    {
        $vat = Vat::create([
            'business_id' => $this->business->id,
            'name' => 'GST 5%',
            'rate' => 5,
            'status' => true,
        ]);
        Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Amox',
            'vat_id' => $vat->id,
        ]);
        $this->upload($this->csv("Item,Qty,Rate,Tax %\nAmox,2,100,12\n"));

        $this->actingAs($this->user)
            ->getJson('/api/v1/purchase-imports/'.PurchaseImportBatch::first()->id.'/commit-payload')
            ->assertOk()
            ->assertJsonPath('data.tax_adjustments.0.document_tax_percent', 12)
            ->assertJsonPath('data.tax_adjustments.0.inventory_tax_percent', 5);
    }

    public function test_an_unmatched_line_is_left_out_of_the_payload(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Amox',
        ]);

        $this->upload($this->csv("Item,Qty,Rate\nAmox,1,10\nGhost,2,20\n"));

        $payload = $this->actingAs($this->user)
            ->getJson('/api/v1/purchase-imports/'.PurchaseImportBatch::first()->id.'/commit-payload')
            ->assertSuccessful()
            ->json('data');

        $this->assertCount(1, $payload['products']);
        $this->assertSame(1, $payload['skipped']);
    }

    public function test_a_batch_with_nothing_matched_cannot_be_committed(): void
    {
        $this->upload($this->csv("Item,Qty,Rate\nGhost,2,20\n"));

        $this->actingAs($this->user)
            ->getJson('/api/v1/purchase-imports/'.PurchaseImportBatch::first()->id.'/commit-payload')
            ->assertStatus(422)
            ->assertJsonPath('reason', 'nothing_to_commit');
    }

    /** A delivery entered twice is stock the shop does not have. */
    public function test_a_committed_import_cannot_be_committed_again(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Amox',
        ]);

        $this->upload($this->csv("Item,Qty,Rate\nAmox,1,10\n"));
        $batch = PurchaseImportBatch::first();
        $batch->update(['status' => 'committed', 'purchase_id' => null]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/purchase-imports/{$batch->id}/commit-payload")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'already_committed');
    }

    /**
     * The payload has to be one `/purchase` will actually accept.
     *
     * Caught on the device: the commit passed validation and then answered 500,
     * because an imported bill was being posted with no payment information at
     * all. A purchase has to say how it was settled, even when the answer is
     * "not yet".
     */
    public function test_the_commit_payload_is_accepted_by_the_purchase_endpoint(): void
    {
        $unit = Unit::factory()->create(['business_id' => $this->business->id]);

        Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Amox',
            'conversionValue' => 10,
            'productUpperUnit' => $unit->id,
            'productLowerUnit' => $unit->id,
        ]);

        $this->upload($this->csv("Item,Qty,Rate,MRP\nAmox,3,100,150\n"));

        $payload = $this->actingAs($this->user)
            ->getJson('/api/v1/purchase-imports/'.PurchaseImportBatch::first()->id.'/commit-payload')
            ->assertSuccessful()
            ->json('data');

        // The unit has to be an id, not a serialised relation.
        $this->assertSame($unit->id, $payload['products'][0]['upper_selected_unit']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/purchase', $payload)
            ->assertSuccessful();
        $detail = \App\Models\PurchaseDetails::firstOrFail();
        $this->assertNotNull($detail->tax_rate_snapshot);
        $this->assertNotNull($detail->tax_inclusive_snapshot);
    }

    /**
     * An import and its purchase are closed together, in one transaction.
     *
     * The app used to create the purchase and then call `markCommitted` as a
     * second request. Between the two the stock had already moved while the
     * batch still read `ready`, so a dropped connection, a killed app or a
     * failed second call left an import that could be committed again —
     * doubling the stock, silently, on real inventory.
     */
    public function test_committing_an_import_closes_its_batch_atomically(): void
    {
        $payload = $this->readyPayload();
        $batchId = PurchaseImportBatch::first()->id;

        $this->actingAs($this->user)
            ->postJson('/api/v1/purchase', $payload + ['import_batch_id' => $batchId])
            ->assertSuccessful();

        $batch = PurchaseImportBatch::find($batchId);

        $this->assertSame('committed', $batch->status);
        $this->assertNotNull(
            $batch->purchase_id,
            'the batch records the purchase it became',
        );
    }

    public function test_a_failed_import_with_partial_lines_cannot_create_a_purchase(): void
    {
        $payload = $this->readyPayload();
        $batch = PurchaseImportBatch::firstOrFail();
        $batch->update(['status' => 'failed']);
        $before = Purchase::count();

        $this->actingAs($this->user)
            ->getJson("/api/v1/purchase-imports/{$batch->id}/commit-payload")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'import_not_ready');

        $this->actingAs($this->user)
            ->postJson('/api/v1/purchase', $payload + ['import_batch_id' => $batch->id])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'import_not_ready');

        $this->assertSame($before, Purchase::count());
        $this->assertSame('failed', $batch->fresh()->status);
    }

    /** A lost-response retry recovers the original purchase without duplicating stock. */
    public function test_the_same_import_cannot_be_committed_twice(): void
    {
        $payload = $this->readyPayload();
        $batchId = PurchaseImportBatch::first()->id;

        $first = $this->actingAs($this->user)
            ->postJson('/api/v1/purchase', $payload + ['import_batch_id' => $batchId])
            ->assertSuccessful()
            ->json('data.id');

        $purchasesAfterFirst = Purchase::count();

        $this->actingAs($this->user)
            ->postJson('/api/v1/purchase', $payload + ['import_batch_id' => $batchId])
            ->assertSuccessful()
            ->assertJsonPath('reason', 'already_committed')
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('data.id', $first);

        $this->assertSame(
            $purchasesAfterFirst,
            Purchase::count(),
            'the refused attempt must not leave a purchase behind',
        );
    }

    /** Another business cannot claim this business's batch. */
    public function test_a_batch_from_another_business_is_refused(): void
    {
        $payload = $this->readyPayload();
        $batchId = PurchaseImportBatch::first()->id;

        PurchaseImportBatch::whereKey($batchId)->update([
            'business_id' => Business::factory()->create()->id,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/purchase', $payload + ['import_batch_id' => $batchId])
            ->assertStatus(422);
    }

    /** A purchase with no import behind it is unaffected. */
    public function test_an_ordinary_purchase_needs_no_batch(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/purchase', $this->readyPayload())
            ->assertSuccessful();
    }

    /** A parsed, matched batch and the payload `/purchase` accepts for it. */
    private function readyPayload(): array
    {
        $unit = Unit::factory()->create(['business_id' => $this->business->id]);

        Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Amox',
            'conversionValue' => 10,
            'productUpperUnit' => $unit->id,
            'productLowerUnit' => $unit->id,
        ]);

        $this->upload($this->csv("Item,Qty,Rate,MRP\nAmox,3,100,150\n"));

        return $this->actingAs($this->user)
            ->getJson('/api/v1/purchase-imports/'.PurchaseImportBatch::first()->id.'/commit-payload')
            ->assertSuccessful()
            ->json('data');
    }

    public function test_a_committed_import_can_no_longer_be_edited(): void
    {
        Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Amox',
        ]);

        $this->upload($this->csv("Item,Qty,Rate\nAmox,1,10\n"));
        $batch = PurchaseImportBatch::first();
        $line = $batch->lines()->first();
        $batch->update(['status' => 'committed']);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/purchase-imports/{$batch->id}/lines/{$line->id}", [
                'quantity' => 999,
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'not_editable');

        $this->assertSame(1.0, (float) $line->fresh()->quantity);
    }
}
