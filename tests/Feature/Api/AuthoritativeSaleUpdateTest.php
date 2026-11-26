<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Doctor;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\User;
use App\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing a sale.
 *
 * `store()` has priced every sale through `CartCalculationService` for a while.
 * `update()` never called it: the write was
 * `$sale->update($request->except('image', 'isPaid') + [...])`, which let every
 * money column through from the client.
 *
 * So a sale could not be created with a made-up total, but could be edited into
 * one - and an edited sale never picked up the taxable-base rule either, so the
 * same basket billed differently depending on whether it had been touched since.
 */
class AuthoritativeSaleUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Customer $customer;
    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
        $this->customer = Customer::factory()->create([
            'business_id' => $this->business->id,
            'type' => 'Customer',
            'due' => 0,
        ]);

        $vat = Vat::create([
            'business_id' => $this->business->id,
            'name' => 'GST 18',
            'rate' => 18,
        ]);

        $product = Product::create([
            'business_id' => $this->business->id,
            'productName' => 'Widget',
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'vat_id' => $vat->id,
            'vat_type' => 'exclusive',
        ]);

        $this->stock = Stock::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => 'B1',
            'productStock' => 100,
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'conversionValue' => 1,
        ]);

        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'purchaseDate' => now(),
            'totalAmount' => 6000,
        ]);

        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $this->stock->id,
            'quantities' => 100,
            'conversionQuantity' => 100,
            'conversionValue' => 1,
            'productPurchasePrice' => 60,
            'productSalePrice' => 100,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'products' => json_encode([[
                'stock_id' => $this->stock->id,
                'quantities' => 2,
                'lossProfit' => 0,
            ]]),
            'saleDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'customer_id' => $this->customer->id,
            'totalAmount' => 0,
            'paidAmount' => 236,
        ], $overrides);
    }

    private function createSale(): Sale
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/sales', $this->body())
            ->assertSuccessful();

        return Sale::latest('id')->first();
    }

    /** @param array<string, mixed> $overrides */
    private function edit(Sale $sale, array $overrides = [])
    {
        return $this->actingAs($this->user)
            ->putJson("/api/v1/sales/{$sale->id}", $this->body($overrides));
    }

    public function test_a_created_sale_is_priced_by_the_server(): void
    {
        // 2 x 100 = 200, plus 18% = 236. The baseline the edits are measured on.
        $this->assertSame(236.0, (float) $this->createSale()->totalAmount);
    }

    public function test_a_manipulated_total_does_not_survive_an_edit(): void
    {
        $sale = $this->createSale();

        $this->edit($sale, ['totalAmount' => 1, 'vat_amount' => 0])
            ->assertSuccessful();

        $fresh = $sale->fresh();
        $this->assertSame(236.0, (float) $fresh->totalAmount);
        $this->assertSame(36.0, (float) $fresh->vat_amount);
    }

    public function test_an_edit_recomputes_the_total_from_the_new_quantities(): void
    {
        $sale = $this->createSale();

        $this->edit($sale, [
            'products' => json_encode([[
                'stock_id' => $this->stock->id,
                'quantities' => 5,
                'lossProfit' => 0,
            ]]),
        ])->assertSuccessful();

        // 5 x 100 = 500, plus 18% = 590.
        $this->assertSame(590.0, (float) $sale->fresh()->totalAmount);
    }

    public function test_an_order_discount_reduces_the_taxable_base_on_edit_too(): void
    {
        $sale = $this->createSale();

        $this->edit($sale, ['discountAmount' => 40, 'discount_type' => 'flat'])
            ->assertSuccessful();

        $fresh = $sale->fresh();
        // 200 - 40 = 160 taxable, 18% = 28.80.
        $this->assertSame(28.8, (float) $fresh->vat_amount);
        $this->assertSame(188.8, (float) $fresh->totalAmount);
    }

    public function test_the_due_is_derived_from_what_was_actually_tendered(): void
    {
        $sale = $this->createSale();

        $this->edit($sale, ['paidAmount' => 100, 'dueAmount' => 0])
            ->assertSuccessful();

        $fresh = $sale->fresh();
        $this->assertSame(100.0, (float) $fresh->paidAmount);
        $this->assertSame(136.0, (float) $fresh->dueAmount, '236 charged, 100 tendered.');
    }

    public function test_the_editing_user_is_taken_from_the_session(): void
    {
        $sale = $this->createSale();

        $someoneElse = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'staff',
        ]);

        $this->edit($sale, ['user_id' => $someoneElse->id])->assertSuccessful();

        $this->assertSame($this->user->id, $sale->fresh()->user_id);
    }

    public function test_an_edit_cannot_move_the_sale_to_another_business(): void
    {
        $sale = $this->createSale();

        $this->edit($sale, ['business_id' => Business::factory()->create()->id])
            ->assertSuccessful();

        $this->assertSame(
            (int) $this->business->id,
            (int) $sale->fresh()->business_id,
        );
    }
    // ── Editing who the bill is for ─────────────────────────────────────────

    /**
     * Changing the customer on an edit has to stick.
     *
     * Reported as "when editing a sale and changing the customer and
     * relationship, the updated customer and relationship are not being
     * reflected". Both travel as ordinary fields, so this pins that they are
     * actually written rather than dropped on the way through.
     */
    public function test_the_customer_can_be_changed_on_an_edit(): void
    {
        $sale = $this->createSale();

        $other = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Nageshwari',
            'phone' => '9346740322',
            'relationship' => 'wife',
            'due' => 0,
        ]);

        $this->edit($sale, ['customer_id' => $other->id])->assertSuccessful();

        $this->assertSame($other->id, $sale->fresh()->customer_id);
    }

    /**
     * And the relationship recorded on the bill is the bill's own.
     *
     * It is not the customer's standing relationship: a bill can be rung up for
     * a customer's husband against a customer whose record says "wife", and the
     * invoice has to print what was chosen at the till.
     */
    public function test_the_relationship_on_the_bill_can_be_changed_on_an_edit(): void
    {
        $sale = $this->createSale();

        $this->edit($sale, ['relationship' => 'Husband'])->assertSuccessful();

        $this->assertSame('Husband', $sale->fresh()->relationship);
    }

    /** Changing both at once is the reported case, and it is one write. */
    public function test_both_change_together(): void
    {
        $sale = $this->createSale();

        $other = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Nageshwari',
            'phone' => '9346740322',
            'relationship' => 'wife',
            'due' => 0,
        ]);

        $this->edit($sale, [
            'customer_id' => $other->id,
            'relationship' => 'Husband',
        ])->assertSuccessful();

        $fresh = $sale->fresh();

        $this->assertSame($other->id, $fresh->customer_id);
        $this->assertSame('Husband', $fresh->relationship);
    }

    /** An edit that says nothing about it must not wipe what was recorded. */
    public function test_an_edit_that_leaves_it_alone_keeps_it(): void
    {
        $sale = $this->createSale();
        $this->edit($sale, ['relationship' => 'Husband'])->assertSuccessful();

        $this->edit($sale, ['paidAmount' => 100])->assertSuccessful();

        $this->assertSame('Husband', $sale->fresh()->relationship);
    }
    // ── Discount cannot leak or be lost across an edit ──────────────────────

    /**
     * Re-saving a bill unchanged must not move a rupee.
     *
     * This is the fault behind "wrong calculation after edit": the screen sends
     * back what it believes the bill says, and any figure it failed to prefill
     * arrives as a zero. A discount that was on the bill and is not in the
     * payload is not "no change" — it is a silent price rise, and the shop only
     * finds it when the customer queries the total.
     */
    public function test_re_saving_a_discounted_bill_unchanged_keeps_the_discount(): void
    {
        $sale = $this->createSale();

        $this->edit($sale, ['discountAmount' => 40, 'discount_type' => 'flat'])
            ->assertSuccessful();

        $discounted = (float) $sale->fresh()->totalAmount;

        // The same bill saved again, with the same figures.
        $this->edit($sale, ['discountAmount' => 40, 'discount_type' => 'flat'])
            ->assertSuccessful();

        $this->assertSame($discounted, (float) $sale->fresh()->totalAmount);
    }

    /**
     * And a discount cannot be conjured by the client.
     *
     * The server prices the bill; the request states intent. A payload claiming
     * a total that a ₹40 discount does not produce is ignored.
     */
    public function test_the_total_comes_from_the_discount_not_from_the_client(): void
    {
        $sale = $this->createSale();

        $this->edit($sale, [
            'discountAmount' => 40,
            'discount_type' => 'flat',
            // Client claims the bill is nearly free.
            'totalAmount' => 5,
        ])->assertSuccessful();

        // 2 x 100 = 200, less 40, plus 18% of 160 = 188.80.
        $this->assertEqualsWithDelta(188.80, (float) $sale->fresh()->totalAmount, 0.01);
    }

    /** A discount larger than the bill cannot turn a sale into a payout. */
    public function test_a_discount_cannot_exceed_the_bill(): void
    {
        $sale = $this->createSale();

        $this->edit($sale, ['discountAmount' => 99999, 'discount_type' => 'flat']);

        $this->assertGreaterThanOrEqual(0, (float) $sale->fresh()->totalAmount);
    }

    /**
     * A percentage stays a percentage.
     *
     * Reported on the sale edit screen: a 5% bill reopened with the rupee value
     * in the flat box, so re-saving billed a fixed ₹74.50 that no longer scaled
     * with the cart. The server is the guard: given a percentage, it recomputes
     * from the lines.
     */
    public function test_a_percentage_discount_is_recomputed_from_the_lines(): void
    {
        $sale = $this->createSale();

        $this->edit($sale, [
            'discount_percent' => 10,
            'discount_type' => 'percentage',
            'products' => json_encode([[
                'stock_id' => $this->stock->id,
                'quantities' => 4,
                'lossProfit' => 0,
            ]]),
        ])->assertSuccessful();

        // 4 x 100 = 400, less 10% = 360, plus 18% = 424.80.
        $this->assertEqualsWithDelta(424.80, (float) $sale->fresh()->totalAmount, 0.01);
    }
    // ── The prescribing doctor ──────────────────────────────────────────────

    /**
     * Changing the prescribing doctor on an edit has to stick.
     *
     * Reported as "changing the prescribed doctor while editing a sale is not
     * being applied". The screen half of it was the field coming up empty, which
     * meant a save sent `doctor_id: null` and *cleared* the doctor off a bill
     * that had one. This pins the other half: the value the screen sends is the
     * value that gets written.
     */
    public function test_the_prescribing_doctor_can_be_changed_on_an_edit(): void
    {
        $first = Doctor::create([
            'business_id' => $this->business->id,
            'name' => 'Dr Mogilesh',
        ]);
        $second = Doctor::create([
            'business_id' => $this->business->id,
            'name' => 'Dr Second',
        ]);

        $sale = $this->createSale();

        $this->edit($sale, ['doctor_id' => $first->id])->assertSuccessful();
        $this->assertSame($first->id, $sale->fresh()->doctor_id);

        $this->edit($sale, ['doctor_id' => $second->id])->assertSuccessful();
        $this->assertSame($second->id, $sale->fresh()->doctor_id);
    }

    /** And clearing it is a change too, not an omission to ignore. */
    public function test_the_doctor_can_be_cleared_on_an_edit(): void
    {
        $doctor = Doctor::create([
            'business_id' => $this->business->id,
            'name' => 'Dr Mogilesh',
        ]);

        $sale = $this->createSale();
        $this->edit($sale, ['doctor_id' => $doctor->id])->assertSuccessful();

        $this->edit($sale, ['doctor_id' => null])->assertSuccessful();

        $this->assertNull($sale->fresh()->doctor_id);
    }
}
