<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The quantity the expired-stock list reports.
 *
 * Each row of that list is a *batch*, and a shop reads it to decide what to pull
 * off the shelf. The number has to be what remains of that batch — not of the
 * product, and not of one purchase out of several.
 *
 * A batch is bought more than once: the same lot number arrives on two invoices,
 * and both add to it. A figure that counts one of them tells a pharmacist to
 * destroy less than they are holding.
 */
class ExpiredStockQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        $this->productId = DB::table('products')->insertGetId([
            'productName' => 'Foracort 200 Rotacaps',
            'productCode' => 'FORA-200',
            'business_id' => $this->business->id,
        ]);

        $this->actingAs($this->user);
    }

    /** A batch of this product, expiring on [expireDate]. */
    private function batch(string $batchNo, string $expireDate): int
    {
        return DB::table('stocks')->insertGetId([
            'business_id'     => $this->business->id,
            'product_id'      => $this->productId,
            'batch_no'        => $batchNo,
            'conversionValue' => 10,
            'expire_date'     => $expireDate,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    /** One purchase adding [remaining] units to [stockId]. */
    private function purchase(int $stockId, float $remaining, float $conversion = 10): void
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
            'quantities'         => $remaining,
            'conversionQuantity' => $remaining,
            'conversionValue'    => $conversion,
            'purchaseUpperLimit' => 0,
            'purchaseLowerLimit' => 0,
        ]);
    }

    private function expired()
    {
        return $this->getJson('/api/v1/expired-products?' . http_build_query([
            'per_page' => 50,
        ]));
    }

    /** Finds the row for [batchNo] in the response. */
    private function row($response, string $batchNo): ?array
    {
        foreach (($response->json('data.data') ?? $response->json('data') ?? []) as $r) {
            if (($r['batch_no'] ?? null) === $batchNo) {
                return $r;
            }
        }

        return null;
    }

    /**
     * The reported case: one batch, bought twice.
     *
     * Both invoices added to the same lot, so the batch holds the sum. Reporting
     * one of them tells a pharmacist to pull less than they are holding.
     */
    public function test_a_batch_bought_twice_reports_both_purchases(): void
    {
        $batch = $this->batch('6SA0617', '2020-01-01');

        $this->purchase($batch, 30);
        $this->purchase($batch, 20);

        $row = $this->row($this->expired()->assertOk(), '6SA0617');

        $this->assertNotNull($row, 'the expired batch should be listed');
        $this->assertEquals(
            5,
            $row['available_strip_quantity'],
            '50 units at 10 per strip is 5 strips, across both purchases',
        );
    }

    /**
     * Two batches of the same product must report their own quantities.
     *
     * Summing by product gives every batch the whole product's stock, so a shop
     * destroying one expired lot is told it holds everything.
     */
    public function test_each_batch_reports_only_its_own_stock(): void
    {
        $expiring = $this->batch('OLD-1', '2020-01-01');
        $fresh    = $this->batch('NEW-1', '2030-01-01');

        $this->purchase($expiring, 30);
        $this->purchase($fresh, 70);

        $row = $this->row($this->expired()->assertOk(), 'OLD-1');

        $this->assertNotNull($row);
        $this->assertEquals(
            3,
            $row['available_strip_quantity'],
            'the expired batch holds 30 units, not the product\'s 100',
        );
    }

    /** What has been sold is no longer on the shelf to destroy. */
    public function test_stock_already_sold_is_not_reported(): void
    {
        $batch = $this->batch('SOLD-1', '2020-01-01');

        // 30 bought, 10 since sold, so 20 remain against the lot.
        $this->purchase($batch, 20);

        $row = $this->row($this->expired()->assertOk(), 'SOLD-1');

        $this->assertEquals(2, $row['available_strip_quantity']);
    }

    /** A batch with nothing left is not something to pull off a shelf. */
    public function test_an_empty_batch_reports_zero(): void
    {
        $batch = $this->batch('EMPTY-1', '2020-01-01');

        $this->purchase($batch, 0);

        $row = $this->row($this->expired()->assertOk(), 'EMPTY-1');

        $this->assertEquals(0, $row['available_strip_quantity']);
    }
}
