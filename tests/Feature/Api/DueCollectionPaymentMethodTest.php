<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\DueCollect;
use App\Models\Party;
use App\Models\PaymentType;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which payment method a due receipt reports.
 *
 * Receipts read SPLIT for cash collections. The cause was on the client - a
 * shared dropdown pre-selected whichever payment type was named "split" when no
 * value had been chosen - but nothing proved the server side of the chain, so
 * the fix could not be told apart from a coincidence.
 *
 * This pins the part the server owns:
 *
 *     payment_type_id sent  ->  DueCollect row  ->  payment_type on the response
 *
 * The receipt renders the eager-loaded `payment_type.name`, so a collection that
 * survives this chain with the right id shows the right method. A collection is
 * also its own transaction: the method used to settle a debt has nothing to do
 * with how the original sale or purchase was paid, and both are asserted below.
 */
class DueCollectionPaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;

    /** @var array<string, PaymentType> */
    private array $paymentTypes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);

        foreach (['Cash', 'UPI', 'Card', 'Split'] as $name) {
            $this->paymentTypes[$name] = PaymentType::create([
                'name' => $name,
                'business_id' => $this->business->id,
                'status' => 1,
            ]);
        }
    }

    private function customer(): Party
    {
        $party = Party::create([
            'business_id' => $this->business->id,
            'name' => 'Due Customer',
            'phone' => '9800000001',
            'type' => 'Customer',
            'opening_balance_type' => 'credit',
            'due' => 1000,
        ]);

        // Sales key on `customers.id`; ids were preserved when non-supplier
        // parties were copied across, so the row has to exist for the invoice
        // lookup in AcnooDueController to resolve.
        // `id` is not fillable, so it has to be set on the instance - passing
        // it to create() is silently dropped and the two sequences drift apart.
        $customer = new Customer([
            'business_id' => $this->business->id,
            'name' => $party->name,
            'phone' => $party->phone,
            'type' => 'Customer',
            'opening_balance_type' => 'credit',
        ]);
        $customer->id = $party->id;
        $customer->save();

        return $party;
    }

    private function supplier(): Party
    {
        return Party::create([
            'business_id' => $this->business->id,
            'name' => 'Due Supplier',
            'phone' => '9800000002',
            'type' => 'Supplier',
            'opening_balance_type' => 'credit',
            'due' => 1000,
        ]);
    }

    /**
     * A sale left partly unpaid, settled later by another method entirely.
     */
    private function saleWithDue(Party $party): Sale
    {
        return Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            // Sales key on customer_id, not party_id - see
            // AcnooDueController::getInvoiceByPartyAndNumber.
            'customer_id' => $party->id,
            // Deliberately not the method any collection below uses.
            'payment_type_id' => $this->paymentTypes['Split']->id,
            'invoiceNumber' => 'S-DUE-' . uniqid(),
            'saleDate' => now(),
            'totalAmount' => 1000,
            'paidAmount' => 0,
            'dueAmount' => 1000,
        ]);
    }

    private function purchaseWithDue(Party $party): Purchase
    {
        return Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'party_id' => $party->id,
            'payment_type_id' => $this->paymentTypes['Split']->id,
            'invoiceNumber' => 'P-DUE-' . uniqid(),
            'purchaseDate' => now(),
            'totalAmount' => 1000,
            'paidAmount' => 0,
            'dueAmount' => 1000,
        ]);
    }

    private function collect(Party $party, string $method, ?string $invoiceNumber): array
    {
        $paymentType = $this->paymentTypes[$method];

        $payload = [
            'party_id' => $party->id,
            'payment_type_id' => $paymentType->id,
            // The controller re-parses this strictly as d/m/Y, which is the
            // format the app sends; an ISO date reaches Carbon and 500s.
            'paymentDate' => now()->format('d/m/Y'),
            'payDueAmount' => 250,
        ];

        if ($invoiceNumber !== null) {
            $payload['invoiceNumber'] = $invoiceNumber;
        }

        // A genuine split carries its two components; the others do not.
        if ($method === 'Split') {
            $payload['cash_amount'] = 150;
            $payload['upi_amount'] = 100;
        }

        $this->actingAs($this->user)
            ->postJson('/api/v1/dues', $payload)
            ->assertSuccessful();

        $collection = DueCollect::where('party_id', $party->id)->latest('id')->first();
        $this->assertNotNull($collection, 'The collection was not persisted.');

        // What the receipt actually renders: the eager-loaded relation name.
        $listed = $this->actingAs($this->user)
            ->getJson('/api/v1/dues')
            ->assertSuccessful()
            ->json();

        $row = collect(data_get($listed, 'data') ?? [])
            ->firstWhere('id', $collection->id);

        return [
            'expectedId' => $paymentType->id,
            'storedId' => $collection->payment_type_id,
            'receiptName' => data_get($row, 'payment_type.name'),
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function paymentMethods(): array
    {
        return [
            'cash' => ['Cash'],
            'upi' => ['UPI'],
            'card' => ['Card'],
            'split' => ['Split'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('paymentMethods')]
    public function test_a_sale_due_collection_keeps_the_method_it_was_paid_with(string $method): void
    {
        $party = $this->customer();
        $sale = $this->saleWithDue($party);

        $result = $this->collect($party, $method, $sale->invoiceNumber);

        $this->assertSame($result['expectedId'], $result['storedId']);
        $this->assertSame(
            $method,
            $result['receiptName'],
            "A sale due collected by $method reported {$result['receiptName']} on the receipt.",
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('paymentMethods')]
    public function test_a_purchase_due_collection_keeps_the_method_it_was_paid_with(string $method): void
    {
        $party = $this->supplier();
        $purchase = $this->purchaseWithDue($party);

        $result = $this->collect($party, $method, $purchase->invoiceNumber);

        $this->assertSame($result['expectedId'], $result['storedId']);
        $this->assertSame(
            $method,
            $result['receiptName'],
            "A purchase due collected by $method reported {$result['receiptName']} on the receipt.",
        );
    }

    /**
     * The original transaction was Split; the collection was not.
     *
     * This is the case the reported bug looked like from the outside, and the
     * one an invoice must never resolve by reaching back to the sale.
     */
    public function test_the_collection_method_is_not_inherited_from_the_sale(): void
    {
        $party = $this->customer();
        $sale = $this->saleWithDue($party);

        $result = $this->collect($party, 'Cash', $sale->invoiceNumber);

        $this->assertSame('Cash', $result['receiptName']);
        $this->assertNotSame(
            $sale->payment_type_id,
            $result['storedId'],
            'The collection took the sale payment type instead of its own.',
        );
    }

    /**
     * A three-way split keeps its card share.
     *
     * The till has offered Cash/UPI/Card all along and the app posted
     * `card_amount`, but the controller neither validated nor stored it and the
     * table had no column. A ₹48,485 due settled as ₹20,000 UPI plus ₹28,485
     * card was recorded as UPI alone, so the money receipt named a single
     * method and the card takings never appeared in the day's split.
     */
    public function test_a_split_collection_keeps_its_card_share(): void
    {
        $party = $this->supplier();

        $this->actingAs($this->user)->postJson('/api/v1/dues', [
            'party_id' => $party->id,
            'payment_type_id' => $this->paymentTypes['Split']->id,
            'paymentDate' => now()->format('d/m/Y'),
            'payDueAmount' => 250,
            'cash_amount' => 50,
            'upi_amount' => 100,
            'card_amount' => 100,
        ])->assertSuccessful();

        $collection = DueCollect::where('party_id', $party->id)->latest('id')->first();

        $this->assertEqualsWithDelta(50.0, (float) $collection->cash_amount, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $collection->upi_amount, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $collection->card_amount, 0.01);
    }

    /** A negative tender is an inversion, not a payment. */
    public function test_a_negative_card_amount_is_refused(): void
    {
        $party = $this->supplier();

        $this->actingAs($this->user)->postJson('/api/v1/dues', [
            'party_id' => $party->id,
            'payment_type_id' => $this->paymentTypes['Card']->id,
            'paymentDate' => now()->format('d/m/Y'),
            'payDueAmount' => 250,
            'card_amount' => -5,
        ])->assertStatus(422);

        $this->assertSame(0, DueCollect::where('party_id', $party->id)->count());
    }

    /**
     * The server stores what it is given and invents nothing.
     */
    public function test_the_server_does_not_substitute_a_payment_type(): void
    {
        $party = $this->customer();

        $result = $this->collect($party, 'Card', null);

        $this->assertSame($this->paymentTypes['Card']->id, $result['storedId']);
        $this->assertNotSame($this->paymentTypes['Split']->id, $result['storedId']);
    }
}
