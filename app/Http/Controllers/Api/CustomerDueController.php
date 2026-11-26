<?php

namespace App\Http\Controllers\Api;

use App\Models\Sale;
use App\Models\Customer;
use App\Models\CustomerDueCollect;
use App\Support\Api\ApiRequest;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerDueController extends Controller
{
    private const ROUNDING_TOLERANCE = 0.005;
    public function index(Request $request)
    {
        $user = auth()->user();

        // `user` is what the receipt prints as "Collected By". It was absent from
        // this eager load, so the field rendered empty on every due invoice even
        // though `user_id` had been stored correctly all along.
        $data = CustomerDueCollect::with('user:id,name,role','customer','payment_type','sale:id,saleDate,invoiceNumber,relationship')
            // Every user sees every due bill for the business. Dues get collected by
            // whoever is on the counter, not only by the person who raised the sale,
            // so narrowing this to the creating staff member hid collectable debt.
            ->where('business_id', $user->business_id)
            // Searchable by who owes it and filterable by when it was collected.
            // The list is one row per collection and grows with every payment
            // taken, so without these the only way to find one was to scroll.
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = $request->input('search');

                // One box for both: a cashier looking someone up has a name or a
                // number in front of them and should not have to say which.
                $query->whereHas('customer', function ($q) use ($term) {
                    $q->where('name', 'LIKE', "%{$term}%")
                        ->orWhere('phone', 'LIKE', "%{$term}%");
                });
            })
            ->when($request->filled('invoiceNumber'), fn ($q) =>
                $q->where('invoiceNumber', $request->input('invoiceNumber')))
            ->when($request->filled('from_date'), fn ($q) =>
                $q->whereDate('paymentDate', '>=', $request->input('from_date')))
            ->when($request->filled('to_date'), fn ($q) =>
                $q->whereDate('paymentDate', '<=', $request->input('to_date')))
            ->latest();

        // Bounded. This returned every collection the shop had ever taken, in
        // one response, growing without limit — the kind of endpoint that is
        // instant in testing and unusable after a year of trading. `data` stays
        // a flat list so the app's parse is unchanged; `meta` is there for it
        // to page with when it is ready to.
        $page = $data->paginate(ApiRequest::perPage($request, 100, 500));

        return response()->json([
            'message' => 'Customer due list',
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'data' => $page->items(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $businessId = $user->business_id;

        // The app posts d/m/Y. An unparseable value is left alone for the `date`
        // rule below to reject with a 422, and an ISO date is accepted as-is.
        if ($request->paymentDate) {
            $parsed = \Carbon\Carbon::canBeCreatedFromFormat($request->paymentDate, 'd/m/Y')
                ? \Carbon\Carbon::createFromFormat('d/m/Y', $request->paymentDate)
                : null;

            if ($parsed) {
                $request->merge(['paymentDate' => $parsed->format('Y-m-d')]);
            }
        }

        // Older app builds send a single invoice number as a string.
        if (is_string($request->invoiceNumber) && $request->invoiceNumber !== '') {
            $request->merge(['invoiceNumber' => [$request->invoiceNumber]]);
        }

        $request->validate([
            'payment_type_id' => [
                'required',
                Rule::exists('payment_types', 'id')
                    ->where('business_id', $businessId),
            ],
            'paymentDate' => 'required|date',
            'payDueAmount' => 'required|numeric|gt:0',
            'cash_amount' => 'nullable|numeric|min:0',
            'upi_amount' => 'nullable|numeric|min:0',
            'card_amount' => 'nullable|numeric|min:0',
            'customer_id' => [
                'required',
                Rule::exists('customers', 'id')
                    ->where('business_id', $businessId),
            ],
            'invoiceNumber' => 'nullable|array|min:1',
            'invoiceNumber.*' => 'string|distinct',
        ]);

        $invoiceNumbers = $request->input('invoiceNumber'); // array|null

        try {
            // Every read the checks depend on happens under the same lock as the
            // writes that consume it, inside one transaction.
            $result = DB::transaction(function () use ($request, $businessId, $invoiceNumbers) {
                $customer = Customer::where('business_id', $businessId)
                    ->lockForUpdate()
                    ->findOrFail($request->customer_id);

                $invoices = collect();
                if ($invoiceNumbers) {
                    // Stable order, so receipts come out oldest invoice first.
                    $invoices = Sale::whereIn('invoiceNumber', $invoiceNumbers)
                        ->where('customer_id', $customer->id)
                        ->where('business_id', $businessId)
                        ->orderBy('saleDate')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    if ($invoices->count() !== count($invoiceNumbers)) {
                        return ['error' => 'One or more invoices not found.', 'status' => 404];
                    }
                    if ($invoices->contains(fn ($i) => (float) $i->dueAmount <= 0)) {
                        return ['error' => 'One or more selected invoices have no due.', 'status' => 422];
                    }
                    $outstanding = round((float) $invoices->sum('dueAmount'), 2);
                } else {
                    $outstanding = round((float) $customer->due, 2);
                }

                $requested = round((float) $request->payDueAmount, 2);

                if ($requested > $outstanding + self::ROUNDING_TOLERANCE) {
                    return [
                        'error' => ($invoices->isNotEmpty() ? 'Selected invoices due is ' : 'Customer due is ') . $outstanding,
                        'status' => 400,
                    ];
                }

                if ($invoices->count() > 1) {
                    // Several invoices are always cleared completely. Anything
                    // short of the total (beyond the app's own rounding) is a
                    // partial payment, which is not allowed here.
                    if ($requested < $outstanding - self::ROUNDING_TOLERANCE) {
                        return [
                            'error' => "Selected invoices must be paid in full ({$outstanding}).",
                            'status' => 422,
                        ];
                    }
                    // Settle every selected invoice at its real due.
                    $payAmount = $outstanding;
                } else {
                    $payAmount = min($requested, $outstanding);
                }

                if ($payAmount <= 0) {
                    return ['error' => 'Nothing is due to collect.', 'status' => 422];
                }

                // Make cash/upi/card add up to what is actually being settled.
                $cash = (float) ($request->cash_amount ?? 0);
                $upi  = (float) ($request->upi_amount ?? 0);
                $card = (float) ($request->card_amount ?? 0);
                $splitTotal = $cash + $upi + $card;
                if ($splitTotal > 0 && abs($splitTotal - $payAmount) > 0.005) {
                    $scale = $payAmount / $splitTotal;
                    $cash = round($cash * $scale, 2);
                    $upi  = round($upi * $scale, 2);
                    $card = max(0, round($payAmount - $cash - $upi, 2));
                }
                $left = ['cash' => $cash, 'upi' => $upi, 'card' => $card];

                $targets = $invoices->isNotEmpty() ? $invoices->all() : [null];
                $remaining = $payAmount;
                $collections = [];

                foreach ($targets as $invoice) {
                    $due = $invoice ? round((float) $invoice->dueAmount, 2) : $outstanding;
                    $alloc = round(min($due, $remaining), 2);
                    if ($alloc <= 0) {
                        break;
                    }
                    $remaining = round($remaining - $alloc, 2);
                    $isLast = $remaining <= 0;

                    // Proportional share of each method; the last row takes the
                    // remainder so the shares sum exactly to the totals.
                    $share = [];
                    foreach ($left as $method => $amount) {
                        $share[$method] = $isLast
                            ? max(0, $amount)
                            : min($amount, round($amount * $alloc / $payAmount, 2));
                    }

                    // Put any rounding difference on the biggest share so the
                    // row's cash + upi + card equals its payDueAmount exactly.
                    $shareTotal = array_sum($share);
                    if ($shareTotal > 0) {
                        $diff = round($alloc - $shareTotal, 2);
                        if ($diff != 0) {
                            $largest = array_search(max($share), $share);
                            $share[$largest] = max(0, round($share[$largest] + $diff, 2));
                        }
                    }

                    foreach ($share as $method => $amount) {
                        $left[$method] = round($left[$method] - $amount, 2);
                    }

                    // Built field by field, never from `$request->all()`.
                    $collections[] = CustomerDueCollect::create([
                        'customer_id' => $customer->id,
                        'payment_type_id' => $request->payment_type_id,
                        'paymentDate' => $request->paymentDate,
                        'payDueAmount' => $alloc,
                        'cash_amount' => $share['cash'],
                        'upi_amount' => $share['upi'],
                        'card_amount' => $share['card'],
                        'user_id' => auth()->id(),
                        'business_id' => $businessId,
                        'branch_id' => \App\Support\ActiveBranch::forWrite(),
                        'sale_id' => $invoice?->id,
                        'totalDue' => $due,
                        'dueAmountAfterPay' => round($due - $alloc, 2),
                    ]);

                    if ($invoice) {
                        $invoice->update(['dueAmount' => round($due - $alloc, 2)]);
                    }
                }

                $customer->update(['due' => round((float) $customer->due - $payAmount, 2)]);

                return ['collections' => $collections];
            });
        } catch (\Throwable $e) {
            \Log::error('Customer due collection failed', [
                'business_id' => $businessId,
                'customer_id' => $request->customer_id,
                'amount' => $request->payDueAmount,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('Failed to collect due payment.'),
            ], 500);
        }

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status']);
        }

        // Load relationships for all collections
        $collections = collect($result['collections'])->map(function ($collection) {
            return $collection->load(
                'user:id,name,role',
                'payment_type:id,name',
                'customer',
                'sale:id,saleDate,invoiceNumber,relationship',
            );
        });

        return response()->json([
            'message' => 'Customer due collected successfully',
            'data' => $collections,
        ]);
    }
}
