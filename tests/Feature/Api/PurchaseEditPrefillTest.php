<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the app is given when a saved purchase is reopened.
 *
 * Reported as "the previously entered values are not properly prefilled": a
 * bill whose invoice reads ₹8,500 with ₹100 off reopens for editing as ₹9,000
 * with ₹1,000 off. Both reach the same ₹8,400, so the arithmetic on each screen
 * is self-consistent — which is exactly why it is worth pinning down which of
 * the two figures the payload actually supports.
 */
class PurchaseEditPrefillTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Party $supplier;
    private Purchase $purchase;

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
            'name' => 'Pulsewave',
            'type' => 'Supplier',
            'due' => 0,
        ]);
    }

    /** One line of the reported bill. */
    private function line(Purchase $purchase, float $quantity, float $price): PurchaseDetails
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);

        $stock = Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'productStock' => $quantity,
        ]);

        return PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => $quantity,
            'conversionQuantity' => $quantity,
            'conversionValue' => 1,
            'productPurchasePrice' => $price,
            'productSalePrice' => $price * 1.5,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);
    }

    /**
     * A bill of ₹9,000: 30x50 + 30x50 + 20x300.
     */
    private function billWithLines(): array
    {
        $this->purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'party_id' => $this->supplier->id,
            'purchaseDate' => now(),
            'totalAmount' => 8400,
            'discountAmount' => 1000,
            'paidAmount' => 0,
            'dueAmount' => 8400,
        ]);

        return [
            $this->line($this->purchase, 30, 50),
            $this->line($this->purchase, 30, 50),
            $this->line($this->purchase, 20, 300),
        ];
    }

    private function reopen(): array
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/purchase/{$this->purchase->id}")
            ->assertOk();

        return $response->json('data');
    }

    /** The bill amount the *invoice* prints: quantity net of returns. */
    private function invoiceBillAmount(array $payload): float
    {
        $returnedByDetail = collect($payload['purchase_returns'] ?? [])
            ->flatMap(fn ($return) => $return['purchase_return_details'] ?? [])
            ->groupBy('purchase_detail_id')
            ->map(fn ($lines) => collect($lines)->sum(fn ($line) => (float) $line['return_qty']));

        return collect($payload['details'] ?? [])->sum(function ($line) use ($returnedByDetail) {
            $returned = (float) ($returnedByDetail[$line['id']] ?? 0);

            return ((float) $line['quantities'] - $returned) * (float) $line['productPurchasePrice'];
        });
    }

    /** The subtotal the billing screen rebuilds: quantity x purchase price. */
    private function editSubtotal(array $payload): float
    {
        return collect($payload['details'] ?? [])->sum(
            fn ($line) => (float) $line['quantities'] * (float) $line['productPurchasePrice']
        );
    }

    public function test_an_untouched_bill_reopens_at_the_figures_it_was_saved_with(): void
    {
        $this->billWithLines();

        $payload = $this->reopen();

        $this->assertSame(9000.0, $this->editSubtotal($payload));
        $this->assertEquals(1000, $payload['discountAmount']);
        $this->assertEquals(8400, $payload['totalAmount']);
    }

    /**
     * A return rewrites the purchase it came from.
     *
     * Recorded here as a finding, not as an approved behaviour. Returning 10 of
     * 30 units does not leave the bill alone and record a movement against it —
     * it edits the original line down to 20, so the purchase document stops
     * saying what was purchased. Everything downstream then reads the reduced
     * figure: the invoice, the edit screen, and any report that sums line
     * quantities.
     *
     * That is what makes the reported "prefill" fault so hard to see from the
     * outside: both screens agree with the stored line, and the stored line is
     * no longer the bill that was signed for.
     *
     * This test pins the behaviour as it stands so that changing it is a
     * deliberate act with a visible diff, and so the inventory plan has a
     * reproduction to point at.
     */
    public function test_a_return_reduces_the_original_purchase_line(): void
    {
        [$first] = $this->billWithLines();

        $this->assertSame(30.0, (float) $first->quantities);

        // 10 of the 30 at ₹50 go back: ₹500 of the ₹9,000.
        $this->actingAs($this->user)->postJson('/api/v1/purchases-return', [
            'purchase_id' => $this->purchase->id,
            'return_date' => now()->toDateString(),
            'purchase_detail_id' => [$first->id],
            'return_qty' => [10],
            'return_amount' => [500],
        ])->assertStatus(200);

        $first->refresh();

        // The line itself was edited, rather than the return being recorded
        // against an untouched line.
        $this->assertSame(20.0, (float) $first->quantities);
        $this->assertSame(20.0, (float) $first->conversionQuantity);

        // So a bill of ₹9,000 reopens — and prints — as ₹8,500.
        $this->assertSame(8500.0, $this->editSubtotal($this->reopen()));
    }
}
