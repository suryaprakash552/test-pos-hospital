<?php

namespace App\Services\Reporting;

use App\Models\Expense;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StaffLoginSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * The staff report, lifted out of StatisticsController.
 *
 * It was a single 652-line method inside a 1,334-line controller - the two
 * branches below sharing one scope, so a variable set for the listing was still
 * live while the detail was assembled. Splitting them makes each answerable on its
 * own and gives the figures somewhere to be tested directly.
 *
 * Reads only. Every money figure comes from the stored `totalAmount` on the sale
 * or purchase rather than being recomputed, so a report cannot disagree with the
 * document it reports on.
 */
class StaffReportService
{
    /**
     * Every staff member of the business, with their headline figures.
     *
     * @return array<string, mixed>
     */
    public function listStaff(Request $request, User $user): array
    {

            $staffs = \App\Models\User::where('business_id', $user->business_id)
                ->where('role', 'staff')

                ->when($request->search, function ($query) use ($request) {

                    $query->where('name', 'LIKE', '%' . $request->search . '%');
                })

                ->get()

                ->map(function ($staff) use ($user, $request) {

                    $session = StaffLoginSession::where('business_id', $user->business_id)

                        ->where('user_id', $staff->id)

                        ->when($request->from_date && $request->to_date, function ($query) use ($request) {

                            $query->whereBetween('login_at', [

                                \Carbon\Carbon::parse($request->from_date)->startOfDay(),

                                \Carbon\Carbon::parse($request->to_date)->endOfDay()

                            ]);
                        })

                        ->latest()

                        ->get();

                        $sessionData = $session->map(function ($session) {

                        $loginTime = $session->login_at;

                        $logoutTime = $session->logout_at ?? now();

                        $salesQuery = Sale::where('user_id', $session->user_id)

                            ->whereBetween('created_at', [

                                $loginTime,

                                $logoutTime

                            ]);

                        $purchaseQuery = Purchase::where('user_id', $session->user_id)

                            ->whereBetween('created_at', [

                                $loginTime,

                                $logoutTime

                            ]);

                            $expenseQuery = Expense::where('user_id', $session->user_id)
                            ->whereBetween('expenseDate', [
                                $loginTime,
                                $logoutTime
                            ]);

                        return [

                            'session_id' => $session->id,

                            'login_at' => $loginTime,

                            'logout_at' => $session->logout_at,

                            'sales_count' => $salesQuery->count(),

                            'sales_amount' => round(
                                $salesQuery->sum('actual_total_amount'),
                                2
                            ),

                            'sales_cash' => round(  
                                    $salesQuery->sum('cash_amount'),
                                    2
                                ),

                                'sales_upi' => round(
                                    $salesQuery->sum('upi_amount'),
                                    2
                                ),

                                'expense_cash' => round(
                                    $expenseQuery->sum('amount'),
                                    2
                                ),

                                'expense_upi' => round(
                                    $expenseQuery->sum('upi_amount'),
                                    2
                                ),

                            'purchase_count' => $purchaseQuery->count(),

                            'purchase_amount' => round(
                                $purchaseQuery->sum('totalAmount'),
                                2
                            ),
                        ];
                    });


                    $salesCountQuery = Sale::where('business_id', $user->business_id)
                        ->where('user_id', $staff->id);

                    $salesAmountQuery = Sale::where('business_id', $user->business_id)
                        ->where('user_id', $staff->id);

                    $salesCashQuery = Sale::where('business_id', $user->business_id)
                        ->where('user_id', $staff->id);

                    $salesUpiQuery = Sale::where('business_id', $user->business_id)
                        ->where('user_id', $staff->id);

                    $expenseCashQuery = Expense::where('business_id', $user->business_id)
                        ->where('user_id', $staff->id);

                    $expenseUpiQuery = Expense::where('business_id', $user->business_id)
                        ->where('user_id', $staff->id);

                    $purchaseCountQuery = Purchase::where('business_id', $user->business_id)
                        ->where('user_id', $staff->id);

                    $purchaseAmountQuery = Purchase::where('business_id', $user->business_id)
                        ->where('user_id', $staff->id);

                    if ($request->from_date && $request->to_date) {

                        $range = [
                            \Carbon\Carbon::parse($request->from_date)->startOfDay(),
                            \Carbon\Carbon::parse($request->to_date)->endOfDay(),
                        ];

                        $salesCountQuery->whereBetween('created_at', $range);
                        $salesAmountQuery->whereBetween('created_at', $range);
                        $salesCashQuery->whereBetween('created_at', $range);
                        $salesUpiQuery->whereBetween('created_at', $range);

                        $expenseCashQuery->whereBetween('expenseDate', $range);
                        $expenseUpiQuery->whereBetween('expenseDate', $range);

                        $purchaseCountQuery->whereBetween('created_at', $range);
                        $purchaseAmountQuery->whereBetween('created_at', $range);

                    } else {

                        $salesCountQuery->whereDate('created_at', today());
                        $salesAmountQuery->whereDate('created_at', today());
                        $salesCashQuery->whereDate('created_at', today());
                        $salesUpiQuery->whereDate('created_at', today());

                        $expenseCashQuery->whereDate('expenseDate', today());
                        $expenseUpiQuery->whereDate('expenseDate', today());

                        $purchaseCountQuery->whereDate('created_at', today());
                        $purchaseAmountQuery->whereDate('created_at', today());
                    }

                                    return [

                        'id' => $staff->id,
                        'name' => $staff->name,
                        'email' => $staff->email,
                        'phone' => $staff->phone,
                        'image' => $staff->image,
                        'role' => $staff->role,

                        'today_sales_count' => $salesCountQuery->count(),

                        'today_sales_amount' => round(
                            $salesAmountQuery->sum('actual_total_amount'),
                            2
                        ),

                        'today_sales_cash' => round(
                                $salesCashQuery->sum('cash_amount'),
                                2
                            ),

                            'today_sales_upi' => round(
                                $salesUpiQuery->sum('upi_amount'),
                                2
                            ),

                            'today_expense_cash' => round(
                                $expenseCashQuery->sum('amount'),
                                2
                            ),

                            'today_expense_upi' => round(
                                $expenseUpiQuery->sum('upi_amount'),
                                2
                            ),

                        'today_purchase_count' => $purchaseCountQuery->count(),

                        'today_purchase_amount' => round(
                            $purchaseAmountQuery->sum('totalAmount'),
                            2
                        ),

                        'total_logins' => $session->count(),

                        'sessions' => $sessionData,
                    ];

                });

    return [
        'message' => 'Staff list fetched successfully',
        'data' => $staffs,
    ];
    }

