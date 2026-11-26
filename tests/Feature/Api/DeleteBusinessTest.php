<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use ReflectionClass;
use Tests\TestCase;

/**
 * Account deletion has to actually delete the account.
 *
 * From the production log: `/business-delete` returned 500 on a foreign key
 * violation, `due_collects_user_id_foreign`. Deleting a business cascades to
 * its users, but on the deployed schema several business-scoped tables
 * *restrict* rather than cascade on `users`, so the delete aborted.
 *
 * It aborted without a transaction, too — so a business could be left partly
 * erased while its owner was shown a 500.
 *
 * The fix clears those tables by business_id first rather than relaxing the
 * constraints, because the constraints are load-bearing: with ON DELETE
 * CASCADE, removing one staff member would silently take that person's sales,
 * purchases and due collections with them.
 *
 * The testing database is built from the migrations, which declare
 * `cascadeOnDelete()` throughout — so it cannot reproduce the production
 * schema's restriction. What is pinned here is the behaviour that must hold on
 * either schema, plus a check that the table list stays complete as tables are
 * added.
 */
class DeleteBusinessTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $password = 'correct-horse'): User
    {
        return User::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'role' => 'shop-owner',
            'password' => Hash::make($password),
        ]);
    }

    public function test_the_business_and_its_rows_are_gone(): void
    {
        $user = $this->owner();
        $businessId = $user->business_id;

        DB::table('due_collects')->insert([
            'business_id' => $businessId,
            'party_id' => DB::table('parties')->insertGetId([
                'business_id' => $businessId,
                'name' => 'A Supplier',
                'type' => 'supplier',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'user_id' => $user->id,
            'payDueAmount' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/business-delete', ['password' => 'correct-horse']);

        $response->assertOk();

        $this->assertDatabaseMissing('businesses', ['id' => $businessId]);
        $this->assertSame(0,
            DB::table('due_collects')->where('business_id', $businessId)->count(),
            'the rows that blocked the delete are still there');
    }

    public function test_a_wrong_password_deletes_nothing(): void
    {
        $user = $this->owner();

        $this->actingAs($user)
            ->postJson('/api/v1/business-delete', ['password' => 'not-it'])
            ->assertStatus(406);

        $this->assertDatabaseHas('businesses', ['id' => $user->business_id]);
    }

    public function test_another_business_is_untouched(): void
    {
        $mine = $this->owner();
        $theirs = $this->owner();

        $this->actingAs($mine)
            ->postJson('/api/v1/business-delete', ['password' => 'correct-horse'])
            ->assertOk();

        $this->assertDatabaseHas('businesses', ['id' => $theirs->business_id]);
        $this->assertDatabaseHas('users', ['id' => $theirs->id]);
    }

    /**
     * The list is hand-maintained, so it can go stale the moment someone adds a
     * business-scoped table with a `user_id`. That table would then block
     * deletion again, and only in production, where the constraint restricts.
     */
    public function test_every_business_scoped_table_referencing_users_is_listed(): void
    {
        $schema = DB::connection()->getDatabaseName();

        $referencing = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', $schema)
            ->where('REFERENCED_TABLE_NAME', 'users')
            ->pluck('TABLE_NAME')
            ->unique();

        $this->assertNotEmpty($referencing,
            'found no foreign keys to users at all — this guard is not looking '
            .'at the schema it thinks it is');

        $businessScoped = $referencing->filter(
            fn ($table) => DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', $schema)
                ->where('TABLE_NAME', $table)
                ->where('COLUMN_NAME', 'business_id')
                ->exists()
        )->values()->sort()->values()->all();

        $declared = (new ReflectionClass(\App\Http\Controllers\Api\BusinessController::class))
            ->getConstant('USER_LINKED_TABLES');
        sort($declared);

        $this->assertSame($businessScoped, $declared,
            'USER_LINKED_TABLES no longer matches the schema; a table missing '
            .'from it will make account deletion fail in production only');
    }
}
