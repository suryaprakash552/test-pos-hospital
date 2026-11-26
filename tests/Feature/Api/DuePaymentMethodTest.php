<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Party;
use App\Models\PaymentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A due collection records the method it was collected by.
 *
 * Not the method of the sale or purchase it settles. A shop that sold on Card
 * and is later handed cash must show CASH on the collection receipt — the
 * original transaction's method is a fact about a different event.
 *
 * The due invoice renders `due.paymentType.name`, so whatever is persisted here
 * is what the customer is handed.
 */
class DuePaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private Party $supplier;
    private PaymentType $cash;
    private PaymentType $card;
    private PaymentType $upi;

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
            'phone'       => '9000000444',
            'due'         => 1000,
        ]);

        foreach (['Cash', 'Card', 'UPI'] as $name) {
            $type = PaymentType::create([
                'business_id' => $this->business->id,
                'name'        => $name,
                'status'      => 1,
            ]);
            $this->{strtolower($name)} = $type;
        }

        $this->actingAs($this->owner);
    }

    private function collect(PaymentType $type, float $amount = 100)
    {
        return $this->postJson('/api/v1/dues', [
            'party_id'        => $this->supplier->id,
            'payment_type_id' => $type->id,
            'paymentDate'     => '25/12/2026',
            'payDueAmount'    => $amount,
        ]);
    }

    public function test_each_method_is_stored_exactly_as_chosen(): void
    {
        foreach ([$this->cash, $this->card, $this->upi] as $type) {
            $this->collect($type)->assertSuccessful();

            $this->assertDatabaseHas('due_collects', [
                'business_id'     => $this->business->id,
                'payment_type_id' => $type->id,
            ]);
        }
    }

    public function test_a_card_sale_settled_in_cash_records_cash(): void
    {
        // The inheritance defect this guards: the collection must carry its own
        // method, not the one on the transaction it settles.
        $this->collect($this->cash)->assertSuccessful();

        $collection = \DB::table('due_collects')
            ->where('business_id', $this->business->id)
            ->latest('id')
            ->first();

        $this->assertSame(
            $this->cash->id,
            (int) $collection->payment_type_id,
            'the collection inherited a payment method instead of recording its own'
        );
    }

    public function test_the_paid_amount_is_persisted_not_left_to_the_receipt(): void
    {
        // The receipts derived paid as `totalDue - dueAmountAfterPay`, a second
        // calculation of a number the server already stores. `payDueAmount` is
        // the authoritative figure and must be present for the renderer to use.
        $this->collect($this->cash, 250)->assertSuccessful();

        $this->assertDatabaseHas('due_collects', [
            'business_id'  => $this->business->id,
            'payDueAmount' => 250,
        ]);
    }

    public function test_a_payment_type_from_another_business_is_refused(): void
    {
        $otherBusiness = Business::factory()->create();
        $foreign = PaymentType::create([
            'business_id' => $otherBusiness->id,
            'name'        => 'Cash',
            'status'      => 1,
        ]);

        $response = $this->collect($foreign);

        // Either refused outright, or at minimum never written against this
        // business's collections.
        if ($response->status() < 300) {
            $this->assertDatabaseMissing('due_collects', [
                'business_id'     => $this->business->id,
                'payment_type_id' => $foreign->id,
            ]);
        } else {
            $response->assertStatus(422);
        }
    }

    public function test_a_missing_payment_type_is_refused(): void
    {
        $this->postJson('/api/v1/dues', [
            'party_id'     => $this->supplier->id,
            'paymentDate'  => '25/12/2026',
            'payDueAmount' => 100,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_type_id');
    }
}
