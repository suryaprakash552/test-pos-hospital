<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerDueCollect;
use App\Models\PaymentType;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Collecting a customer's outstanding balance.
 *
 * `CustomerDueCollect::create($request->all() + [...])` read as "server defaults on
 * the right", but PHP's array union keeps the LEFT operand's keys - so the request
 * won every collision. `user_id`, `business_id`, `sale_id`, `totalDue` and
 * `dueAmountAfterPay` are all fillable, which let a caller name someone else as the
 * collector, restate what the debt had been, or post the row into another tenant.
 *
 * Separately, the receipt's "Collected By" was blank because `index()` never eager
 * loaded `user`, even though `user_id` had always been stored.
 */
class CustomerDueCollectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Customer $customer;
    private PaymentType $paymentType;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);

        $this->customer = Customer::factory()->create([
            'business_id' => $business->id,
            'due' => 1000,
        ]);

        // No factory exists for this model; the table is three meaningful columns.
        $this->paymentType = PaymentType::create([
            'business_id' => $business->id,
            'name' => 'Cash',
            'status' => 1,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function collect(array $overrides = [])
    {
        return $this->actingAs($this->user)->postJson('/api/v1/customer-dues', $overrides + [
            'customer_id' => $this->customer->id,
            'payment_type_id' => $this->paymentType->id,
            'paymentDate' => now()->format('Y-m-d'),
            'payDueAmount' => 400,
        ]);
    }

    public function test_a_collection_reduces_the_customers_balance(): void
    {
        $this->collect()->assertSuccessful();

        $this->assertSame(600.0, (float) $this->customer->fresh()->due);
        $this->assertSame(400.0, (float) CustomerDueCollect::first()->payDueAmount);
    }

    public function test_due_invoice_list_includes_five_bills_from_another_user(): void
    {
        $creator = User::factory()->create(['business_id' => $this->user->business_id]);
        for ($i = 1; $i <= 5; $i++) {
            Sale::create([
                'business_id' => $this->user->business_id,
                'user_id' => $creator->id,
                'customer_id' => $this->customer->id,
                'invoiceNumber' => 'QA-DUE-'.$i,
                'saleDate' => now(),
                'totalAmount' => 500,
                'paidAmount' => 300,
                'dueAmount' => 200,
                'cash_amount' => 100,
                'upi_amount' => 200,
            ]);
        }
        $this->actingAs($this->user)
            ->getJson('/api/v1/invoices?customer_id='.$this->customer->id)
            ->assertSuccessful()
            ->assertJsonCount(5, 'data.sales_dues');
    }

    public function test_staff_member_can_view_other_staff_member_due_bills(): void
    {
        $creator = User::factory()->create([
            'business_id' => $this->user->business_id,
            'role' => 'staff',
        ]);
        $collector = User::factory()->create([
            'business_id' => $this->user->business_id,
            'role' => 'staff',
        ]);
        Sale::create([
            'business_id' => $this->user->business_id,
            'user_id' => $creator->id,
            'customer_id' => $this->customer->id,
            'invoiceNumber' => 'QA-OTHER-STAFF',
            'saleDate' => now(),
            'totalAmount' => 600,
            'paidAmount' => 300,
            'dueAmount' => 300,
            'cash_amount' => 100,
            'upi_amount' => 200,
        ]);
        $this->actingAs($collector)
            ->getJson('/api/v1/invoices?customer_id='.$this->customer->id)
            ->assertSuccessful()
            ->assertJsonPath('data.sales_dues.0.invoiceNumber', 'QA-OTHER-STAFF');
    }

    public function test_the_collector_is_the_authenticated_user(): void
    {
        $this->collect()->assertSuccessful();

        $this->assertSame($this->user->id, CustomerDueCollect::first()->user_id);
    }

    public function test_a_client_cannot_name_someone_else_as_the_collector(): void
    {
        $someoneElse = User::factory()->create([
            'business_id' => $this->user->business_id,
            'role' => 'staff',
        ]);

        $this->collect(['user_id' => $someoneElse->id])->assertSuccessful();

        $this->assertSame(
            $this->user->id,
            CustomerDueCollect::first()->user_id,
            'The collector must come from the session, not the payload.',
        );
    }

    public function test_a_client_cannot_restate_the_outstanding_balance(): void
    {
        $this->collect(['totalDue' => 999999, 'dueAmountAfterPay' => 0])->assertSuccessful();

        $collection = CustomerDueCollect::first();
        $this->assertSame(1000.0, (float) $collection->totalDue);
        $this->assertSame(600.0, (float) $collection->dueAmountAfterPay);
    }

    public function test_a_client_cannot_post_the_collection_into_another_business(): void
    {
        $otherBusiness = Business::factory()->create();

        $this->collect(['business_id' => $otherBusiness->id])->assertSuccessful();

        $this->assertSame(
            (int) $this->user->business_id,
            (int) CustomerDueCollect::first()->business_id,
        );
    }

    public function test_a_client_cannot_attach_the_collection_to_an_unrelated_sale(): void
    {
        // No SaleFactory exists; the columns below are what `sales` requires.
        $foreignSale = Sale::create([
            'business_id' => Business::factory()->create()->id,
            'user_id' => $this->user->id,
            'invoiceNumber' => 'FOREIGN-1',
            'saleDate' => now(),
            'totalAmount' => 100,
            'paidAmount' => 0,
            'dueAmount' => 100,
        ]);

        $this->collect(['sale_id' => $foreignSale->id])->assertSuccessful();

        $this->assertNull(
            CustomerDueCollect::first()->sale_id,
            'sale_id is derived from the looked-up invoice, never taken from the request.',
        );
    }

    public function test_paying_more_than_is_owed_is_refused(): void
    {
        $this->collect(['payDueAmount' => 1500])->assertStatus(400);

        $this->assertSame(0, CustomerDueCollect::count());
        $this->assertSame(1000.0, (float) $this->customer->fresh()->due);
    }

    public function test_the_listing_carries_the_collector_for_the_receipt(): void
    {
        $this->collect()->assertSuccessful();

        $this->actingAs($this->user)
            ->getJson('/api/v1/customer-dues')
            ->assertSuccessful()
            // "Collected By" renders from this relation; without it the field was
            // blank on every printed due receipt.
            ->assertJsonPath('data.0.user.name', $this->user->name);
    }

    public function test_the_listing_carries_the_relationship_saved_on_the_sale(): void
    {
        $sale = Sale::create([
            'business_id' => $this->user->business_id,
            'customer_id' => $this->customer->id,
            'user_id' => $this->user->id,
            'invoiceNumber' => 'DUE-REL-1',
            'saleDate' => now(),
            'totalAmount' => 1000,
            'paidAmount' => 0,
            'dueAmount' => 1000,
            'relationship' => 'wife',
        ]);

        $this->collect(['invoiceNumber' => $sale->invoiceNumber])
            ->assertSuccessful()
            ->assertJsonPath('data.sale.relationship', 'wife');

        $this->actingAs($this->user)
            ->getJson('/api/v1/customer-dues')
            ->assertSuccessful()
            ->assertJsonPath('data.0.sale.relationship', 'wife');
    }

    public function test_the_store_response_carries_the_collector_and_method(): void
    {
        $this->collect()
            ->assertSuccessful()
            ->assertJsonPath('data.user.name', $this->user->name)
            ->assertJsonPath('data.payment_type.name', $this->paymentType->name);
    }
}
