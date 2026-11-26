<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\StaffLoginSession;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The staff report showed "No sessions today" against every member of staff.
 *
 * These pin the read path, so a future empty report can be read as "nobody has
 * logged in" rather than "the report is broken" — the two look identical on
 * screen and the counter reasonably assumed the second.
 *
 * A session row exists only when someone with `role = staff` signs in
 * (`App\Actions\Auth\LoginAction`). An owner working the till alone therefore
 * produces no sessions at all, correctly.
 */
class StaffReportSessionsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
    }

    private function staff(string $name): User
    {
        return User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'staff',
            'name' => $name,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function rowFor(string $name, array $query = []): ?array
    {
        $response = $this->actingAs($this->owner)
            ->getJson('/api/v1/staff-reports?' . http_build_query($query))
            ->assertOk();

        $rows = $response->json('data') ?? [];
        if (isset($rows['data'])) {
            $rows = $rows['data'];
        }

        foreach ($rows as $row) {
            if (($row['name'] ?? null) === $name) {
                return $row;
            }
        }

        return null;
    }

    public function test_a_recorded_session_reaches_the_report(): void
    {
        $staff = $this->staff('Ramcharan');

        StaffLoginSession::create([
            'business_id' => $this->business->id,
            'user_id' => $staff->id,
            'login_at' => now()->subHours(3),
            'logout_at' => now()->subHour(),
        ]);

        $row = $this->rowFor('Ramcharan');

        $this->assertNotNull($row, 'the staff member is listed');
        $this->assertNotEmpty(
            $row['sessions'] ?? [],
            'a recorded session must reach the report',
        );
    }

    /** An open session — signed in, not yet signed out — still counts. */
    public function test_an_open_session_reaches_the_report(): void
    {
        $staff = $this->staff('Sandhya');

        StaffLoginSession::create([
            'business_id' => $this->business->id,
            'user_id' => $staff->id,
            'login_at' => now()->subHour(),
            'logout_at' => null,
        ]);

        $this->assertNotEmpty($this->rowFor('Sandhya')['sessions'] ?? []);
    }

    /** With no session rows the staff member is still listed, just empty. */
    public function test_a_staff_member_who_never_signed_in_is_listed_with_no_sessions(): void
    {
        $this->staff('Chathurya');

        $row = $this->rowFor('Chathurya');

        $this->assertNotNull($row, 'listed even with nothing to show');
        $this->assertSame([], $row['sessions'] ?? []);
    }

    /** A date range narrows to sessions that started inside it. */
    public function test_a_date_range_selects_sessions_by_login_time(): void
    {
        $staff = $this->staff('Neelufa');

        StaffLoginSession::create([
            'business_id' => $this->business->id,
            'user_id' => $staff->id,
            'login_at' => now()->subDays(10),
            'logout_at' => now()->subDays(10)->addHours(6),
        ]);

        $inRange = $this->rowFor('Neelufa', [
            'from_date' => now()->subDays(11)->toDateString(),
            'to_date' => now()->subDays(9)->toDateString(),
        ]);
        $this->assertNotEmpty($inRange['sessions'] ?? []);

        $outOfRange = $this->rowFor('Neelufa', [
            'from_date' => now()->subDays(2)->toDateString(),
            'to_date' => now()->toDateString(),
        ]);
        $this->assertSame([], $outOfRange['sessions'] ?? []);
    }

    /** Another business's staff never appear. */
    public function test_another_businesss_staff_are_not_listed(): void
    {
        $otherBusiness = Business::factory()->create();
        User::factory()->create([
            'business_id' => $otherBusiness->id,
            'role' => 'staff',
            'name' => 'Someone Elses Staff',
        ]);

        $this->assertNull($this->rowFor('Someone Elses Staff'));
    }

    /**
     * Tapping a session shows what was sold and bought during it.
     *
     * The session window is `login_at` to `logout_at` (or now, while still
     * signed in), and sales and purchases are reported separately so a shift
     * can be reconciled: this is the screen a shop uses to check a staff
     * member's till against their sessions.
     */
    public function test_a_session_reports_the_sales_made_during_it(): void
    {
        $staff = $this->staff('Surya');

        $session = StaffLoginSession::create([
            'business_id' => $this->business->id,
            'user_id' => $staff->id,
            'login_at' => now()->subHours(4),
            'logout_at' => now()->subHour(),
        ]);

        // Inside the window.
        $during = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $staff->id,
            'invoiceNumber' => 'IN-WINDOW',
            'saleDate' => now()->subHours(2),
            'totalAmount' => 500,
        ]);
        $during->forceFill(['created_at' => now()->subHours(2)])->save();

        // Before the shift started — must not be attributed to it.
        $before = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $staff->id,
            'invoiceNumber' => 'BEFORE-SHIFT',
            'saleDate' => now()->subDays(2),
            'totalAmount' => 900,
        ]);
        $before->forceFill(['created_at' => now()->subDays(2)])->save();

        $response = $this->actingAs($this->owner)
            ->getJson('/api/v1/staff-reports?session_id='.$session->id)
            ->assertOk();

        $body = json_encode($response->json());

        $this->assertStringContainsString('IN-WINDOW', $body);
        $this->assertStringNotContainsString(
            'BEFORE-SHIFT',
            $body,
            'a sale made before the session must not be counted against it',
        );
    }

    /** Purchases are reported separately from sales for the same session. */
    public function test_a_session_reports_its_purchases_separately(): void
    {
        $staff = $this->staff('Ramcharan');

        $session = StaffLoginSession::create([
            'business_id' => $this->business->id,
            'user_id' => $staff->id,
            'login_at' => now()->subHours(4),
            'logout_at' => now()->subHour(),
        ]);

        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $staff->id,
            'invoiceNumber' => 'PUR-IN-WINDOW',
            'purchaseDate' => now()->subHours(2),
            'totalAmount' => 700,
        ]);
        $purchase->forceFill(['created_at' => now()->subHours(2)])->save();

        $body = json_encode(
            $this->actingAs($this->owner)
                ->getJson('/api/v1/staff-reports?session_id='.$session->id)
                ->assertOk()
                ->json()
        );

        $this->assertStringContainsString('PUR-IN-WINDOW', $body);
    }

    /** A session still open counts everything up to now. */
    public function test_an_open_session_counts_up_to_the_present(): void
    {
        $staff = $this->staff('Sandhya');

        $session = StaffLoginSession::create([
            'business_id' => $this->business->id,
            'user_id' => $staff->id,
            'login_at' => now()->subHours(2),
            'logout_at' => null,
        ]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $staff->id,
            'invoiceNumber' => 'STILL-OPEN',
            'saleDate' => now()->subMinutes(30),
            'totalAmount' => 300,
        ]);
        $sale->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $this->assertStringContainsString(
            'STILL-OPEN',
            json_encode(
                $this->actingAs($this->owner)
                    ->getJson('/api/v1/staff-reports?session_id='.$session->id)
                    ->assertOk()
                    ->json()
            ),
        );
    }
}
