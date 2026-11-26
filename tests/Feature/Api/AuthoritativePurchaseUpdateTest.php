<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\InventoryChangeLog;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Sale;
use App\Models\SaleDetails;
use App\Models\User;
use App\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing a purchase.
 *
 * `store()` has priced every purchase through `PurchaseCalculationService` for a
 * while. `update()` did not call it at all: it wrote the client's line totals
 * straight to `purchase_details`, and set the header with
 * `$request->all() + [...]` - which, because PHP's array union keeps the LEFT
 * operand, let the request override `user_id` and every money column.
 *
 * So a purchase could not be created with a made-up total, but could be edited
 * into one. An edit is not a lesser operation than a create.
 */
class AuthoritativePurchaseUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Party $supplier;
    private Product $product;

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
            'name' => 'Acme Supplies',
            'type' => 'Supplier',
            'due' => 0,
        ]);

        $vat = Vat::factory()->create([
            'business_id' => $this->business->id,
            'rate' => 18,
        ]);

        $this->product = Product::factory()->create([
            'business_id' => $this->business->id,
            'vat_id' => $vat->id,
            'vat_type' => 'exclusive',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function line(array $overrides = []): array
    {
        return array_merge([
            'product_id' => $this->product->id,
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
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'party_id' => $this->supplier->id,
            'purchaseDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'products' => [$this->line()],
        ], $overrides);
    }

    private function createPurchase(): Purchase
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/purchase', $this->body())
            ->assertSuccessful();

        return Purchase::latest('id')->first();
    }

    /** @param array<string, mixed> $overrides */
    private function edit(Purchase $purchase, array $overrides = [])
    {
        return $this->actingAs($this->user)
            ->putJson("/api/v1/purchase/{$purchase->id}", $this->body($overrides));
    }

    public function test_an_edit_recomputes_the_header_total(): void
    {
        $purchase = $this->createPurchase();

        // 20 x 50 = 1000, plus 18% = 1180.
        //
        // `conversion_quantities` moves with `quantities`: the two are checked
        // against each other, so raising one alone is refused - correctly.
        $this->edit($purchase, [
            'products' => [$this->line([
                'quantities' => 20,
                'conversion_quantities' => 20,
            ])],
        ])->assertSuccessful();

        $this->assertSame(1180.0, (float) $purchase->fresh()->totalAmount);
    }

    public function test_a_purchase_correction_records_before_and_after_values_with_a_reason(): void
    {
        $purchase = $this->createPurchase();

        $this->edit($purchase, [
            'correction_reason' => 'Supplier corrected delivered quantity',
            'products' => [$this->line([
                'quantities' => 12,
                'conversion_quantities' => 12,
            ])],
        ])->assertSuccessful();

        $event = InventoryChangeLog::query()
            ->where('purchase_id', $purchase->id)
            ->where('event_type', 'purchase_corrected')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('Supplier corrected delivered quantity', $event->reason);
        $this->assertSame(10.0, (float) $event->before_values['remaining_quantity']);
        $this->assertSame(12.0, (float) $event->after_values['remaining_quantity']);
    }

    public function test_a_manipulated_header_total_does_not_reach_the_record(): void
    {
        $purchase = $this->createPurchase();

        $this->edit($purchase, ['totalAmount' => 1, 'vat_amount' => 0])
            ->assertSuccessful();

        $fresh = $purchase->fresh();
        $this->assertSame(590.0, (float) $fresh->totalAmount);
        $this->assertSame(90.0, (float) $fresh->vat_amount);
    }

    public function test_a_manipulated_line_total_does_not_reach_the_row(): void
    {
        $purchase = $this->createPurchase();

        $this->edit($purchase, [
            'products' => [$this->line([
                'total_purchase_price' => 1,
                'total_price' => 1,
                'conversion_price' => 1,
            ])],
        ])->assertSuccessful();

        $detail = PurchaseDetails::where('purchase_id', $purchase->id)->firstOrFail();
        $this->assertSame(500.0, (float) $detail->totalPurchasePrice);
    }

    public function test_the_editing_user_is_taken_from_the_session(): void
    {
        $purchase = $this->createPurchase();

        $someoneElse = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'staff',
        ]);

        $this->edit($purchase, ['user_id' => $someoneElse->id])->assertSuccessful();

        $this->assertSame($this->user->id, $purchase->fresh()->user_id);
    }

    public function test_an_edit_cannot_move_the_purchase_to_another_business(): void
    {
        $purchase = $this->createPurchase();
        $otherBusiness = Business::factory()->create();

        $this->edit($purchase, ['business_id' => $otherBusiness->id])->assertSuccessful();

        $this->assertSame(
            (int) $this->business->id,
            (int) $purchase->fresh()->business_id,
        );
    }

    public function test_an_order_discount_reduces_the_taxable_base_on_edit_too(): void
    {
        $purchase = $this->createPurchase();

        $this->edit($purchase, ['discountAmount' => 100, 'discount_type' => 'flat'])
            ->assertSuccessful();

        $fresh = $purchase->fresh();
        // 500 - 100 = 400 taxable, 18% = 72.
        $this->assertSame(72.0, (float) $fresh->vat_amount);
        $this->assertSame(472.0, (float) $fresh->totalAmount);
    }

    public function test_the_due_is_derived_from_what_was_actually_paid(): void
    {
        $purchase = $this->createPurchase();

        $this->edit($purchase, ['paidAmount' => 200, 'dueAmount' => 0])
            ->assertSuccessful();

        $fresh = $purchase->fresh();
        $this->assertSame(200.0, (float) $fresh->paidAmount);
        $this->assertSame(390.0, (float) $fresh->dueAmount, '590 charged, 200 tendered.');
    }

    public function test_a_batch_number_can_be_corrected_before_any_stock_movement(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/purchase', $this->body([
                'products' => [$this->line(['batch_no' => 'ORIGINAL-BATCH'])],
            ]))
            ->assertSuccessful();

        $purchase = Purchase::latest('id')->firstOrFail();
        $detail = PurchaseDetails::where('purchase_id', $purchase->id)->firstOrFail();

        $this->edit($purchase, [
            'products' => [$this->line([
                'stock_id' => $detail->stock_id,
                'batch_no' => 'CORRECTED-BATCH',
            ])],
        ])->assertSuccessful();

        $this->assertSame('CORRECTED-BATCH', $detail->stock->fresh()->batch_no);
    }

    public function test_a_batch_number_cannot_change_after_a_sale_used_that_batch(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/purchase', $this->body([
                'products' => [$this->line(['batch_no' => 'SOLD-BATCH'])],
            ]))
            ->assertSuccessful();

        $purchase = Purchase::latest('id')->firstOrFail();
        $detail = PurchaseDetails::where('purchase_id', $purchase->id)->firstOrFail();
        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'invoiceNumber' => 'S-BATCH-LOCK',
            'saleDate' => now(),
            'totalAmount' => 80,
            'paidAmount' => 80,
            'dueAmount' => 0,
        ]);
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'stock_id' => $detail->stock_id,
            'price' => 80,
            'quantities' => 1,
            'lossProfit' => 30,
        ]);

        $this->edit($purchase, [
            'products' => [$this->line([
                'stock_id' => $detail->stock_id,
                'batch_no' => 'RENAMED-BATCH',
            ])],
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'BATCH_IDENTITY_PROTECTED')
            ->assertJsonPath('current_batch', 'SOLD-BATCH')
            ->assertJsonPath('attempted_batch', 'RENAMED-BATCH');

        $this->assertSame('SOLD-BATCH', $detail->stock->fresh()->batch_no);
    }
}
