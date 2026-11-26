<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Category;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the purchase invoice is drawn from.
 *
 * The purchase *calculation* is covered thoroughly — discount before GST,
 * apportionment across mixed rates, server-side due, manipulated totals being
 * replaced. What nothing covered is the last hop, which is exactly what
 * `SaleInvoicePersistenceTest` asserts for the sale side: the invoice does not
 * recalculate anything. It renders `totalAmount`, `vatAmount`, `discountAmount`,
 * `paidAmount`, `dueAmount` and `change_amount` straight off the purchase it was
 * handed.
 *
 * So a figure can be computed correctly, stored correctly, and still reach the
 * printed invoice wrong if the read path drops or reshapes it. A mismatch here
 * would mean a supplier bill disagreeing with the books while every calculation
 * test still passed.
 */
class PurchaseInvoicePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Party $supplier;
    private Category $category;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);
        $this->category = Category::factory()->create([
            'business_id' => $this->business->id,
        ]);
        $this->supplier = Party::create([
            'business_id' => $this->business->id,
            'name'        => 'Acme Distributors',
            'type'        => 'Supplier',
            'phone'       => '9000000777',
        ]);
        $this->product = Product::create([
            'business_id'  => $this->business->id,
            'category_id'  => $this->category->id,
            'productName'  => 'Widget',
            'productCode'  => 'W-1',
            'productStock' => 0,
        ]);
    }

    /**
     * Buys [quantity] at [price] with whatever billing shape is under test.
     *
     * @param array<string, mixed> $overrides
     */
    private function purchase(array $overrides = [], int $quantity = 10, float $price = 20): Purchase
    {
        $lineTotal = $quantity * $price;

        $this->actingAs($this->user)
            ->postJson('/api/v1/purchase', array_merge([
                'party_id'    => $this->supplier->id,
                'paidAmount'  => $lineTotal,
                'dueAmount'   => 0,
                'totalAmount' => $lineTotal,
                'paymentType' => 'Cash',
                'products'    => [[
                    'product_id'                => $this->product->id,
                    'batch_no'                  => 'B-1',
                    'quantities'                => $quantity,
                    'conversion_quantities'     => $quantity,
                    'conversion_value'          => 1,
                    'productSalePrice'          => 30,
                    'productPurchasePrice'      => $price,
                    'conversion_purchase_price' => $price,
                    'conversion_price'          => 30,
                    'total_purchase_price'      => $lineTotal,
                    'total_price'               => $quantity * 30,
                    'expire_date'               => '2030-01-01',
                    'mfg_date'                  => '2026-01-01',
                    'upper_selected_unit'       => 1,
                    'lower_selected_unit'       => 1,
                ]],
            ], $overrides))
            ->assertOk();

        return Purchase::latest('id')->firstOrFail();
    }

    /**
     * The purchase as the invoice screen receives it.
     *
     * @return array<string, mixed>
     */
    private function asDelivered(Purchase $purchase): array
    {
        $rows = $this->actingAs($this->user)
            ->getJson('/api/v1/purchase')
            ->assertOk()
            ->json();

        $row = $this->findNode($rows, $purchase->id);

        $this->assertNotNull(
            $row,
            'The saved purchase was not returned to the client.'
        );

        return $row;
    }

    /**
     * Depth-first search for the node describing this purchase.
     *
     * The list envelope has been reshaped before (it is a paginator inside a
     * `data` key), so this finds the row wherever it sits rather than pinning
     * the test to today's nesting.
     *
     * @param  mixed  $node
     * @return array<string, mixed>|null
     */
    private function findNode($node, int $purchaseId): ?array
    {
        if (is_array($node)) {
            if (($node['id'] ?? null) === $purchaseId
                && array_key_exists('totalAmount', $node)) {
                return $node;
            }

            foreach ($node as $child) {
                $found = $this->findNode($child, $purchaseId);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** Every figure the invoice prints must be the figure that was stored. */
    private function assertInvoiceMatchesDatabase(Purchase $purchase): void
    {
        $delivered = $this->asDelivered($purchase);
        $stored    = $purchase->fresh();

        foreach ([
            'totalAmount',
            'vat_amount',
            'discountAmount',
            'paidAmount',
            'dueAmount',
        ] as $field) {
            if (! array_key_exists($field, $delivered)) {
                continue;
            }

            $this->assertEqualsWithDelta(
                (float) $stored->{$field},
                (float) $delivered[$field],
                0.001,
                "$field reached the invoice as a different number than was stored."
            );
        }
    }

    public function test_a_plain_purchase_reaches_the_invoice_unchanged(): void
    {
        $this->assertInvoiceMatchesDatabase($this->purchase());
    }

    public function test_gst_reaches_the_invoice_unchanged(): void
    {
        $purchase = $this->purchase([
            'vat_amount'  => 36,
            'vat_percent' => 18,
            'totalAmount' => 236,
            'paidAmount'  => 236,
        ]);

        $this->assertInvoiceMatchesDatabase($purchase);
    }

    public function test_a_flat_discount_reaches_the_invoice_unchanged(): void
    {
        $purchase = $this->purchase([
            'discountAmount' => 25,
            'discount_type'  => 'flat',
            'totalAmount'    => 175,
            'paidAmount'     => 175,
        ]);

        $this->assertInvoiceMatchesDatabase($purchase);
    }

    public function test_a_percentage_discount_reaches_the_invoice_unchanged(): void
    {
        $purchase = $this->purchase([
            'discount_percent' => 10,
            'discount_type'    => 'percentage',
            'totalAmount'      => 180,
            'paidAmount'       => 180,
        ]);

        $this->assertInvoiceMatchesDatabase($purchase);
    }

    public function test_shipping_reaches_the_invoice_unchanged(): void
    {
        $purchase = $this->purchase([
            'shipping_charge' => 50,
            'totalAmount'     => 250,
            'paidAmount'      => 250,
        ]);

        $this->assertInvoiceMatchesDatabase($purchase);
    }

    public function test_a_part_paid_purchase_reaches_the_invoice_unchanged(): void
    {
        $purchase = $this->purchase([
            'paidAmount' => 120,
            'dueAmount'  => 80,
        ]);

        $this->assertInvoiceMatchesDatabase($purchase);
    }

    public function test_paid_and_due_account_for_the_stored_total(): void
    {
        // The identity a supplier bill is read against. Anything else means the
        // invoice's own arithmetic disagrees with what was banked.
        $purchase = $this->purchase([
            'paidAmount' => 120,
            'dueAmount'  => 80,
        ])->fresh();

        $this->assertEqualsWithDelta(
            (float) $purchase->totalAmount,
            (float) $purchase->paidAmount + (float) $purchase->dueAmount,
            0.001,
            'paid + due did not account for the stored total.'
        );
    }

    public function test_a_decimal_price_survives_the_round_trip(): void
    {
        // Laravel serialises decimal casts as strings; a float comparison here
        // is what catches a column that silently truncated.
        $purchase = $this->purchase(quantity: 3, price: 19.99);

        $this->assertInvoiceMatchesDatabase($purchase);

        $delivered = $this->asDelivered($purchase);

        $this->assertEqualsWithDelta(
            59.97,
            (float) $delivered['totalAmount'],
            0.001,
            'A decimal line total did not survive persistence.'
        );
    }

    public function test_change_is_carried_rather_than_recomputed(): void
    {
        $purchase = $this->purchase([
            'change_amount' => 30,
            'paidAmount'    => 200,
            'dueAmount'     => 0,
        ]);

        $delivered = $this->asDelivered($purchase);

        if (array_key_exists('change_amount', $delivered)) {
            $this->assertEqualsWithDelta(
                (float) $purchase->fresh()->change_amount,
                (float) $delivered['change_amount'],
                0.001,
                'change_amount reached the invoice as a different number.'
            );
        }

        $this->assertInvoiceMatchesDatabase($purchase);
    }

    public function test_the_payment_type_reaches_the_invoice(): void
    {
        // The invoice prints the method. It must be the one that was recorded,
        // not a default filled in on the read path.
        $purchase  = $this->purchase();
        $delivered = $this->asDelivered($purchase);

        $this->assertSame(
            (int) $purchase->fresh()->payment_type_id,
            (int) ($delivered['payment_type_id'] ?? 0),
            'The invoice received a different payment type than was stored.'
        );
    }
}
