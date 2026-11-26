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
 * A card-paid expense counts for its full amount.
 *
 * Reported as: paying an expense by card shows "Expense saved successfully" but
 * the record then reads ₹0 / "Payment not recorded". `Expense.amount` holds only
 * the cash share, and every total across the app summed that column alone — so a
 * card-only expense was worth zero everywhere it was added up, even though the
 * row (and its `card_amount`) had been stored correctly.
 */
class ExpenseTenderTotalTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private ExpenseCategory $category;
    private PaymentType $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create(['remainingShopBalance' => 10000]);
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
        $this->category = ExpenseCategory::create([
            'business_id' => $this->business->id,
            'categoryName' => 'Travel',
        ]);
        $this->card = PaymentType::create([
            'business_id' => $this->business->id,
            'name' => 'Card',
            'status' => 1,
        ]);
    }

    private function createCardExpense(int $amount = 200)
    {
        return $this->actingAs($this->owner)->postJson('/api/v1/expenses', [
            'expense_category_id' => $this->category->id,
            'amount' => 0,
            'upi_amount' => 0,
            'card_amount' => $amount,
            'expanseFor' => 'Petrol',
            'payment_type_id' => $this->card->id,
            'expenseDate' => now()->toDateString(),
        ]);
    }

    public function test_a_card_expense_stores_its_card_amount(): void
    {
        $this->createCardExpense(200)->assertSuccessful();

        $expense = Expense::latest('id')->firstOrFail();
        $this->assertEquals(0, $expense->amount);
        $this->assertEquals(200, $expense->card_amount);
    }

    public function test_a_card_expense_is_subtracted_from_the_shop_balance(): void
    {
        $this->createCardExpense(200)->assertSuccessful();

        $this->assertEquals(
            9800,
            $this->business->fresh()->remainingShopBalance,
        );
    }

    public function test_deleting_a_card_expense_returns_its_full_amount_to_the_balance(): void
    {
        $this->createCardExpense(200)->assertSuccessful();
        $expense = Expense::latest('id')->firstOrFail();

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/expenses/{$expense->id}")
            ->assertSuccessful();

        // Back to where it started — not 9800 + 0 because the cash share was 0.
        $this->assertEquals(10000, $this->business->fresh()->remainingShopBalance);
    }

    public function test_the_summary_expense_total_includes_card_payments(): void
    {
        $this->createCardExpense(200)->assertSuccessful();

        $this->actingAs($this->owner)
            ->getJson('/api/v1/summary')
            ->assertSuccessful()
            ->assertJsonPath('data.expense', fn ($v) => (float) $v === 200.0);
    }

    public function test_the_dashboard_expense_total_includes_card_payments(): void
    {
        $this->createCardExpense(200)->assertSuccessful();

        $this->actingAs($this->owner)
            ->getJson('/api/v1/dashboard?duration=current_month')
            ->assertSuccessful()
            ->assertJsonPath('data.total_expense', fn ($v) => (float) $v === 200.0)
            ->assertJsonPath('data.total_expense_card', fn ($v) => (float) $v === 200.0);
    }

    public function test_tender_total_expression_sums_every_column(): void
    {
        Expense::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'expense_category_id' => $this->category->id,
            'amount' => 100,
            'upi_amount' => 50,
            'card_amount' => 25,
            'expenseDate' => now()->toDateString(),
        ]);

        $this->assertEquals(
            175,
            Expense::query()->sum(Expense::tenderTotalExpression()),
        );
    }

    /**
     * The MultiBranch dashboard's monthly income/expense chart used the same
     * `SUM(ABS(amount))` shortcut and under-reported a card expense the same way.
     */
    public function test_the_multibranch_monthly_chart_expression_sums_every_column(): void
    {
        $this->createCardExpense(200)->assertSuccessful();

        $total = Expense::where('business_id', $this->business->id)
            ->whereYear('expenseDate', now()->year)
            ->selectRaw('SUM(ABS(COALESCE(amount, 0) + COALESCE(upi_amount, 0) + COALESCE(card_amount, 0))) as total')
            ->value('total');

        $this->assertEquals(200, (float) $total);
    }
}
