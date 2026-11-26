<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Category;
use App\Models\Party;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What a stock audit actually moves.
 *
 * Reported from the field as two symptoms that turned out to be one cause: after
 * counting a batch, the product's stock was unchanged everywhere (inventory,
 * sales products, purchase products), and the audit report's quantity, amount,
 * profit and loss were wrong.
 *
 * The submit wrote only `purchase_details.conversionQuantity` and never
 * `stocks.productStock` — the column sales decrement and every picker reads. And
 * because `previous_quantity` is read from `productStock`, the *next* audit
 * measured its difference against a baseline that had never moved, so the report
 * compounded the error.
 */
class StockAuditSubmitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Business $business;
    private Party $party;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'owner',
        ]);
        $this->category = Category::factory()->create(['business_id' => $this->business->id]);
        $this->party = Party::create([
            'name'        => 'Supplier',
            'business_id' => $this->business->id,
            'type'        => 'Supplier',
            'phone'       => '9000000888',
        ]);

        $this->actingAs($this->user);
    }

    private function product(string $code): Product
    {
        return Product::create([
            'productName'  => 'Item ' . $code,
            'business_id'  => $this->business->id,
            'category_id'  => $this->category->id,
            'productCode'  => $code,
            'productStock' => 0,
        ]);
    }

    /** Total sellable units recorded against the batch's purchase lines. */
    private function conversionQuantity(int $stockId): float
    {
        return (float) DB::table('purchase_details')
            ->where('stock_id', $stockId)
            ->sum('conversionQuantity');
    }

    private function buy(Product $product, int $quantity, string $batch = 'B-1', int $conversion = 1): void
    {
        $this->postJson('/api/v1/purchase', [
            'party_id'    => $this->party->id,
            'paidAmount'  => 0,
            'dueAmount'   => 0,
            'totalAmount' => 100,
            'paymentType' => 'Cash',
            'products'    => [[
                'product_id'                => $product->id,
                'batch_no'                  => $batch,
                'quantities'                => $quantity,
                'conversion_quantities'     => $quantity * $conversion,
                'conversion_value'          => $conversion,
                'productSalePrice'          => 30,
                'productPurchasePrice'      => 20,
                'conversion_purchase_price' => 20,
                'conversion_price'          => 30,
                'total_purchase_price'      => 20 * $quantity,
                'total_price'               => 30 * $quantity,
                'expire_date'               => '2030-01-01',
                'mfg_date'                  => '2026-01-01',
                'upper_selected_unit'       => 1,
                'lower_selected_unit'       => 1,
            ]],
        ])->assertOk();
    }

    private function stockIdFor(Product $product, string $batch = 'B-1'): int
    {
        return (int) DB::table('stocks')
            ->where('product_id', $product->id)
            ->where('batch_no', $batch)
            ->value('id');
    }

    private function stockQuantity(int $stockId): float
    {
        return (float) DB::table('stocks')->where('id', $stockId)->value('productStock');
    }

    private function audit(int $stockId, float $counted)
    {
        return $this->postJson('/api/v1/stock-audit-submit', [
            'items' => [[
                'stock_id'         => $stockId,
                'updated_quantity' => $counted,
                'remarks'          => 'Counted on the shelf',
            ]],
        ]);
    }

    public function test_the_picker_excludes_batches_with_no_current_stock(): void
    {
        $product = $this->product('SA-ZERO');
        $this->buy($product, 10, 'AVAILABLE');
        $this->buy($product, 5, 'SOLD-OUT');

        $soldOutId = $this->stockIdFor($product, 'SOLD-OUT');
        $this->audit($soldOutId, 0)->assertOk();

        $response = $this->getJson('/api/v1/stock-audit')->assertOk();
        $row = collect($response->json('data.data'))
            ->firstWhere('id', $product->id);

        $this->assertNotNull($row);
        $this->assertSame(
            ['AVAILABLE'],
            collect($row['batches'])->pluck('batch_no')->values()->all()
        );
        $this->assertSame(10.0, (float) $row['batches'][0]['available_quantity']);
    }

    public function test_counting_short_reduces_the_batch_quantity(): void
    {
        $product = $this->product('SA-1');
        $this->buy($product, 10);
        $stockId = $this->stockIdFor($product);

        $this->assertSame(10.0, $this->stockQuantity($stockId));

        $this->audit($stockId, 7)->assertOk();

        // The defect: this stayed at 10, so every screen kept showing stock the
        // shop did not have.
        $this->assertSame(7.0, $this->stockQuantity($stockId));
    }

    public function test_counting_over_raises_the_batch_quantity(): void
    {
        $product = $this->product('SA-2');
        $this->buy($product, 10);
        $stockId = $this->stockIdFor($product);

        $this->audit($stockId, 12)->assertOk();

        $this->assertSame(12.0, $this->stockQuantity($stockId));
    }

    public function test_the_audit_row_records_the_movement_it_made(): void
    {
        $product = $this->product('SA-3');
        $this->buy($product, 10);
        $stockId = $this->stockIdFor($product);

        $this->audit($stockId, 6)->assertOk();

        $audit = DB::table('stock_audits')->where('stock_id', $stockId)->first();

        $this->assertSame(10.0, (float) $audit->previous_quantity);
        $this->assertSame(6.0, (float) $audit->updated_quantity);
        $this->assertSame(-4.0, (float) $audit->difference_quantity);
        // Four units short at the batch's selling price - the same figure the
        // sales sheet quotes for it.
        $this->assertSame(-120.0, (float) $audit->difference_amount);
    }

    public function test_a_second_audit_measures_against_the_first(): void
    {
        // The compounding failure. With `productStock` never written, the second
        // audit's `previous_quantity` was still the original 10, so it reported a
        // loss of 4 again instead of a further 1.
        $product = $this->product('SA-4');
        $this->buy($product, 10);
        $stockId = $this->stockIdFor($product);

        $this->audit($stockId, 6)->assertOk();
        $this->audit($stockId, 5)->assertOk();

        $second = DB::table('stock_audits')
            ->where('stock_id', $stockId)
            ->orderByDesc('id')
            ->first();

        $this->assertSame(6.0, (float) $second->previous_quantity);
        $this->assertSame(5.0, (float) $second->updated_quantity);
        $this->assertSame(-1.0, (float) $second->difference_quantity);
        $this->assertSame(-30.0, (float) $second->difference_amount);

        $this->assertSame(5.0, $this->stockQuantity($stockId));
    }

    public function test_counting_to_zero_empties_the_batch(): void
    {
        $product = $this->product('SA-5');
        $this->buy($product, 4);
        $stockId = $this->stockIdFor($product);

        $this->audit($stockId, 0)->assertOk();

        $this->assertSame(0.0, $this->stockQuantity($stockId));
    }

    public function test_a_negative_count_is_refused_and_moves_nothing(): void
    {
        $product = $this->product('SA-6');
        $this->buy($product, 8);
        $stockId = $this->stockIdFor($product);

        $this->audit($stockId, -3)->assertUnprocessable();

        // The whole submit is transactional, so a refused item leaves the batch
        // exactly as it was.
        $this->assertSame(8.0, $this->stockQuantity($stockId));
        $this->assertDatabaseCount('stock_audits', 0);
    }

    public function test_each_submit_gets_a_distinct_reference_and_report_can_filter_it(): void
    {
        $product = $this->product('SA-REF');
        $product->update(['rack' => '1']);
        $this->buy($product, 10);
        $stockId = $this->stockIdFor($product);

        $first = $this->audit($stockId, 9)->assertOk();
        $second = $this->audit($stockId, 8)->assertOk();

        $firstRef = $first->json('audit_reference');
        $secondRef = $second->json('audit_reference');
        $this->assertNotEmpty($firstRef);
        $this->assertNotSame($firstRef, $secondRef);

        $report = $this->getJson('/api/v1/stock-audit-report?audit_reference='.$secondRef)
            ->assertOk();
        $report->assertJsonCount(1, 'data.data');
        $this->assertSame($secondRef, $report->json('data.data.0.audit_reference'));
    }

    public function test_rack_filter_is_exact_and_does_not_mix_similar_racks(): void
    {
        $rackOne = $this->product('SA-RACK-1');
        $rackOne->update(['rack' => '1']);
        $rackTen = $this->product('SA-RACK-10');
        $rackTen->update(['rack' => '10']);
        $this->buy($rackOne, 5, 'R1');
        $this->buy($rackTen, 5, 'R10');

        $response = $this->getJson('/api/v1/stock-audit?rack=1')->assertOk();
        $this->assertSame(
            [$rackOne->id],
            collect($response->json('data.data'))->pluck('id')->all()
        );
    }

    public function test_the_sellable_units_follow_the_counted_quantity(): void
    {
        // A batch held in strips of ten, counted in tablets - the figure
        // product details shows batch-wise and the one the shop counts by.
        //
        // Counting 70 tablets must leave 70 tablets against the lots and 7
        // strips on the redundant tally the pickers still read.
        $product = $this->product('SA-8');
        $this->buy($product, 10, 'B-1', 10);
        $stockId = $this->stockIdFor($product);

        $this->assertSame(10.0, $this->stockQuantity($stockId));
        $this->assertSame(100.0, $this->conversionQuantity($stockId));

        $this->audit($stockId, 70)->assertOk();

        $this->assertSame(7.0, $this->stockQuantity($stockId));
        $this->assertSame(70.0, $this->conversionQuantity($stockId));
    }

    public function test_counting_up_a_converted_batch_adds_sellable_units(): void
    {
        $product = $this->product('SA-9');
        $this->buy($product, 4, 'B-1', 6);
        $stockId = $this->stockIdFor($product);

        $this->assertSame(24.0, $this->conversionQuantity($stockId));

        $this->audit($stockId, 30)->assertOk();

        $this->assertSame(5.0, $this->stockQuantity($stockId));
        $this->assertSame(30.0, $this->conversionQuantity($stockId));
    }

    public function test_another_business_batch_cannot_be_audited(): void
    {
        $product = $this->product('SA-7');
        $this->buy($product, 5);
        $stockId = $this->stockIdFor($product);

        $otherBusiness = Business::factory()->create();
        $intruder = User::factory()->create([
            'business_id' => $otherBusiness->id,
            'role'        => 'owner',
        ]);

        $this->actingAs($intruder)->audit($stockId, 1);

        $this->assertSame(5.0, $this->stockQuantity($stockId));
    }

    /**
     * Phantom batches must not be offered for counting.
     *
     * 37 rows on the live database claim a `productStock` with no purchase lot
     * behind them at all. They surfaced in the audit with no batch number and
     * no price, absent from every other screen, and counting one booked a
     * shrinkage loss against stock the ledger never held.
     */
    public function test_a_batch_with_no_purchase_lots_is_not_auditable(): void
    {
        $product = $this->product('SA-PHANTOM');
        $this->buy($product, 10, 'REAL');

        // A stock row claiming quantity with nothing behind it.
        DB::table('stocks')->insert([
            'product_id'   => $product->id,
            'business_id'  => $this->business->id,
            'batch_no'     => null,
            'productStock' => 200,
            'expire_date'  => '2030-01-01',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $response = $this->getJson('/api/v1/stock-audit')->assertOk();
        $row = collect($response->json('data.data'))->firstWhere('id', $product->id);

        $this->assertNotNull($row);
        $this->assertSame(
            ['REAL'],
            collect($row['batches'])->pluck('batch_no')->values()->all(),
            'a stock row with no lots is not stock anyone can count'
        );
    }

    /**
     * The rate shown is the rate the variance is booked at.
     *
     * The list quoted `purchase_details.conversionPrice` - the per-sellable-unit
     * rate - beside a quantity of whole stock units, while the submit booked the
     * difference at `stocks.productPurchasePrice`. The profit and loss on screen
     * could not match the loss recorded.
     */
    public function test_the_displayed_rate_matches_the_rate_the_audit_books(): void
    {
        $product = $this->product('SA-RATE');
        $this->buy($product, 10, 'RATE', conversion: 3);

        $stockId = $this->stockIdFor($product, 'RATE');

        $response = $this->getJson('/api/v1/stock-audit')->assertOk();
        $row = collect($response->json('data.data'))->firstWhere('id', $product->id);
        $batch = collect($row['batches'])->firstWhere('stock_id', $stockId);

        $shown = (float) $batch['price_per_unit'];

        $this->audit($stockId, 8)->assertOk();

        $booked = (float) DB::table('stock_audits')
            ->where('stock_id', $stockId)
            ->value('price_per_unit');

        $this->assertSame($shown, $booked);
    }

    /**
     * The baseline shown is the baseline written back.
     *
     * `productStock` is a redundant tally that drifts. The write-back always
     * reconciled against the lots, so taking `previous_quantity` from the tally
     * recorded a variance that did not match the stock actually moved.
     */
    public function test_the_recorded_variance_matches_the_stock_actually_moved(): void
    {
        $product = $this->product('SA-DRIFT');
        $this->buy($product, 10, 'DRIFT', conversion: 3);

        $stockId = $this->stockIdFor($product, 'DRIFT');

        // Drift the redundant tally away from the lots.
        DB::table('stocks')->where('id', $stockId)->update(['productStock' => 99]);

        $this->audit($stockId, 21)->assertOk();

        $audit = DB::table('stock_audits')->where('stock_id', $stockId)->first();

        $this->assertSame(30.0, (float) $audit->previous_quantity);
        $this->assertSame(-9.0, (float) $audit->difference_quantity);
        $this->assertSame(21.0, $this->conversionQuantity($stockId));
    }

    /**
     * Both grains are published, so the count is not taken in the wrong unit.
     *
     * Billing and product details report sellable units; the audit counts whole
     * stock units. The same batch therefore reads 10 here and 30 there, which
     * looks like a discrepancy unless both are stated.
     */
    public function test_the_batch_is_published_in_sellable_units(): void
    {
        $product = $this->product('SA-UNITS');
        $this->buy($product, 10, 'UNITS', conversion: 3);

        $response = $this->getJson('/api/v1/stock-audit')->assertOk();
        $row = collect($response->json('data.data'))->firstWhere('id', $product->id);
        $batch = collect($row['batches'])->firstWhere('batch_no', 'UNITS');

        // 10 strips of 3 is 30 tablets, and 30 is what product details shows.
        $this->assertSame(30.0, (float) $batch['available_quantity']);
        $this->assertSame(10.0, (float) $batch['available_upper_quantity']);
        $this->assertSame(3.0, (float) $batch['conversion_value']);
    }


    /**
     * What was counted is what inventory then holds.
     *
     * The count, the batch and the inventory list are all in sellable units, so
     * the number typed into Physical Qty is the number the product then shows -
     * no conversion in between to round or scale it away.
     */
    public function test_inventory_reports_exactly_the_quantity_counted(): void
    {
        // Ten strips of five: fifty tablets on the shelf.
        $product = $this->product('SA-INV');
        $this->buy($product, 10, 'B-1', 5);
        $stockId = $this->stockIdFor($product);

        $this->assertSame(50.0, $this->conversionQuantity($stockId));

        // Counted short: 37 tablets.
        $this->audit($stockId, 37)->assertOk();

        $this->assertSame(37.0, $this->conversionQuantity($stockId));

        $response = $this->getJson('/api/v1/stocks')->assertOk();
        $row = collect($response->json('data.data'))->firstWhere('id', $product->id);

        $this->assertNotNull($row, 'the audited product must still be listed');
        $this->assertSame(
            37.0,
            (float) collect($row['stocks'])
                ->firstWhere('id', $stockId)['available_quantity'],
            'inventory must show the counted figure, not a converted one'
        );

        // And the audit list agrees, so a recount starts from what was left.
        $audit = $this->getJson('/api/v1/stock-audit')->assertOk();
        $batch = collect(
            collect($audit->json('data.data'))->firstWhere('id', $product->id)['batches']
        )->firstWhere('stock_id', $stockId);

        $this->assertSame(37.0, (float) $batch['available_quantity']);
    }


    /**
     * The audit quotes the same price the sales sheet does.
     *
     * Reported from the field: a batch offered at 115.17 per piece in sales
     * showed a different figure in the audit, because the audit valued stock at
     * cost while sales quoted the selling price. Both now read the batch's own
     * `conversionPrice`.
     */
    public function test_the_audit_and_the_sales_sheet_quote_one_price(): void
    {
        $product = $this->product('SA-PRICE');
        $this->buy($product, 10, 'B-1', 3);
        $stockId = $this->stockIdFor($product);

        $audit = $this->getJson('/api/v1/stock-audit')->assertOk();
        $batch = collect(
            collect($audit->json('data.data'))->firstWhere('id', $product->id)['batches']
        )->firstWhere('stock_id', $stockId);

        $sales = (float) DB::table('purchase_details')
            ->where('stock_id', $stockId)
            ->orderBy('id')
            ->value('conversionPrice');

        $this->assertSame($sales, (float) $batch['price_per_unit']);
    }

}
