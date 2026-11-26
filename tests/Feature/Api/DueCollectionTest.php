<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Party;
use App\Models\PaymentType;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Collecting a customer's outstanding balance.
 *
 * `getAllInvoiceDue()` queried `sales.party_id`, a column the rename to
 * `customer_id` removed. Every customer collection made *without* selecting an
 * invoice threw "Unknown column 'party_id'" - and because the overpayment guard is
 * computed from that figure, the check protecting against paying more than is owed
 * went down with it.
 */
class DueCollectionTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Party $customer;
    private PaymentType $paymentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);

        $this->customer = Party::create([
            'business_id' => $this->business->id,
            'name' => 'Owing Customer',
            'type' => 'Retailer',
            'due' => 500,
        ]);

        // Due collection still resolves the payer as a Party, while sales key on
        // `customers`. The migration that copied non-supplier parties across kept
        // their ids, so the two rows share one id in production - mirrored here so
        // the sales foreign key resolves.
        //
        // The id is forced rather than mass-assigned: `id` is not fillable, so
        // passing it to create() is silently dropped and the two rows only line up
        // when both auto-increment counters happen to agree.
        $mirroredCustomer = new Customer([
            'business_id' => $this->business->id,
            'name' => 'Owing Customer',
            'type' => 'Retailer',
            'due' => 500,
        ]);
        $mirroredCustomer->id = $this->customer->id;
        $mirroredCustomer->save();

        $this->paymentType = PaymentType::create([
            'business_id' => $this->business->id,
            'name' => 'Cash',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function collect(array $overrides = [])
    {
        return $this->actingAs($this->user)->postJson('/api/v1/dues', array_merge([
            'party_id' => $this->customer->id,
            'payment_type_id' => $this->paymentType->id,
            'paymentDate' => now()->format('d/m/Y'),
            'payDueAmount' => 100,
        ], $overrides));
    }

    public function test_a_customer_due_can_be_collected_without_an_invoice(): void
    {
        // This is the path that raised "Unknown column 'party_id'".
        $this->collect()->assertSuccessful();

        $this->assertSame(400.0, (float) $this->customer->fresh()->due);
    }

    public function test_paying_more_than_is_owed_is_refused(): void
    {
        $this->collect(['payDueAmount' => 5000])->assertStatus(400);

        // Untouched.
        $this->assertSame(500.0, (float) $this->customer->fresh()->due);
    }

    public function test_open_invoice_dues_are_counted_against_the_limit(): void
    {
        // 450 of the 500 owed is already tied to an invoice, so only 50 may be
        // collected without naming one. This sum is what the dead column broke.
        Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'invoiceNumber' => 'DUE-1',
            'saleDate' => now(),
            'totalAmount' => 450,
            'paidAmount' => 0,
            'dueAmount' => 450,
        ]);

        $this->collect(['payDueAmount' => 100])->assertStatus(400);

        $this->collect(['payDueAmount' => 50])->assertSuccessful();
    }

    public function test_a_named_invoice_is_paid_against_its_own_due(): void
    {
        Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'invoiceNumber' => 'DUE-2',
            'saleDate' => now(),
            'totalAmount' => 200,
            'paidAmount' => 0,
            'dueAmount' => 200,
        ]);

        // Resolving the invoice also read the dead column.
        $this->collect([
            'invoiceNumber' => 'DUE-2',
            'payDueAmount' => 200,
        ])->assertSuccessful();

        $this->assertSame(0.0, (float) Sale::where('invoiceNumber', 'DUE-2')->first()->dueAmount);
        $this->assertSame(200.0, (float) Sale::where('invoiceNumber', 'DUE-2')->first()->paidAmount);
    }

    public function test_a_supplier_due_collection_updates_purchase_paid_and_due_together(): void
    {
        $supplier = Party::create([
            'business_id' => $this->business->id,
            'name' => 'Owing Supplier',
            'type' => 'Supplier',
            'due' => 600,
        ]);

        Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'party_id' => $supplier->id,
            'invoiceNumber' => 'PUR-DUE-1',
            'purchaseDate' => now(),
            'totalAmount' => 1000,
            'paidAmount' => 400,
            'dueAmount' => 600,
            'isPaid' => false,
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/dues', [
            'party_id' => $supplier->id,
            'payment_type_id' => $this->paymentType->id,
            'paymentDate' => now()->format('d/m/Y'),
            'invoiceNumber' => 'PUR-DUE-1',
            'payDueAmount' => 250,
        ])->assertSuccessful();

        $purchase = Purchase::where('invoiceNumber', 'PUR-DUE-1')->firstOrFail();
        $this->assertSame(650.0, (float) $purchase->paidAmount);
        $this->assertSame(350.0, (float) $purchase->dueAmount);
        $this->assertFalse($purchase->isPaid);
    }

    public function test_paying_more_than_the_named_invoice_owes_is_refused(): void
    {
        Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'invoiceNumber' => 'DUE-3',
            'saleDate' => now(),
            'totalAmount' => 120,
            'paidAmount' => 0,
            'dueAmount' => 120,
        ]);

        $this->collect([
            'invoiceNumber' => 'DUE-3',
            'payDueAmount' => 500,
        ])->assertStatus(400);

        $this->assertSame(120.0, (float) Sale::where('invoiceNumber', 'DUE-3')->first()->dueAmount);
    }
}
