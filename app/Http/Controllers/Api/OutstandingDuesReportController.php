<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Every contact who currently owes, or is owed, money — not a history of
 * collections against them.
 *
 * The Due List screens under Sales and Purchase are for *acting* on a due:
 * pick a contact, collect a payment. The reports under Reports → Due
 * Collection are a *history* of payments already collected. Neither answers
 * "who owes what right now" as a single, exportable list, which is what this
 * endpoint is for.
 *
 * A shop's suppliers live in `parties` and its customers in `customers` — two
 * tables with the same shape for this purpose, so this reads both and
 * combines them rather than adding a third source of truth.
 */
class OutstandingDuesReportController extends Controller
{
    /**
     * A balance signed by which way the money points.
     *
     * Positive is receivable (owed to the shop), negative is payable (owed by
     * it). Shared by the row filter and the header totals so the two cannot
     * disagree — they did once, and payable read ₹0.00 beside a list of
     * suppliers owed ₹165,285.
     */
    private const SIGNED_DUE = "CASE WHEN type = 'Supplier' THEN -due ELSE due END";

    /**
     * Narrow [$contacts] to those with a sale or purchase in the requested range.
     *
     * A `due` is a running balance with no date of its own, so "dues in this
     * period" can only mean "contacts who traded in this period and still owe".
     * Both tables are checked against both transaction types: `parties` holds
     * suppliers but has carried customers historically, and the report is a
     * consolidated view of everyone.
     *
     * @param  \Illuminate\Database\Query\Builder  $contacts
     */
    private function restrictToActivity(
        $contacts,
        string $idColumn,
        int $businessId,
        Request $request,
    ): void {
        $from = $request->filled('from_date') ? $request->input('from_date') : null;
        $to = $request->filled('to_date') ? $request->input('to_date') : null;

        if ($from === null && $to === null) {
            return;
        }

        $contacts->where(function ($outer) use ($idColumn, $businessId, $from, $to) {
            $outer
                ->whereExists(function ($sale) use ($idColumn, $businessId, $from, $to) {
                    $sale->from('sales')
                        ->whereColumn('sales.customer_id', $idColumn)
                        ->where('sales.business_id', $businessId);

                    if ($from !== null) {
                        $sale->whereDate('sales.saleDate', '>=', $from);
                    }
                    if ($to !== null) {
                        $sale->whereDate('sales.saleDate', '<=', $to);
                    }
                })
                ->orWhereExists(function ($purchase) use ($idColumn, $businessId, $from, $to) {
                    $purchase->from('purchases')
                        ->whereColumn('purchases.party_id', $idColumn)
                        ->where('purchases.business_id', $businessId);

                    if ($from !== null) {
                        $purchase->whereDate('purchases.purchaseDate', '>=', $from);
                    }
                    if ($to !== null) {
                        $purchase->whereDate('purchases.purchaseDate', '<=', $to);
                    }
                });
        });
    }

    public function index(Request $request)
    {
        $businessId = (int) auth()->user()->business_id;
        $perPage = ApiRequest::perPage($request, 50, 200);
        $search = ApiRequest::search($request);

        $columns = "id, business_id, name, phone, type, due, 'party' as source, created_at";
        $parties = DB::table('parties')
            ->selectRaw($columns)
            ->where('business_id', $businessId)
            ->where('due', '!=', 0);

        $customersColumns = "id, business_id, name, phone, type, due, 'customer' as source, created_at";
        $customers = DB::table('customers')
            ->selectRaw($customersColumns)
            ->where('business_id', $businessId)
            ->where('due', '!=', 0);

        // Contacts whose debt arose in the period, not contacts *added* in it.
        //
        // The date filter used to run on `created_at` — when the contact record
        // was first written. A shop's customers are added once and then owe
        // money for years, so any range narrower than "since we opened" matched
        // nobody: 175 contacts holding real balances filtered down to none, and
        // the report looked broken rather than empty.
        $this->restrictToActivity($parties, 'parties.id', $businessId, $request);
        $this->restrictToActivity($customers, 'customers.id', $businessId, $request);

        $query = DB::query()->fromSub(
            $parties->unionAll($customers),
            'contacts_with_due',
        );

        // Dates are applied to each contact's own sub-query above, against the
        // transactions the debt came from — see `restrictToActivity()`.

        // Show only what the shop is owed, or only what it owes.
        //
        // Uses the same signed expression the totals do, so the rows and the
        // header can never disagree about which way a balance points: a
        // supplier's positive `due` is payable, a customer's is receivable, and
        // a credit balance on either reverses that.
        $direction = strtolower(trim((string) $request->input('direction', '')));

        if ($direction === 'receivable') {
            $query->whereRaw('('.self::SIGNED_DUE.') > 0');
        } elseif ($direction === 'payable') {
            $query->whereRaw('('.self::SIGNED_DUE.') < 0');
        }

        if ($request->filled('min_due')) {
            $query->where('due', '>=', (float) $request->input('min_due'));
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%');
            });
        }

        // Reported separately, over the whole matching set — not the page on
        // screen — for the same reason `GstReportController::rateSummary`
        // sums over the full period: a total read off one page of a
        // multi-page report understates what is actually owed.
        // Which way the money points is carried by the contact's *type*, not by
        // the sign of `due`.
        //
        // A supplier's positive `due` is money the shop owes; a customer's is
        // money owed to it. Splitting on the sign alone put every supplier
        // balance into receivable and left payable at ₹0.00 on a report listing
        // three suppliers owed ₹165,285 between them.
        //
        // The sign still matters on top of that: an overpaid supplier, or a
        // customer holding a credit, points the other way.
        $signed = self::SIGNED_DUE;

        $totals = (clone $query)->selectRaw(
            "COUNT(*) as contact_count,
             COALESCE(SUM(CASE WHEN ($signed) > 0 THEN ($signed) ELSE 0 END), 0) as total_receivable,
             COALESCE(SUM(CASE WHEN ($signed) < 0 THEN -($signed) ELSE 0 END), 0) as total_payable"
        )->first();

        $rows = $query->orderByDesc(DB::raw('ABS(due)'))->paginate($perPage);

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $rows,
            'totals' => [
                'contacts' => (int) ($totals->contact_count ?? 0),
                // What customers owe the shop.
                'receivable' => round((float) ($totals->total_receivable ?? 0), 2),
                // What the shop owes suppliers.
                'payable' => round((float) ($totals->total_payable ?? 0), 2),
            ],
        ]);
    }
}
