<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The home screen's summary, as a member of staff sees it.
 *
 * It used to be measured from `last_login_at`, which `LoginAction` stamps on
 * every sign-in. So a cashier's own figures reset to zero the moment they
 * signed back in — including the re-login after an app restart.
 *
 * Reported from the field and confirmed on live data: one staff account had 18
 * sales worth 13,962.92 that day and a home screen reading 0, because all of
 * the selling happened before that session began.
 *
 * The window is the day. Who is counted is a separate question, and staff still
 * see only their own sales.
 */
class StaffSummaryWindowTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();

        $this->staff = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'staff',
            // Signed in a moment ago, after the day's selling.
            'last_login_at' => now(),
        ]);
    }

    private function sale(User $seller, float $amount, string $at): Sale
    {
        $customer = Customer::factory()->create(['business_id' => $this->business->id]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $seller->id,
            'customer_id' => $customer->id,
            'saleDate' => $at,
            'totalAmount' => $amount,
            'paidAmount' => $amount,
            'dueAmount' => 0,
        ]);

        // Eloquent stamps `created_at` itself on create, and the window under
        // test is measured against it, so it is set afterwards.
        $sale->forceFill(['created_at' => $at])->saveQuietly();

        return $sale;
    }

    private function summary(): array
    {
        return $this->actingAs($this->staff)
            ->getJson('/api/v1/summary')
            ->assertStatus(200)
            ->json();
    }

    public function test_selling_done_before_this_session_still_counts(): void
    {
        // Earlier today, then signed in again — the reported shape.
        $this->sale($this->staff, 13962.92, today()->addHours(9)->toDateTimeString());

        $summary = $this->summary();

        $this->assertSame(
            13962.92,
            (float) data_get($summary, 'data.sales'),
            'signing in again must not erase the day already sold',
        );
    }

    public function test_yesterdays_selling_is_not_counted(): void
    {
        $this->sale($this->staff, 500, today()->subDay()->addHours(10)->toDateTimeString());

        $this->assertSame(0.0, (float) data_get($this->summary(), 'data.sales'));
    }

    public function test_staff_still_see_only_their_own_sales(): void
    {
        $colleague = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'staff',
        ]);

        $this->sale($this->staff, 100, today()->addHours(9)->toDateTimeString());
        $this->sale($colleague, 900, today()->addHours(9)->toDateTimeString());

        $this->assertSame(
            100.0,
            (float) data_get($this->summary(), 'data.sales'),
            'widening the window must not widen whose sales are shown',
        );
    }

    public function test_a_staff_member_who_has_never_logged_in_is_not_broken(): void
    {
        $this->staff->update(['last_login_at' => null]);
        $this->sale($this->staff, 250, today()->addHours(8)->toDateTimeString());

        $this->assertSame(250.0, (float) data_get($this->summary(), 'data.sales'));
    }

    public function test_the_period_can_be_narrowed_to_yesterday(): void
    {
        $this->sale($this->staff, 100, today()->addHours(9)->toDateTimeString());
        $this->sale($this->staff, 700, today()->subDay()->addHours(9)->toDateTimeString());

        $summary = $this->actingAs($this->staff)
            ->getJson('/api/v1/summary?period=yesterday')
            ->assertStatus(200)
            ->json();

        $this->assertSame(
            700.0,
            (float) data_get($summary, 'data.sales'),
            'yesterday must end before today begins',
        );
    }

    public function test_a_wider_period_includes_the_days_inside_it(): void
    {
        $this->sale($this->staff, 100, today()->addHours(9)->toDateTimeString());
        $this->sale($this->staff, 700, today()->subDay()->addHours(9)->toDateTimeString());

        $summary = $this->actingAs($this->staff)
            ->getJson('/api/v1/summary?period=year')
            ->assertStatus(200)
            ->json();

        $this->assertSame(800.0, (float) data_get($summary, 'data.sales'));
    }

    public function test_an_unknown_period_is_today(): void
    {
        $this->sale($this->staff, 100, today()->addHours(9)->toDateTimeString());
        $this->sale($this->staff, 700, today()->subDay()->addHours(9)->toDateTimeString());

        $summary = $this->actingAs($this->staff)
            ->getJson('/api/v1/summary?period=nonsense')
            ->assertStatus(200)
            ->json();

        $this->assertSame(100.0, (float) data_get($summary, 'data.sales'));
    }
}
