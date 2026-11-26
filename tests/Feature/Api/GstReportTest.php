<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The GST position for a period: tax collected on sales, tax paid on purchases, and
 * the difference that gets filed.
 *
 * Figures come from the `vat_amount` / `vat_percent` columns recorded on each
 * transaction, not from the product's current tax settings — so changing a rate today
 * must not rewrite what last quarter's return says.
 */
class GstReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);
    }

    /** A branch row, inserted directly: the model's hooks want an authed user. */
    private function branch(string $name): int
    {
        return DB::table('branches')->insertGetId([
            'business_id' => $this->business->id,
            'name'        => $name,
            'status'      => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function sale(float $total, float $tax, float $rate, string $date, ?int $branchId = null): int
    {
        return DB::table('sales')->insertGetId([
            'business_id' => $this->business->id,
            'branch_id'   => $branchId,
            'invoiceNumber' => 'S-' . uniqid(),
            'totalAmount' => $total,
            'vat_amount'  => $tax,
            'vat_percent' => $rate,
            'saleDate'    => $date,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function purchase(float $total, float $tax, float $rate, string $date, ?int $branchId = null): int
    {
        return DB::table('purchases')->insertGetId([
            'business_id'   => $this->business->id,
            'branch_id'     => $branchId,
            'invoiceNumber' => 'P-' . uniqid(),
            'totalAmount'   => $total,
            'vat_amount'    => $tax,
            'vat_percent'   => $rate,
            'purchaseDate'  => $date,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function report(array $query = [])
    {
        return $this->actingAs($this->user)
            ->getJson('/api/v1/gst-report?' . http_build_query($query));
    }

    public function test_it_reports_output_input_and_the_net_position(): void
    {
        $this->sale(total: 1180, tax: 180, rate: 18, date: '2026-07-10');
        $this->purchase(total: 590, tax: 90, rate: 18, date: '2026-07-11');

        $this->report(['from_date' => '2026-07-01', 'to_date' => '2026-07-31'])
            ->assertStatus(200)
            ->assertJsonPath('output_tax', 180)
            ->assertJsonPath('input_tax', 90)
            ->assertJsonPath('net_tax_payable', 90);
    }

    /**
     * Taxable value is the total less the tax, which holds for inclusive and
     * exclusive alike.
     */
    public function test_taxable_value_excludes_the_tax(): void
    {
        $this->sale(total: 1180, tax: 180, rate: 18, date: '2026-07-10');

        $this->report(['from_date' => '2026-07-01', 'to_date' => '2026-07-31'])
            ->assertJsonPath('taxable_sales', 1000)
            ->assertJsonPath('total_sales', 1180);
    }

    /**
     * More input than output means a credit, not a payment.
     */
    public function test_a_credit_position_is_reported_as_negative(): void
    {
        $this->sale(total: 118, tax: 18, rate: 18, date: '2026-07-10');
        $this->purchase(total: 1180, tax: 180, rate: 18, date: '2026-07-10');

        $this->report(['from_date' => '2026-07-01', 'to_date' => '2026-07-31'])
            ->assertJsonPath('net_tax_payable', -162);
    }

    public function test_transactions_outside_the_period_are_excluded(): void
    {
        $this->sale(total: 1180, tax: 180, rate: 18, date: '2026-06-30');
        $this->sale(total: 236, tax: 36, rate: 18, date: '2026-07-15');
        $this->sale(total: 118, tax: 18, rate: 18, date: '2026-08-01');

        $this->report(['from_date' => '2026-07-01', 'to_date' => '2026-07-31'])
            ->assertJsonPath('output_tax', 36)
            ->assertJsonPath('sales_count', 1);
    }

    public function test_the_period_boundaries_are_inclusive(): void
    {
        $this->sale(total: 118, tax: 18, rate: 18, date: '2026-07-01');
        $this->sale(total: 118, tax: 18, rate: 18, date: '2026-07-31');

        $this->report(['from_date' => '2026-07-01', 'to_date' => '2026-07-31'])
            ->assertJsonPath('sales_count', 2)
            ->assertJsonPath('output_tax', 36);
    }

    public function test_it_breaks_the_period_down_by_rate(): void
    {
        $this->sale(total: 1180, tax: 180, rate: 18, date: '2026-07-10');
        $this->sale(total: 1050, tax: 50, rate: 5, date: '2026-07-11');
        $this->purchase(total: 590, tax: 90, rate: 18, date: '2026-07-12');

        $response = $this->report(['from_date' => '2026-07-01', 'to_date' => '2026-07-31'])
            ->assertStatus(200);

        // Sorted ascending by rate, so 5% comes first.
        $response->assertJsonPath('rate_summary.0.rate', 5)
            ->assertJsonPath('rate_summary.0.output_tax', 50)
            ->assertJsonPath('rate_summary.0.input_tax', 0)
            ->assertJsonPath('rate_summary.1.rate', 18)
            ->assertJsonPath('rate_summary.1.output_tax', 180)
            ->assertJsonPath('rate_summary.1.input_tax', 90)
            ->assertJsonPath('rate_summary.1.net_tax_payable', 90);
    }

    public function test_it_can_be_filtered_to_a_single_rate(): void
    {
        $this->sale(total: 1180, tax: 180, rate: 18, date: '2026-07-10');
        $this->sale(total: 1050, tax: 50, rate: 5, date: '2026-07-11');

        $this->report([
            'from_date' => '2026-07-01',
            'to_date'   => '2026-07-31',
            'rate'      => 5,
        ])
            ->assertJsonPath('output_tax', 50)
            ->assertJsonPath('sales_count', 1)
            ->assertJsonPath('filters.rate', '5');
    }

    public function test_it_can_be_filtered_by_gst_type(): void
    {
        $inclusiveProduct = Product::create([
            'productName' => 'Inclusive item',
            'business_id' => $this->business->id,
            'vat_type'    => 'inclusive',
        ]);
        $exclusiveProduct = Product::create([
            'productName' => 'Exclusive item',
            'business_id' => $this->business->id,
            'vat_type'    => 'exclusive',
        ]);

        $inclusiveSale = $this->sale(total: 118, tax: 18, rate: 18, date: '2026-07-10');
        $exclusiveSale = $this->sale(total: 236, tax: 36, rate: 18, date: '2026-07-11');

        DB::table('sale_details')->insert([
            ['sale_id' => $inclusiveSale, 'product_id' => $inclusiveProduct->id, 'quantities' => 1, 'price' => 100],
            ['sale_id' => $exclusiveSale, 'product_id' => $exclusiveProduct->id, 'quantities' => 1, 'price' => 200],
        ]);

        $this->report([
            'from_date' => '2026-07-01',
            'to_date'   => '2026-07-31',
            'gst_type'  => 'inclusive',
        ])
            ->assertJsonPath('output_tax', 18)
            ->assertJsonPath('sales_count', 1);
    }

    public function test_another_businesses_transactions_are_never_included(): void
    {
        $this->sale(total: 118, tax: 18, rate: 18, date: '2026-07-10');

        $otherBusiness = Business::factory()->create();
        DB::table('sales')->insert([
            'business_id' => $otherBusiness->id,
            'totalAmount' => 10000,
            'vat_amount'  => 9999,
            'vat_percent' => 18,
            'saleDate'    => '2026-07-10',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->report(['from_date' => '2026-07-01', 'to_date' => '2026-07-31'])
            ->assertJsonPath('output_tax', 18);
    }

    public function test_an_empty_period_reports_zeroes_rather_than_failing(): void
    {
        $this->report(['from_date' => '2026-07-01', 'to_date' => '2026-07-31'])
            ->assertStatus(200)
            ->assertJsonPath('output_tax', 0)
            ->assertJsonPath('input_tax', 0)
            ->assertJsonPath('net_tax_payable', 0)
            ->assertJsonPath('rate_summary', []);
    }

    public function test_an_inverted_date_range_is_rejected(): void
    {
        $this->report(['from_date' => '2026-07-31', 'to_date' => '2026-07-01'])
            ->assertStatus(422);
    }

    public function test_an_unknown_gst_type_is_rejected(): void
    {
        $this->report(['gst_type' => 'sometimes'])->assertStatus(422);
    }

    /**
     * The mobile client raises per_page to 500 when it walks the whole period
     * to build a PDF or Excel export. A cap of 100 rejected every export with a
     * 422 and the report screen showed "something went wrong".
     */
    public function test_the_export_page_size_is_accepted(): void
    {
        $this->report(['per_page' => 500])->assertStatus(200);
        $this->report(['per_page' => 501])->assertStatus(422);
    }

    public function test_it_defaults_to_the_current_month(): void
    {
        $this->report()
            ->assertStatus(200)
            ->assertJsonPath('from_date', now()->startOfMonth()->toDateString())
            ->assertJsonPath('to_date', now()->toDateString());
    }

    public function test_each_row_carries_what_a_return_needs(): void
    {
        $this->sale(total: 1180, tax: 180, rate: 18, date: '2026-07-10');

        $this->report(['from_date' => '2026-07-01', 'to_date' => '2026-07-31'])
            ->assertJsonStructure([
                'data' => [
                    'sales' => [
                        '*' => [
                            'invoice_number', 'date', 'rate',
                            'taxable_value', 'tax_amount', 'total_amount',
                        ],
                    ],
                ],
            ]);
    }

    // ── Chain scale ──────────────────────────────────────────────────────────

    /**
     * A month of trading at a busy store is tens of thousands of invoices. The
     * report has to stay answerable at that size — which means the invoice lines
     * are paged while the figures a return is filed from are not.
     */
    public function test_the_invoice_lines_are_paged(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->sale(118, 18, 18, now()->toDateString());
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/gst-report?per_page=20');

        $response->assertOk();
        $this->assertCount(20, $response->json('data.sales'));
        $this->assertSame(3, $response->json('meta.sales_last_page'));
    }

    /**
     * The figures must describe the whole period, not the page being looked at.
     * A return filed from one page of a report would understate the liability.
     */
    public function test_the_totals_cover_the_period_not_the_page(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->sale(118, 18, 18, now()->toDateString());
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/gst-report?per_page=5');

        $response->assertOk();
        $this->assertSame(60, $response->json('sales_count'));
        $this->assertEquals(1080, $response->json('output_tax'));
        $this->assertEquals(6000, $response->json('taxable_sales'));
        $this->assertEquals(7080, $response->json('total_sales'));
    }

    /** Rate-wise filing totals are grouped over the period, for the same reason. */
    public function test_the_rate_summary_covers_the_period(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->sale(118, 18, 18, now()->toDateString());
            $this->sale(105, 5, 5, now()->toDateString());
        }
        for ($i = 0; $i < 10; $i++) {
            $this->purchase(118, 18, 18, now()->toDateString());
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/gst-report?per_page=5');

        $response->assertOk();

        $summary = collect($response->json('rate_summary'))->keyBy('rate');

        $this->assertEquals(540, $summary[18]['output_tax']);
        $this->assertEquals(180, $summary[18]['input_tax']);
        $this->assertEquals(360, $summary[18]['net_tax_payable']);
        $this->assertEquals(150, $summary[5]['output_tax']);
        $this->assertEquals(0, $summary[5]['input_tax']);
    }

    /** Reading a period must not cost a query per invoice. */
    public function test_the_report_does_not_scale_its_queries_with_the_invoices(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->sale(118, 18, 18, now()->toDateString());
            $this->purchase(118, 18, 18, now()->toDateString());
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->user)->getJson('/api/v1/gst-report?per_page=50')->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        $this->assertLessThan(
            25,
            $queries,
            'the period is summed by the database, not folded row by row',
        );
    }

    /**
     * §21 lists the branch as both a column and a search filter.
     *
     * Without it a multi-branch business cannot tell which branch an invoice was
     * billed from - which is the whole point of configuring GST per branch.
     */
    public function test_each_invoice_line_names_its_branch(): void
    {
        $branch = $this->branch('Nagar Road');
        $this->sale(total: 1180, tax: 180, rate: 18, date: '2026-07-10', branchId: $branch);

        $this->report(['from_date' => '2026-07-01', 'to_date' => '2026-07-31'])
            ->assertSuccessful()
            ->assertJsonPath('data.sales.0.branch', 'Nagar Road');
    }

    public function test_the_report_can_be_filtered_to_one_branch(): void
    {
        $one = $this->branch('Nagar Road');
        $two = $this->branch('Camp');

        $this->sale(total: 1180, tax: 180, rate: 18, date: '2026-07-10', branchId: $one);
        $this->sale(total: 2360, tax: 360, rate: 18, date: '2026-07-12', branchId: $two);

        $this->report([
            'from_date' => '2026-07-01',
            'to_date'   => '2026-07-31',
            'branch_id' => $one,
        ])
            ->assertSuccessful()
            // JSON renders a whole float as an int, so compare by value.
            ->assertJsonPath('total_sales', fn ($v) => (float) $v === 1180.0)
            ->assertJsonPath('output_tax', fn ($v) => (float) $v === 180.0);
    }

    public function test_the_branch_filter_reaches_the_rate_summary_too(): void
    {
        $one = $this->branch('Nagar Road');
        $two = $this->branch('Camp');

        $this->sale(total: 1180, tax: 180, rate: 18, date: '2026-07-10', branchId: $one);
        $this->sale(total: 2360, tax: 360, rate: 18, date: '2026-07-12', branchId: $two);

        // The per-rate breakdown is built by its own query; a filter applied to
        // the lines and not to the summary would have the two disagree.
        $this->report([
            'from_date' => '2026-07-01',
            'to_date'   => '2026-07-31',
            'branch_id' => $one,
        ])
            ->assertSuccessful()
            ->assertJsonPath(
                'rate_summary.0.taxable_sales',
                fn ($v) => (float) $v === 1000.0,
            );
    }

    public function test_the_active_filters_are_echoed_back(): void
    {
        $branch = $this->branch('Nagar Road');

        $this->report([
            'from_date' => '2026-07-01',
            'to_date'   => '2026-07-31',
            'branch_id' => $branch,
        ])
            ->assertSuccessful()
            ->assertJsonPath('filters.branch_id', fn ($v) => (int) $v === $branch);
    }
}
