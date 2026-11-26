<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Party;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The purchase report's filters.
 *
 * The screen has always sent `name`, `phone` and `min_amount` on every request,
 * and this query had no clause for any of them - they were accepted and silently
 * discarded, so the list came back identical however the filters were set.
 *
 * The date and pagination cases are here too, because a filter that works on the
 * first page and is dropped on the second is the same bug wearing a hat.
 */
class PurchaseReportFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Party $acme;
    private Party $globex;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);

        $this->acme = $this->supplier('Acme Supplies', '111111');
        $this->globex = $this->supplier('Globex Trading', '222222');

        $this->purchaseFor($this->acme, 'P-1', 100, '2026-01-10');
        $this->purchaseFor($this->acme, 'P-2', 5000, '2026-02-10');
        $this->purchaseFor($this->globex, 'P-3', 900, '2026-03-10');
    }

    private function supplier(string $name, string $phone): Party
    {
        return Party::create([
            'business_id' => $this->user->business_id,
            'name' => $name,
            'phone' => $phone,
            'type' => 'Supplier',
            'due' => 0,
        ]);
    }

    private function purchaseFor(Party $party, string $invoice, float $total, string $date): Purchase
    {
        return Purchase::create([
            'business_id' => $this->user->business_id,
            'user_id' => $this->user->id,
            'party_id' => $party->id,
            'invoiceNumber' => $invoice,
            'purchaseDate' => $date,
            'totalAmount' => $total,
        ]);
    }

    /** @param array<string, mixed> $query */
    private function invoicesFor(array $query): array
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/purchase?' . http_build_query($query))
            ->assertSuccessful();

        return array_column($response->json('data.data'), 'invoiceNumber');
    }

    public function test_unfiltered_returns_everything(): void
    {
        $this->assertCount(3, $this->invoicesFor([]));
    }

    public function test_the_supplier_name_filter_narrows_the_list(): void
    {
        $this->assertEqualsCanonicalizing(['P-1', 'P-2'], $this->invoicesFor(['name' => 'Acme']));
    }

    public function test_the_name_filter_matches_partially(): void
    {
        $this->assertEqualsCanonicalizing(['P-3'], $this->invoicesFor(['name' => 'globex']));
    }

    public function test_the_phone_filter_narrows_the_list(): void
    {
        $this->assertEqualsCanonicalizing(['P-3'], $this->invoicesFor(['phone' => '222222']));
    }

    public function test_the_minimum_amount_filter_narrows_the_list(): void
    {
        $this->assertEqualsCanonicalizing(['P-2', 'P-3'], $this->invoicesFor(['min_amount' => 900]));
    }

    public function test_filters_combine_rather_than_replace_each_other(): void
    {
        $this->assertEqualsCanonicalizing(
            ['P-2'],
            $this->invoicesFor(['name' => 'Acme', 'min_amount' => 900]),
        );
    }

    public function test_the_date_range_narrows_the_list(): void
    {
        $this->assertEqualsCanonicalizing(
            ['P-2', 'P-3'],
            $this->invoicesFor(['from_date' => '2026-02-01', 'to_date' => '2026-03-31']),
        );
    }

    public function test_a_filter_that_matches_nothing_returns_nothing(): void
    {
        $this->assertSame([], $this->invoicesFor(['name' => 'Nobody Ltd']));
    }

    public function test_pagination_preserves_the_filter(): void
    {
        for ($i = 4; $i <= 15; $i++) {
            $this->purchaseFor($this->acme, "P-{$i}", 100, '2026-01-10');
        }

        $page2 = $this->invoicesFor(['name' => 'Globex', 'per_page' => 5, 'page' => 2]);

        // Globex has exactly one purchase, so a second page of a filtered set is
        // empty. Unfiltered it would be full of Acme's.
        $this->assertSame([], $page2);
    }

    public function test_totals_cover_all_filtered_pages_and_exclude_other_businesses(): void
    {
        Purchase::where('invoiceNumber', 'P-1')->update(['dueAmount' => 40]);
        Purchase::where('invoiceNumber', 'P-2')->update(['dueAmount' => 500]);

        $otherBusiness = Business::factory()->create();
        $otherParty = Party::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Acme Supplies',
            'phone' => '111111',
            'type' => 'Supplier',
            'due' => 0,
        ]);
        Purchase::create([
            'business_id' => $otherBusiness->id,
            'user_id' => $this->user->id,
            'party_id' => $otherParty->id,
            'invoiceNumber' => 'FOREIGN-TOTAL',
            'purchaseDate' => '2026-02-10',
            'totalAmount' => 99999,
            'dueAmount' => 99999,
        ]);

        foreach ([1, 2] as $page) {
            $response = $this->actingAs($this->user)->getJson('/api/v1/purchase?'.http_build_query([
                'name' => 'Acme',
                'per_page' => 1,
                'page' => $page,
                'include_totals' => true,
            ]))->assertSuccessful();

            $response->assertJsonPath('data.total', 2)
                ->assertJsonPath('totals.count', 2);
            $this->assertEquals(5100, $response->json('totals.total'));
            $this->assertEquals(540, $response->json('totals.due'));
            $this->assertEquals(4560, $response->json('totals.received'));
        }

        $history = $this->actingAs($this->user)
            ->getJson('/api/v1/purchase?per_page=1')
            ->assertSuccessful();
        $this->assertArrayNotHasKey('totals', $history->json());
    }

    public function test_another_tenants_purchases_are_never_returned(): void
    {
        $otherBusiness = Business::factory()->create();
        $otherParty = Party::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Acme Supplies',
            'phone' => '111111',
            'type' => 'Supplier',
            'due' => 0,
        ]);

        Purchase::create([
            'business_id' => $otherBusiness->id,
            'user_id' => $this->user->id,
            'party_id' => $otherParty->id,
            'invoiceNumber' => 'FOREIGN-1',
            'purchaseDate' => '2026-01-10',
            'totalAmount' => 100,
        ]);

        // Same name, same phone - the business boundary still has to hold.
        $this->assertEqualsCanonicalizing(
            ['P-1', 'P-2'],
            $this->invoicesFor(['name' => 'Acme']),
        );
    }
}
