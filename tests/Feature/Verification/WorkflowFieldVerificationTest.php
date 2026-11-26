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
 * Field-level round trips against the database.
 *
 * Every case saves through the API, reads the row back, re-saves unchanged, and
 * reads it again. The second read is the one that matters: a value that
 * survives the first save but not an untouched re-save is the failure mode
 * these workflows actually had.
 */
class WorkflowFieldVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Party $supplier;
    private Category $category;
    private Customer $customerA;
    private Customer $customerB;

    private static array $evidence = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
        $this->supplier = Party::create([
            'business_id' => $this->business->id,
            'name' => 'E2E_VERIFY Supplier',
            'type' => 'Supplier',
            'due' => 0,
        ]);
        $this->category = Category::factory()->create(['business_id' => $this->business->id]);

        $this->customerA = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'E2E_VERIFY Cust A', 'phone' => '9000000201', 'relationship' => 'self',
        ]);
        $this->customerB = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'E2E_VERIFY Cust B', 'phone' => '9000000202', 'relationship' => 'father',
        ]);

        $this->actingAs($this->user);
    }

    private function record(string $key, array $data): void
    {
        self::$evidence[$key] = $data;
        fwrite(STDERR, "\n[EVIDENCE] {$key}: ".json_encode($data)."\n");
    }

    private function product(string $code): Product
    {
        return Product::create([
            'productName' => 'E2E_VERIFY '.$code,
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
            'productCode' => $code,
            'productStock' => 0,
        ]);
    }

    private function line(Product $p, array $o = []): array
    {
        return array_merge([
            'product_id' => $p->id,
            'batch_no' => 'E2E-'.$p->productCode,
            'quantities' => 10,
            'productPurchasePrice' => 50,
            'productSalePrice' => 80,
            'conversion_purchase_price' => 50,
            'conversion_quantities' => 10,
            'conversion_value' => 1,
            'conversion_price' => 80,
            'expire_date' => now()->addYear()->toDateString(),
            'mfg_date' => now()->subMonth()->toDateString(),
            'upper_selected_unit' => 1,
            'lower_selected_unit' => 1,
            'total_purchase_price' => 500,
            'total_price' => 800,
        ], $o);
    }

    private function buy(array $lines, array $o = [])
    {
        return $this->postJson('/api/v1/purchase', array_merge([
            'party_id' => $this->supplier->id,
            'purchaseDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'products' => $lines,
        ], $o));
    }

    private function purchaseRow(int $id): array
    {
        return (array) DB::table('purchases')->where('id', $id)->first([
            'id', 'totalAmount', 'paidAmount', 'dueAmount',
            'cash_amount', 'upi_amount', 'card_amount',
            'reference_invoice_no', 'purchaseDate', 'isPaid',
        ]);
    }

    // ══ P2: payment split survives an untouched re-save ═══════════════════

    public function test_p2_a_split_paid_purchase_keeps_its_tenders_through_an_edit(): void
    {
        $product = $this->product('P2SPLIT');

        $this->buy([$this->line($product)], [
            'paidAmount' => 400,
            'dueAmount' => 100,
            'cash_amount' => 150,
            'upi_amount' => 250,
            'card_amount' => 0,
            'reference_invoice_no' => 'E2E-REF-1',
        ])->assertStatus(200);

        $id = (int) DB::table('purchases')->where('business_id', $this->business->id)->value('id');
        $before = $this->purchaseRow($id);
        $this->record('p2/after_create', $before);

        // Re-save the same bill unchanged, exactly as the edit screen would.
        $this->putJson("/api/v1/purchase/{$id}", [
            'party_id' => $this->supplier->id,
            'purchaseDate' => $before['purchaseDate'],
            'paymentType' => 'cash',
            'paidAmount' => $before['paidAmount'],
            'dueAmount' => $before['dueAmount'],
            'cash_amount' => $before['cash_amount'],
            'upi_amount' => $before['upi_amount'],
            'card_amount' => $before['card_amount'],
            'reference_invoice_no' => $before['reference_invoice_no'],
            'products' => [$this->line($product, [
                'stock_id' => DB::table('stocks')->where('product_id', $product->id)->value('id'),
            ])],
        ])->assertStatus(200);

        $after = $this->purchaseRow($id);
        $this->record('p2/after_untouched_resave', $after);

        $this->assertSame((float) $before['cash_amount'], (float) $after['cash_amount'], 'cash changed');
        $this->assertSame((float) $before['upi_amount'], (float) $after['upi_amount'], 'UPI became something else');
        $this->assertSame((float) $before['dueAmount'], (float) $after['dueAmount'], 'due changed');
        $this->assertSame($before['reference_invoice_no'], $after['reference_invoice_no']);
    }

    // ══ P5: purchase line discount round trip ════════════════════════════

    public function test_p5_purchase_line_discounts_persist_by_type(): void
    {
        $none = $this->product('P5NONE');
        $pct = $this->product('P5PCT');
        $flat = $this->product('P5FLAT');

        $this->buy([
            $this->line($none),
            $this->line($pct, ['discount_percent' => 10]),
            $this->line($flat, ['discount_flat' => 75]),
        ])->assertStatus(200);

        $rows = DB::table('purchase_details')
            ->join('products', 'products.id', '=', 'purchase_details.product_id')
            ->orderBy('purchase_details.id')
            ->get(['products.productCode as code', 'discountPercent', 'discountFlat', 'discountAmount', 'totalPurchasePrice']);

        $this->record('p5/rows', $rows->map(fn ($r) => (array) $r)->all());

        $byCode = $rows->keyBy('code');

        $this->assertSame(0.0, (float) $byCode['P5NONE']->discountAmount);
        $this->assertSame(500.0, (float) $byCode['P5NONE']->totalPurchasePrice);

        $this->assertSame(10.0, (float) $byCode['P5PCT']->discountPercent);
        $this->assertSame(50.0, (float) $byCode['P5PCT']->discountAmount);
        $this->assertSame(450.0, (float) $byCode['P5PCT']->totalPurchasePrice);

        $this->assertSame(75.0, (float) $byCode['P5FLAT']->discountFlat);
        $this->assertSame(75.0, (float) $byCode['P5FLAT']->discountAmount);
        $this->assertSame(425.0, (float) $byCode['P5FLAT']->totalPurchasePrice);
    }

    /** A row written before the columns existed must still calculate. */
    public function test_p5_a_null_discount_row_does_not_break_anything(): void
    {
        $product = $this->product('P5NULL');
        $this->buy([$this->line($product)])->assertStatus(200);

        // Simulate a pre-migration row.
        DB::table('purchase_details')->update([
            'discountPercent' => null, 'discountFlat' => null, 'discountAmount' => null,
        ]);

        $listed = $this->getJson('/api/v1/purchase')->assertSuccessful();

        $this->record('p5/null_row_listing', [
            'status' => $listed->status(),
            'rows' => count($listed->json('data.data') ?? $listed->json('data') ?? []),
        ]);

        $this->assertNull(DB::table('purchase_details')->value('discountAmount'));
    }

    // ══ S3/S6: customer and relationship on a sale ═══════════════════════

    public function test_s3_s6_customer_and_relationship_change_and_can_be_cleared(): void
    {
        $product = $this->product('S3REL');
        $this->buy([$this->line($product)])->assertStatus(200);
        $stockId = (int) DB::table('stocks')->where('product_id', $product->id)->value('id');

        $this->postJson('/api/v1/sales', [
            'saleDate' => now()->toDateString(),
            'customer_id' => $this->customerA->id,
            'relationship' => 'wife',
            'totalAmount' => 160, 'paidAmount' => 160,
            'products' => json_encode([[
                'stock_id' => $stockId, 'quantities' => 2, 'price' => 80, 'lossProfit' => 30,
            ]]),
        ])->assertSuccessful();

        $saleId = (int) DB::table('sales')->where('business_id', $this->business->id)->value('id');
        $before = (array) DB::table('sales')->where('id', $saleId)->first(['customer_id', 'relationship']);
        $this->record('s6/after_create', $before);

        $this->assertSame($this->customerA->id, (int) $before['customer_id']);
        $this->assertSame('wife', $before['relationship'], 'the relationship chosen at the till must be stored on the sale');

        // Change both.
        $this->putJson("/api/v1/sales/{$saleId}", [
            'saleDate' => now()->toDateString(),
            'customer_id' => $this->customerB->id,
            'relationship' => 'son',
            'totalAmount' => 160, 'paidAmount' => 160,
            'products' => json_encode([[
                'stock_id' => $stockId, 'quantities' => 2, 'price' => 80, 'lossProfit' => 30,
            ]]),
        ])->assertSuccessful();

        $changed = (array) DB::table('sales')->where('id', $saleId)->first(['customer_id', 'relationship']);
        $this->record('s3/after_change', $changed);

        $this->assertSame($this->customerB->id, (int) $changed['customer_id']);
        $this->assertSame('son', $changed['relationship']);

        // Clear it.
        $this->putJson("/api/v1/sales/{$saleId}", [
            'saleDate' => now()->toDateString(),
            'customer_id' => $this->customerB->id,
            'relationship' => '',
            'totalAmount' => 160, 'paidAmount' => 160,
            'products' => json_encode([[
                'stock_id' => $stockId, 'quantities' => 2, 'price' => 80, 'lossProfit' => 30,
            ]]),
        ])->assertSuccessful();

        $cleared = (array) DB::table('sales')->where('id', $saleId)->first(['relationship']);
        $this->record('s3/after_clear', $cleared);

        $this->assertNotSame('son', $cleared['relationship'], 'a cleared relationship must not keep the old value');
    }

    // ══ S7: sale line discount round trip ════════════════════════════════

    public function test_s7_sale_line_discount_persists_and_survives_an_untouched_resave(): void
    {
        $product = $this->product('S7DISC');
        $this->buy([$this->line($product)])->assertStatus(200);
        $stockId = (int) DB::table('stocks')->where('product_id', $product->id)->value('id');

        $this->postJson('/api/v1/sales', [
            'saleDate' => now()->toDateString(),
            'customer_id' => $this->customerA->id,
            'totalAmount' => 288, 'paidAmount' => 288,
            'products' => json_encode([[
                'stock_id' => $stockId, 'quantities' => 4, 'price' => 80,
                'lossProfit' => 30, 'discount_percent' => 10,
            ]]),
        ])->assertSuccessful();

        $saleId = (int) DB::table('sales')->where('business_id', $this->business->id)->value('id');
        $before = (array) DB::table('sale_details')->where('sale_id', $saleId)
            ->first(['discountPercent', 'discountFlat', 'discountAmount', 'price', 'quantities']);
        $this->record('s7/after_create', $before);

        // 4 x 80 = 320 gross, 10% = 32.
        $this->assertSame(10.0, (float) $before['discountPercent']);
        $this->assertSame(32.0, (float) $before['discountAmount']);

        // Re-save unchanged, carrying the discount back as the edit screen now can.
        $this->putJson("/api/v1/sales/{$saleId}", [
            'saleDate' => now()->toDateString(),
            'customer_id' => $this->customerA->id,
            'totalAmount' => 288, 'paidAmount' => 288,
            'products' => json_encode([[
                'stock_id' => $stockId, 'quantities' => 4, 'price' => 80,
                'lossProfit' => 30, 'discount_percent' => 10,
            ]]),
        ])->assertSuccessful();

        $after = (array) DB::table('sale_details')->where('sale_id', $saleId)
            ->first(['discountPercent', 'discountAmount']);
        $this->record('s7/after_untouched_resave', $after);

        $this->assertSame(10.0, (float) $after['discountPercent'], 'the discount must survive a re-save');
        $this->assertSame(32.0, (float) $after['discountAmount']);
    }
}
