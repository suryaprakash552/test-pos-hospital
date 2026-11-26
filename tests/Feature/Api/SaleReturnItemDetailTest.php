<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetails;
use App\Models\SaleReturn;
use App\Models\SaleReturnDetails;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Sales Return report showed a total per transaction with no way to tell
 * which product it was for. The relation chain to get there already existed
 * on the models — `SaleReturnDetails::saleDetail()` to `SaleDetails::product()`
 * — it was just never eager-loaded or serialized, so the app's own model had
 * nowhere to read a product name from.
 */
class SaleReturnItemDetailTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private SaleReturn $return;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
        $customer = Customer::factory()->create([
            'business_id' => $this->business->id,
            'due' => 0,
        ]);

        $this->product = Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => 'Paracetamol 500mg',
            'productCode' => 'PARA-500',
        ]);

        $stock = Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $this->product->id,
            'productStock' => 5,
        ]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'customer_id' => $customer->id,
            'invoiceNumber' => 'RET-ITEM-1',
            'saleDate' => now(),
            'totalAmount' => 400,
            'paidAmount' => 400,
            'dueAmount' => 0,
            'lossProfit' => 160,
            'isPaid' => 1,
            'paymentType' => 'cash',
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'stock_id' => $stock->id,
            'price' => 100,
            'quantities' => 4,
            'lossProfit' => 160,
        ]);

        $this->return = SaleReturn::create([
            'business_id' => $this->business->id,
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'return_amount' => 100,
        ]);

        SaleReturnDetails::create([
            'business_id' => $this->business->id,
            'sale_return_id' => $this->return->id,
            'sale_detail_id' => $detail->id,
            'return_amount' => 100,
            'return_qty' => 1,
        ]);
    }

    public function test_the_list_names_the_product_that_was_returned(): void
    {
        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/sales-return?start_date=2000-01-01&end_date=2100-01-01'
        )->assertSuccessful();

        $detail = $response->json('data.0.details.0');

        $this->assertSame(
            'Paracetamol 500mg',
            $detail['sale_detail']['product']['productName'] ?? null,
            'the report has nowhere to show the returned product from'
        );
    }

    public function test_a_single_return_names_the_product_too(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/sales-return/{$this->return->id}")
            ->assertSuccessful();

        $detail = $response->json('data.details.0');

        $this->assertSame(
            'Paracetamol 500mg',
            $detail['sale_detail']['product']['productName'] ?? null,
        );
    }

    /**
     * The sales return **report** does not read `/sales-return` — it reads
     * `/sales` (via `fetchSalesList()`) and pulls the return detail out of
     * each sale's nested `saleReturns`. `AcnooSaleController::index` eager
     * loads that chain independently of `SaleReturnController`, so it needed
     * the same fix a second time.
     */
    public function test_the_sales_list_the_report_actually_reads_names_the_product(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/sales')
            ->assertSuccessful();

        $sales = collect($response->json('data.data'))
            ->firstWhere('invoiceNumber', 'RET-ITEM-1');

        $this->assertNotNull($sales, 'the seeded sale is not in the list');

        // Laravel serialises the `saleReturns()` relation method name as
        // snake_case by default.
        $detail = $sales['sale_returns'][0]['details'][0] ?? null;

        $this->assertSame(
            'Paracetamol 500mg',
            $detail['sale_detail']['product']['productName'] ?? null,
            'the report reads /sales, not /sales-return, and this is the '
                .'relation chain it walks'
        );
    }

    public function test_another_businesss_return_is_not_reachable(): void
    {
        $other = User::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'role' => 'shop-owner',
        ]);

        $this->actingAs($other)
            ->getJson("/api/v1/sales-return/{$this->return->id}")
            ->assertStatus(404);
    }
}
