<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\DueCollect;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A supplier's account, as a statement.
 *
 * The app could list a supplier's purchases, and separately their returns, and
 * separately the payments made to them. It could not answer the question a shop
 * actually asks — what do we owe them and how did we get there — because that
 * needs the three interleaved in date order with a balance carried down.
 *
 * The balance is the reason this is built server-side. Computed in the app over
 * three partly-loaded lists it would be wrong in the way that matters least
 * visibly: plausible on the first page.
 */
class PartyLedgerTest extends TestCase
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
            'name' => 'Acme Distributors',
            'type' => 'Supplier',
            'gstin' => '29AAACR5055K1Z5',
            'due' => 0,
        ]);
    }

    private function purchase(float $total, string $date, float $due = 0): Purchase
    {
        return Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'party_id' => $this->supplier->id,
            'purchaseDate' => $date,
            'invoiceNumber' => 'P'.fake()->unique()->numberBetween(100, 999),
            'totalAmount' => $total,
            'paidAmount' => $total - $due,
            'dueAmount' => $due,
        ]);
    }

    private function ledger()
    {
        return $this->actingAs($this->user)
            ->getJson("/api/v1/parties/{$this->supplier->id}/ledger");
    }

    public function test_the_account_carries_the_suppliers_registration(): void
    {
        $this->ledger()
            ->assertSuccessful()
            ->assertJsonPath('data.party.gstin', '29AAACR5055K1Z5')
            ->assertJsonPath('data.party.name', 'Acme Distributors');
    }

    public function test_a_purchase_increases_what_is_owed(): void
    {
        $this->purchase(1000, '2026-08-01');

        $response = $this->ledger()->assertSuccessful();

        $this->assertEquals(1000.0, $response->json('data.balance'));
        $this->assertSame(1, $response->json('data.counts.purchases'));
    }

    /** Goods going back reduce the account. */
    public function test_a_return_reduces_what_is_owed(): void
    {
        $purchase = $this->purchase(1000, '2026-08-01');

        $return = PurchaseReturn::create([
            'business_id' => $this->business->id,
            'purchase_id' => $purchase->id,
            'user_id' => $this->user->id,
            'invoice_no' => 'PR1',
            'return_date' => '2026-08-05',
        ]);

        // A return detail points at the purchase line it came off, so the line
        // has to exist for the fixture to be a real one.
        $product = Product::factory()->create(['business_id' => $this->business->id]);

        $detail = PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantities' => 10,
            'conversionQuantity' => 10,
            'conversionValue' => 1,
            'productPurchasePrice' => 100,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);

        PurchaseReturnDetail::create([
            'business_id' => $this->business->id,
            'purchase_return_id' => $return->id,
            'purchase_detail_id' => $detail->id,
            'return_amount' => 300,
            'return_qty' => 1,
        ]);

        $response = $this->ledger()->assertSuccessful();

        $this->assertEquals(700.0, $response->json('data.balance'));
        $this->assertSame(1, $response->json('data.counts.returns'));
    }

    public function test_a_payment_reduces_what_is_owed(): void
    {
        $this->purchase(1000, '2026-08-01');

        DueCollect::create([
            'business_id' => $this->business->id,
            'party_id' => $this->supplier->id,
            'user_id' => $this->user->id,
            'payDueAmount' => 400,
            'paymentType' => 'cash',
            'paymentDate' => '2026-08-10',
        ]);

        $response = $this->ledger()->assertSuccessful();

        $this->assertEquals(600.0, $response->json('data.balance'));
        $this->assertSame(1, $response->json('data.counts.payments'));
    }

    /**
     * The point of the statement: the balance beside each row is what was owed
     * *at that moment*, which only means anything if the three kinds are
     * interleaved by date rather than listed one kind after another.
     */
    public function test_the_balance_is_carried_down_in_date_order(): void
    {
        $this->purchase(1000, '2026-08-01');

        DueCollect::create([
            'business_id' => $this->business->id,
            'party_id' => $this->supplier->id,
            'user_id' => $this->user->id,
            'payDueAmount' => 400,
            'paymentType' => 'cash',
            'paymentDate' => '2026-08-05',
        ]);

        $this->purchase(500, '2026-08-09');

        $entries = $this->ledger()->assertSuccessful()->json('data.entries');

        // Newest first for reading.
        $this->assertEquals(1100.0, $entries[0]['balance']);  // +500 on 600
        $this->assertEquals(600.0, $entries[1]['balance']);   // -400 on 1000
        $this->assertEquals(1000.0, $entries[2]['balance']);
        $this->assertEquals(1100.0, $this->ledger()->json('data.balance'));
    }

    /** The supplier's own bill number is what their statement is written against. */
    public function test_a_purchase_carries_the_supplier_reference(): void
    {
        $purchase = $this->purchase(1000, '2026-08-01');
        $purchase->update(['reference_invoice_no' => 'ACM/2026/8841']);

        $this->ledger()
            ->assertSuccessful()
            ->assertJsonPath('data.entries.0.supplier_reference', 'ACM/2026/8841');
    }

    public function test_an_account_with_no_movement_reads_as_zero(): void
    {
        $response = $this->ledger()->assertSuccessful();

        $this->assertEquals(0.0, $response->json('data.balance'));
        $this->assertSame([], $response->json('data.entries'));
    }

    /**
     * A supplier ledger exposes what a shop buys, at what price, and what it
     * still owes.
     */
    public function test_another_business_cannot_read_the_account(): void
    {
        $outsider = User::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'role' => 'shop-owner',
        ]);

        $this->actingAs($outsider)
            ->getJson("/api/v1/parties/{$this->supplier->id}/ledger")
            ->assertStatus(404);
    }

    /** Another shop's dealings with the same supplier are not this shop's. */
    public function test_only_this_businesss_movements_are_counted(): void
    {
        $this->purchase(1000, '2026-08-01');

        $otherBusiness = Business::factory()->create();
        $otherUser = User::factory()->create([
            'business_id' => $otherBusiness->id,
            'role' => 'shop-owner',
        ]);

        Purchase::create([
            'business_id' => $otherBusiness->id,
            'user_id' => $otherUser->id,
            'party_id' => $this->supplier->id,
            'purchaseDate' => '2026-08-02',
            'invoiceNumber' => 'P999',
            'totalAmount' => 5000,
            'paidAmount' => 0,
            'dueAmount' => 5000,
        ]);

        $response = $this->ledger()->assertSuccessful();

        $this->assertEquals(1000.0, $response->json('data.balance'));
        $this->assertSame(1, $response->json('data.counts.purchases'));
    }

    private function windowedLedger(array $query)
    {
        return $this->actingAs($this->user)->getJson(
            "/api/v1/parties/{$this->supplier->id}/ledger?".http_build_query($query)
        );
    }

    /**
     * A window folds everything before it into an opening balance.
     *
     * The statement is built over the whole account first — a running balance
     * computed from a slice is wrong in a way nobody notices, because it looks
     * plausible on its own — and only then is the window cut. What falls before
     * it becomes the opening figure, exactly as a bank statement does it.
     *
     * Without a window the endpoint returned every movement a supplier had ever
     * made, growing without limit on the shop's oldest accounts.
     */
    public function test_a_window_carries_an_opening_balance(): void
    {
        $this->purchase(1000, '2026-01-10', due: 1000);
        $this->purchase(500, '2026-06-15', due: 500);

        $response = $this->windowedLedger(['from_date' => '2026-06-01'])
            ->assertSuccessful();

        // The January purchase is not listed, but it is still owed.
        $this->assertEqualsWithDelta(1000.0, (float) $response->json('data.opening_balance'), 0.01);
        $this->assertCount(1, $response->json('data.entries'));
        $this->assertEqualsWithDelta(1500.0, (float) $response->json('data.balance'), 0.01);
    }

    /** The rows shown still add up to the closing balance. */
    public function test_the_window_closes_on_the_last_entry_in_it(): void
    {
        $this->purchase(1000, '2026-01-10', due: 1000);
        $this->purchase(500, '2026-06-15', due: 500);
        $this->purchase(300, '2026-09-20', due: 300);

        $response = $this->windowedLedger([
            'from_date' => '2026-06-01',
            'to_date' => '2026-06-30',
        ])->assertSuccessful();

        $this->assertEqualsWithDelta(1000.0, (float) $response->json('data.opening_balance'), 0.01);
        $this->assertCount(1, $response->json('data.entries'));

        // Opening 1000 plus the one entry in the window, not the September one.
        $this->assertEqualsWithDelta(1500.0, (float) $response->json('data.balance'), 0.01);
    }

    /** An unfiltered statement starts from nothing, by definition. */
    public function test_without_a_window_the_opening_balance_is_zero(): void
    {
        $this->purchase(1000, '2026-01-10', due: 1000);

        $response = $this->ledger()->assertSuccessful();

        $this->assertEqualsWithDelta(0.0, (float) $response->json('data.opening_balance'), 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $response->json('data.balance'), 0.01);
    }

    /** A window with nothing in it still reports what was owed going in. */
    public function test_an_empty_window_still_reports_the_opening_balance(): void
    {
        $this->purchase(1000, '2026-01-10', due: 1000);

        $response = $this->windowedLedger([
            'from_date' => '2026-07-01',
            'to_date' => '2026-07-31',
        ])->assertSuccessful();

        $this->assertSame([], $response->json('data.entries'));
        $this->assertEqualsWithDelta(1000.0, (float) $response->json('data.opening_balance'), 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $response->json('data.balance'), 0.01);
    }
}
