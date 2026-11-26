<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sales history, purchase history and due bills used to be narrowed to the staff
 * member who created them. That hid collectable debt and made returns impossible for
 * anyone but the original biller — a counter is staffed by whoever is on shift.
 *
 * These are now business-wide. The business boundary itself is unchanged and must
 * stay that way: widening visibility within a business must never widen it across
 * businesses, so every case below is paired with a cross-tenant check.
 */
class StaffVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $owner;
    protected User $staff;
    protected User $otherStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);
        // Permission to open these screens is a separate gate from whose records
        // they show; these tests are about the latter, so the permission is granted.
        $this->staff = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'staff',
            'visibility'  => self::STAFF_PERMISSIONS,
        ]);
        $this->otherStaff = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'staff',
            'visibility'  => self::STAFF_PERMISSIONS,
        ]);
    }

    private const STAFF_PERMISSIONS = [
        'sales'     => ['read' => '1'],
        'purchases' => ['read' => '1'],
    ];

    private function saleBy(User $user, ?Business $business = null, float $due = 0): int
    {
        return DB::table('sales')->insertGetId([
            'business_id'   => ($business ?? $this->business)->id,
            'user_id'       => $user->id,
            'invoiceNumber' => 'S-' . uniqid(),
            'totalAmount'   => 100,
            'dueAmount'     => $due,
            'saleDate'      => now()->toDateString(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function purchaseBy(User $user, ?Business $business = null): int
    {
        return DB::table('purchases')->insertGetId([
            'business_id'   => ($business ?? $this->business)->id,
            'user_id'       => $user->id,
            'invoiceNumber' => 'P-' . uniqid(),
            'totalAmount'   => 100,
            'purchaseDate'  => now()->toDateString(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    // ── Sales history (#15) ──────────────────────────────────────────────────

    public function test_staff_see_sales_raised_by_other_staff(): void
    {
        $this->saleBy($this->otherStaff);

        $this->actingAs($this->staff)
            ->getJson('/api/v1/sales')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data');
    }

    public function test_sales_history_never_crosses_the_business_boundary(): void
    {
        $this->saleBy($this->otherStaff);

        $otherBusiness = Business::factory()->create();
        $outsider = User::factory()->create([
            'business_id' => $otherBusiness->id,
            'role'        => 'staff',
            'visibility'  => self::STAFF_PERMISSIONS,
        ]);
        $this->saleBy($outsider, $otherBusiness);

        $this->actingAs($this->staff)
            ->getJson('/api/v1/sales')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data');
    }

    // ── Purchase history (#15) ───────────────────────────────────────────────

    public function test_staff_see_purchases_recorded_by_other_staff(): void
    {
        $this->purchaseBy($this->otherStaff);

        $this->actingAs($this->staff)
            ->getJson('/api/v1/purchase')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data');
    }

    public function test_purchase_history_never_crosses_the_business_boundary(): void
    {
        $this->purchaseBy($this->otherStaff);

        $otherBusiness = Business::factory()->create();
        $outsider = User::factory()->create([
            'business_id' => $otherBusiness->id,
            'role'        => 'staff',
            'visibility'  => self::STAFF_PERMISSIONS,
        ]);
        $this->purchaseBy($outsider, $otherBusiness);

        $this->actingAs($this->staff)
            ->getJson('/api/v1/purchase')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data');
    }

    // ── Due bills (#5) ───────────────────────────────────────────────────────

    private function dueCollectBy(User $user, ?Business $business = null): void
    {
        $forBusiness = $business ?? $this->business;

        $customer = Customer::create([
            'name'        => 'Walk-in',
            'business_id' => $forBusiness->id,
        ]);

        $paymentTypeId = DB::table('payment_types')->insertGetId([
            'business_id' => $forBusiness->id,
            'name'        => 'Cash',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table('customer_due_collects')->insert([
            'business_id'     => $forBusiness->id,
            'user_id'         => $user->id,
            'customer_id'     => $customer->id,
            'payment_type_id' => $paymentTypeId,
            'paymentDate'     => now()->toDateString(),
            'payDueAmount'    => 50,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function test_staff_see_dues_collected_by_other_staff(): void
    {
        $this->dueCollectBy($this->otherStaff);

        $this->actingAs($this->staff)
            ->getJson('/api/v1/customer-dues')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_due_list_never_crosses_the_business_boundary(): void
    {
        $this->dueCollectBy($this->otherStaff);

        $otherBusiness = Business::factory()->create();
        $outsider = User::factory()->create([
            'business_id' => $otherBusiness->id,
            'role'        => 'staff',
            'visibility'  => self::STAFF_PERMISSIONS,
        ]);
        $this->dueCollectBy($outsider, $otherBusiness);

        $this->actingAs($this->staff)
            ->getJson('/api/v1/customer-dues')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_the_due_summary_covers_the_whole_business_for_staff(): void
    {
        // Outstanding raised by someone else, so it used to be invisible.
        $this->saleBy($this->otherStaff, due: 250);
        $this->dueCollectBy($this->otherStaff);

        $staffView = $this->actingAs($this->staff)->getJson('/api/v1/due-summary');
        $ownerView = $this->actingAs($this->owner)->getJson('/api/v1/due-summary');

        $staffView->assertStatus(200);
        $ownerView->assertStatus(200);

        $this->assertSame(
            $ownerView->json('data'),
            $staffView->json('data'),
            'staff and owner must now see the same outstanding position'
        );
    }
}
