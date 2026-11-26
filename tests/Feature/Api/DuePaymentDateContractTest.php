<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Party;
use App\Models\PaymentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The date contract for a due collection.
 *
 * The app posts `dd/MM/yyyy`. The controller converted that to ISO *before*
 * validation, by calling `Carbon::createFromFormat('d/m/Y', ...)` unguarded — so
 * any value that was not in that exact shape threw inside the parser and reached
 * the shopkeeper as a 500, rather than the 422 the `date` rule exists to
 * produce. A mistyped date read as "the server is broken".
 *
 * `CustomerDueController` had this corrected already. This asserts both
 * controllers now agree, because they collect the same kind of payment and a
 * silent disagreement about the date contract is how one of them ends up wrong
 * again.
 */
class DuePaymentDateContractTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private Party $supplier;
    private PaymentType $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);
        $this->supplier = Party::create([
            'business_id' => $this->business->id,
            'name'        => 'Acme Distributors',
            'type'        => 'Supplier',
            'phone'       => '9000000555',
            'due'         => 500,
        ]);
        $this->cash = PaymentType::create([
            'business_id' => $this->business->id,
            'name'        => 'Cash',
            'status'      => 1,
        ]);

        $this->actingAs($this->owner);
    }

    private function collect(string $paymentDate)
    {
        return $this->postJson('/api/v1/dues', [
            'party_id'        => $this->supplier->id,
            'payment_type_id' => $this->cash->id,
            'paymentDate'     => $paymentDate,
            'payDueAmount'    => 100,
        ]);
    }

    public function test_the_apps_slash_format_is_accepted(): void
    {
        // What the two due screens actually send: DateFormat('dd/MM/yyyy').
        $this->collect('25/12/2026')->assertSuccessful();

        $this->assertDatabaseHas('due_collects', [
            'business_id' => $this->business->id,
            'paymentDate' => '2026-12-25',
        ]);
    }

    public function test_an_iso_date_is_accepted_unchanged(): void
    {
        // Anything server-side or a future client sending ISO must not be
        // rejected, and must not be re-interpreted as d/m/Y.
        $this->collect('2026-12-25')->assertSuccessful();

        $this->assertDatabaseHas('due_collects', [
            'business_id' => $this->business->id,
            'paymentDate' => '2026-12-25',
        ]);
    }

    public function test_a_malformed_date_is_refused_not_crashed(): void
    {
        // The defect. This threw inside `createFromFormat` and returned 500.
        $response = $this->collect('not-a-date');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('paymentDate');
    }

    public function test_an_impossible_date_is_refused_not_crashed(): void
    {
        // Structurally d/m/Y but not a real day. Carbon's strict check rejects
        // it, so it must fall through to the validator rather than throw.
        $response = $this->collect('32/13/2026');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('paymentDate');
    }

    public function test_a_missing_date_is_refused(): void
    {
        $response = $this->postJson('/api/v1/dues', [
            'party_id'        => $this->supplier->id,
            'payment_type_id' => $this->cash->id,
            'payDueAmount'    => 100,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('paymentDate');
    }

    public function test_the_customer_due_endpoint_agrees(): void
    {
        // Both controllers must read the same contract. They diverged once.
        $customer = \App\Models\Customer::create([
            'business_id' => $this->business->id,
            'name'        => 'Asha',
            'phone'       => '9000000666',
            'type'        => 'Customer',
            'due'         => 500,
        ]);

        $payload = [
            'customer_id'     => $customer->id,
            'payment_type_id' => $this->cash->id,
            'payDueAmount'    => 100,
        ];

        $this->postJson('/api/v1/customer-dues',
            $payload + ['paymentDate' => 'not-a-date'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('paymentDate');

        $this->postJson('/api/v1/customer-dues',
            $payload + ['paymentDate' => '25/12/2026'])
            ->assertSuccessful();
    }
}