    /**
     * One staff member's activity over the requested period.
     *
     * @return array<string, mixed>
     */
    public function staffDetail(Request $request, User $user): array
    {
            $salesQuery = Sale::with(
                'customer:id,name,phone',
                'payment_type:id,name',
                'branch:id,name',
                'user:id,name,role',
                'details',
                'details.product',
                'details.stock',
                'saleReturns'
            )

            ->where('business_id', $user->business_id)

            ->where('user_id', $request->staff_id)

            ->where('branch_id', $user->branch_id);

        if ($request->from_date && $request->to_date) {

            $salesQuery->whereBetween('created_at', [

                \Carbon\Carbon::parse($request->from_date)->startOfDay(),

                \Carbon\Carbon::parse($request->to_date)->endOfDay()

            ]);

        } else {

            $salesQuery->whereDate('created_at', today());
        }

            $sales = $salesQuery
                ->latest()
                ->get();

            $purchaseQuery = Purchase::with(
        'party:id,name,phone',
        'payment_type:id,name',
        'user:id,name,role',
        'details',
        'details.product',
        'details.stock',
        'purchaseReturns'
    )

            ->where('business_id', $user->business_id)

            ->where('user_id', $request->staff_id)

            ->where('branch_id', $user->branch_id);

        if (!$request->from_date || !$request->to_date) {

            $purchaseQuery->whereDate('created_at', today());
        }

        if ($request->from_date && $request->to_date) {

            $purchaseQuery->whereBetween('created_at', [

                \Carbon\Carbon::parse($request->from_date)->startOfDay(),

                \Carbon\Carbon::parse($request->to_date)->endOfDay()

            ]);
        }

            $purchases = $purchaseQuery
                ->latest()
                ->get();

        $todaySalesCount = Sale::where('business_id', $user->business_id)
            ->where('user_id', $request->staff_id)
            ->whereDate('created_at', today())
            ->count();

        $todaySalesAmount = Sale::where('business_id', $user->business_id)
            ->where('user_id', $request->staff_id)
            ->whereDate('created_at', today())
            ->sum('actual_total_amount');

            $todaySalesCash = Sale::where('business_id', $user->business_id)
            ->where('user_id', $request->staff_id)
            ->whereDate('created_at', today())
            ->sum('cash_amount');

        $todaySalesUpi = Sale::where('business_id', $user->business_id)
            ->where('user_id', $request->staff_id)
            ->whereDate('created_at', today())
            ->sum('upi_amount');

        $todayExpenseCash = Expense::where('business_id', $user->business_id)
            ->where('user_id', $request->staff_id)
            ->whereDate('expenseDate', today())
            ->sum('amount');

        $todayExpenseUpi = Expense::where('business_id', $user->business_id)
            ->where('user_id', $request->staff_id)
            ->whereDate('expenseDate', today())
            ->sum('upi_amount');


        $selectedSalesCountQuery = Sale::where('business_id', $user->business_id)
            ->where('user_id', $request->staff_id);

        $selectedSalesAmountQuery = Sale::where('business_id', $user->business_id)
            ->where('user_id', $request->staff_id);

            $selectedSalesCashQuery = Sale::where('business_id', $user->business_id)
            ->where('user_id', $request->staff_id);

        $selectedSalesUpiQuery = Sale::where('business_id', $user->business_id)
            ->where('user_id', $request->staff_id);

        $selectedExpenseCashQuery = Expense::where('business_id', $user->business_id)
            ->where('user_id', $request->staff_id);

        $selectedExpenseUpiQuery = Expense::where('business_id', $user->business_id)
            ->where('user_id', $request->staff_id);

        if ($request->from_date && $request->to_date) {

            $selectedSalesCountQuery->whereBetween('created_at', [

                \Carbon\Carbon::parse($request->from_date)->startOfDay(),
                \Carbon\Carbon::parse($request->to_date)->endOfDay()

            ]);

            $selectedSalesAmountQuery->whereBetween('created_at', [

                \Carbon\Carbon::parse($request->from_date)->startOfDay(),
                \Carbon\Carbon::parse($request->to_date)->endOfDay()

            ]);
        }
    if ($request->from_date && $request->to_date) {

        $range = [
            Carbon::parse($request->from_date)->startOfDay(),
            Carbon::parse($request->to_date)->endOfDay()
        ];

        $selectedSalesCashQuery->whereBetween('created_at', $range);
        $selectedSalesUpiQuery->whereBetween('created_at', $range);

        $selectedExpenseCashQuery->whereBetween('expenseDate', [
            Carbon::parse($request->from_date)->toDateString(),
            Carbon::parse($request->to_date)->toDateString()
        ]);

        $selectedExpenseUpiQuery->whereBetween('expenseDate', [
            Carbon::parse($request->from_date)->toDateString(),
            Carbon::parse($request->to_date)->toDateString()
        ]);
    }
    else {

        $selectedSalesCashQuery->whereDate('created_at', today());
        $selectedSalesUpiQuery->whereDate('created_at', today());

        $selectedExpenseCashQuery->whereDate('expenseDate', today());
        $selectedExpenseUpiQuery->whereDate('expenseDate', today());
    }


        $selectedSalesCount = $selectedSalesCountQuery->count();

        $selectedSalesAmount = $selectedSalesAmountQuery->sum('actual_total_amount');

        $selectedSalesCash = $selectedSalesCashQuery->sum('cash_amount');
    $selectedSalesUpi = $selectedSalesUpiQuery->sum('upi_amount');

    $selectedExpenseCash = $selectedExpenseCashQuery->sum('amount');
    $selectedExpenseUpi = $selectedExpenseUpiQuery->sum('upi_amount');


        $todayPurchaseCount = Purchase::where('business_id', $user->business_id)

            ->where('user_id', $request->staff_id)

            ->where('branch_id', $user->branch_id)

            ->whereDate('created_at', today())

            ->count();

        $todayPurchaseAmount = Purchase::where('business_id', $user->business_id)

            ->where('user_id', $request->staff_id)

            ->where('branch_id', $user->branch_id)

            ->whereDate('created_at', today())

            ->sum('totalAmount');


        $selectedPurchaseCountQuery = Purchase::where('business_id', $user->business_id)

            ->where('user_id', $request->staff_id)

            ->where('branch_id', $user->branch_id);

        $selectedPurchaseAmountQuery = Purchase::where('business_id', $user->business_id)

            ->where('user_id', $request->staff_id)

            ->where('branch_id', $user->branch_id);


        if (!$request->from_date || !$request->to_date) {

            $selectedPurchaseCountQuery->whereDate('created_at', today());

            $selectedPurchaseAmountQuery->whereDate('created_at', today());
        }


        if ($request->from_date && $request->to_date) {

            $selectedPurchaseCountQuery->whereBetween('created_at', [

                \Carbon\Carbon::parse($request->from_date)->startOfDay(),

                \Carbon\Carbon::parse($request->to_date)->endOfDay()

            ]);

            $selectedPurchaseAmountQuery->whereBetween('created_at', [

                \Carbon\Carbon::parse($request->from_date)->startOfDay(),

                \Carbon\Carbon::parse($request->to_date)->endOfDay()

            ]);
        }

        $selectedPurchaseCount = $selectedPurchaseCountQuery->count();

        $selectedPurchaseAmount = $selectedPurchaseAmountQuery->sum('totalAmount');

        $sessions = StaffLoginSession::where('business_id', $user->business_id)

        ->where('user_id', $request->staff_id)

        ->when($request->from_date && $request->to_date, function ($query) use ($request) {

            $query->whereBetween('login_at', [

                \Carbon\Carbon::parse($request->from_date)->startOfDay(),

                \Carbon\Carbon::parse($request->to_date)->endOfDay()

            ]);
        })

        ->when($request->session_id, function ($query) use ($request) {

            $query->where('id', $request->session_id);
        })

        ->latest()

        // Bounded, because the cost of this report is the number of shifts it walks
        // and that number grows for as long as someone is employed. The most recent
        // shifts are what a report screen shows; `from_date`/`to_date` reach older
        // ones, and `session_id` reaches a specific one.
        ->paginate(max(1, min((int) $request->input('per_page', 50), 100)))

        ->through(function ($session) use ($request) {

            $loginTime = $session->login_at;

            $logoutTime = $session->logout_at ?? now();

            // The filter, without the eager loads. Totals are counted over these rows;
            // the relations are only needed for the invoice list, and loading them to
            // compute a SUM would fetch every product and line item of the shift.
            $salesFilter = Sale::where('user_id', $session->user_id)
                ->whereBetween('created_at', [$loginTime, $logoutTime]);

            $purchaseFilter = Purchase::where('user_id', $session->user_id)
                ->whereBetween('created_at', [$loginTime, $logoutTime]);

            $salesQuery = (clone $salesFilter)->with(
                'customer:id,name,phone',
                'payment_type:id,name',
                'branch:id,name',
                'user:id,name,role',
                'details',
                'details.product',
                'details.stock',
                'saleReturns'
            );

            $purchaseQuery = (clone $purchaseFilter)->with(
                'party:id,name,phone',
                'payment_type:id,name',
                'user:id,name,role',
                'details',
                'details.product',
                'details.stock',
                'purchaseReturns'
            );

                $expenseQuery = Expense::where('user_id', $session->user_id)
                ->whereBetween('expenseDate', [
                    \Carbon\Carbon::parse($loginTime)->toDateString(),
                    \Carbon\Carbon::parse($logoutTime)->toDateString(),
                ]);

            // Eight separate aggregates over the same three sets of rows became eight
            // round trips per shift. The arithmetic is unchanged — the same columns
            // over the same filters — but a shift now costs three queries instead of
            // eight, which is what makes a long-serving employee's report affordable.
            $salesTotals = $salesFilter->selectRaw(
                'COUNT(*) as row_count,
                 COALESCE(SUM(actual_total_amount), 0) as amount_total,
                 COALESCE(SUM(cash_amount), 0) as cash_total,
                 COALESCE(SUM(upi_amount), 0) as upi_total'
            )->first();

            $purchaseTotals = $purchaseFilter->selectRaw(
                'COUNT(*) as row_count,
                 COALESCE(SUM(totalAmount), 0) as amount_total'
            )->first();

            $expenseTotals = $expenseQuery->selectRaw(
                'COALESCE(SUM(amount), 0) as cash_total,
                 COALESCE(SUM(upi_amount), 0) as upi_total'
            )->first();

            $response = [

                'session_id' => $session->id,

                'login_at' => $loginTime,

                'logout_at' => $session->logout_at,

                'sales_count' => (int) ($salesTotals->row_count ?? 0),

                'sales_amount' => round((float) ($salesTotals->amount_total ?? 0), 2),

                'sales_cash' => round((float) ($salesTotals->cash_total ?? 0), 2),

                'sales_upi' => round((float) ($salesTotals->upi_total ?? 0), 2),

                'expense_cash' => round((float) ($expenseTotals->cash_total ?? 0), 2),

                'expense_upi' => round((float) ($expenseTotals->upi_total ?? 0), 2),

                'purchase_count' => (int) ($purchaseTotals->row_count ?? 0),

                'purchase_amount' => round((float) ($purchaseTotals->amount_total ?? 0), 2),
            ];

            if ($request->session_id) {

                $response['sales_data'] = $salesQuery->latest()->get();

                $response['purchase_data'] = $purchaseQuery->latest()->get();
            }

            return $response;
        });

            return [

                'message' => 'Staff sales and purchases fetched successfully',

                'logged_in_business_id' => $user->business_id,

                'requested_staff_id' => $request->staff_id,

                'sessions_meta' => [
                    'current_page' => $sessions->currentPage(),
                    'per_page'     => $sessions->perPage(),
                    'total'        => $sessions->total(),
                    'last_page'    => $sessions->lastPage(),
                ],

                // Kept a plain array: the app reads `sessions` as a list and finds the
                // one it asked for inside it.
                'sessions' => $sessions->values(),

                'today_sales_count' => $todaySalesCount,
                'today_sales_amount' => round($todaySalesAmount, 2),
                'today_sales_cash' => round($todaySalesCash, 2),
                'today_sales_upi' => round($todaySalesUpi, 2),

                'today_expense_cash' => round($todayExpenseCash, 2),
                'today_expense_upi' => round($todayExpenseUpi, 2),

                'selected_sales_count' => $selectedSalesCount,
                'selected_sales_amount' => round($selectedSalesAmount, 2),
                'selected_sales_cash' => round($selectedSalesCash, 2),
                'selected_sales_upi' => round($selectedSalesUpi, 2),
                'selected_expense_cash' => round($selectedExpenseCash, 2),
                'selected_expense_upi' => round($selectedExpenseUpi, 2),

                'from_date' => $request->from_date,
                'to_date' => $request->to_date,

                'today_purchase_count' => $todayPurchaseCount,

                'today_purchase_amount' => round($todayPurchaseAmount, 2),

                'selected_purchase_count' => $selectedPurchaseCount,

                'selected_purchase_amount' => round($selectedPurchaseAmount, 2),

                'purchase_data' => $purchases,
            ];
    }
}
