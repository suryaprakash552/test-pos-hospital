<?php

namespace Tests\Feature;

use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The deployment health check.
 *
 * It exists to turn "the app is not stable and we cannot submit sales" into a list
 * of rows. A shop reports the app is broken; the till is in fact refusing sales
 * because the data says there is nothing to sell, and the message it shows —
 * "Available stock is : 0" — looks like a bug when the shelf is visibly full.
 *
 * Its numbers are the thing being trusted, so they are what is asserted here. A
 * check that under-reports is worse than none: it sends someone looking somewhere
 * else while the till stays broken.
 */
class PosHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();

        $this->productId = DB::table('products')->insertGetId([
            'productName' => 'Paracetamol 650',
            'productCode' => 'PARA-650',
            'business_id' => $this->business->id,
        ]);
    }

    private function batch(): int
    {
        return DB::table('stocks')->insertGetId([
            'business_id' => $this->business->id,
            'product_id'  => $this->productId,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /** A purchase lot against [stockId], with [remaining] left of it. */
    private function lot(?int $stockId, ?float $remaining, float $purchased = 100): void
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'business_id'   => $this->business->id,
            'invoiceNumber' => 'P-' . uniqid(),
            'totalAmount'   => 0,
            'purchaseDate'  => now()->toDateString(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('purchase_details')->insert([
            'purchase_id'        => $purchaseId,
            'product_id'         => $this->productId,
            'stock_id'           => $stockId,
            'quantities'         => $purchased,
            'conversionQuantity' => $remaining,
            'purchaseUpperLimit' => 0,
            'purchaseLowerLimit' => 0,
        ]);
    }

    public function test_a_healthy_deployment_passes(): void
    {
        $this->lot($this->batch(), 100);

        $this->artisan('pos:health-check')
            ->expectsOutputToContain('Every batch on this database can be sold from')
            ->assertSuccessful();
    }

    /**
     * The failure the command exists for: stock was purchased, but the remaining
     * quantity was never recorded, so the till refuses every sale of it.
     */
    public function test_it_fails_when_a_batch_cannot_be_sold_from(): void
    {
        $this->lot($this->batch(), null);

        $this->artisan('pos:health-check')
            ->expectsOutputToContain('cannot be sold from')
            ->assertFailed();
    }

    /** It names the product, because a count is not something anyone can act on. */
    public function test_it_names_the_products_a_cashier_cannot_sell(): void
    {
        $this->lot($this->batch(), null);

        $this->artisan('pos:health-check')
            ->expectsOutputToContain('Paracetamol 650')
            ->assertFailed();
    }

    /** A batch nobody ever purchased into is unsellable too, for a different reason. */
    public function test_a_batch_with_no_lots_is_reported(): void
    {
        $this->batch();

        $this->artisan('pos:health-check')
            ->expectsOutputToContain('Batches never purchased into:             1')
            ->assertFailed();
    }

    /**
     * A lot whose batch was cleared is invisible to the till: its stock can never
     * be sold and never be counted, so it has to be reported separately.
     */
    public function test_a_detached_lot_is_counted(): void
    {
        $this->lot($this->batch(), 100);
        $this->lot(null, 50);

        $this->artisan('pos:health-check')
            ->expectsOutputToContain('Lots detached from any batch:             1')
            ->assertSuccessful();
    }

    /** A sold-out batch is healthy — zero remaining is an answer, not a fault. */
    public function test_a_sold_out_batch_is_not_a_fault(): void
    {
        $this->lot($this->batch(), 0);

        $this->artisan('pos:health-check')
            ->expectsOutputToContain('Every batch on this database can be sold from')
            ->assertSuccessful();
    }

    /** It reads only — a diagnostic that repairs would hide what it found. */
    public function test_it_changes_nothing(): void
    {
        $stockId = $this->batch();
        $this->lot($stockId, null);

        $this->artisan('pos:health-check')->assertFailed();

        $this->assertNull(
            DB::table('purchase_details')->value('conversionQuantity'),
            'the check must not repair what it reports',
        );
        $this->assertSame(1, DB::table('stocks')->count());
    }

    public function test_it_can_be_restricted_to_one_business(): void
    {
        $this->lot($this->batch(), null);

        $other = Business::factory()->create();

        $this->artisan('pos:health-check --business=' . $other->id)
            ->expectsOutputToContain('Batches:          0')
            ->assertSuccessful();
    }
}
