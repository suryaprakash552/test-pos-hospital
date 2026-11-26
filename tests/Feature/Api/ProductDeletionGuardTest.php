<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Deleting a product used to cascade into `sale_details`, `purchase_details` and
 * `transfer_products`, wiping the line items that profit/loss, stock audit and tax
 * reporting are built from — silently, and for periods that were already closed.
 *
 * These tests pin both halves of the fix: history blocks the delete, and the owner
 * override is the only way past it.
 */
class ProductDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_PASSWORD = 'correct-horse-battery';

    protected User $owner;
    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
            'password'    => Hash::make(self::OWNER_PASSWORD),
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'productName' => 'Paracetamol 500mg',
            'business_id' => $this->business->id,
        ]);
    }

    /** Creates a sale line referencing the product, the way a real sale would. */
    private function saleLineFor(Product $product): int
    {
        $saleId = DB::table('sales')->insertGetId([
            'business_id' => $this->business->id,
            'totalAmount' => 100,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table('sale_details')->insert([
            'sale_id'    => $saleId,
            'product_id' => $product->id,
            'quantities' => 2,
        ]);

        return $saleId;
    }

    public function test_product_without_transactions_can_be_deleted(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_delete_is_blocked_when_the_product_has_sales(): void
    {
        $product = $this->product();
        $this->saleLineFor($product);

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'has_transactions')
            ->assertJsonPath('transactions.sales', 1)
            // No override remains, so the app must not offer a password prompt.
            ->assertJsonPath('force_deletable', false)
            ->assertJsonPath('owner_can_override', false);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    /**
     * The regression that mattered: the product survives, but so must the sale line.
     */
    public function test_blocked_delete_leaves_sale_history_intact(): void
    {
        $product = $this->product();
        $this->saleLineFor($product);

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertStatus(409);

        $this->assertSame(
            1,
            DB::table('sale_details')->where('product_id', $product->id)->count(),
            'sale history must not be touched by a refused delete'
        );
    }

    /**
     * The owner's password used to unlock this. It no longer does.
     *
     * Sending a correct password is now simply irrelevant: the refusal is the
     * same 409 a caller gets without one, and the sale line survives. Anyone
     * still holding an older client that prompts for a password gets a refusal
     * rather than a surprise deletion.
     */
    public function test_the_owners_password_no_longer_unlocks_the_delete(): void
    {
        $product = $this->product();
        $this->saleLineFor($product);

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/products/{$product->id}", ['password' => self::OWNER_PASSWORD])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'has_transactions');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertSame(1, DB::table('sale_details')->where('product_id', $product->id)->count());
    }

    public function test_staff_cannot_delete_a_product_with_history_either(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'staff',
            'password'    => Hash::make(self::OWNER_PASSWORD),
        ]);

        $product = $this->product();
        $this->saleLineFor($product);

        $this->actingAs($staff)
            ->deleteJson("/api/v1/products/{$product->id}", ['password' => self::OWNER_PASSWORD])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'has_transactions');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_staff_sees_force_deletable_false_so_the_client_hides_the_override(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'staff',
        ]);

        $product = $this->product();
        $this->saleLineFor($product);

        $this->actingAs($staff)
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertStatus(409)
            ->assertJsonPath('force_deletable', false);
    }

    /**
     * What the override used to destroy, and now cannot.
     *
     * The forced path deleted the sale, purchase and transfer lines along with
     * the product. Those lines are what profit, stock and GST reporting are
     * built from, so a single delete silently changed finished reports. The
     * history is now what protects the product rather than what gets discarded.
     */
    public function test_a_refused_delete_discards_nothing(): void
    {
        $product = $this->product();
        $this->saleLineFor($product);

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/products/{$product->id}", ['password' => self::OWNER_PASSWORD])
            ->assertStatus(409);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertSame(
            1,
            DB::table('sale_details')->where('product_id', $product->id)->count(),
            'the sale line the report is built from must still be there'
        );
    }

    public function test_another_business_cannot_delete_the_product(): void
    {
        $product = $this->product();

        $outsider = User::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'role'        => 'shop-owner',
            'password'    => Hash::make(self::OWNER_PASSWORD),
        ]);

        $this->actingAs($outsider)
            ->deleteJson("/api/v1/products/{$product->id}", ['password' => self::OWNER_PASSWORD])
            ->assertStatus(403);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_the_database_itself_refuses_to_orphan_sale_history(): void
    {
        $product = $this->product();
        $this->saleLineFor($product);

        // Bypasses the controller entirely: proves the FK is RESTRICT, so any other
        // code path that deletes a product also cannot destroy history.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('products')->where('id', $product->id)->delete();
    }
}
