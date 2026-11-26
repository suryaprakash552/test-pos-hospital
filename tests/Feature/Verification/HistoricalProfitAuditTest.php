<?php

namespace Tests\Feature\Verification;

use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Party;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Finds sales whose recorded profit predates the conversion fix - and changes
 * nothing.
 *
 * Profit was computed as `stocks.productPurchasePrice x quantity`, charging a
 * whole strip's cost to every loose tablet. New sales are correct; completed
 * ones keep the figure they were saved with, because a finished sale is a
 * historical snapshot and rewriting it silently would be worse than leaving it
 * visibly wrong.
 *
 * This proves the detection query, so the shop can be shown the size of the
 * problem before anyone decides whether to correct it.
 */
class HistoricalProfitAuditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sale lines whose stored profit disagrees with the corrected basis.
     *
     * Read-only. Compares what was stored against what the fixed calculation
     * would produce for the same line, using the batch's own conversion.
     */
    public static function auditQuery(): string
    {
        return "
            SELECT
                sd.id                       AS sale_detail_id,
                sd.sale_id,
                s.business_id,
                st.conversionValue          AS conversion_value,
                sd.quantities,
                sd.price,
                st.productPurchasePrice     AS cost_per_pack,
                sd.lossProfit               AS stored_profit,
                ROUND(
                    (sd.price * sd.quantities)
                    - ((st.productPurchasePrice / GREATEST(COALESCE(NULLIF(st.conversionValue,0),1),1)) * sd.quantities)
                , 2)                        AS corrected_profit,
                ROUND(
                    ROUND(
                        (sd.price * sd.quantities)
                        - ((st.productPurchasePrice / GREATEST(COALESCE(NULLIF(st.conversionValue,0),1),1)) * sd.quantities)
                    , 2) - sd.lossProfit
                , 2)                        AS difference
            FROM sale_details sd
            JOIN stocks st ON st.id = sd.stock_id
            JOIN sales  s  ON s.id  = sd.sale_id
            WHERE COALESCE(st.conversionValue, 1) > 1
              AND ABS(
                    ROUND(
                        (sd.price * sd.quantities)
                        - ((st.productPurchasePrice / GREATEST(COALESCE(NULLIF(st.conversionValue,0),1),1)) * sd.quantities)
                    , 2) - sd.lossProfit
                  ) > 0.01
        ";
    }

    private function say(string $k, array $d): void
    {
        fwrite(STDERR, "\n[PROFIT] {$k}: ".json_encode($d)."\n");
    }

    public function test_the_audit_finds_a_mis_valued_historical_line_and_changes_nothing(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id, 'role' => 'shop-owner']);
        $category = Category::factory()->create(['business_id' => $business->id]);
        $supplier = Party::create([
            'business_id' => $business->id, 'name' => 'PROFIT Sup', 'type' => 'Supplier', 'due' => 0,
        ]);
        $customer = Customer::create([
            'business_id' => $business->id, 'name' => 'PROFIT Cust', 'phone' => '9400000001',
        ]);

        $product = Product::create([
            'productName' => 'PROFIT Strip', 'business_id' => $business->id,
            'category_id' => $category->id, 'productCode' => 'PROFIT-1',
            'productStock' => 0, 'conversionValue' => 10, 'vat_type' => 'exclusive',
        ]);

        $this->actingAs($owner)->postJson('/api/v1/purchase', [
            'party_id' => $supplier->id, 'purchaseDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'products' => [[
                'product_id' => $product->id, 'batch_no' => 'PROFIT-B1',
                'quantities' => 5, 'productPurchasePrice' => 120, 'productSalePrice' => 200,
                'conversion_purchase_price' => 12, 'conversion_quantities' => 50,
                'conversion_value' => 10, 'conversion_price' => 20,
                'expire_date' => now()->addYear()->toDateString(),
                'mfg_date' => now()->subMonth()->toDateString(),
                'upper_selected_unit' => 1, 'lower_selected_unit' => 1,
                'total_purchase_price' => 600, 'total_price' => 1000,
            ]],
        ])->assertStatus(200);

        $stockId = (int) DB::table('stocks')->where('product_id', $product->id)->value('id');

        $this->actingAs($owner)->postJson('/api/v1/sales', [
            'saleDate' => now()->toDateString(), 'customer_id' => $customer->id,
            'totalAmount' => 140, 'paidAmount' => 140,
            'products' => json_encode([[
                'stock_id' => $stockId, 'quantities' => 7, 'price' => 20, 'lossProfit' => 0,
            ]]),
        ])->assertSuccessful();

        $detailId = (int) DB::table('sale_details')->value('id');

        // A line as the old code would have left it: 7 x (20 - 120) = -700.
        DB::table('sale_details')->where('id', $detailId)->update(['lossProfit' => -700]);

        $before = (array) DB::table('sale_details')->where('id', $detailId)
            ->first(['lossProfit', 'quantities', 'price']);

        $rows = DB::select(self::auditQuery());

        $this->say('dry_run', [
            'affected_lines' => count($rows),
            'row' => count($rows) ? (array) $rows[0] : null,
            'total_difference' => round(array_sum(array_map(fn ($r) => (float) $r->difference, $rows)), 2),
        ]);

        $this->assertCount(1, $rows, 'the audit must find the mis-valued line');
        $this->assertSame(-700.0, (float) $rows[0]->stored_profit);
        $this->assertSame(56.0, (float) $rows[0]->corrected_profit);
        $this->assertSame(756.0, (float) $rows[0]->difference);

        // The audit is read-only.
        $after = (array) DB::table('sale_details')->where('id', $detailId)
            ->first(['lossProfit', 'quantities', 'price']);

        $this->assertSame($before, $after, 'the audit must not rewrite history');
    }

    /** A correctly valued line must not be reported as affected. */
    public function test_the_audit_does_not_flag_correct_lines(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id, 'role' => 'shop-owner']);
        $category = Category::factory()->create(['business_id' => $business->id]);
        $supplier = Party::create([
            'business_id' => $business->id, 'name' => 'PROFIT Sup2', 'type' => 'Supplier', 'due' => 0,
        ]);

        $product = Product::create([
            'productName' => 'PROFIT OK', 'business_id' => $business->id,
            'category_id' => $category->id, 'productCode' => 'PROFIT-2',
            'productStock' => 0, 'conversionValue' => 10, 'vat_type' => 'exclusive',
        ]);

        $this->actingAs($owner)->postJson('/api/v1/purchase', [
            'party_id' => $supplier->id, 'purchaseDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'products' => [[
                'product_id' => $product->id, 'batch_no' => 'PROFIT-B2',
                'quantities' => 5, 'productPurchasePrice' => 120, 'productSalePrice' => 200,
                'conversion_purchase_price' => 12, 'conversion_quantities' => 50,
                'conversion_value' => 10, 'conversion_price' => 20,
                'expire_date' => now()->addYear()->toDateString(),
                'mfg_date' => now()->subMonth()->toDateString(),
                'upper_selected_unit' => 1, 'lower_selected_unit' => 1,
                'total_purchase_price' => 600, 'total_price' => 1000,
            ]],
        ])->assertStatus(200);

        $stockId = (int) DB::table('stocks')->where('product_id', $product->id)->value('id');

        // Written by the fixed calculation, so nothing to report.
        $this->actingAs($owner)->postJson('/api/v1/sales', [
            'saleDate' => now()->toDateString(),
            'totalAmount' => 140, 'paidAmount' => 140,
            'products' => json_encode([[
                'stock_id' => $stockId, 'quantities' => 7, 'price' => 20, 'lossProfit' => 0,
            ]]),
        ])->assertSuccessful();

        $rows = DB::select(self::auditQuery());

        $this->say('clean_data', ['affected_lines' => count($rows)]);

        $this->assertCount(0, $rows, 'a line written by the fixed code must not be flagged');
    }
}
