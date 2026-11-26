<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockDisposal;
use App\Services\Disposal\StockDisposalService;
use App\Services\Pricing\CartCalculationException;
use App\Support\Api\ApiRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Expired stock: what is waiting to be disposed, and what already was.
 *
 * The waiting list is a *query*, not a table. A batch is awaiting disposal when
 * its expiry has passed and its purchase lots still hold quantity — both facts
 * already exist, and keeping a second copy of them is exactly how
 * `stocks.productStock` came to disagree with the lots on 131 of 158 expired
 * batches. Nothing to sync, nothing to drift.
 */
class StockDisposalController extends Controller
{
    public function __construct(private readonly StockDisposalService $disposals)
    {
    }

    /** Everything expired that still holds stock: the disposal queue. */
    public function pending(Request $request)
    {
        $businessId = (int) auth()->user()->business_id;

        $validated = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:191'],
            'expired_from' => ['nullable', 'date'],
            'expired_to' => ['nullable', 'date'],
        ]);

        $query = Stock::query()
            ->where('stocks.business_id', $businessId)
            ->whereNotNull('stocks.expire_date')
            ->whereDate('stocks.expire_date', '<', today())
            ->withSum('availableLots as available_quantity', 'conversionQuantity')
            ->having('available_quantity', '>', 0)
            ->with('product:id,productName,productCode')
            ->when($validated['product_id'] ?? null, fn ($q, $id) =>
                $q->where('stocks.product_id', $id))
            ->when($validated['expired_from'] ?? null, fn ($q, $date) =>
                $q->whereDate('stocks.expire_date', '>=', $date))
            ->when($validated['expired_to'] ?? null, fn ($q, $date) =>
                $q->whereDate('stocks.expire_date', '<=', $date))
            ->when($validated['search'] ?? null, function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('stocks.batch_no', 'like', '%'.$term.'%')
                        ->orWhereHas('product', fn ($p) =>
                            $p->where('productName', 'like', '%'.$term.'%'));
                });
            })
            ->orderBy('stocks.expire_date');

        // Totals over the whole filtered queue, not the page — "select all
        // filtered" has to know what it is selecting.
        $totals = (clone $query)->get(['stocks.id']);

        $page = $query->paginate(ApiRequest::perPage($request, 50, 500));

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'totals' => [
                'batches' => $totals->count(),
                // Every id in the filtered set, so the app can "select all
                // filtered" without walking every page first.
                'stock_ids' => $totals->pluck('id')->all(),
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'data' => $page->items(),
        ]);
    }

    /** Dispose the selected expired batches, in one transaction. */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'stock_ids' => ['required', 'array', 'min:1', 'max:500'],
            'stock_ids.*' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $disposal = $this->disposals->dispose(
                (int) auth()->user()->business_id,
                (int) auth()->id(),
                $validated['stock_ids'],
                $validated['note'] ?? null,
            );
        } catch (CartCalculationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
            ] + $e->context, $e->status);
        }

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data' => $disposal,
        ]);
    }

    /** The disposal register: what was destroyed, when, by whom. */
    public function index(Request $request)
    {
        $businessId = (int) auth()->user()->business_id;

        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'product_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:191'],
        ]);

        $query = StockDisposal::query()
            ->forBusiness($businessId)
            ->with(['lines', 'user:id,name', 'branch:id,name'])
            ->when($validated['from_date'] ?? null, fn ($q, $d) =>
                $q->whereDate('disposal_date', '>=', $d))
            ->when($validated['to_date'] ?? null, fn ($q, $d) =>
                $q->whereDate('disposal_date', '<=', $d))
            ->when($validated['user_id'] ?? null, fn ($q, $id) =>
                $q->where('user_id', $id))
            ->when($validated['product_id'] ?? null, fn ($q, $id) =>
                $q->whereHas('lines', fn ($l) => $l->where('product_id', $id)))
            ->when($validated['search'] ?? null, fn ($q, $term) =>
                $q->where(function ($inner) use ($term) {
                    $inner->where('disposal_no', 'like', '%'.$term.'%')
                        ->orWhereHas('lines', fn ($l) =>
                            $l->where('product_name', 'like', '%'.$term.'%')
                                ->orWhere('batch_no', 'like', '%'.$term.'%'));
                }))
            ->latest('disposal_date')
            ->latest('id');

        // Over the whole filtered register, the way every other report totals.
        $totals = (clone $query)->getQuery()->getCountForPagination();
        $lineTotals = DB::table('stock_disposal_lines')
            ->whereIn('stock_disposal_id', (clone $query)->select('id'))
            ->selectRaw('COALESCE(SUM(quantity),0) qty, COALESCE(SUM(value),0) val, COUNT(*) batches')
            ->first();

        $page = $query->paginate(ApiRequest::perPage($request, 50, 200));

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'totals' => [
                'disposals' => $totals,
                'batches' => (int) ($lineTotals->batches ?? 0),
                'quantity' => round((float) ($lineTotals->qty ?? 0), 2),
                'value' => round((float) ($lineTotals->val ?? 0), 2),
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'data' => $page->items(),
        ]);
    }

    public function show(int $id)
    {
        $disposal = StockDisposal::query()
            ->forBusiness((int) auth()->user()->business_id)
            ->with(['lines', 'user:id,name', 'branch:id,name'])
            ->findOrFail($id);

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $disposal,
        ]);
    }
}
