<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What belongs on a low-stock report, as the shop defines it.
 *
 * Two rules, and the code had both of them backwards:
 *
 * 1. **Zero stock is the most urgent row, not an excluded one.** The query was
 *    driven from `purchase_details` and filtered to `conversionQuantity > 0`,
 *    so a product sold down to nothing dropped out entirely. The counter
 *    reported it as "Eupen 1 Gm Injection shows 0 PACK in the product list and
 *    is not in Low Stock".
 *
 * 2. **A minimum level of 0 means discontinued.** The shop cannot delete such a
 *    product — its sales history would go with it — so they zero the minimum to
 *    say "never reorder this again". With no guard, `0 <= 0` matched and the
 *    item was reported every day forever.
 *
 * A product sitting exactly *at* its minimum stays in: at-or-below is the
 * reorder trigger.
 */
class LowStockReportRuleTest extends TestCase
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
            'role'        => 'shop-owner',
        ]);

        $this->actingAs($this->user);
    }

    /**
     * A product with [$minimum] as its reorder level holding [$available] units.
     *
     * `$available === null` means the product was never purchased at all, so it
     * has no lots behind it.
     */
    private function product(string $name, int $minimum, ?int $available, string $rack = 'GENERAL'): int
    {
        $productId = DB::table('products')->insertGetId([
            'productName' => $name,
            'productCode' => strtoupper(substr(md5($name), 0, 8)),
            'business_id' => $this->business->id,
            'alert_qty'   => $minimum,
            'rack'        => $rack,
        ]);

        if ($available === null) {
            return $productId;
        }

        $stockId = DB::table('stocks')->insertGetId([
            'business_id'     => $this->business->id,
            'product_id'      => $productId,
            'batch_no'        => 'B-' . $productId,
            'conversionValue' => 1,
            'productStock'    => $available,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $purchaseId = DB::table('purchases')->insertGetId([
            'business_id'   => $this->business->id,
            'invoiceNumber' => 'P-' . $productId,
            'totalAmount'   => 0,
            'purchaseDate'  => now()->toDateString(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('purchase_details')->insert([
            'purchase_id'        => $purchaseId,
            'product_id'         => $productId,
            'stock_id'           => $stockId,
            'quantities'         => $available,
            'conversionQuantity' => $available,
            'conversionValue'    => 1,
            'purchaseUpperLimit' => 0,
            'purchaseLowerLimit' => 0,
        ]);

        return $productId;
    }

    /** @return array<int, string> */
    private function reportedNames(): array
    {
        $data = $this->getJson('/api/v1/low-stock-products')->assertOk()->json('data');

        if (is_array($data) && isset($data['data'])) {
            $data = $data['data'];
        }

        return array_map(fn ($row) => $row['productName'], $data ?? []);
    }

    public function test_a_product_sold_down_to_zero_is_reported(): void
    {
        $this->product('Eupen 1 Gm Injection', minimum: 20, available: 0);

        $this->assertContains('Eupen 1 Gm Injection', $this->reportedNames());
    }

    /**
     * The counter's exact sequence: a batch that had stock, sold out entirely.
     *
     * The lot row survives with `conversionQuantity` drained to 0, which the
     * old query read as "nothing here to reorder" and dropped — so the product
     * disappeared from the report at the moment it most needed to be on it.
     * Reported as "I sold all items, now again not showing in low level".
     */
    public function test_a_product_whose_lots_are_drained_is_reported(): void
    {
        $this->product('Monocef 1 Gm Injection', minimum: 20, available: 0, rack: 'INJECTION');

        $this->assertContains('Monocef 1 Gm Injection', $this->reportedNames());
    }

    /** And it survives the rack filter the counter had applied. */
    public function test_a_drained_product_survives_the_rack_filter(): void
    {
        $this->product('Monocef 1 Gm Injection', minimum: 20, available: 0, rack: 'INJECTION');
        $this->product('Elsewhere Entirely', minimum: 20, available: 0, rack: 'GASTRO');

        $data = $this->getJson('/api/v1/low-stock-products?rack=INJECTION')
            ->assertOk()
            ->json('data');

        if (is_array($data) && isset($data['data'])) {
            $data = $data['data'];
        }

        $names = array_map(fn ($row) => $row['productName'], $data ?? []);

        $this->assertContains('Monocef 1 Gm Injection', $names);
        $this->assertNotContains('Elsewhere Entirely', $names);
    }

    /**
     * Reported as: filtered Low Stock by one manufacturer and every other
     * manufacturer's products still showed up.
     *
     * The filter sheet is a name typeahead - same as Product Name, Rack and
     * Shelf above it - so it has never sent an id. The query only ever
     * checked `manufacturer_id`, which nothing sends, so picking a
     * manufacturer silently filtered nothing at all.
     */
    public function test_a_drained_product_survives_the_manufacturer_filter(): void
    {
        $matins = DB::table('manufacturers')->insertGetId([
            'manufacturerName' => 'MATINS',
            'business_id'      => $this->business->id,
        ]);
        $apollo = DB::table('manufacturers')->insertGetId([
            'manufacturerName' => 'APOLLO AGENCY',
            'business_id'      => $this->business->id,
        ]);

        $matinsProductId = $this->product('Fawn', minimum: 2, available: 2);
        DB::table('products')->where('id', $matinsProductId)->update(['manufacturer_id' => $matins]);

        $apolloProductId = $this->product('Knee L', minimum: 2, available: 0);
        DB::table('products')->where('id', $apolloProductId)->update(['manufacturer_id' => $apollo]);

        $data = $this->getJson('/api/v1/low-stock-products?manufacturer=MATINS')
            ->assertOk()
            ->json('data');

        if (is_array($data) && isset($data['data'])) {
            $data = $data['data'];
        }

        $names = array_map(fn ($row) => $row['productName'], $data ?? []);

        $this->assertContains('Fawn', $names);
        $this->assertNotContains('Knee L', $names);
    }

    public function test_a_product_never_purchased_is_reported(): void
    {
        $this->product('Never Stocked', minimum: 10, available: null);

        $this->assertContains('Never Stocked', $this->reportedNames());
    }

    public function test_a_discontinued_product_is_never_reported(): void
    {
        $this->product('Discontinued Syrup', minimum: 0, available: 0);

        $this->assertNotContains('Discontinued Syrup', $this->reportedNames());
    }

    /** Even holding stock, a zero minimum means the shop is done with it. */
    public function test_a_discontinued_product_holding_stock_is_never_reported(): void
    {
        $this->product('Discontinued But Held', minimum: 0, available: 40);

        $this->assertNotContains('Discontinued But Held', $this->reportedNames());
    }

    public function test_a_product_exactly_at_its_minimum_is_reported(): void
    {
        $this->product('Exactly At Minimum', minimum: 50, available: 50);

        $this->assertContains('Exactly At Minimum', $this->reportedNames());
    }

    public function test_a_product_below_its_minimum_is_reported(): void
    {
        $this->product('Below Minimum', minimum: 20, available: 19);

        $this->assertContains('Below Minimum', $this->reportedNames());
    }

    public function test_a_well_stocked_product_is_not_reported(): void
    {
        $this->product('Well Stocked', minimum: 20, available: 500);

        $this->assertNotContains('Well Stocked', $this->reportedNames());
    }

    /**
     * The purchase order report answers the same question, so it follows the
     * same two rules — the counter reported both screens together.
     *
     * @return array<int, string>
     */
    private function purchaseOrderNames(): array
    {
        $data = $this->getJson('/api/v1/purchase-order')->assertOk()->json('data');

        if (is_array($data) && isset($data['data'])) {
            $data = $data['data'];
        }

        return array_map(fn ($row) => $row['productName'], $data ?? []);
    }

    public function test_the_purchase_order_reports_a_product_sold_down_to_zero(): void
    {
        $this->product('Eupen 1 Gm Injection', minimum: 20, available: 0);

        $this->assertContains('Eupen 1 Gm Injection', $this->purchaseOrderNames());
    }

    public function test_the_purchase_order_never_reports_a_discontinued_product(): void
    {
        // `productStock` is the maximum level, which feeds the shortage sum —
        // so a discontinued product can surface here even holding nothing.
        DB::table('products')->insert([
            'productName'  => 'Discontinued With Max',
            'productCode'  => 'DISC-MAX',
            'business_id'  => $this->business->id,
            'alert_qty'    => 0,
            'productStock' => 500,
        ]);

        $this->assertNotContains('Discontinued With Max', $this->purchaseOrderNames());
    }

    public function test_another_businesss_product_is_not_reported(): void
    {
        $otherBusiness = Business::factory()->create();

        DB::table('products')->insert([
            'productName' => 'Someone Elses Stock',
            'productCode' => 'OTHER-1',
            'business_id' => $otherBusiness->id,
            'alert_qty'   => 20,
        ]);

        $this->assertNotContains('Someone Elses Stock', $this->reportedNames());
    }
}
