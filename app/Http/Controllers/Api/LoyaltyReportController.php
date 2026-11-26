<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltySubscription;
use App\Services\LoyaltyPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * §12: the six loyalty reports.
 *
 * They all read the same two tables, which is why they live together — a renewal and
 * an upgrade are the same row shape with a different `origin`, and splitting them
 * across controllers would duplicate the filtering without separating anything.
 *
 * Every figure comes from what was recorded at the time: a subscription's own
 * `package_cost` and discount columns rather than the package master, so a report run
 * next year still reconciles against the invoices it describes (§11).
 */
class LoyaltyReportController extends Controller
{
    public function __construct()
    {
        $this->middleware('check.permission:loyalty.read');
    }

    /** §12.1 — who holds what, and how long is left. */
    public function customers(Request $request)
    {
        $rows = $this->base($request)
            ->with(['customer:id,name,phone', 'package:id,name,code'])
            ->when($request->filled('status'), fn ($q) =>
                $q->where('status', $request->input('status')))
            ->when($request->boolean('active_only'), fn ($q) => $q->current())
            ->when($request->boolean('expired_only'), fn ($q) =>
                $q->whereDate('end_date', '<', now()->toDateString()))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        // The most recent renewal and upgrade per customer, read in one query
        // rather than per row — a shop with a few thousand members would otherwise
        // issue a few thousand queries to fill two columns.
        //
        // Restricted to the customers on this page: aggregating the whole
        // membership to annotate fifty rows is the same mistake in a different
        // place.
        $lastEvents = LoyaltySubscription::query()
            ->where('business_id', auth()->user()->business_id)
            ->whereIn('customer_id', $rows->pluck('customer_id')->unique()->all())
            ->whereIn('origin', ['renewal', 'upgrade'])
            ->selectRaw('customer_id, origin, MAX(subscription_date) as last_date')
            ->groupBy('customer_id', 'origin')
            ->get()
            ->groupBy('customer_id');

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'meta' => $this->meta($rows),
            'data' => $rows->getCollection()->map(function (LoyaltySubscription $s) use ($lastEvents) {
                $events = $lastEvents[$s->customer_id] ?? collect();

                return [
                    'customer_id'   => $s->customer_id,
                    'customer_name' => $s->customer?->name,
                    'mobile'        => $s->customer?->phone,
                    'package_name'  => $s->package?->name,
                    'package_cost'  => (float) $s->package_cost,
                    'start_date'    => $s->start_date->toDateString(),
                    'end_date'      => $s->end_date->toDateString(),
                    'remaining_days'=> $s->remainingDays(),
                    'status'        => $s->status,
                    'discount_type' => $s->discount_type,
                    'discount_value'=> (float) $s->discount_value,
                    'max_discount'  => $s->max_discount_amount === null
                        ? null : (float) $s->max_discount_amount,
                    'amount_received' => (float) $s->amount_received,
                    'payment_mode'    => $s->payment_mode,
                    'last_renewal_date' => $events->firstWhere('origin', 'renewal')?->last_date,
                    'last_upgrade_date' => $events->firstWhere('origin', 'upgrade')?->last_date,
                ];
            })->values(),
        ]);
    }

    /** §12.2 — who has lapsed, and how long ago. */
    public function expired(Request $request)
    {
        $asOf = $request->input('as_of', now()->toDateString());

        $rows = $this->base($request)
            ->with(['customer:id,name,phone', 'package:id,name'])
            ->whereDate('end_date', '<', $asOf)
            // A term that was renewed or upgraded did not lapse — it was replaced,
            // and listing it as expired would invent a lost customer.
            ->whereNotIn('status', [LoyaltyPeriod::RENEWED, LoyaltyPeriod::UPGRADED])
            ->when($request->filled('expiry_from'), fn ($q) =>
                $q->whereDate('end_date', '>=', $request->input('expiry_from')))
            ->when($request->filled('expiry_to'), fn ($q) =>
                $q->whereDate('end_date', '<=', $request->input('expiry_to')))
            // Asked of the database rather than filtered afterwards: rejecting rows
            // in PHP after paging would return short pages and a total that counts
            // customers the caller asked to exclude.
            ->when($request->boolean('renewal_pending_only'), fn ($q) =>
                $q->whereNotExists(fn ($sub) => $sub
                    ->selectRaw(1)
                    ->from('loyalty_subscriptions as live')
                    ->whereColumn('live.customer_id', 'loyalty_subscriptions.customer_id')
                    ->where('live.business_id', auth()->user()->business_id)
                    ->where('live.status', LoyaltyPeriod::ACTIVE)
                    ->whereDate('live.start_date', '<=', $asOf)
                    ->whereDate('live.end_date', '>=', $asOf)))
            ->orderByDesc('end_date')
            ->paginate($this->perPage($request));

        // Whether the customer has since bought again — the "renewal pending"
        // question the report exists to answer. Only for the customers on this
        // page, since that is all the answer is shown against.
        $renewedSince = LoyaltySubscription::query()
            ->where('business_id', auth()->user()->business_id)
            ->whereIn('customer_id', $rows->pluck('customer_id')->unique()->all())
            ->current($asOf)
            ->pluck('customer_id')
            ->flip();

        // §12.2 "last contacted": the most recent renewal reminder actually sent for
        // this customer. Derived from the reminder log rather than a new field —
        // a reminder that went out *is* a contact, and a second record of the same
        // fact would drift from the first.
        //
        // Null means never contacted, which is the answer that matters most: it is
        // the list of lapsed customers nobody has chased yet.
        $lastContacted = DB::table('loyalty_reminders')
            ->join(
                'loyalty_subscriptions',
                'loyalty_subscriptions.id',
                '=',
                'loyalty_reminders.loyalty_subscription_id',
            )
            ->where('loyalty_reminders.business_id', auth()->user()->business_id)
            ->whereIn('loyalty_subscriptions.customer_id', $rows->pluck('customer_id')->unique()->all())
            ->groupBy('loyalty_subscriptions.customer_id')
            ->selectRaw(
                'loyalty_subscriptions.customer_id as customer_id,
                 MAX(loyalty_reminders.sent_on) as last_sent'
            )
            ->get()
            ->pluck('last_sent', 'customer_id');

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'meta' => $this->meta($rows),
            'data' => $rows->getCollection()->map(fn (LoyaltySubscription $s) => [
                'customer_id'      => $s->customer_id,
                'customer_name'    => $s->customer?->name,
                'mobile'           => $s->customer?->phone,
                'expired_package'  => $s->package?->name,
                // §12.2
                'last_contacted_date' => $lastContacted[$s->customer_id] ?? null,
                'expiry_date'      => $s->end_date->toDateString(),
                'days_since_expiry'=> $s->end_date->startOfDay()
                    ->diffInDays(\Illuminate\Support\Carbon::parse($asOf)->startOfDay()),
                'previous_package_cost' => (float) $s->package_cost,
                'renewal_status' => $renewedSince->has($s->customer_id)
                    ? 'renewed' : 'pending',
            ])->values(),
        ]);
    }

    /** §12.3 */
    public function renewals(Request $request)
    {
        return $this->lineage($request, 'renewal');
    }

    /** §12.4 */
    public function upgrades(Request $request)
    {
        return $this->lineage($request, 'upgrade');
    }

    /**
     * §12.5 — what was collected, and from where.
     *
     * Split by origin because the three are different businesses: a new
     * subscription is growth, a renewal is retention, an upgrade is expansion, and
     * a single total hides which of them is working.
     */
    public function amountReceived(Request $request)
    {
        // Summed by the database, not in PHP. This report is pure arithmetic over
        // the whole filtered set — loading every subscription row to add up one
        // column is the most expensive possible way to reach a handful of numbers,
        // and it grows with the membership.
        $byOrigin = (clone $this->base($request))
            ->selectRaw('origin, SUM(amount_received) as total')
            ->groupBy('origin')
            ->pluck('total', 'origin');

        $sumOf = fn (string $origin) => round((float) ($byOrigin[$origin] ?? 0), 2);

        /** One grouped total, keyed however the report needs it. */
        $groupedBy = function (string $column) use ($request) {
            return (clone $this->base($request))
                ->selectRaw("{$column} as bucket, SUM(amount_received) as total")
                ->groupBy($column)
                ->get();
        };

        // §12.5: what had already been taken before this period began, so a period
        // report reconciles against a running total rather than starting from zero.
        $opening = 0.0;

        if ($request->filled('from_date')) {
            $opening = round(
                LoyaltySubscription::query()
                    ->where('business_id', auth()->user()->business_id)
                    ->whereDate('subscription_date', '<', $request->input('from_date'))
                    ->sum('amount_received'),
                2,
            );
        }

        // Named once, for the packages that actually appear — previously read off
        // an eager-loaded relation on every row.
        $packageNames = \App\Models\LoyaltyPackage::query()
            ->where('business_id', auth()->user()->business_id)
            ->pluck('name', 'id');

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => [
                'opening_amount'          => $opening,
                // §12.5: money paid back, which reduces what the shop kept.
                'refund_amount'           => round(
                    (float) (clone $this->base($request))->sum('refund_amount'), 2,
                ),
                'new_subscription_amount' => $sumOf('new'),
                'renewal_amount'          => $sumOf('renewal'),
                'upgrade_amount'          => $sumOf('upgrade'),
                // Net of refunds: what the shop actually kept, which is the figure
                // the line is named for.
                'net_amount_received'     => round(
                    (float) (clone $this->base($request))->sum('amount_received')
                        - (float) (clone $this->base($request))->sum('refund_amount'),
                    2,
                ),

                'by_payment_mode' => $groupedBy('payment_mode')
                    ->mapWithKeys(fn ($row) => [
                        // A subscription taken before the field existed has no mode;
                        // it is still money received and must not be dropped.
                        ($row->bucket ?? 'unspecified') => round((float) $row->total, 2),
                    ]),
                'by_package' => $groupedBy('loyalty_package_id')
                    ->map(fn ($row) => [
                        'package' => $packageNames[$row->bucket] ?? null,
                        'amount'  => round((float) $row->total, 2),
                    ])->values(),
                'by_branch' => $groupedBy('branch_id')
                    ->mapWithKeys(fn ($row) => [
                        (int) ($row->bucket ?? 0) => round((float) $row->total, 2),
                    ]),
                'by_user' => $groupedBy('created_by')
                    ->mapWithKeys(fn ($row) => [
                        (int) ($row->bucket ?? 0) => round((float) $row->total, 2),
                    ]),
            ],
        ]);
    }

    /**
     * §12.6 — the discount actually given away, invoice by invoice.
     *
     * Read from `sales`, not from the packages: this reports what was charged, and
     * only the invoice knows that.
     */
    public function utilisation(Request $request)
    {
        $discounted = fn () => DB::table('sales')
            ->where('sales.business_id', auth()->user()->business_id)
            ->where('sales.loyalty_discount_amount', '>', 0)
            ->when($request->filled('from_date'), fn ($q) =>
                $q->whereDate('sales.saleDate', '>=', $request->input('from_date')))
            ->when($request->filled('to_date'), fn ($q) =>
                $q->whereDate('sales.saleDate', '<=', $request->input('to_date')))
            ->when($request->filled('customer_id'), fn ($q) =>
                $q->where('sales.customer_id', $request->integer('customer_id')));

        $rows = $discounted()
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('users', 'users.id', '=', 'sales.loyalty_discount_by')
            ->orderByDesc('sales.id')
            ->paginate($this->perPage($request), [
                'sales.id', 'sales.invoiceNumber', 'sales.saleDate',
                'sales.totalAmount', 'sales.rowDiscount',
                'sales.loyalty_package_name', 'sales.loyalty_eligible_amount',
                'sales.loyalty_discount_amount', 'sales.loyalty_discount_mode',
                'customers.name as customer_name', 'users.name as entered_by',
            ]);

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'meta' => $this->meta($rows),
            'data' => [
                // The total covers everything the filters match, not just this
                // page — a running total that changed as you paged would be worse
                // than no total at all.
                'total_discount_given' => round(
                    (float) $discounted()->sum('sales.loyalty_discount_amount'), 2,
                ),
                'rows' => $rows->getCollection(),
            ],
        ]);
    }

    // ── Shared ───────────────────────────────────────────────────────────────

    /**
     * How many rows one page of a report may carry.
     *
     * Capped, because these reports are read by a chain with tens of thousands of
     * members: an unbounded report builds the whole membership in memory and sends
     * it over a shop's connection, which fails at exactly the size where the report
     * starts being useful. 200 matches the cap the rest of the API already applies.
     */
    private function perPage(Request $request): int
    {
        $requested = (int) $request->input('per_page', 50);

        return max(1, min($requested, 200));
    }

    /** The paging block every report returns alongside its rows. */
    private function meta(\Illuminate\Contracts\Pagination\LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'per_page'     => $page->perPage(),
            'total'        => $page->total(),
            'last_page'    => $page->lastPage(),
        ];
    }

    /** The filters every subscription-based report accepts. */
    private function base(Request $request): Builder
    {
        return LoyaltySubscription::query()
            ->where('business_id', auth()->user()->business_id)
            ->when($request->filled('customer_id'), fn ($q) =>
                $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('package_id'), fn ($q) =>
                $q->where('loyalty_package_id', $request->integer('package_id')))
            ->when($request->filled('branch_id'), fn ($q) =>
                $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('created_by'), fn ($q) =>
                $q->where('created_by', $request->integer('created_by')))
            ->when($request->filled('payment_mode'), fn ($q) =>
                $q->where('payment_mode', $request->input('payment_mode')))
            ->when($request->filled('from_date'), fn ($q) =>
                $q->whereDate('subscription_date', '>=', $request->input('from_date')))
            ->when($request->filled('to_date'), fn ($q) =>
                $q->whereDate('subscription_date', '<=', $request->input('to_date')))
            ->when($request->filled('mobile'), fn ($q) =>
                $q->whereHas('customer', fn ($c) =>
                    $c->where('phone', 'like', '%' . $request->input('mobile') . '%')))
            ->when($request->filled('customer_name'), fn ($q) =>
                $q->whereHas('customer', fn ($c) =>
                    $c->where('name', 'like', '%' . $request->input('customer_name') . '%')))
            // One box for both, the way every other search in the app works.
            //
            // `mobile` and `customer_name` are separate filters and are ANDed,
            // so the report's single "Search customer" box could only ever match
            // a name — typing a phone number found nothing at all. A counter
            // looking someone up has a name or a number in front of them and
            // should not have to say which.
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->input('search');

                $q->whereHas('customer', fn ($c) =>
                    $c->where('name', 'like', '%' . $term . '%')
                        ->orWhere('phone', 'like', '%' . $term . '%'));
            });
    }

    /** §12.3 and §12.4 differ only in which origin they select. */
    private function lineage(Request $request, string $origin)
    {
        $rows = $this->base($request)
            ->where('origin', $origin)
            ->with([
                'customer:id,name,phone',
                'package:id,name',
                'previous:id,loyalty_package_id,start_date,end_date,package_cost',
                'previous.package:id,name',
            ])
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'meta' => $this->meta($rows),
            'data' => $rows->getCollection()->map(fn (LoyaltySubscription $s) => [
                'customer_id'      => $s->customer_id,
                'customer_name'    => $s->customer?->name,
                'mobile'           => $s->customer?->phone,
                'previous_package' => $s->previous?->package?->name,
                'new_package'      => $s->package?->name,
                'previous_end_date'=> $s->previous?->end_date?->toDateString(),
                'transaction_date' => $s->subscription_date->toDateString(),
                'new_start_date'   => $s->start_date->toDateString(),
                'new_end_date'     => $s->end_date->toDateString(),
                'previous_package_cost' => $s->previous === null
                    ? null : (float) $s->previous->package_cost,
                'new_package_cost' => (float) $s->package_cost,
                // §4.4: the credit for the unused term, so the amount charged can
                // be explained rather than merely stated.
                'adjustment_amount' => (float) $s->adjustment_amount,
                'amount_received'   => (float) $s->amount_received,
                'payment_mode'      => $s->payment_mode,
                'payment_reference' => $s->payment_reference,
                'reason'            => $s->change_reason,
                'branch_id'         => $s->branch_id,
                'processed_by'      => $s->created_by,
            ])->values(),
        ]);
    }
}
