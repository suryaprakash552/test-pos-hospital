<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An expense that was saved but could not be seen.
 *
 * Reported as: Add Expense succeeds, then the expense screen and the expense
 * report both come back without it. That symptom has several possible homes -
 * the row never persisted, it persisted against the wrong business or branch, or
 * it persisted correctly and only the client's list was stale - and they call for
 * completely different fixes.
 *
 * These pin the server's half: the row exists, it belongs to the right business,
 * and the endpoint the screen reads returns it. Anything still missing after this
 * passes is a client-side staleness problem, not a persistence one.
 */
class ExpenseVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private ExpenseCategory $category;
    private PaymentType $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);

        $this->category = ExpenseCategory::create([
            'business_id' => $this->business->id,
            'categoryName' => 'Rent',
        ]);

        $this->cash = PaymentType::create([
            'business_id' => $this->business->id,
            'name' => 'Cash',
            'status' => 1,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function create(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)
            ->postJson('/api/v1/expenses', array_merge([
                'expense_category_id' => $this->category->id,
                'amount' => 500,
                'expanseFor' => 'Shop rent',
                'payment_type_id' => $this->cash->id,
                'expenseDate' => now()->toDateString(),
            ], $overrides));
    }

    /** @return array<int, array<string, mixed>> */
    private function listed(?User $as = null): array
    {
        return $this->actingAs($as ?? $this->owner)
            ->getJson('/api/v1/expenses')
            ->assertSuccessful()
            ->json('data') ?? [];
    }

    public function test_a_saved_expense_is_persisted(): void
    {
        $this->create()->assertSuccessful();

        $expense = Expense::latest('id')->first();

        $this->assertNotNull($expense, 'The expense was never written.');
        $this->assertSame($this->business->id, $expense->business_id);
        $this->assertSame($this->owner->id, $expense->user_id);
        $this->assertEquals(500, $expense->amount);
    }

    public function test_a_saved_expense_appears_in_the_list_the_screen_reads(): void
    {
        $this->create()->assertSuccessful();
        $expense = Expense::latest('id')->firstOrFail();

        $ids = collect($this->listed())->pluck('id')->all();

        $this->assertContains(
            $expense->id,
            $ids,
            'The expense was saved but the list endpoint did not return it.',
        );
    }

    /**
     * The list is what the report is built from, so a newly created expense has
     * to be reachable the moment it exists rather than after some later cutoff.
     */
    public function test_an_expense_created_today_is_returned_immediately(): void
    {
        $this->create(['expenseDate' => now()->toDateString()])->assertSuccessful();
        $expense = Expense::latest('id')->firstOrFail();

        $this->assertContains($expense->id, collect($this->listed())->pluck('id')->all());
    }

    public function test_a_saved_expense_can_be_opened_for_viewing_or_editing(): void
    {
        $this->create()->assertSuccessful();
        $expense = Expense::latest('id')->firstOrFail();

        $this->actingAs($this->owner)
            ->getJson("/api/v1/expenses/{$expense->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.id', $expense->id)
            ->assertJsonPath('data.expense_for', 'Shop rent')
            ->assertJsonPath('data.category.name', 'Rent');
    }

    public function test_an_expense_detail_is_not_visible_to_another_business(): void
    {
        $this->create()->assertSuccessful();
        $expense = Expense::latest('id')->firstOrFail();

        $otherBusiness = Business::factory()->create();
        $stranger = User::factory()->create([
            'business_id' => $otherBusiness->id,
            'role' => 'shop-owner',
        ]);

        $this->actingAs($stranger)
            ->getJson("/api/v1/expenses/{$expense->id}")
            ->assertNotFound();
    }

    public function test_the_selected_payment_type_is_kept(): void
    {
        $upi = PaymentType::create([
            'business_id' => $this->business->id,
            'name' => 'UPI',
            'status' => 1,
        ]);

        $this->create(['payment_type_id' => $upi->id])->assertSuccessful();

        $this->assertSame(
            $upi->id,
            Expense::latest('id')->firstOrFail()->payment_type_id,
            'The expense did not keep the payment type it was saved with.',
        );
    }

    public function test_an_expense_is_not_visible_to_another_business(): void
    {
        $this->create()->assertSuccessful();
        $expense = Expense::latest('id')->firstOrFail();

        $otherBusiness = Business::factory()->create();
        $stranger = User::factory()->create([
            'business_id' => $otherBusiness->id,
            'role' => 'shop-owner',
        ]);

        $ids = collect($this->listed($stranger))->pluck('id')->all();

        $this->assertNotContains(
            $expense->id,
            $ids,
            'An expense leaked into another business list.',
        );
    }

    /**
     * Staff see their own expenses.
     *
     * The staff branch of the list query filters on `user_id` alone, without the
     * `business_id` clause the owner branch carries. This asserts the isolation
     * that filter is relied upon to provide.
     */
    public function test_staff_do_not_see_another_businesses_expenses(): void
    {
        $otherBusiness = Business::factory()->create();
        $otherOwner = User::factory()->create([
            'business_id' => $otherBusiness->id,
            'role' => 'shop-owner',
        ]);
        $otherCategory = ExpenseCategory::create([
            'business_id' => $otherBusiness->id,
            'categoryName' => 'Other Rent',
        ]);

        $this->actingAs($otherOwner)
            ->postJson('/api/v1/expenses', [
                'expense_category_id' => $otherCategory->id,
                'amount' => 900,
                'expenseDate' => now()->toDateString(),
            ])
            ->assertSuccessful();

        $foreign = Expense::latest('id')->firstOrFail();

        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'staff',
        ]);

        $ids = collect($this->listed($staff))->pluck('id')->all();

        $this->assertNotContains($foreign->id, $ids);
    }
}
