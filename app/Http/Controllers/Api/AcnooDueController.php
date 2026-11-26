<?php

namespace App\Http\Controllers\Api;

use App\Models\Sale;
use App\Models\Party;
use App\Models\Business;
use App\Models\Purchase;
use App\Models\DueCollect;
use App\Support\Api\ApiRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Jobs\SendSmsMessageJob;

class AcnooDueController extends Controller
{
    public function index(Request $request)
    {
        $data = DueCollect::with('user:id,name,role', 'party:id,name,email,phone,type', 'payment_type:id,name', 'branch:id,name,phone,address','purchase:id,purchaseDate,invoiceNumber')
                ->where('business_id', auth()->user()->business_id)
                // Same filters as the customer side: one search box covering name
                // and phone, and a date range over the collection date.
                ->when($request->filled('search'), function ($query) use ($request) {
                    $term = $request->input('search');

                    $query->whereHas('party', function ($q) use ($term) {
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

        // Bounded, for the same reason as the customer side: this returned every
        // supplier payment ever made in a single response. `data` stays a flat
        // list so the app's parse is unchanged.
        $page = $data->paginate(ApiRequest::perPage($request, 100, 500));

        return response()->json([
            'message' => __('Data fetched successfully.'),
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
        $this->preparePaymentDate($request);

        $party = $this->validateStoreRequest($request);

        $branchId = $this->determineBranchId($request, $party);

        $invoice = $this->validateAndLoadInvoice($request, $party);

        $this->validateDueAmount($request, $party, $invoice);

        try {
            $data = DB::transaction(function () use ($request, $party, $invoice, $branchId) {
                $party = Party::whereKey($party->id)->lockForUpdate()->firstOrFail();
                $invoice = $invoice
                    ? $invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail()
                    : null;

                $this->validateDueAmount($request, $party, $invoice);

                $dueCollect = $this->createDueCollect($request, $party, $invoice, $branchId);

                $this->updateInvoiceDue($invoice, $request->payDueAmount);

                $this->updatePartyDue($party, $request->payDueAmount);

                $this->updateBusinessBalance($party->type, $request->payDueAmount, $branchId);

                return $dueCollect;
            });

            $business = Business::findOrFail(auth()->user()->business_id);
            $businessName = $business->companyName;

            $this->sendDueCollectionNotification($data, $party, $businessName, $request->invoiceNumber);

            $this->logDueCollection($data, $party, $request->payDueAmount);

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $data->load('user:id,name,role', 'party:id,name,email,phone,type','payment_type:id,name', 'branch:id,name,phone,address'),
            ]);

        } catch (\Exception $e) {
            Log::error('Due collection failed', [
                'business_id' => auth()->user()->business_id,
                'party_id' => $request->party_id,
                'amount' => $request->payDueAmount,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('Failed to collect due payment.')
            ], 500);
        }
    }

    private function preparePaymentDate(Request $request): void
    {
        // The app posts d/m/Y. This ran before validation and called
        // `createFromFormat` unguarded, so anything else — an ISO date, a typo,
        // an empty-ish value that still passed the truthiness check — threw
        // inside the parser and surfaced as a 500 rather than the 422 the
        // `date` rule below exists to produce.
        //
        // An unparseable value is now left alone for that rule to reject, and an
        // ISO date is accepted as it stands. Same treatment as
        // `CustomerDueController`, which had this corrected already; the two
        // collect the same kind of payment and disagreeing on the date contract
        // is how one of them ends up wrong again.
        if ($request->paymentDate) {
            $parsed = \Carbon\Carbon::canBeCreatedFromFormat($request->paymentDate, 'd/m/Y')
                ? \Carbon\Carbon::createFromFormat('d/m/Y', $request->paymentDate)
                : null;

            if ($parsed) {
                $request->merge(['paymentDate' => $parsed->format('Y-m-d')]);
            }
        }
    }

    private function validateStoreRequest(Request $request): Party
    {
        $businessId = auth()->user()->business_id;

        $request->validate([
            // Scoped to the signed-in business.
            //
            // This was a bare `exists:payment_types,id`, which is a global
            // check: another shop's payment type satisfied it, was stored on
            // this shop's collection, and would then have been printed on the
            // receipt. `CustomerDueController` already scoped the same rule —
            // the two controllers had diverged, as they had over the date
            // format.
            'payment_type_id' => [
                'required',
                Rule::exists('payment_types', 'id')
                    ->where(fn ($query) => $query->where('business_id', $businessId)),
            ],
            'paymentDate' => 'required|date',
            'payDueAmount' => 'required|numeric',
            'cash_amount' => 'nullable|numeric',
            'upi_amount' => 'nullable|numeric',
            // Third tender alongside cash and UPI — see CustomerDueController.
            'card_amount' => 'nullable|numeric|min:0',
            // Same reasoning: a party from another business must not be
            // reachable by id.
            'party_id' => [
                'required',
                Rule::exists('parties', 'id')
                    ->where(fn ($query) => $query->where('business_id', $businessId)),
            ],
        ]);

        $party = Party::findOrFail($request->party_id);

        $request->validate([
            'invoiceNumber' => 'nullable|exists:' . ($party->type == 'Supplier' ? 'purchases' : 'sales') . ',invoiceNumber',
        ]);

        if (auth()->user()->active_branch && !$request->invoiceNumber) {
            response()->json([
                'message' => __('You must select an invoice when login any branch.')
            ], 400)->throwResponse();
        }

        return $party;
    }

    private function determineBranchId(Request $request, Party $party): ?int
    {
        $branchId = null;

        if ($request->invoiceNumber) {
            $invoice = $this->getInvoiceByPartyAndNumber($party, $request->invoiceNumber);

            if (!auth()->user()->active_branch && isset($invoice) && isset($invoice->branch_id)) {
                $branchId = $invoice->branch_id;
            }
        }

        return $branchId;
    }

    private function getInvoiceByPartyAndNumber(Party $party, string $invoiceNumber)
    {
        if ($party->type == 'Supplier') {
            return Purchase::where('invoiceNumber', $invoiceNumber)->where('party_id', $party->id)->first();
        }

        // Sales key on customer_id; ids were preserved when non-supplier
        // parties were copied into `customers`.
        return Sale::where('invoiceNumber', $invoiceNumber)->where('customer_id', $party->id)->first();
    }

    private function validateAndLoadInvoice(Request $request, Party $party)
    {
        if (!$request->invoiceNumber) {
            return null;
        }

        $invoice = $this->getInvoiceByPartyAndNumber($party, $request->invoiceNumber);

        if (!$invoice) {
            response()->json([
                'message' => 'Invoice Not Found.'
            ], 404)->throwResponse();
        }

        return $invoice;
    }

    private function validateDueAmount(Request $request, Party $party, $invoice): void
    {
        if ($invoice) {
            $this->validateInvoiceDueAmount($invoice, $request->payDueAmount);
        } else {
            $this->validatePartyDueLimit($party, $request->payDueAmount);
        }
    }

    private function validateInvoiceDueAmount($invoice, float $payAmount): void
    {
        if ($invoice->dueAmount < $payAmount) {
            response()->json([
                'message' => 'Invoice due is ' . $invoice->dueAmount . '. You can not pay more then the invoice due amount.'
            ], 400)->throwResponse();
        }
    }

    private function validatePartyDueLimit(Party $party, float $payAmount): void
    {
        $allInvoiceDue = $this->getAllInvoiceDue($party);

        if (($allInvoiceDue + $payAmount) > $party->due) {
            response()->json([
                'message' => __('You can pay only '. ($party->due - $allInvoiceDue) .', without selecting an invoice.')
            ], 400)->throwResponse();
        }
    }

    /**
     * What this party already owes across their open invoices.
     *
     * Purchases still key on `party_id` (suppliers), but `sales.party_id` was
     * renamed to `customer_id`, so this threw "Unknown column 'party_id'" on every
     * customer collection made without selecting an invoice - taking the
     * overpayment guard below down with it, since it depends on this figure.
     *
     * Customer ids were preserved when the non-supplier parties were copied into
     * `customers`, so the party's own id is the customer id.
     */
    private function getAllInvoiceDue(Party $party): float
    {
        if ($party->type == 'Supplier') {
            return (float) Purchase::where('party_id', $party->id)->sum('dueAmount');
        }

        return (float) Sale::where('customer_id', $party->id)->sum('dueAmount');
    }

    private function createDueCollect(Request $request, Party $party, $invoice, ?int $branchId)
    {
        $saleId = $party->type != 'Supplier' && $invoice ? $invoice->id : null;
        $purchaseId = $party->type == 'Supplier' && $invoice ? $invoice->id : null;
        $totalDue = $invoice ? $invoice->dueAmount : $party->due;
        $dueAmountAfterPay = $invoice ? ($invoice->dueAmount - $request->payDueAmount) : ($party->due - $request->payDueAmount);

        return DueCollect::create([
            'payment_type_id' => $request->payment_type_id,
            'paymentDate' => $request->paymentDate,
            'payDueAmount' => $request->payDueAmount,
            'cash_amount' => $request->cash_amount ?? 0,
            'upi_amount' => $request->upi_amount ?? 0,
            'card_amount' => $request->card_amount ?? 0,
            'party_id' => $request->party_id,
            'invoiceNumber' => $request->invoiceNumber,
            'user_id' => auth()->id(),
            'business_id' => auth()->user()->business_id,
            'sale_id' => $saleId,
            'purchase_id' => $purchaseId,
            'totalDue' => $totalDue,
            'dueAmountAfterPay' => $dueAmountAfterPay,
            'branch_id' => $branchId,
        ]);
    }

    private function updateInvoiceDue($invoice, float $payAmount): void
    {
        if ($invoice) {
            $newDue = max(0, (float) $invoice->dueAmount - $payAmount);
            $newPaid = min(
                (float) $invoice->totalAmount,
                (float) $invoice->paidAmount + $payAmount,
            );

            $invoice->update([
                // Keep the invoice accounting invariant true after a later due
                // collection. Previously only `dueAmount` moved, leaving
                // `paidAmount` frozen at the checkout value and causing PDF,
                // Excel and the supplier ledger to disagree.
                'dueAmount' => $newDue,
                'paidAmount' => $newPaid,
                'isPaid' => $newDue <= 0.001,
            ]);
        }
    }

    private function updatePartyDue(Party $party, float $payAmount): void
    {
        $party->update([
            'due' => $party->due - $payAmount
        ]);
    }

    private function updateBusinessBalance(string $partyType, float $payAmount, ?int $branchId): void
    {
        if ($partyType == 'Supplier') {
            updateBalance($payAmount, 'decrement', $branchId);
        } else {
            updateBalance($payAmount, 'increment', $branchId);
        }
    }

    private function sendDueCollectionNotification($dueCollect, Party $party, string $businessName, ?string $invoiceNumber): void
    {
        if (env('MESSAGE_ENABLED')) {
            SendSmsMessageJob::dispatch(
                $party->phone,
                dueCollectMessage($dueCollect, $party, $businessName, $invoiceNumber)
            )->afterCommit();
        }
    }

    private function logDueCollection($dueCollect, Party $party, float $amount): void
    {
        Log::info('Due collection successful', [
            'business_id' => auth()->user()->business_id,
            'branch_id' => $dueCollect->branch_id,
            'user_id' => auth()->id(),
            'party_id' => $party->id,
            'amount' => $amount,
            'due_collect_id' => $dueCollect->id,
        ]);
    }
}
