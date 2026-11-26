<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleDetails;
use App\Models\Income;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Category;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Stock;
use App\Models\StaffLoginSession;
use App\Models\CounterSession;
use App\Models\CustomerDueCollect;
use App\Models\DueCollect;
use App\Services\Reporting\ProductStockSummary;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * NOT WIRED UP. Nothing references this class - the equivalent logic currently
 * lives in the controllers. Wire it up or delete it; editing it alone changes
 * no behaviour.
 */
class StatisticsService extends BaseService
{
    /**
     * Get dashboard summary statistics.
     */
    public function getSummary(): array
    {
        $user = $this->authUser();

        // Today, for everyone.
        //
        // Staff used to be measured from `last_login_at`, which `LoginAction`
        // stamps on every sign-in — so a cashier's figures reset to zero the
        // moment they signed back in. Confirmed on live data: one staff account
        // had 18 sales worth 13,962.92 today and a summary reading 0, because
        // the day's selling happened before that session began.
        //
        // The window is the day; whose sales are counted is still decided
        // separately below, and staff still see only their own.
        $loginTime = today();

        // Build sale query based on role
        $saleQuery = Sale::query();
        if ($this->isStaff()) {
            $saleQuery->where('user_id', $user->id);
        } else {
            $saleQuery->where('business_id', $this->businessId());
        }

        // Calculate sale profit
        $saleProfit = $this->calculateSaleProfit($loginTime);

        // Build purchase query based on role
        $purchaseQuery = Purchase::query();
        if ($this->isStaff()) {
            $purchaseQuery->where('user_id', $user->id);
        } else {
            $purchaseQuery->where('business_id', $this->businessId());
        }

        // Calculate quantities
        $purchaseQuantity = $this->calculatePurchaseQuantity($loginTime);
        $soldQuantity = $this->calculateSoldQuantity($loginTime);

        // Get totals
        $totalSales = $saleQuery->clone()->where('created_at', '>=', $loginTime)->sum('totalAmount');
        $totalPurchases = $purchaseQuery->clone()->where('created_at', '>=', $loginTime)->sum('totalAmount');
        $totalIncome = Income::where('business_id', $this->businessId())
                            ->where('created_at', '>=', $loginTime)
                            ->sum('amount');
        $totalExpense = Expense::where('business_id', $this->businessId())
                             ->where('created_at', '>=', $loginTime)
                             ->sum(Expense::tenderTotalExpression());

        return [
            'total_sales' => $totalSales,
            'total_purchases' => $totalPurchases,
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'sale_profit' => $saleProfit,
            'purchase_quantity' => $purchaseQuantity,
            'sold_quantity' => $soldQuantity,
            'net_profit' => $saleProfit - $totalExpense,
        ];
    }

    /**
     * Calculate sale profit.
     */
    protected function calculateSaleProfit($loginTime): float
    {
        $user = $this->authUser();

        // Summed from the figure each sale line already carries.
        //
        // It used to join `purchase_details` on `stock_id`, which is one row
        // per *lot*, not per batch — so every sale line was counted once for
        // each lot ever bought into its batch. On the live data that turned
        // 2,653 sale lines into 3,739 rows (worst batch: 69 lots) and reported
        // 6,441,226 of profit on 4,072,083 of sales, which cannot be true.
        //
        // `sale_details.lossProfit` is written by the pricing service when the
        // sale is made, from the cost of the stock actually drawn down, so it
        // needs no join at all. The same clamp is kept: a line sold below cost
        // does not subtract from the day's profit, which is how this figure has
        // always been read.
        return (float) (DB::table('sale_details as sd')
            ->join('sales as s', 'sd.sale_id', '=', 's.id')
            ->where('s.created_at', '>=', $loginTime)
            ->when($this->isStaff(), function ($q) use ($user) {
                $q->where('s.user_id', $user->id);
            }, function ($q) use ($user) {
                $q->where('s.business_id', $user->business_id);
            })
            ->selectRaw('SUM(GREATEST(sd.lossProfit, 0)) as total_profit')
            ->value('total_profit') ?? 0);
    }

    /**
     * Calculate purchase quantity.
     */
    protected function calculatePurchaseQuantity($loginTime): float
    {
        $user = $this->authUser();

        return PurchaseDetails::join('purchases', 'purchase_details.purchase_id', '=', 'purchases.id')
            ->when($this->isStaff(), function ($query) use ($user) {
                $query->where('purchases.user_id', $user->id);
            }, function ($query) use ($user) {
                $query->where('purchases.business_id', $user->business_id);
            })
            ->where('purchases.created_at', '>=', $loginTime)
            ->sum('purchase_details.conversionQuantity');
    }

