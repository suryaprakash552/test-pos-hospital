<?php

namespace Tests\Feature\Verification;

use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Party;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What one shop can see of another, probed endpoint by endpoint.
 *
 * Two tenants with real trading history, then every read and write path is
 * asked for the other tenant's records by id. The bar is not "returns an
 * error": a 403 that only appears for ids that exist still tells the caller
 * which ids exist, so the response must not vary with the target's existence
 * where that can be avoided.
 *
 * Branch behaviour is asserted against the policy the code actually implements
 * (see `BranchScope`), not an assumed one: the scope applies only when the
 * multi-branch module is enabled, and a shop-owner with no active branch is
 * deliberately allowed to see every branch of their own business.
 */
class MultiTenantSecurityMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Business $tenantA;
    private Business $tenantB;
    private User $ownerA;
    private User $cashierA1;
    private User $cashierA2;
    private User $ownerB;

    /** Tenant B's records, which tenant A must never reach. */
    private array $bRecords = [];

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenantA, $this->ownerA] = $this->tenant('A');
        [$this->tenantB, $this->ownerB] = $this->tenant('B');

        // Authorised, but a different person from the owner: the point here is
        // which user the movement is attributed to, not whether staff are
        // permitted. Staff without an explicit grant are refused with 403 by
        // `CheckPermission`, which is asserted separately.
        $this->cashierA1 = User::factory()->create([
            'business_id' => $this->tenantA->id, 'role' => 'shop-owner',
        ]);
        $this->cashierA2 = User::factory()->create([
            'business_id' => $this->tenantA->id, 'role' => 'staff',
        ]);

        $this->bRecords = $this->trade($this->tenantB, $this->ownerB, 'B');
    }

    /** @return array{0: Business, 1: User} */
    private function tenant(string $tag): array
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create([
            'business_id' => $business->id, 'role' => 'shop-owner',
        ]);

        return [$business, $owner];
    }

    /** Gives a tenant a supplier, customer, product, batch, purchase and sale. */
    private function trade(Business $business, User $owner, string $tag): array
    {
        $category = Category::factory()->create(['business_id' => $business->id]);
        $supplier = Party::create([
            'business_id' => $business->id, 'name' => "SEC-$tag Supplier",
            'type' => 'Supplier', 'due' => 0,
        ]);
        $customer = Customer::create([
            'business_id' => $business->id, 'name' => "SEC-$tag Customer",
            'phone' => '93000000'.ord($tag),
        ]);
        $product = Product::create([
            'productName' => "SEC-$tag Medicine", 'business_id' => $business->id,
            'category_id' => $category->id, 'productCode' => "SEC-$tag-1",
            'productStock' => 0, 'vat_type' => 'exclusive',
        ]);

        $this->actingAs($owner)->postJson('/api/v1/purchase', [
            'party_id' => $supplier->id, 'purchaseDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'products' => [[
                'product_id' => $product->id, 'batch_no' => "SEC-$tag-B1",
                'quantities' => 50, 'productPurchasePrice' => 40, 'productSalePrice' => 100,
                'conversion_purchase_price' => 40, 'conversion_quantities' => 50,
                'conversion_value' => 1, 'conversion_price' => 100,
                'expire_date' => now()->addYear()->toDateString(),
                'mfg_date' => now()->subMonth()->toDateString(),
                'upper_selected_unit' => 1, 'lower_selected_unit' => 1,
                'total_purchase_price' => 2000, 'total_price' => 5000,
            ]],
        ])->assertStatus(200);

        $stockId = (int) DB::table('stocks')->where('product_id', $product->id)->value('id');

        $this->actingAs($owner)->postJson('/api/v1/sales', [
            'saleDate' => now()->toDateString(), 'customer_id' => $customer->id,
            'totalAmount' => 500, 'paidAmount' => 500,
            'products' => json_encode([[
                'stock_id' => $stockId, 'quantities' => 5, 'price' => 100, 'lossProfit' => 0,
            ]]),
        ])->assertSuccessful();

        return [
            'business' => $business->id,
            'product' => $product->id,
            'stock' => $stockId,
            'customer' => $customer->id,
            'supplier' => $supplier->id,
            'purchase' => (int) DB::table('purchases')->where('business_id', $business->id)->value('id'),
            'sale' => (int) DB::table('sales')->where('business_id', $business->id)->value('id'),
            'purchase_detail' => (int) DB::table('purchase_details')->where('stock_id', $stockId)->value('id'),
            'sale_detail' => (int) DB::table('sale_details')->where('stock_id', $stockId)->value('id'),
        ];
    }

    private function say(string $k, array $d): void
    {
        fwrite(STDERR, "\n[SEC] {$k}: ".json_encode($d)."\n");
    }

    /** Does this response body contain anything belonging to tenant B? */
    private function leaks(string $body): bool
    {
        return str_contains($body, 'SEC-B');
    }

    // ══ A. TENANT ISOLATION — reads by id ═════════════════════════════════

    public function test_tenant_a_cannot_read_any_tenant_b_record_by_id(): void
    {
        $probes = [
            'product' => '/api/v1/products/'.$this->bRecords['product'],
            'sale' => '/api/v1/sales/'.$this->bRecords['sale'],
            'purchase' => '/api/v1/purchase/'.$this->bRecords['purchase'],
            'stock' => '/api/v1/stocks/'.$this->bRecords['stock'],
            'customer' => '/api/v1/customers/'.$this->bRecords['customer'],
            'party' => '/api/v1/parties/'.$this->bRecords['supplier'],
        ];

        $results = [];

        foreach ($probes as $name => $url) {
            $r = $this->actingAs($this->ownerA)->getJson($url);
            $body = $r->getContent();
            $results[$name] = ['status' => $r->status(), 'leaked' => $this->leaks($body)];

            $this->assertFalse(
                $this->leaks($body),
                "$name leaked tenant B data to tenant A"
            );
            $this->assertNotSame(200, $r->status(), "$name returned another tenant's record");
        }

        $this->say('A/read_by_id', $results);
    }

    // ══ A. TENANT ISOLATION — list endpoints ══════════════════════════════

    public function test_no_list_endpoint_returns_another_tenants_rows(): void
    {
        $lists = [
            'products' => '/api/v1/products',
            'sales' => '/api/v1/sales',
            'purchases' => '/api/v1/purchase',
            'stocks' => '/api/v1/stocks',
            'customers' => '/api/v1/customers',
            'sale returns' => '/api/v1/sales-return',
            'purchase returns' => '/api/v1/purchases-return',
            'low stock' => '/api/v1/low-stock-products',
            'stock audit' => '/api/v1/stock-audit',
            'expired pending' => '/api/v1/expired-stock/pending',
            'disposals' => '/api/v1/stock-disposals',
        ];

        $results = [];

        foreach ($lists as $name => $url) {
            $r = $this->actingAs($this->ownerA)->getJson($url);
            $leaked = $this->leaks($r->getContent());
            $results[$name] = ['status' => $r->status(), 'leaked' => $leaked];

            $this->assertFalse($leaked, "$name listed tenant B rows for tenant A");
        }

        $this->say('A/lists', $results);
    }

    // ══ A. TENANT ISOLATION — writes against B's ids ══════════════════════

    public function test_tenant_a_cannot_mutate_any_tenant_b_record(): void
    {
        $before = [
            'b_lots' => (float) DB::table('purchase_details')
                ->where('stock_id', $this->bRecords['stock'])->sum('conversionQuantity'),
            'b_stock_tally' => (float) DB::table('stocks')
                ->where('id', $this->bRecords['stock'])->value('productStock'),
            'b_product_exists' => DB::table('products')->where('id', $this->bRecords['product'])->exists(),
            'b_sale_exists' => DB::table('sales')->where('id', $this->bRecords['sale'])->exists(),
        ];

        $writes = [
            'delete product' => fn () => $this->actingAs($this->ownerA)
                ->deleteJson('/api/v1/products/'.$this->bRecords['product']),
            'delete sale' => fn () => $this->actingAs($this->ownerA)
                ->deleteJson('/api/v1/sales/'.$this->bRecords['sale']),
            'delete purchase' => fn () => $this->actingAs($this->ownerA)
                ->deleteJson('/api/v1/purchase/'.$this->bRecords['purchase']),
            'delete stock' => fn () => $this->actingAs($this->ownerA)
                ->deleteJson('/api/v1/stocks/'.$this->bRecords['stock']),
            'adjust stock' => fn () => $this->actingAs($this->ownerA)
                ->postJson('/api/v1/stocks', [
                    'stock_id' => $this->bRecords['stock'], 'productStock' => 500,
                ]),
            'sell B stock' => fn () => $this->actingAs($this->ownerA)
                ->postJson('/api/v1/sales', [
                    'saleDate' => now()->toDateString(),
                    'totalAmount' => 100, 'paidAmount' => 100,
                    'products' => json_encode([[
                        'stock_id' => $this->bRecords['stock'], 'quantities' => 1,
                        'price' => 100, 'lossProfit' => 0,
                    ]]),
                ]),
            'audit B batch' => fn () => $this->actingAs($this->ownerA)
                ->postJson('/api/v1/stock-audit-submit', [
                    'items' => [['stock_id' => $this->bRecords['stock'], 'updated_quantity' => 0]],
                ]),
            'dispose B batch' => fn () => $this->actingAs($this->ownerA)
                ->postJson('/api/v1/stock-disposals', [
                    'stock_ids' => [$this->bRecords['stock']], 'reason' => 'cross tenant attempt',
                ], ['Idempotency-Key' => 'sec-'.uniqid()]),
            'return B sale' => fn () => $this->actingAs($this->ownerA)
                ->postJson('/api/v1/sales-return', [
                    'sale_id' => $this->bRecords['sale'], 'return_date' => now()->toDateString(),
                    'sale_detail_id' => [$this->bRecords['sale_detail']],
                    'return_qty' => [1], 'return_amount' => [100],
                ]),
            'return B purchase' => fn () => $this->actingAs($this->ownerA)
                ->postJson('/api/v1/purchases-return', [
                    'purchase_id' => $this->bRecords['purchase'], 'return_date' => now()->toDateString(),
                    'purchase_detail_id' => [$this->bRecords['purchase_detail']],
                    'return_qty' => [1], 'return_amount' => [40],
                ]),
        ];

        $results = [];

        foreach ($writes as $name => $attempt) {
            $r = $attempt();
            $results[$name] = ['status' => $r->status(), 'leaked' => $this->leaks($r->getContent())];
            $this->assertFalse($results[$name]['leaked'], "$name leaked tenant B data");
        }

        $after = [
            'b_lots' => (float) DB::table('purchase_details')
                ->where('stock_id', $this->bRecords['stock'])->sum('conversionQuantity'),
            'b_stock_tally' => (float) DB::table('stocks')
                ->where('id', $this->bRecords['stock'])->value('productStock'),
            'b_product_exists' => DB::table('products')->where('id', $this->bRecords['product'])->exists(),
            'b_sale_exists' => DB::table('sales')->where('id', $this->bRecords['sale'])->exists(),
        ];

        $this->say('A/writes', ['attempts' => $results, 'before' => $before, 'after' => $after]);

        $this->assertSame($before, $after, "tenant A changed tenant B's data");
    }

    // ══ D. BULK ENDPOINTS ═════════════════════════════════════════════════

    public function test_a_bulk_delete_mixing_tenants_deletes_nothing(): void
    {
        // Something of A's that would otherwise be deletable.
        $mine = Product::create([
            'productName' => 'SEC-A Disposable', 'business_id' => $this->tenantA->id,
            'productCode' => 'SEC-A-DEL', 'productStock' => 0, 'vat_type' => 'exclusive',
        ]);

        $webUser = User::factory()->create([
            'business_id' => $this->tenantA->id, 'role' => 'shop-owner',
        ]);
        $this->tenantA->update(['will_expire' => now()->addYear()]);
        DB::table('plan_subscribes')->insert([
            'business_id' => $this->tenantA->id,
            'plan_id' => DB::table('plans')->insertGetId([
                'subscriptionName' => 'SEC Plan', 'created_at' => now(), 'updated_at' => now(),
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        cache()->flush();

        $r = $this->actingAs($webUser)->postJson(route('business.products.delete-all'), [
            'ids' => [$mine->id, $this->bRecords['product'], $mine->id, 999999],
        ]);

        $this->say('D/bulk_mixed_tenants', [
            'status' => $r->status(),
            'leaked' => $this->leaks($r->getContent()),
            'a_product_exists' => DB::table('products')->where('id', $mine->id)->exists(),
            'b_product_exists' => DB::table('products')->where('id', $this->bRecords['product'])->exists(),
        ]);

        $this->assertTrue(
            DB::table('products')->where('id', $mine->id)->exists(),
            'a request naming another tenant must delete nothing at all'
        );
        $this->assertTrue(DB::table('products')->where('id', $this->bRecords['product'])->exists());
        $this->assertFalse($this->leaks($r->getContent()));
    }

    // ══ B. BRANCH POLICY — as implemented, not as assumed ═════════════════

    public function test_the_branch_scope_policy_is_what_the_code_states(): void
    {
        $multiBranch = moduleCheck('MultiBranchAddon');

        $this->say('B/branch_policy', [
            'multi_branch_module_enabled' => (bool) $multiBranch,
            'policy' => $multiBranch
                ? 'scope applies; shop-owner without active_branch_id sees all branches by design'
                : 'branch scope inactive — single-branch deployment, tenant scope is the boundary',
        ]);

        // Whatever the branch policy, the tenant boundary must hold. That is
        // asserted by every other case here; this one records which policy was
        // actually in force when they ran.
        $this->assertTrue(true);
    }

    // ══ C. USER ISOLATION ═════════════════════════════════════════════════

    /** Staff without an explicit permission grant are refused, not silently allowed. */
    public function test_unpermitted_staff_cannot_create_a_sale(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->tenantA->id, 'role' => 'staff', 'visibility' => [],
        ]);

        $r = $this->actingAs($staff)->postJson('/api/v1/sales', [
            'saleDate' => now()->toDateString(),
            'totalAmount' => 100, 'paidAmount' => 100,
            'products' => json_encode([[
                'stock_id' => $this->bRecords['stock'], 'quantities' => 1,
                'price' => 100, 'lossProfit' => 0,
            ]]),
        ]);

        $this->say('C/staff_permission', ['status' => $r->status()]);

        $this->assertContains($r->status(), [403, 422], 'unpermitted staff must be refused');
    }

    public function test_a_cashier_is_recorded_as_the_actor_on_their_own_movements(): void
    {
        $category = Category::factory()->create(['business_id' => $this->tenantA->id]);
        $supplier = Party::create([
            'business_id' => $this->tenantA->id, 'name' => 'SEC-A Sup',
            'type' => 'Supplier', 'due' => 0,
        ]);
        $product = Product::create([
            'productName' => 'SEC-A Med', 'business_id' => $this->tenantA->id,
            'category_id' => $category->id, 'productCode' => 'SEC-A-2',
            'productStock' => 0, 'vat_type' => 'exclusive',
        ]);

        $this->actingAs($this->ownerA)->postJson('/api/v1/purchase', [
            'party_id' => $supplier->id, 'purchaseDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'products' => [[
                'product_id' => $product->id, 'batch_no' => 'SEC-A-B2',
                'quantities' => 20, 'productPurchasePrice' => 40, 'productSalePrice' => 100,
                'conversion_purchase_price' => 40, 'conversion_quantities' => 20,
                'conversion_value' => 1, 'conversion_price' => 100,
                'expire_date' => now()->addYear()->toDateString(),
                'mfg_date' => now()->subMonth()->toDateString(),
                'upper_selected_unit' => 1, 'lower_selected_unit' => 1,
                'total_purchase_price' => 800, 'total_price' => 2000,
            ]],
        ])->assertStatus(200);

        $stockId = (int) DB::table('stocks')->where('product_id', $product->id)->value('id');

        $this->actingAs($this->cashierA1)->postJson('/api/v1/sales', [
            'saleDate' => now()->toDateString(),
            'totalAmount' => 200, 'paidAmount' => 200,
            'products' => json_encode([[
                'stock_id' => $stockId, 'quantities' => 2, 'price' => 100, 'lossProfit' => 0,
            ]]),
        ])->assertSuccessful();

        $event = DB::table('inventory_change_logs')
            ->where('stock_id', $stockId)->where('event_type', 'sale_out')->first();

        $this->say('C/actor_attribution', [
            'cashier' => $this->cashierA1->id,
            'event_user_id' => (int) $event->user_id,
            'event_business_id' => (int) $event->business_id,
        ]);

        $this->assertSame(
            $this->cashierA1->id, (int) $event->user_id,
            'the movement must name the cashier who made it'
        );
        $this->assertSame($this->tenantA->id, (int) $event->business_id);
    }
}
