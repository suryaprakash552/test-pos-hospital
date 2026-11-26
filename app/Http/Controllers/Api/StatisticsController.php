<?php

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Controller;
use App\Models\SaleReturn;
use App\Models\SaleReturnDetails;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnDetail;
use App\Services\Reporting\StaffReportService;

class StatisticsController extends Controller
{

    /**
     * Start and end of a named reporting period.
     *
     * Calendar-based rather than rolling: "this month" is what a shopkeeper
     * reconciles against, not the last thirty days. An unknown or missing value
     * is today, which is what the screen opens on.
     *
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon}
     */
    private function summaryWindow(?string $period): array
    {
        $endOfToday = today()->endOfDay();

        return match ($period) {
            'yesterday' => [today()->subDay(), today()->subDay()->endOfDay()],
            'week' => [now()->startOfWeek(), $endOfToday],
            'month' => [now()->startOfMonth(), $endOfToday],
            'year' => [now()->startOfYear(), $endOfToday],
            default => [today(), $endOfToday],
        };
    }

   public function summary(Request $request)
{
    try {
        $user = auth()->user();
        // The window the home screen is asking about.
        //
        // Staff used to be measured from `last_login_at`, which `LoginAction`
        // stamps on every sign-in, so a cashier's figures reset to zero the
        // moment they signed back in. That is gone: the window is a period, the
        // same one for everyone, and only *whose* rows are counted still varies
        // by role.
        //
        // Yesterday is the reason this is a range and not just a start: it has
        // to end before today begins, or it silently means "since yesterday".
        [$from, $to] = $this->summaryWindow($request->input('period'));
        $saleQuery = Sale::query();

        if ($user->role == 'staff') {
            $saleQuery->where('user_id', $user->id);
        } else {
            $saleQuery->where('business_id', $user->business_id);
        }

    // Summed from the figure each sale line already carries.
    //
    // This joined `purchase_details` on `stock_id`, which is one row per *lot*,
    // not per batch — so every sale line was counted once for each lot ever
    // bought into its batch. On the live data that turned 2,653 sale lines into
    // 3,739 rows (worst batch: 69 lots) and reported 6,441,226 of profit on
    // 4,072,083 of sales. The home screen showed profit exceeding sales.
    //
    // `sale_details.lossProfit` is written when the sale is made, from the cost
    // of the stock actually drawn down, so no join is needed. The clamp is
    // kept: a line sold below cost does not subtract from the day's profit.
    $sale_profit = (float) (DB::table('sale_details as sd')
        ->join('sales as s', 'sd.sale_id', '=', 's.id')
        ->whereBetween('s.created_at', [$from, $to])
        ->when($user->role == 'staff', function ($q) use ($user) {
            $q->where('s.user_id', $user->id);
        }, function ($q) use ($user) {
            $q->where('s.business_id', $user->business_id);
        })
        ->selectRaw('SUM(GREATEST(sd.lossProfit, 0)) as total_profit')
        ->value('total_profit') ?? 0);

    $purchaseQuery = Purchase::query();

    if ($user->role == 'staff') {
        $purchaseQuery->where('user_id', $user->id);
    } else {
        $purchaseQuery->where('business_id', $user->business_id);
    }

    $quantity = PurchaseDetails::join(
    'purchases',
    'purchase_details.purchase_id',
    '=',
    'purchases.id'
)
->where('purchases.business_id', $user->business_id)
->sum('purchase_details.conversionQuantity');

    $soldQuery = SaleDetails::join('sales', 'sale_details.sale_id', '=', 'sales.id');
        $purchasequantity = PurchaseDetails::join(
        'purchases',
        'purchase_details.purchase_id',
        '=',
        'purchases.id'
    )
    ->when($user->role == 'staff', function ($query) use ($user) {
        $query->where('purchases.user_id', $user->id);
    }, function ($query) use ($user) {
        $query->where('purchases.business_id', $user->business_id);
    })
    ->whereBetween('purchases.created_at', [$from, $to])
    ->sum('purchase_details.quantities');
    if ($user->role == 'staff') {
        $soldQuery->where('sales.user_id', $user->id);
    } else {
        $soldQuery->where('sales.business_id', $user->business_id);
    }

    $sold_quantity = $soldQuery
    ->whereBetween('sales.created_at', [$from, $to])
    ->sum('sale_details.quantities');

    $data = [

        'sales' => (float)(clone $saleQuery)
            ->whereBetween('created_at', [$from, $to])
            ->sum('totalAmount'),

        'sold_products' => (float)$sold_quantity,

        'income' => (float)(
            Income::where('business_id', $user->business_id)
                ->whereBetween('created_at', [$from, $to])
                ->sum('amount')
        ) + $sale_profit,

        'expense' => (float)Expense::where('business_id', $user->business_id)
            ->whereBetween('created_at', [$from, $to])
            ->sum(Expense::tenderTotalExpression()),

        'purchase' => (float)(
            PurchaseDetails::join(
                'purchases',
                'purchase_details.purchase_id',
                '=',
                'purchases.id'
            )
            ->when($user->role == 'staff', function ($query) use ($user) {
                $query->where('purchases.user_id', $user->id);
            }, function ($query) use ($user) {
                $query->where('purchases.business_id', $user->business_id);
            })
            ->whereBetween('purchases.created_at', [$from, $to])
            ->sum('purchase_details.totalPurchasePrice')
        ),

        'quantity' => (float)$quantity,
        'purchase_quantity' => (float)$purchasequantity,
    ];

    return response()->json([
        'message' => __('Data fetched successfully.'),
        'data' => $data,
    ]);
    } catch (\Exception $e) {
        return response()->json([
            'message' => __('Failed to fetch summary data.')
        ], 500);
    }
}

