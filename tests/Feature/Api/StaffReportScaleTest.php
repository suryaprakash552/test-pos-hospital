<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The staff report, measured at the size a real employment record reaches.
 *
 * The report walks a staff member's login sessions and totals each one. A cashier
 * who has worked a year has hundreds of sessions, and at 50,000 employees the cost
 * of one of these reports is the cost of the whole feature.
 */
class StaffReportScaleTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $owner;
    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);
        $this->staff = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'staff',
        ]);

        $this->actingAs($this->owner);
    }

    /** Records [count] completed shifts for the staff member. */
    private function seedSessions(int $count): void
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'business_id' => $this->business->id,
                'user_id'     => $this->staff->id,
                'login_at'    => now()->subDays($i)->setTime(9, 0),
                'logout_at'   => now()->subDays($i)->setTime(18, 0),
                'created_at'  => now()->subDays($i),
                'updated_at'  => now()->subDays($i),
            ];
        }

        DB::table('staff_login_sessions')->insert($rows);
    }

    private function queriesForReport(array $query = []): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/v1/staff-reports?' . http_build_query(
            $query + ['staff_id' => $this->staff->id],
        ))->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }

    /**
     * The measurement that matters: does the report's cost grow with how long the
     * employee has worked there?
     *
     * Each session is totalled with its own aggregate queries, so without a bound
     * a long-serving cashier's report issues thousands of them.
     */
    public function test_the_query_count_does_not_grow_with_length_of_service(): void
    {
        $this->seedSessions(20);
        $short = $this->queriesForReport();

        $this->seedSessions(200);
        $long = $this->queriesForReport();

        $this->assertLessThan(
            $short * 3,
            $long,
            "a report over 220 sessions cost {$long} queries against {$short} for 20 — "
                . 'the per-session totals are not bounded',
        );

        // The measured figures before this was bounded: 182 queries for 20 shifts
        // and 1,779 for 220 — eight per shift, growing without limit. The absolute
        // ceiling matters as much as the ratio.
        $this->assertLessThan(
            250,
            $long,
            'one page of shifts, at a few queries each',
        );
    }

    /** The page size is capped, so a caller cannot ask for an entire career. */
    public function test_the_session_page_size_is_capped(): void
    {
        $this->seedSessions(200);

        $response = $this->getJson('/api/v1/staff-reports?' . http_build_query([
            'staff_id' => $this->staff->id,
            'per_page' => 100000,
        ]));

        $response->assertOk();
        $this->assertSame(100, $response->json('sessions_meta.per_page'));
        $this->assertSame(200, $response->json('sessions_meta.total'));
        $this->assertCount(100, $response->json('sessions'));
    }

    /**
     * The totals are the point of the report, so collapsing eight queries into
     * three must not have changed a single figure.
     */
    public function test_the_shift_totals_are_unchanged(): void
    {
        $this->seedSessions(1);

        $session = DB::table('staff_login_sessions')
            ->where('user_id', $this->staff->id)
            ->first();

        foreach ([[500, 300, 200], [250, 250, 0]] as [$total, $cash, $upi]) {
            DB::table('sales')->insert([
                'business_id'         => $this->business->id,
                'user_id'             => $this->staff->id,
                'invoiceNumber'       => 'S-' . uniqid(),
                'totalAmount'         => $total,
                'actual_total_amount' => $total,
                'cash_amount'         => $cash,
                'upi_amount'          => $upi,
                'saleDate'            => $session->login_at,
                'created_at'          => $session->login_at,
                'updated_at'          => $session->login_at,
            ]);
        }

        $response = $this->getJson('/api/v1/staff-reports?' . http_build_query([
            'staff_id'   => $this->staff->id,
            'session_id' => $session->id,
        ]));

        $response->assertOk();

        $shift = $response->json('sessions.0');

        $this->assertSame(2, $shift['sales_count']);
        $this->assertEquals(750, $shift['sales_amount']);
        $this->assertEquals(550, $shift['sales_cash']);
        $this->assertEquals(200, $shift['sales_upi']);
        $this->assertSame(0, $shift['purchase_count']);
    }

    /** A single shift's detail is unaffected by how many other shifts exist. */
    public function test_one_session_is_answered_without_walking_the_rest(): void
    {
        $this->seedSessions(200);

        $sessionId = DB::table('staff_login_sessions')
            ->where('user_id', $this->staff->id)
            ->value('id');

        $queries = $this->queriesForReport(['session_id' => $sessionId]);

        $this->assertLessThan(
            30,
            $queries,
            'asking for one session should cost about one session',
        );
    }
}
