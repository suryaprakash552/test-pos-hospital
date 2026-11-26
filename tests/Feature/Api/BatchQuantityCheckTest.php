<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Product;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The check for lots that cannot be sold from.
 *
 * Availability sums `purchase_details.conversionQuantity`. Where it is NULL the
 * sum is zero, so a full shelf reads as out of stock and the till refuses the
 * sale — with nothing on screen explaining why. This command finds those lots
 * before a shop meets them on a Monday morning.
 */
class BatchQuantityCheckTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Product $product;
    protected int $purchaseId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();

        $this->product = Product::create([
            'productName' => 'IV Set',
            'business_id' => $this->business->id,
        ]);

        $this->purchaseId = DB::table('purchases')->insertGetId([
            'business_id'      => $this->business->id,
            'invoiceNumber'    => 'P-1',
            'purchaseDate'     => '2026-07-01',
            'discountAmount'   => 0,
            'shipping_charge'  => 0,
            'discount_percent' => 0,
            'discount_type'    => 'fixed',
            'rowDiscount'      => 0,
            'dueAmount'        => 0,
            'paidAmount'       => 0,
            'cash_amount'      => 0,
            'upi_amount'       => 0,
            'card_amount'      => 0,
            'change_amount'    => 0,
            'totalAmount'      => 0,
            'isPaid'           => 1,
            'vat_percent'      => 0,
            'vat_amount'       => 0,
        ]);
    }

    private function stock(): Stock
    {
        return Stock::create([
            'business_id'  => $this->business->id,
            'product_id'   => $this->product->id,
            'batch_no'     => 'B' . uniqid(),
            'productStock' => 20,
        ]);
    }

    private function lot(?int $stockId, ?float $remaining, float $purchased = 20): int
    {
        return DB::table('purchase_details')->insertGetId([
            'purchase_id'             => $this->purchaseId,
            'product_id'              => $this->product->id,
            'stock_id'                => $stockId,
            'quantities'              => $purchased,
            'conversionQuantity'      => $remaining,
            'productDealerPrice'      => 0,
            'productPurchasePrice'    => 0,
            'conversionPurchasePrice' => 0,
            'productSalePrice'        => 0,
            'productWholeSalePrice'   => 0,
            'purchaseUpperLimit'      => '',
            'purchaseLowerLimit'      => '',
            'totalPrice'              => 0,
            'totalPurchasePrice'      => 0,
        ]);
    }

    public function test_a_healthy_shop_reports_nothing_to_repair(): void
    {
        $this->lot($this->stock()->id, 20);

        $this->artisan('pos:check-batch-quantities')
            ->expectsOutputToContain('Nothing to repair')
            ->assertSuccessful();
    }

    public function test_it_finds_a_lot_with_no_remaining_quantity(): void
    {
        $this->lot($this->stock()->id, null);

        $this->artisan('pos:check-batch-quantities')
            ->expectsOutputToContain('Lots with no remaining quantity recorded: 1')
            ->assertSuccessful();
    }

    /** A lot with no batch is invisible to the sale path entirely. */
    public function test_it_counts_lots_detached_from_a_batch(): void
    {
        $this->lot(null, null);

        $this->artisan('pos:check-batch-quantities')
            ->expectsOutputToContain('detached from any batch (stock_id null): 1')
            ->assertSuccessful();
    }

    /** Read-only unless asked: a report must not quietly change stock figures. */
    public function test_it_changes_nothing_without_the_fix_flag(): void
    {
        $id = $this->lot($this->stock()->id, null);

        $this->artisan('pos:check-batch-quantities')->assertSuccessful();

        $this->assertNull(
            DB::table('purchase_details')->where('id', $id)->value('conversionQuantity'),
        );
    }

    /** Records [quantity] of the product as sold. */
    private function sell(float $quantity): void
    {
        $saleId = DB::table('sales')->insertGetId([
            'business_id'   => $this->business->id,
            'invoiceNumber' => 'S-' . uniqid(),
            'saleDate'      => '2026-08-01',
            'totalAmount'   => 10,
        ]);

        DB::table('sale_details')->insert([
            'sale_id'              => $saleId,
            'product_id'           => $this->product->id,
            'quantities'           => $quantity,
            'price'                => 10,
            'lossProfit'           => 0,
            'productPurchasePrice' => 0,
        ]);
    }

    public function test_it_can_be_restricted_to_one_business(): void
    {
        $this->lot($this->stock()->id, null);

        $this->artisan('pos:check-batch-quantities --business=' . ($this->business->id + 999))
            ->expectsOutputToContain('Lots with no remaining quantity recorded: 0')
            ->assertSuccessful();
    }


    /**
     * Repair mode is refused, and refusing it changes nothing.
     *
     * `--fix` used to derive a lot balance and write it straight onto the row.
     * A lot balance that moves with no source document, no reason and no event
     * is precisely the drift this command exists to report, so the repair was
     * withdrawn in favour of `inventory:reconcile`.
     *
     * The scenarios it used to handle are kept as fixtures below, because what
     * makes a lot repairable or ambiguous is domain knowledge worth holding on
     * to even though nothing acts on it now.
     */
    public function test_repair_mode_is_refused(): void
    {
        $id = $this->lot($this->stock()->id, null, purchased: 17);

        $this->artisan('pos:check-batch-quantities --fix')
            ->expectsOutputToContain('Legacy repair mode is disabled')
            ->assertFailed();

        $this->assertNull(
            DB::table('purchase_details')->where('id', $id)->value('conversionQuantity'),
            'repair mode is refused, so it must not have written a balance',
        );
    }

    /**
     * Refusal does not depend on the shape of the data.
     *
     * A single lot with sales was the case the old repair could derive by
     * arithmetic, and the one most likely to tempt a caller into passing
     * `--fix`. It is refused like every other.
     */
    public function test_repair_is_refused_even_where_the_balance_is_derivable(): void
    {
        $stockId = $this->stock()->id;
        $id = $this->lot($stockId, null, purchased: 13);
        $this->sell(5);

        $this->artisan('pos:check-batch-quantities --fix')->assertFailed();

        $this->assertNull(
            DB::table('purchase_details')->where('id', $id)->value('conversionQuantity'),
        );
    }

    /** An existing balance is never touched, with or without the flag. */
    public function test_an_existing_quantity_survives_both_modes(): void
    {
        $id = $this->lot($this->stock()->id, 4.0, purchased: 10);

        $this->artisan('pos:check-batch-quantities')->assertSuccessful();
        $this->artisan('pos:check-batch-quantities --fix')->assertFailed();

        $this->assertEquals(
            4.0,
            DB::table('purchase_details')->where('id', $id)->value('conversionQuantity'),
        );
    }
}
