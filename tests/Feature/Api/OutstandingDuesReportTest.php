<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Party;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Who owes what right now" as one exportable report, combined across
 * suppliers (`parties`) and customers (`customers`) — distinct from the
 * `dues`/`customer-dues` endpoints, which are a history of collections
 * already made rather than the current balance.
 */
class OutstandingDuesReportTest extends TestCase
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
            'role' => 'shop-owner',
        ]);
    }

    public function test_it_combines_supplier_and_customer_dues(): void
    {
        Party::create([
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'name' => 'Owed Supplier',
            'due' => 500,
        ]);
        Customer::create([
            'business_id' => $this->business->id,
            'type' => 'Customer',
            'name' => 'Owing Customer',
            'due' => 250,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/outstanding-dues-report')
            ->assertSuccessful();

        $names = collect($response->json('data.data'))->pluck('name');

        $this->assertTrue($names->contains('Owed Supplier'));
        $this->assertTrue($names->contains('Owing Customer'));
        $this->assertSame(2, $response->json('totals.contacts'));
    }

    public function test_a_contact_with_no_due_is_excluded(): void
    {
        Party::create([
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'name' => 'Settled Supplier',
            'due' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/outstanding-dues-report')
            ->assertSuccessful();

        $this->assertSame(0, $response->json('totals.contacts'));
    }

    public function test_totals_are_computed_over_the_full_set_not_the_page(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Party::create([
                'business_id' => $this->business->id,
                'type' => 'Supplier',
                'name' => "Supplier {$i}",
                'due' => 100,
            ]);
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/outstanding-dues-report?per_page=1')
            ->assertSuccessful();

        // Only one row is on the page, but the total spans every matching row.
        $this->assertCount(1, $response->json('data.data'));

        // Suppliers, so the balance is what the shop owes.
        $this->assertEquals(300, $response->json('totals.payable'));
        $this->assertEquals(0, $response->json('totals.receivable'));
    }

    /**
     * Dates select contacts whose debt arose in the period, not contacts added in it.
     *
     * The filter used to run on `created_at` — when the contact record was
     * first written. A shop's customers are added once and then owe money for
     * years, so any range narrower than "since we opened" matched nobody: 175
     * contacts holding real balances filtered to none and the report looked
     * broken rather than empty.
     */
    public function test_dates_select_contacts_who_traded_in_the_period(): void
    {
        // Added long ago, bought recently, still owes.
        $longStanding = Party::create([
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'name' => 'Long Standing Supplier',
            'due' => 100,
        ]);
        $longStanding->forceFill(['created_at' => '2024-01-01'])->save();

        Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'party_id' => $longStanding->id,
            'purchaseDate' => '2026-06-15',
            'totalAmount' => 100,
            'dueAmount' => 100,
        ]);

        // Owes, but has not traded in this window.
        $dormant = Party::create([
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'name' => 'Dormant Supplier',
            'due' => 100,
        ]);
        $dormant->forceFill(['created_at' => '2026-06-10'])->save();

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/outstanding-dues-report?from_date=2026-06-01&to_date=2026-06-30'
        )->assertSuccessful();

        $names = collect($response->json('data.data'))->pluck('name');

        $this->assertTrue(
            $names->contains('Long Standing Supplier'),
            'a contact added years ago with a due from this period must appear',
        );
        $this->assertFalse($names->contains('Dormant Supplier'));
    }

    /** A customer's sales count the same way a supplier's purchases do. */
    public function test_a_customers_sale_puts_them_in_range(): void
    {
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Trading Customer',
            'type' => 'Customer',
            'due' => 250,
        ]);
        $customer->forceFill(['created_at' => '2023-05-01'])->save();

        Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'customer_id' => $customer->id,
            'invoiceNumber' => 'OD-1',
            'saleDate' => '2026-06-20',
            'totalAmount' => 250,
            'dueAmount' => 250,
        ]);

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/outstanding-dues-report?from_date=2026-06-01&to_date=2026-06-30'
        )->assertSuccessful();

        $this->assertTrue(
            collect($response->json('data.data'))->pluck('name')->contains('Trading Customer'),
        );
    }

    /** With no dates, every contact who owes is listed. */
    public function test_without_dates_every_owing_contact_is_listed(): void
    {
        Party::create([
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'name' => 'Never Traded Recently',
            'due' => 100,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/outstanding-dues-report')
            ->assertSuccessful();

        $this->assertTrue(
            collect($response->json('data.data'))->pluck('name')->contains('Never Traded Recently'),
        );
    }

    /**
     * Which way the money points comes from the contact's type, not the sign.
     *
     * A supplier's positive `due` is money the shop owes; a customer's is money
     * owed to it. Splitting on the sign alone put every supplier balance into
     * receivable and left payable at ₹0.00 on a report listing three suppliers
     * owed ₹165,285 between them.
     */
    public function test_supplier_balances_are_payable_and_customer_balances_receivable(): void
    {
        Party::create([
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'name' => 'Owed Supplier',
            'due' => 81000,
        ]);

        Customer::create([
            'business_id' => $this->business->id,
            'type' => 'Customer',
            'name' => 'Owing Customer',
            'due' => 500,
        ]);

        $totals = $this->actingAs($this->user)
            ->getJson('/api/v1/outstanding-dues-report')
            ->assertSuccessful()
            ->json('totals');

        $this->assertEqualsWithDelta(81000.0, (float) $totals['payable'], 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $totals['receivable'], 0.01);
    }

    /** A negative balance points the other way for either kind of contact. */
    public function test_a_credit_balance_reverses_the_direction(): void
    {
        // Overpaid supplier: they owe the shop.
        Party::create([
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'name' => 'Overpaid Supplier',
            'due' => -300,
        ]);

        // Customer holding a credit: the shop owes them.
        Customer::create([
            'business_id' => $this->business->id,
            'type' => 'Customer',
            'name' => 'Credited Customer',
            'due' => -120,
        ]);

        $totals = $this->actingAs($this->user)
            ->getJson('/api/v1/outstanding-dues-report')
            ->assertSuccessful()
            ->json('totals');

        $this->assertEqualsWithDelta(300.0, (float) $totals['receivable'], 0.01);
        $this->assertEqualsWithDelta(120.0, (float) $totals['payable'], 0.01);
    }

    /**
     * The report can be narrowed to one direction of money.
     *
     * Uses the same signed expression the header totals do, so a filtered list
     * and its totals cannot disagree about which way a balance points.
     */
    public function test_it_can_be_filtered_to_receivables_only(): void
    {
        Party::create([
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'name' => 'Owed Supplier',
            'due' => 900,
        ]);

        Customer::create([
            'business_id' => $this->business->id,
            'type' => 'Customer',
            'name' => 'Owing Customer',
            'due' => 400,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/outstanding-dues-report?direction=receivable')
            ->assertSuccessful();

        $names = collect($response->json('data.data'))->pluck('name');

        $this->assertTrue($names->contains('Owing Customer'));
        $this->assertFalse($names->contains('Owed Supplier'));
    }

    public function test_it_can_be_filtered_to_payables_only(): void
    {
        Party::create([
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'name' => 'Owed Supplier',
            'due' => 900,
        ]);

        Customer::create([
            'business_id' => $this->business->id,
            'type' => 'Customer',
            'name' => 'Owing Customer',
            'due' => 400,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/outstanding-dues-report?direction=payable')
            ->assertSuccessful();

        $names = collect($response->json('data.data'))->pluck('name');

        $this->assertTrue($names->contains('Owed Supplier'));
        $this->assertFalse($names->contains('Owing Customer'));
    }

    /** A credit balance points the other way, and the filter follows it. */
    public function test_a_customer_credit_is_filtered_as_payable(): void
    {
        Customer::create([
            'business_id' => $this->business->id,
            'type' => 'Customer',
            'name' => 'Credited Customer',
            'due' => -250,
        ]);

        $names = collect(
            $this->actingAs($this->user)
                ->getJson('/api/v1/outstanding-dues-report?direction=payable')
                ->assertSuccessful()
                ->json('data.data')
        )->pluck('name');

        $this->assertTrue($names->contains('Credited Customer'));
    }

    /** An unknown or absent direction narrows nothing. */
    public function test_no_direction_lists_both_sides(): void
    {
        Party::create([
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'name' => 'Owed Supplier',
            'due' => 900,
        ]);

        Customer::create([
            'business_id' => $this->business->id,
            'type' => 'Customer',
            'name' => 'Owing Customer',
            'due' => 400,
        ]);

        $names = collect(
            $this->actingAs($this->user)
                ->getJson('/api/v1/outstanding-dues-report')
                ->assertSuccessful()
                ->json('data.data')
        )->pluck('name');

        $this->assertTrue($names->contains('Owed Supplier'));
        $this->assertTrue($names->contains('Owing Customer'));
    }

    public function test_it_can_be_filtered_by_minimum_due(): void
    {
        Party::create([
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'name' => 'Small Due',
            'due' => 10,
        ]);
        Party::create([
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'name' => 'Big Due',
            'due' => 1000,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/outstanding-dues-report?min_due=500')
            ->assertSuccessful();

        $names = collect($response->json('data.data'))->pluck('name');

        $this->assertTrue($names->contains('Big Due'));
        $this->assertFalse($names->contains('Small Due'));
    }

    public function test_another_businesss_dues_are_not_visible(): void
    {
        $other = Business::factory()->create();
        Party::create([
            'business_id' => $other->id,
            'type' => 'Supplier',
            'name' => 'Other Business Supplier',
            'due' => 500,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/outstanding-dues-report')
            ->assertSuccessful();

        $this->assertSame(0, $response->json('totals.contacts'));
    }

    public function test_search_matches_name_or_phone(): void
    {
        Party::create([
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'name' => 'Medico Supplies',
            'phone' => '9999999999',
            'due' => 500,
        ]);
        Party::create([
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'name' => 'Other Supplier',
            'phone' => '8888888888',
            'due' => 500,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/outstanding-dues-report?search=Medico')
            ->assertSuccessful();

        $names = collect($response->json('data.data'))->pluck('name');

        $this->assertSame(['Medico Supplies'], $names->all());
    }
}
