<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * GST position for a period: tax collected on sales, tax paid on purchases, and the
 * difference — which is what actually gets filed.
 *
 * Both `sales` and `purchases` already carry `vat_amount` and `vat_percent`, recorded
 * at the time of the transaction. This reads those columns rather than recomputing
 * anything, so the report always agrees with the documents that were issued, even if
 * a product's tax rate is changed later.
 *
 * Taxable value is derived as `totalAmount - vat_amount`, which holds whether the tax
 * was charged inclusively or exclusively: an inclusive total already contains the tax,
 * an exclusive one had it added.
 *
 * `vat_type` (inclusive/exclusive) lives on the product, not the transaction, so the
 * type filter selects transactions containing at least one line of that type.
 */
class GstReportController extends Controller
{
    public function __construct()
    {
        // `vat-reports.read` was already seeded and already known to the mobile client;
        // it simply had no endpoint behind it until now.
        $this->middleware('check.permission:vat-reports.read');
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date'   => ['nullable', 'date', 'after_or_equal:from_date'],
            'rate'      => ['nullable', 'numeric', 'min:0'],
            'gst_type'  => ['nullable', 'in:inclusive,exclusive'],
            // §21 lists branch among the report's search filters.
            'branch_id' => ['nullable', 'integer'],
            'page'      => ['nullable', 'integer', 'min:1'],
            // The mobile client raises this to 500 while walking the whole
            // period to build a PDF or spreadsheet export (see
            // GstReportFilters.perPage). Capping at 100 rejected every export
            // with a 422 — "something went wrong" on the report screen. Only
            // the paged rows are hydrated per request, so 500 is safe.
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $user       = auth()->user();
        $businessId = $user->business_id;

        $fromDate = $validated['from_date'] ?? now()->startOfMonth()->toDateString();
        $toDate   = $validated['to_date'] ?? now()->toDateString();
        $rate     = $validated['rate'] ?? null;
        $gstType  = $validated['gst_type'] ?? null;
        $branchId = $validated['branch_id'] ?? null;

        $perPage = (int) ($validated['per_page'] ?? 20);

        try {
            // Totals come from the database. A month of trading at a busy medical
            // store is tens of thousands of invoices, and building them all in
            // memory to add up eight columns is what makes this report fail at
            // exactly the size where a shop most needs to file one.
            $salesTotals     = $this->totalsFor(
                $this->salesQuery($businessId, $fromDate, $toDate, $rate, $gstType, $branchId),
                'totalAmount',
            );
            $purchaseTotals  = $this->totalsFor(
                $this->purchasesQuery($businessId, $fromDate, $toDate, $rate, $gstType, $branchId),
                'totalAmount',
            );

            $sales = $this->salesFor($businessId, $fromDate, $toDate, $rate, $gstType, $perPage, $branchId);
            $purchases = $this->purchasesFor($businessId, $fromDate, $toDate, $rate, $gstType, $perPage, $branchId);

            $outputTax = $salesTotals['tax'];
            $inputTax  = $purchaseTotals['tax'];

            return response()->json([
                'message'   => __('Data fetched successfully.'),
                'from_date' => $fromDate,
                'to_date'   => $toDate,
                // Echoed so an exported or shared report says what it covers,
                // rather than looking like the whole period.
                'filters'   => [
                    'rate'      => $rate,
                    'gst_type'  => $gstType,
                    'branch_id' => $branchId,
                ],

                // Tax collected on sales, owed to the tax authority.
                'output_tax' => $outputTax,
                // Tax paid on purchases, reclaimable against the above.
                'input_tax'  => $inputTax,
                // Positive means payable; negative means credit carried forward.
                'net_tax_payable' => round($outputTax - $inputTax, 2),

                'taxable_sales'    => $salesTotals['taxable'],
                'taxable_purchases' => $purchaseTotals['taxable'],
                'total_sales'      => $salesTotals['total'],
                'total_purchases'  => $purchaseTotals['total'],

                'sales_count'    => $salesTotals['count'],
                'purchase_count' => $purchaseTotals['count'],

                // Filed separately, so reported separately. These sum to output_tax
                // and input_tax respectively for any period billed after the
                // breakdown was introduced.
                'output_components' => $salesTotals['components'],
                'input_components'  => $purchaseTotals['components'],

                // Filing is done rate by rate, so this is the part most reports need.
                'rate_summary' => $this->rateSummary(
                    $businessId, $fromDate, $toDate, $rate, $gstType, $branchId,
                ),

                // The invoice lines are paged; the figures above are not, because a
                // total that changed as you paged would be worse than no total.
                'meta' => [
                    'per_page'        => $perPage,
                    'sales_page'      => $sales->currentPage(),
                    'sales_last_page' => $sales->lastPage(),
                    'purchases_page'      => $purchases->currentPage(),
                    'purchases_last_page' => $purchases->lastPage(),
                ],

                'data' => [
                    'sales'     => $sales->getCollection()->values(),
                    'purchases' => $purchases->getCollection()->values(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('GST report failed', [
                'business_id' => $businessId,
                'user_id'     => $user->id,
                'error'       => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('Failed to generate GST report.'),
            ], 500);
        }
    }

    /** The sales this report covers, unfetched, so totals and rows share one filter. */
    private function salesQuery(int $businessId, string $fromDate, string $toDate, $rate, $gstType, ?int $branchId = null)
    {
        return Sale::query()
            ->where('business_id', $businessId)
            ->whereDate('saleDate', '>=', $fromDate)
            ->whereDate('saleDate', '<=', $toDate)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($rate !== null, fn ($q) => $q->where('vat_percent', $rate))
            ->when($gstType !== null, fn ($q) => $q->whereHas(
                'details.product',
                fn ($p) => $p->where('vat_type', $gstType)
            ));
    }

    private function purchasesQuery(int $businessId, string $fromDate, string $toDate, $rate, $gstType, ?int $branchId = null)
    {
        return Purchase::query()
            ->where('business_id', $businessId)
            ->whereDate('purchaseDate', '>=', $fromDate)
            ->whereDate('purchaseDate', '<=', $toDate)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($rate !== null, fn ($q) => $q->where('vat_percent', $rate))
            ->when($gstType !== null, fn ($q) => $q->whereHas(
                'details.product',
                fn ($p) => $p->where('vat_type', $gstType)
            ));
    }

    /**
     * The period's figures, added up by the database.
     *
     * `taxable` is the total less the tax on it, which is why this is a
     * `SUM(a - b)` rather than two sums subtracted: a null tax on a legacy invoice
     * must contribute the invoice's full value, not void the row.
     */
    private function totalsFor($query, string $totalColumn): array
    {
        $row = (clone $query)->selectRaw(
            "COUNT(*) as row_count,
             COALESCE(SUM(COALESCE(vat_amount, 0)), 0) as tax_total,
             COALESCE(SUM(COALESCE({$totalColumn}, 0)), 0) as gross_total,
             COALESCE(SUM(COALESCE({$totalColumn}, 0) - COALESCE(vat_amount, 0)), 0) as taxable_total,
             COALESCE(SUM(COALESCE(cgst_amount, 0)), 0) as cgst_total,
             COALESCE(SUM(COALESCE(sgst_amount, 0)), 0) as sgst_total,
             COALESCE(SUM(COALESCE(igst_amount, 0)), 0) as igst_total,
             COALESCE(SUM(COALESCE(cess_amount, 0)), 0) as cess_total"
        )->first();

        return [
            'count'   => (int) ($row->row_count ?? 0),
            'tax'     => round((float) ($row->tax_total ?? 0), 2),
            'total'   => round((float) ($row->gross_total ?? 0), 2),
            'taxable' => round((float) ($row->taxable_total ?? 0), 2),
            'components' => [
                'cgst' => round((float) ($row->cgst_total ?? 0), 2),
                'sgst' => round((float) ($row->sgst_total ?? 0), 2),
                'igst' => round((float) ($row->igst_total ?? 0), 2),
                'cess' => round((float) ($row->cess_total ?? 0), 2),
            ],
        ];
    }

    private function salesFor(int $businessId, string $fromDate, string $toDate, $rate, $gstType, int $perPage, ?int $branchId = null)
    {
        $page = $this->salesQuery($businessId, $fromDate, $toDate, $rate, $gstType, $branchId)
            ->with(['customer:id,name,phone', 'vat:id,name,rate', 'branch:id,name'])
            ->orderBy('saleDate')
            ->paginate($perPage);

        $page->setCollection($page->getCollection()->map(fn (Sale $sale) => [
                'id'             => $sale->id,
                'invoice_number' => $sale->invoiceNumber,
                'date'           => $sale->saleDate,
                'party_name'     => $sale->customer?->name,
                'party_phone'    => $sale->customer?->phone,
                // §21 lists the branch, and it is also one of the report's search
                // filters. Without it a multi-branch business cannot tell which
                // branch an invoice was billed from - which is the whole point of
                // configuring GST per branch.
                'branch'         => $sale->branch?->name,
                'rate'           => (float) ($sale->vat_percent ?? 0),
                'tax_name'       => $sale->vat?->name,
                'tax_amount'     => round((float) ($sale->vat_amount ?? 0), 2),
                // Null on rows billed before the breakdown was recorded, which is a
                // legacy invoice rather than one with no tax.
                'cgst'           => $sale->cgst_amount,
                'sgst'           => $sale->sgst_amount,
                'igst'           => $sale->igst_amount,
                'cess'           => $sale->cess_amount,
                'is_inter_state' => $sale->is_inter_state,
                'gst_method'     => $sale->gst_method,
                'total_amount'   => round((float) ($sale->totalAmount ?? 0), 2),
                // §21 lists the discount alongside the tax: a taxable value that
                // looks wrong is usually a discount someone forgot was applied.
                'discount'       => round(
                    (float) ($sale->discountAmount ?? 0) + (float) ($sale->rowDiscount ?? 0),
                    2,
                ),
                'taxable_value'  => round(
                    (float) ($sale->totalAmount ?? 0) - (float) ($sale->vat_amount ?? 0),
                    2
                ),
            ]));

        return $page;
    }

    private function purchasesFor(int $businessId, string $fromDate, string $toDate, $rate, $gstType, int $perPage, ?int $branchId = null)
    {
        $page = $this->purchasesQuery($businessId, $fromDate, $toDate, $rate, $gstType, $branchId)
            ->with(['party:id,name,phone', 'vat:id,name,rate', 'branch:id,name'])
            ->orderBy('purchaseDate')
            ->paginate($perPage);

        $page->setCollection($page->getCollection()->map(fn (Purchase $purchase) => [
                'id'             => $purchase->id,
                'invoice_number' => $purchase->invoiceNumber,
                'date'           => $purchase->purchaseDate,
                'party_name'     => $purchase->party?->name,
                'party_phone'    => $purchase->party?->phone,
                'branch'         => $purchase->branch?->name,
                'rate'           => (float) ($purchase->vat_percent ?? 0),
                'tax_name'       => $purchase->vat?->name,
                'tax_amount'     => round((float) ($purchase->vat_amount ?? 0), 2),
                // Null on rows billed before the breakdown was recorded, which is a
                // legacy invoice rather than one with no tax.
                'cgst'           => $purchase->cgst_amount,
                'sgst'           => $purchase->sgst_amount,
                'igst'           => $purchase->igst_amount,
                'cess'           => $purchase->cess_amount,
                'is_inter_state' => $purchase->is_inter_state,
                'gst_method'     => $purchase->gst_method,
                'total_amount'   => round((float) ($purchase->totalAmount ?? 0), 2),
                'taxable_value'  => round(
                    (float) ($purchase->totalAmount ?? 0) - (float) ($purchase->vat_amount ?? 0),
                    2
                ),
            ]));

        return $page;
    }

    /**
     * Rate-wise totals — the shape a GST return is filed in.
     *
     * Grouped by the database over the whole period, not over the page of invoices
     * being displayed: a return filed from a single page of a report would understate
     * the liability.
     */
    private function rateSummary(
        int $businessId,
        string $fromDate,
        string $toDate,
        $rate,
        $gstType,
        ?int $branchId = null,
    ): array {
        $group = fn ($query, string $totalColumn) => $query
            ->selectRaw(
                "COALESCE(vat_percent, 0) as gst_rate,
                 COALESCE(SUM(COALESCE(vat_amount, 0)), 0) as tax_total,
                 COALESCE(SUM(COALESCE({$totalColumn}, 0) - COALESCE(vat_amount, 0)), 0) as taxable_total"
            )
            ->groupBy('gst_rate')
            ->get()
            ->keyBy(fn ($row) => (string) (float) $row->gst_rate);

        $sales = $group(
            $this->salesQuery($businessId, $fromDate, $toDate, $rate, $gstType, $branchId),
            'totalAmount',
        );
        $purchases = $group(
            $this->purchasesQuery($businessId, $fromDate, $toDate, $rate, $gstType, $branchId),
            'totalAmount',
        );

        $rates = $sales->keys()
            ->merge($purchases->keys())
            ->unique()
            ->map(fn ($value) => (float) $value)
            ->sort()
            ->values();

        return $rates->map(function (float $gstRate) use ($sales, $purchases) {
            $key = (string) $gstRate;

            $output = round((float) ($sales[$key]->tax_total ?? 0), 2);
            $input  = round((float) ($purchases[$key]->tax_total ?? 0), 2);

            return [
                'rate'               => $gstRate,
                'taxable_sales'      => round((float) ($sales[$key]->taxable_total ?? 0), 2),
                'output_tax'         => $output,
                'taxable_purchases'  => round((float) ($purchases[$key]->taxable_total ?? 0), 2),
                'input_tax'          => $input,
                'net_tax_payable'    => round($output - $input, 2),
            ];
        })->all();
    }
}