    public function dashboard()
    {
        try {
            $currentDate = Carbon::now();
            $user = auth()->user();
            $business_id = $user->business_id;
            $branch_id = $user->branch_id;
            $role = $user->role;
            $duration = request('duration', 'current_month');

        // Set date range, format, and period based on selected duration
        switch ($duration) {
            case 'today':
                $start = $currentDate->copy()->startOfDay();
                $end = $currentDate->copy()->endOfDay();
                $format = 'H';
                $period = collect(range(0, 23))->map(function ($hour) use ($start) {
                    return $start->copy()->setHour($hour);
                });
                break;

            case 'yesterday':
                $start = $currentDate->copy()->subDay()->startOfDay();
                $end = $currentDate->copy()->subDay()->endOfDay();
                $format = 'H';
               $period = collect(range(0, 23))->map(function ($hour) use ($start) {
                    return $start->copy()->setHour($hour);
                });
                break;

            case 'last_seven_days':
                $start = $currentDate->copy()->subDays(6)->startOfDay();
                $end = $currentDate->copy()->endOfDay();
                $format = 'd';
                $period = $start->daysUntil($end);
                break;

            case 'last_thirty_days':
                $start = $currentDate->copy()->subDays(29)->startOfDay();
                $end = $currentDate->copy()->endOfDay();
                $format = 'd';
                $period = $start->daysUntil($end);
                break;

            case 'current_month':
                $start = $currentDate->copy()->startOfMonth();
                $end = $currentDate->copy()->endOfMonth();
                $format = 'd';
                $period = $start->daysUntil($end);
                break;

            case 'last_month':
                $start = $currentDate->copy()->subMonthNoOverflow()->startOfMonth();
                $end = $currentDate->copy()->subMonthNoOverflow()->endOfMonth();
                $format = 'd';
                $period = $start->daysUntil($end);
                break;

            case 'current_year':
                $start = $currentDate->copy()->startOfYear();
                $end = $currentDate->copy()->endOfYear();
                $format = 'M';
                $period = $start->monthsUntil($end);
                break;

            case 'custom_date':
                if (request()->has('from_date') && request()->has('to_date')) {
                    $start = Carbon::parse(request('from_date'))->startOfDay();
                    $end = Carbon::parse(request('to_date'))->endOfDay();
                    $format = 'd';
                    $period = $start->daysUntil($end);
                } else {
                    return response()->json(['error' => 'From and To dates are required for custom date.'], 400);
                }
                break;

            default:
                return response()->json(['error' => 'Invalid duration'], 400);
        }

        // SQL date format for grouping
        $dateFormatSQL = match ($format) {
            'H' => '%H',
            'd' => '%Y-%m-%d',
            'M' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        // ✅ Correct invoice logic based on selected duration
        $user_sales_for_invoice = Sale::where('business_id', $business_id)
        ->when($role == 'staff', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
        ->whereBetween('created_at', [$start, $end]);

        $start_sale_invoice = 0;
        $end_sale_invoice = 0;

        $first_sale = (clone $user_sales_for_invoice)
            ->orderBy('created_at', 'asc')
            ->value('InvoiceNumber');

        $last_sale = (clone $user_sales_for_invoice)
            ->orderBy('created_at', 'desc')
            ->value('InvoiceNumber');

        if ($first_sale) {
            $start_sale_invoice = $first_sale;
            $end_sale_invoice = $last_sale;
        }

        // Sales data fetch and map
        $sales_data = Sale::selectRaw("
        DATE_FORMAT(created_at, '$dateFormatSQL') as date,
        SUM(cash_amount + upi_amount + card_amount) as amount
    ")
    ->where('business_id', $business_id)
    ->when($role == 'staff', function ($query) use ($user) {
        $query->where('user_id', $user->id);
    })
    ->whereBetween('created_at', [$start, $end])
    ->groupBy('date')
    ->orderBy('date')
    ->get()
    ->map(function ($item) {
        $item->amount = (float) $item->amount;
        return $item;
    })
    ->keyBy('date');

        // Sale count and invoice numbers for logged-in user and duration
        $user_sales_query = Sale::where('business_id', $business_id)
        ->when($role == 'staff', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
        ->whereBetween('created_at', [$start, $end]);

        $sale_count = $user_sales_query->count();
        $sale_invoice_numbers = $user_sales_query->pluck('InvoiceNumber');

        // Purchase data fetch and map
        $purchase_data = Purchase::selectRaw("
        DATE_FORMAT(created_at, '$dateFormatSQL') as date,
        SUM(cash_amount + upi_amount + card_amount) as amount
    ")
    ->where('business_id', $business_id)
    ->when($role == 'staff', function ($query) use ($user) {
        $query->where('user_id', $user->id);
    })
    ->whereBetween('created_at', [$start, $end])
    ->groupBy('date')
    ->orderBy('date')
    ->get()
    ->map(function ($item) {
        $item->amount = (float) $item->amount;
        return $item;
    })
    ->keyBy('date');

        $income_amount = Income::where('business_id', $business_id)
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        $sale_profit = Sale::where('business_id', $business_id)
            ->whereBetween('created_at', [$start, $end])
            ->where('lossProfit', '>', 0)
            ->sum('lossProfit');

        $expenseQuery = Expense::where('business_id', $business_id)
    ->when($role == 'staff', function ($query) use ($user) {
    $query->where('user_id', $user->id);
})
    ->whereDate('expenseDate', '>=', $start->toDateString())
    ->whereDate('expenseDate', '<=', $end->toDateString());
    $expense_cash = (clone $expenseQuery)->sum('amount');
    $expense_upi  = (clone $expenseQuery)->sum('upi_amount');
    // Card was added to expenses after this dashboard was written, so the
    // total omitted it — a card-paid expense did not move `total_expense`.
    $expense_card = (clone $expenseQuery)->sum('card_amount');
    $expense_total = $expense_cash + $expense_upi + $expense_card;

        $cleared_due = CustomerDueCollect::where('business_id', $business_id)
            ->when($role == 'staff', function ($query) use ($user) {
    $query->where('user_id', $user->id);
})
            ->whereBetween('created_at', [$start, $end])
            ->sum('payDueAmount');

        // Cleared due by cash and upi fields
        $cleared_due_cash = CustomerDueCollect::where('business_id', $business_id)
            ->when($role == 'staff', function ($query) use ($user) {
    $query->where('user_id', $user->id);
})
            ->whereBetween('created_at', [$start, $end])
            ->sum('cash_amount');

        $cleared_due_upi = CustomerDueCollect::where('business_id', $business_id)
            ->when($role == 'staff', function ($query) use ($user) {
    $query->where('user_id', $user->id);
})
            ->whereBetween('created_at', [$start, $end])
            ->sum('upi_amount');

        $cleared_due_card = CustomerDueCollect::where('business_id', $business_id)
            ->when($role == 'staff', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->whereBetween('created_at', [$start, $end])
            ->sum('card_amount');

        $pending_due = Sale::where('business_id', $business_id)
            ->when($role == 'staff', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->whereBetween('saleDate', [$start, $end])
            ->where('dueAmount', '>', 0)
            ->sum('dueAmount');

        // Purchase due clearance (from due_collects)
        $purchase_cleared_due = DueCollect::where('business_id', $business_id)
            ->when($role == 'staff', function ($query) use ($user) {
    $query->where('user_id', $user->id);
})
            ->whereBetween('created_at', [$start, $end])
            ->sum('payDueAmount');

        $purchase_cleared_due_cash = DueCollect::where('business_id', $business_id)
            ->when($role == 'staff', function ($query) use ($user) {
    $query->where('user_id', $user->id);
})
            ->whereBetween('created_at', [$start, $end])
            ->sum('cash_amount');

        $purchase_cleared_due_upi = DueCollect::where('business_id', $business_id)
           ->when($role == 'staff', function ($query) use ($user) {
    $query->where('user_id', $user->id);
})
            ->whereBetween('created_at', [$start, $end])
            ->sum('upi_amount');

        $purchase_cleared_due_card = DueCollect::where('business_id', $business_id)
            ->when($role == 'staff', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->whereBetween('created_at', [$start, $end])
            ->sum('card_amount');

        // Purchase pending due (from purchases)
        $purchase_pending_due = \App\Models\Purchase::where('business_id', $business_id)
            ->when($role == 'staff', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->whereBetween('purchaseDate', [$start, $end])
            ->where('dueAmount', '>', 0)
            ->sum('dueAmount');

       $baseSaleReturnQuery = SaleReturnDetails::whereHas('saleReturn', function ($q) use ($business_id, $start, $end, $role, $user) {

    $q->where('business_id', $business_id)
      ->whereDate('return_date', '>=', $start->toDateString())
      ->whereDate('return_date', '<=', $end->toDateString());

    if ($role == 'staff') {
        $q->whereHas('sale', function ($qq) use ($user) {
            $qq->where('user_id', $user->id);
        });
    }
});

$sale_return_cash = (clone $baseSaleReturnQuery)->sum('return_cash');
$sale_return_upi  = (clone $baseSaleReturnQuery)->sum('return_upi');
$sale_return_card = (clone $baseSaleReturnQuery)->sum('return_card');

   $basePurchaseReturnQuery = PurchaseReturnDetail::whereHas('purchaseReturn', function ($q) use ($business_id, $start, $end, $role, $user) {

    $q->where('business_id', $business_id)
      ->whereDate('return_date', '>=', $start->toDateString())
      ->whereDate('return_date', '<=', $end->toDateString());

    if ($role == 'staff') {
        $q->whereHas('purchase', function ($qq) use ($user) {
            $qq->where('user_id', $user->id);
        });
    }
});

$purchase_return_cash = (clone $basePurchaseReturnQuery)->sum('return_cash');
$purchase_return_upi  = (clone $basePurchaseReturnQuery)->sum('return_upi');
$purchase_return_card = (clone $basePurchaseReturnQuery)->sum('return_card');

        // Current inventory value comes from the remaining purchase lots. The
        // product-level stock and purchase-price fields are only derived/default
        // values and can represent a different batch or a stale tally after
        // returns and corrections.
        $stockValue = (float) PurchaseDetails::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_details.purchase_id')
            ->where('purchases.business_id', $business_id)
            ->sum(DB::raw('purchase_details.conversionQuantity * COALESCE(purchase_details.conversionPurchasePrice, 0)'));

        $data = [
            'total_expense_cash' => (float) $expense_cash,
            'total_expense_upi'  => (float) $expense_upi,
            'total_expense_card' => (float) $expense_card,
            'total_expense'      => (float) $expense_total,
            'total_income' => (float) $income_amount + $sale_profit,
            'total_items' => Product::where('business_id', $business_id)->count(),
            'total_categories' => Category::where('business_id', $business_id)->count(),
            'stock_value' => $stockValue,
            'total_due' => (float) Sale::where('business_id', $business_id)->whereBetween('saleDate', [$start, $end])->sum('dueAmount'),
            'cleared_due' => (float) $cleared_due,
            'cleared_due_cash' => (float) $cleared_due_cash,
            'cleared_due_upi' => (float) $cleared_due_upi,
            'cleared_due_card' => (float) $cleared_due_card,
            'pending_due' => (float) $pending_due,
            'purchase_cleared_due' => (float) $purchase_cleared_due,
            'purchase_cleared_due_cash' => (float) $purchase_cleared_due_cash,
            'purchase_cleared_due_upi' => (float) $purchase_cleared_due_upi,
            'purchase_cleared_due_card' => (float) $purchase_cleared_due_card,
            'purchase_pending_due' => (float) $purchase_pending_due,
            'sale_count' => $sale_count,
            'sale_invoice_numbers' => $sale_invoice_numbers,
            'start_sale_invoice' => $start_sale_invoice,
            'end_sale_invoice' => $end_sale_invoice,
            'total_sales_cash' => (float) Sale::where('business_id', $business_id)
                ->when($role == 'staff', function ($query) use ($user) {
    $query->where('user_id', $user->id);
})
                ->whereBetween('created_at', [$start, $end])
                ->sum('cash_amount'),
            'total_sales_upi' => (float) Sale::where('business_id', $business_id)
                ->when($role == 'staff', function ($query) use ($user) {
    $query->where('user_id', $user->id);
})
                ->whereBetween('created_at', [$start, $end])
                ->sum('upi_amount'),
            'total_sales_card' => (float) Sale::where('business_id', $business_id)
                ->when($role == 'staff', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->whereBetween('created_at', [$start, $end])
                ->sum('card_amount'),
            'total_purchase_cash' => (float) Purchase::where('business_id', $business_id)
                ->when($role == 'staff', function ($query) use ($user) {
    $query->where('user_id', $user->id);
})
                ->whereBetween('created_at', [$start, $end])
                ->sum('cash_amount'),
            'total_purchase_upi' => (float) Purchase::where('business_id', $business_id)
                ->when($role == 'staff', function ($query) use ($user) {
    $query->where('user_id', $user->id);
})
                ->whereBetween('created_at', [$start, $end])
                ->sum('upi_amount'),
            'total_purchase_card' => (float) Purchase::where('business_id', $business_id)
                ->when($role == 'staff', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->whereBetween('created_at', [$start, $end])
                ->sum('card_amount'),
                'sale_return_cash' => (float) $sale_return_cash,
                'sale_return_upi'  => (float) $sale_return_upi,
                'sale_return_card' => (float) $sale_return_card,
                'purchase_return_cash' => (float) $purchase_return_cash,
                'purchase_return_upi'  => (float) $purchase_return_upi,
                'purchase_return_card' => (float) $purchase_return_card,
            'total_profit' => (float) Sale::where('business_id', $business_id)->whereBetween('created_at', [$start, $end])->where('lossProfit', '>', 0)->sum('lossProfit'),
            'total_loss' => (float) Sale::where('business_id', $business_id)->whereBetween('created_at', [$start, $end])->where('lossProfit', '<', 0)->sum('lossProfit'),
            'sales' => $this->formatData($period, $sales_data, $format),
            'purchases' => $this->formatData($period, $purchase_data, $format),
        ];

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $data,
        ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to fetch dashboard data.')
            ], 500);
        }
    }

    public function dueSummary()
    {
        try {
            $user = auth()->user();

            // Business-wide for every user, matching the due lists themselves. A
            // staff member collecting a due needs to see the whole outstanding
            // position, not only the part they happened to raise.
            $collectedDueQuery = CustomerDueCollect::where('business_id', $user->business_id);

        $pendingDueQuery = Sale::where('business_id', $user->business_id)
            ->where('dueAmount', '>', 0);

        $cleared_due = (float) $collectedDueQuery->sum('payDueAmount');
        $pending_due = (float) $pendingDueQuery->sum('dueAmount');

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => [
                'cleared_due' => $cleared_due,
                'pending_due' => $pending_due,
                'total_due' => $cleared_due + $pending_due,
            ],
        ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to fetch due summary data.')
            ], 500);
        }
    }

    public function addCounterAmount(Request $request)
    {
        try {
            $request->validate([
                // A drawer float cannot be negative, and the type is a fixed
                // vocabulary. Unbounded, a negative amount silently corrupts the
                // reconciliation this table exists to support.
                'amount' => 'nullable|numeric|min:0',
                'upi_amount' => 'nullable|numeric|min:0',
                'type' => 'nullable|string|in:counter,opening,closing',
            ]);

            $user = auth()->user();

            $branchId = \App\Support\ActiveBranch::forWrite($user);

            $session = CounterSession::create([
                'user_id' => $user->id,
                'branch_id' => $branchId,
                'amount' => $request->amount ?? 0,
                'upi_amount' => $request->upi_amount ?? 0,
                'type' => $request->type??'counter'
            ]);

            return response()->json([
                'message' => 'Counter amount added successfully',
                'data' => $session
            ]);
        } catch (ValidationException $e) {
            // Rethrown so Laravel renders the 422 with its field errors. The
            // blanket catch below turned every invalid drawer entry into a 500,
            // leaving the till with nothing to show the user.
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to add counter amount.')
            ], 500);
        }
    }

    /**
     * When the current working period began, for counter purposes.
     *
     * A staff member's period is their login session. Nobody else has one:
     * `StaffLoginSession` is created only for `role === staff`, so for a
     * shop-owner this used to resolve to "no session" and take the drawer with
     * it - the opening float saved fine but never appeared on the dashboard, and
     * saving from the dashboard answered 404. Their period is the calendar day,
     * which is how a till is reconciled anyway.
     */
    private function counterPeriodStart($user): \Illuminate\Support\Carbon
    {
        $session = StaffLoginSession::where('user_id', $user->id)
            ->whereNull('logout_at')
            ->latest()
            ->first();

        return $session?->login_at
            ? \Illuminate\Support\Carbon::parse($session->login_at)
            : now()->startOfDay();
    }

    /**
     * The counter row the current period is working against, if any.
     */
    private function currentCounter($user): ?CounterSession
    {
        return CounterSession::where('user_id', $user->id)
            ->where('created_at', '>=', $this->counterPeriodStart($user))
            // The float, not the handover.
            //
            // Closing a counter writes a row of its own, holding what was
            // *counted* at the end of the shift. It was newer than the float, so
            // it became "the counter": the dashboard then showed the counted
            // figure as the opening amount, and editing that field wrote over
            // the closing record instead of the float — which is how an edited
            // amount appeared to fall back to a previous one.
            ->whereIn('type', ['counter', 'opening'])
            // By id, not by timestamp. Two rows written in the same second tie
            // on `created_at`, and MySQL is then free to return either — which
            // is what made this intermittent rather than constant.
            ->latest('id')
            ->first();
    }

    public function viewCounterAmount()
    {
        try {
            $user = auth()->user();
            $counter = $this->currentCounter($user);

            // `data` is always present, even when null. The early return for a
            // missing session omitted the key entirely, and the app reads the
            // figures out of `data` - so the dashboard had nothing to show even
            // when a float had just been saved.
            return response()->json([
                'cash_amount' => $counter->amount ?? 0,
                'upi_amount' => $counter->upi_amount ?? 0,
                'data' => $counter,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to view counter amount.')
            ], 500);
        }
    }

    public function updateCounterAmount(Request $request)
    {
        try {
            $request->validate([
                // A drawer float cannot be negative, and the type is a fixed
                // vocabulary. Unbounded, a negative amount silently corrupts the
                // reconciliation this table exists to support.
                'amount' => 'nullable|numeric|min:0',
                'upi_amount' => 'nullable|numeric|min:0',
                'type' => 'nullable|string|in:counter,opening,closing',
            ]);

            $user = auth()->user();

            // No longer refuses when there is no StaffLoginSession. Only staff get
            // one, so this 404'd for every shop-owner - which is the whole of
            // "Could not update the counter." A period start is always resolvable.
            $session = $this->currentCounter($user);

            if (!$session) {

                $session = CounterSession::create([
                    'user_id'    => $user->id,
                    'branch_id'  => \App\Support\ActiveBranch::forWrite($user),
                    'amount'     => 0,
                    'upi_amount' => 0,
                    'type'       => 'counter'
                ]);
            }

            $session->update([
                'amount'     => $request->amount ?? $session->amount,
                'upi_amount' => $request->upi_amount ?? $session->upi_amount,
                'type'       => $request->type ?? $session->type
            ]);

            return response()->json([
                'message' => 'Counter amount updated successfully',
                'data' => $session
            ]);
        } catch (ValidationException $e) {
            // Rethrown so Laravel renders the 422 with its field errors. The
            // blanket catch below turned every invalid drawer entry into a 500,
            // leaving the till with nothing to show the user.
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to update counter amount.')
            ], 500);
        }
    }

    private function formatData($period, $datas, $format)
    {
        $rows = [];

        foreach ($period as $date) {
            $key = $date->format($format);

            if ($format === 'M') {
                // Sum amounts for the month
                $dateKey = $date->format('Y-m');
                $amount = $datas->filter(fn($value) => strpos($value->date, $dateKey) === 0)->sum('amount');
            } elseif ($format === 'd') {
                // Get amount by full date
                $fullDateKey = $date->format('Y-m-d');
                $amount = $datas->get($fullDateKey)?->amount ?? 0;
            } elseif ($format === 'H') {
                // Get amount by hour
                $amount = $datas->get($key)?->amount ?? 0;
            } else {
                // Default: treat as full date
                $fullDateKey = $date->format('Y-m-d');
                $amount = $datas->get($fullDateKey)?->amount ?? 0;
            }

            $rows[] = [
                'date' => $key,
                'amount' => $amount,
            ];
        }

        return $rows;
    }

    /**
     * Staff activity, either the roster or one member's detail.
     *
     * The 652 lines this used to hold now live in StaffReportService, which lets
     * the two branches be read - and tested - separately.
     */
    public function staffReports(Request $request, StaffReportService $staffReports)
    {
        $user = auth()->user();

        if (! in_array($user->role, ['shop-owner', 'admin', 'superadmin'])) {
            return response()->json([
                'message' => 'Unauthorized access'
            ], 403);
        }

        // A session is already tied to exactly one staff member. Accepting a
        // session-only deep link keeps the drill-down safe and useful for
        // report exports while still scoping it to the current business.
        if ($request->filled('session_id') && ! $request->filled('staff_id')) {
            $staffId = StaffLoginSession::where('business_id', $user->business_id)
                ->whereKey($request->integer('session_id'))
                ->value('user_id');

            if (! $staffId) {
                return response()->json(['message' => 'Staff session not found'], 404);
            }

            $request->merge(['staff_id' => $staffId]);
        }

        $data = $request->staff_id
            ? $staffReports->staffDetail($request, $user)
            : $staffReports->listStaff($request, $user);

        return response()->json($data);
    }

}