    /**
     * Calculate sold quantity.
     */
    protected function calculateSoldQuantity($loginTime): float
    {
        $user = $this->authUser();

        return PurchaseDetails::join('purchases', 'purchase_details.purchase_id', '=', 'purchases.id')
            ->join('sale_details', 'purchase_details.stock_id', '=', 'sale_details.stock_id')
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->when($this->isStaff(), function ($query) use ($user) {
                $query->where('purchases.user_id', $user->id);
            }, function ($query) use ($user) {
                $query->where('purchases.business_id', $user->business_id);
            })
            ->where('sales.created_at', '>=', $loginTime)
            ->sum('purchase_details.quantities');
    }

    /**
     * Get due summary.
     */
    public function getDueSummary(): array
    {
        $businessId = $this->businessId();

        $totalDue = DueCollect::where('business_id', $businessId)->sum('payDueAmount');
        $totalCustomerDue = CustomerDueCollect::where('business_id', $businessId)->sum('payDueAmount');
        $totalCollected = DueCollect::where('business_id', $businessId)->sum('collectedAmount');

        return [
            'total_due' => $totalDue,
            'total_customer_due' => $totalCustomerDue,
            'total_collected' => $totalCollected,
            'remaining_due' => $totalDue - $totalCollected,
        ];
    }

    /**
     * Get dashboard statistics.
     */
    public function getDashboard(): array
    {
        $businessId = $this->businessId();

        $totalProducts = Product::where('business_id', $businessId)->count();
        $totalCategories = Category::where('business_id', $businessId)->count();
        // Keep this legacy service aligned with the API: purchase-detail lots
        // are authoritative; stock counters are derived caches only.
        $totalStock = PurchaseDetails::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_details.purchase_id')
            ->where('purchases.business_id', $businessId)
            ->sum('purchase_details.conversionQuantity');
        $lowStockProducts = app(ProductStockSummary::class)
            ->forBusiness($businessId)['low_stock'];

        $todaySales = Sale::where('business_id', $businessId)
                          ->whereDate('created_at', today())
                          ->sum('totalAmount');

        $todayPurchases = Purchase::where('business_id', $businessId)
                                ->whereDate('created_at', today())
                                ->sum('totalAmount');

        return [
            'total_products' => $totalProducts,
            'total_categories' => $totalCategories,
            'total_stock' => $totalStock,
            'low_stock_products' => $lowStockProducts,
            'today_sales' => $todaySales,
            'today_purchases' => $todayPurchases,
        ];
    }

    /**
     * Add counter amount.
     */
    public function addCounterAmount(float $amount): CounterSession
    {
        return CounterSession::create([
            'business_id' => $this->businessId(),
            'branch_id' => $this->branchId(),
            'user_id' => $this->authUser()->id,
            'amount' => $amount,
            'cash_amount' => request('cash_amount', 0),
            'upi_amount' => request('upi_amount', 0),
        ]);
    }

    /**
     * View counter amount.
     */
    public function viewCounterAmount(): array
    {
        $today = today();
        $counterSessions = CounterSession::where('business_id', $this->businessId())
                                        ->whereDate('created_at', $today)
                                        ->get();

        return [
            'total_amount' => $counterSessions->sum('amount'),
            'total_cash' => $counterSessions->sum('cash_amount'),
            'total_upi' => $counterSessions->sum('upi_amount'),
            'sessions' => $counterSessions,
        ];
    }

    /**
     * Update counter amount.
     */
    public function updateCounterAmount(CounterSession $session, float $amount): CounterSession
    {
        $session->update([
            'amount' => $amount,
            'cash_amount' => request('cash_amount', $session->cash_amount),
            'upi_amount' => request('upi_amount', $session->upi_amount),
        ]);

        return $session->fresh();
    }

    /**
     * Get staff reports.
     */
    public function getStaffReports(array $filters = [])
    {
        $query = StaffLoginSession::with('user')
                                  ->where('business_id', $this->businessId());

        if (!empty($filters['from_date']) && !empty($filters['to_date'])) {
            $query->whereBetween('login_time', [$filters['from_date'], $filters['to_date']]);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        return $query->latest()->get();
    }
}
