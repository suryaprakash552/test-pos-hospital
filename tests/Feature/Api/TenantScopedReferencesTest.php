<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\ExpenseCategory;
use App\Models\IncomeCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A record may only reference rows its own business owns.
 *
 * The form requests used a bare `exists:table,id`, which accepts any id in the
 * table including another shop's. Two consequences: a record could be filed
 * against a category belonging to someone else, quietly corrupting that shop's
 * reporting; and the endpoint answered differently for a real id from another
 * tenant than for an invented one, which is an enumeration oracle.
 */
class TenantScopedReferencesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Business $otherBusiness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
        $this->otherBusiness = Business::factory()->create();
    }

    public function test_income_cannot_be_booked_to_another_tenants_category(): void
    {
        $foreignCategory = IncomeCategory::create([
            'business_id' => $this->otherBusiness->id,
            'categoryName' => 'Their Category',
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/incomes', [
            'amount' => 100,
            'income_category_id' => $foreignCategory->id,
            'incomeDate' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('income_category_id');
    }

    public function test_income_can_be_booked_to_its_own_category(): void
    {
        $ownCategory = IncomeCategory::create([
            'business_id' => $this->business->id,
            'categoryName' => 'Our Category',
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/incomes', [
            'amount' => 100,
            'income_category_id' => $ownCategory->id,
            'incomeDate' => now()->toDateString(),
        ])->assertSuccessful();
    }

    public function test_expense_cannot_be_booked_to_another_tenants_category(): void
    {
        $foreignCategory = ExpenseCategory::create([
            'business_id' => $this->otherBusiness->id,
            'categoryName' => 'Their Category',
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/expenses', [
            'amount' => 100,
            'expense_category_id' => $foreignCategory->id,
            'expenseDate' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('expense_category_id');
    }

    public function test_expense_can_be_booked_to_its_own_category(): void
    {
        $ownCategory = ExpenseCategory::create([
            'business_id' => $this->business->id,
            'categoryName' => 'Our Category',
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/expenses', [
            'amount' => 100,
            'expense_category_id' => $ownCategory->id,
            'expenseDate' => now()->toDateString(),
        ])->assertSuccessful();
    }

    public function test_a_negative_amount_is_refused(): void
    {
        $category = IncomeCategory::create([
            'business_id' => $this->business->id,
            'categoryName' => 'Our Category',
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/incomes', [
            'amount' => -5000,
            'income_category_id' => $category->id,
            'incomeDate' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_an_invented_category_id_and_a_foreign_one_answer_alike(): void
    {
        $foreignCategory = IncomeCategory::create([
            'business_id' => $this->otherBusiness->id,
            'categoryName' => 'Their Category',
        ]);

        $payload = fn ($id) => [
            'amount' => 100,
            'income_category_id' => $id,
            'incomeDate' => now()->toDateString(),
        ];

        $foreign = $this->actingAs($this->user)
            ->postJson('/api/v1/incomes', $payload($foreignCategory->id));

        $invented = $this->actingAs($this->user)
            ->postJson('/api/v1/incomes', $payload(999999));

        // Indistinguishable, so the endpoint reveals nothing about what exists
        // outside this tenant.
        $this->assertSame($invented->status(), $foreign->status());
        $this->assertSame(
            array_keys($invented->json('errors') ?? []),
            array_keys($foreign->json('errors') ?? []),
        );
    }
}
