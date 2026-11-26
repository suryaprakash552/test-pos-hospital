<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The supplier's own bill number, kept beside ours.
 *
 * `purchases.invoiceNumber` is the number *we* generate — the `#P202` printed at
 * the top of our purchase invoice. The number on the paper the supplier actually
 * delivered had nowhere to live, so once a purchase was saved there was no way
 * back to the document it came from: reconciling a supplier statement, or
 * answering "which bill was this stock on", meant going through them by date and
 * amount.
 */
class PurchaseReferenceInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Party $supplier;

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
    }

    /** @return array<string, mixed> */
    private function line(): array
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);

        return [
            'product_id' => $product->id,
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
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function purchase(array $overrides = [])
    {
        return $this->actingAs($this->user)->postJson('/api/v1/purchase', array_merge([
            'party_id' => $this->supplier->id,
            'purchaseDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'products' => [$this->line()],
        ], $overrides));
    }

    public function test_the_suppliers_bill_number_is_stored(): void
    {
        $this->purchase(['reference_invoice_no' => 'ACM/2026/8841'])
            ->assertSuccessful();

        $this->assertSame('ACM/2026/8841', Purchase::first()->reference_invoice_no);
    }

    /**
     * Ours is generated and theirs is transcribed; they are different facts and
     * one must not overwrite the other.
     */
    public function test_it_does_not_disturb_our_own_invoice_number(): void
    {
        $this->purchase(['reference_invoice_no' => 'ACM/2026/8841'])
            ->assertSuccessful();

        $purchase = Purchase::first();

        $this->assertNotSame(
            $purchase->reference_invoice_no,
            $purchase->invoiceNumber,
            'our generated number is not the supplier reference',
        );
    }

    /**
     * A delivery taken over the counter has no paperwork to reference, which is
     * the ordinary case rather than a missing field.
     */
    public function test_a_purchase_without_a_reference_saves_normally(): void
    {
        $this->purchase()->assertSuccessful();

        $this->assertNull(Purchase::first()->reference_invoice_no);
    }

    public function test_an_over_long_reference_is_refused(): void
    {
        $this->purchase(['reference_invoice_no' => str_repeat('X', 101)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reference_invoice_no');

        $this->assertSame(0, Purchase::count());
    }

    public function test_the_reference_can_be_corrected_on_edit(): void
    {
        $this->purchase(['reference_invoice_no' => 'TYPO/1'])->assertSuccessful();
        $purchase = Purchase::first();

        $this->actingAs($this->user)
            ->putJson("/api/v1/purchase/{$purchase->id}", [
                'party_id' => $this->supplier->id,
                'purchaseDate' => now()->toDateString(),
                'paymentType' => 'cash',
                'reference_invoice_no' => 'ACM/2026/8841',
                'products' => [$this->line()],
            ])
            ->assertSuccessful();

        $this->assertSame('ACM/2026/8841', $purchase->fresh()->reference_invoice_no);
    }

    public function test_it_comes_back_on_the_response_the_invoice_is_built_from(): void
    {
        $this->purchase(['reference_invoice_no' => 'ACM/2026/8841'])
            ->assertSuccessful()
            ->assertJsonPath('data.reference_invoice_no', 'ACM/2026/8841');
    }

    public function test_it_is_listed_so_a_saved_purchase_can_be_traced_back(): void
    {
        $this->purchase(['reference_invoice_no' => 'ACM/2026/8841'])
            ->assertSuccessful();

        $this->actingAs($this->user)
            ->getJson('/api/v1/purchase')
            ->assertSuccessful()
            ->assertJsonPath('data.data.0.reference_invoice_no', 'ACM/2026/8841');
    }
}
