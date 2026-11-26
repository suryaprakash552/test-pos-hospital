<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\User;
use App\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the invoice is drawn from.
 *
 * The calculation itself is already covered - discount before GST, apportionment
 * across mixed rates, rounding, shipping. What nothing covered is the last hop:
 * the invoice does not recalculate anything, it renders `totalAmount`,
 * `vatAmount` and `discountAmount` straight off the sale it was handed. So the
 * figures a shopkeeper hands to a customer are only right if the values that
 * were persisted survive unchanged into the response the invoice reads.
 *
 * This asserts that hop for each of the discount shapes the till supports. A
 * mismatch here would mean a receipt disagreeing with the books while every
 * calculation test still passed.
 */
class SaleInvoicePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Customer $customer;
    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
        $this->customer = Customer::factory()->create([
            'business_id' => $this->business->id,
            'type' => 'Customer',
            'due' => 0,
        ]);

        $vat = Vat::create([
            'business_id' => $this->business->id,
            'name' => 'GST 18',
            'rate' => 18,
        ]);

        $product = Product::create([
            'business_id' => $this->business->id,
            'productName' => 'Widget',
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'vat_id' => $vat->id,
            'vat_type' => 'exclusive',
        ]);

        $this->stock = Stock::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => 'B1',
            'productStock' => 100,
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'conversionValue' => 1,
        ]);

        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'purchaseDate' => now(),
            'totalAmount' => 6000,
        ]);

        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $this->stock->id,
            'quantities' => 100,
            'conversionQuantity' => 100,
            'conversionValue' => 1,
            'productPurchasePrice' => 60,
            'productSalePrice' => 100,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createSale(array $overrides = []): Sale
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/sales', array_merge([
                'products' => json_encode([[
                    'stock_id' => $this->stock->id,
                    'quantities' => 2,
                    'lossProfit' => 0,
                ]]),
                'saleDate' => now()->toDateString(),
                'paymentType' => 'cash',
                'customer_id' => $this->customer->id,
                'totalAmount' => 0,
                'paidAmount' => 0,
            ], $overrides))
            ->assertSuccessful();

        return Sale::latest('id')->firstOrFail();
    }

    /**
     * The sale as the invoice screen receives it.
     *
     * @return array<string, mixed>
     */
    private function asDelivered(Sale $sale): array
    {
        $rows = $this->actingAs($this->user)
            ->getJson('/api/v1/sales')
            ->assertSuccessful()
            ->json();

        // The list envelope has changed shape before; find the sale wherever
        // it sits rather than pinning this test to the current nesting.
        $row = $this->findSaleNode($rows, $sale->id);

        $this->assertNotNull($row, 'The saved sale was not returned to the client.');

        return $row;
    }

    /**
     * Depth-first search for the node describing this sale.
     *
     * @param mixed $node
     * @return array<string, mixed>|null
     */
    private function findSaleNode($node, int $saleId): ?array
    {
        if (is_array($node)) {
            if (($node['id'] ?? null) === $saleId && array_key_exists('totalAmount', $node)) {
                return $node;
            }

            foreach ($node as $child) {
                $found = $this->findSaleNode($child, $saleId);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Every figure the invoice prints must be the figure that was stored.
     */
    private function assertInvoiceMatchesDatabase(Sale $sale): void
    {
        $delivered = $this->asDelivered($sale);
        $stored = $sale->fresh();

        foreach ([
            'totalAmount',
            'vatAmount',
            'discountAmount',
            'paidAmount',
            'dueAmount',
        ] as $field) {
            $this->assertEqualsWithDelta(
                (float) $stored->{$field},
                (float) ($delivered[$field] ?? 0),
                0.001,
                "$field reaches the invoice as a different number than the one stored.",
            );
        }

        // The invoice resolves the method through the sale's own payment type,
        // not through any later due collection.
        $this->assertSame(
            $stored->payment_type_id,
            $delivered['payment_type_id'] ?? null,
            'The invoice would show a different payment type than the sale holds.',
        );
    }

    public function test_a_plain_sale_reaches_the_invoice_unchanged(): void
    {
        $this->assertInvoiceMatchesDatabase($this->createSale());
    }

    public function test_gst_reaches_the_invoice_unchanged(): void
    {
        $sale = $this->createSale();

        $this->assertInvoiceMatchesDatabase($sale);
    }

    public function test_a_flat_discount_reaches_the_invoice_unchanged(): void
    {
        $sale = $this->createSale([
            'discountAmount' => 40,
            'discount_type' => 'flat',
        ]);

        $this->assertInvoiceMatchesDatabase($sale);
    }

    public function test_a_percentage_discount_reaches_the_invoice_unchanged(): void
    {
        $sale = $this->createSale([
            'discountAmount' => 10,
            'discount_type' => 'percentage',
        ]);

        $this->assertInvoiceMatchesDatabase($sale);
    }

    public function test_a_part_paid_sale_reaches_the_invoice_unchanged(): void
    {
        $sale = $this->createSale(['paidAmount' => 50]);

        $this->assertGreaterThan(0, (float) $sale->fresh()->dueAmount);
        $this->assertInvoiceMatchesDatabase($sale);
    }

    /**
     * The identity the receipt is read against: what was paid plus what is still
     * owed is the total that was charged.
     */
    public function test_paid_and_due_account_for_the_stored_total(): void
    {
        $sale = $this->createSale(['paidAmount' => 50])->fresh();

        $this->assertEqualsWithDelta(
            (float) $sale->totalAmount,
            (float) $sale->paidAmount + (float) $sale->dueAmount - (float) ($sale->changeAmount ?? 0),
            0.01,
            'Paid + due does not reconcile against the stored total.',
        );
    }
}
