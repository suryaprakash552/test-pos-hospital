<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * One column, three vocabularies.
 *
 * The till writes `product_type` as `single` or `batch`, `StoreProductRequest`
 * accepted only `simple` or `variant`, and every branch in the controller tested
 * for `variant` alone. A batch product edited from the till therefore arrived as
 * `batch`, failed the `variant` test, and was written down the single-product
 * path — which assigns the request's *array* of per-batch prices to scalar
 * columns and cascades one price over every batch the product owns.
 *
 * These pin the behaviour for both spellings, because app versions already
 * installed on shop phones keep sending the old one.
 */
class ProductTypeVocabularyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);
    }

    private function product(string $type = 'variant'): Product
    {
        return Product::create([
            'productName'      => 'Paracetamol 500mg',
            'business_id'      => $this->business->id,
            'productSalePrice' => 10,
            'product_type'     => $type,
        ]);
    }

    private function batch(Product $product, string $batchNo, float $salePrice): Stock
    {
        return Stock::create([
            'business_id'      => $this->business->id,
            'product_id'       => $product->id,
            'batch_no'         => $batchNo,
            'productSalePrice' => $salePrice,
            'productStock'     => 5,
        ]);
    }

    /** The till's spelling has to reach the per-batch branch. */
    public function test_batch_is_recognised_as_a_variant_product(): void
    {
        $product = $this->product();
        $first = $this->batch($product, 'B1', 10);
        $second = $this->batch($product, 'B2', 20);

        $this->actingAs($this->user)->putJson("/api/v1/products/{$product->id}", [
            'productName'      => $product->productName,
            'product_type'     => 'batch',
            'productSalePrice' => [11, 22],
        ])->assertStatus(200);

        // The single-product path would have flattened both to one figure.
        $this->assertSame(10.0, $first->fresh()->productSalePrice,
            'a per-batch product must keep its own price per batch');
        $this->assertSame(20.0, $second->fresh()->productSalePrice);
    }

    /** Whatever spelling arrives, one spelling is stored. */
    public function test_the_column_keeps_a_single_spelling(): void
    {
        $product = $this->product();

        $this->actingAs($this->user)->putJson("/api/v1/products/{$product->id}", [
            'productName'  => $product->productName,
            'product_type' => 'batch',
        ])->assertStatus(200);

        $this->assertSame('variant', $product->fresh()->product_type);
    }

    /** An edit that never mentions the type must not silently change it. */
    public function test_an_edit_that_omits_the_type_leaves_it_alone(): void
    {
        $product = $this->product();

        $this->actingAs($this->user)->putJson("/api/v1/products/{$product->id}", [
            'productName' => 'Paracetamol 650mg',
        ])->assertStatus(200);

        $this->assertSame('variant', $product->fresh()->product_type,
            'omitting the field turned every batch product into a single one');
    }

    /** Creating from the till was rejected outright by the old `in:` rule. */
    public function test_the_till_can_create_a_product(): void
    {
        foreach (['single', 'batch', 'simple', 'variant'] as $spelling) {
            $this->actingAs($this->user)->post('/api/v1/products', [
                'productName'      => "Amoxicillin {$spelling}",
                'product_type'     => $spelling,
                'productSalePrice' => 10,
                // Required by the create request; not what this test is about.
                'productPicture'   => UploadedFile::fake()->image('p.jpg'),
            ], ['Accept' => 'application/json'])->assertSuccessful();
        }

        $this->assertSame(
            ['single', 'variant'],
            Product::where('business_id', $this->business->id)
                ->pluck('product_type')->unique()->sort()->values()->all(),
            'only the two canonical spellings should ever be stored'
        );
    }
}
