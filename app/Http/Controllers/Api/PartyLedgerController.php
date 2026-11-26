<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DueCollect;
use App\Models\Party;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * One supplier's account, as a statement.
 *
 * The app could already list a supplier's purchases, and separately their
 * returns, and separately the payments made to them. What it could not do is
 * answer the question a shop actually asks — *what do we owe them, and how did
 * we get there* — because that needs the three interleaved in date order with a
 * balance carried down the page.
 *
 * Doing that in the app would mean paging three endpoints and merging them, and
 * a running balance computed over three partially-loaded lists is wrong in a way
 * nobody notices: it looks plausible on the first page. So the statement is
 * built here, in one pass, over the whole account.
 */
class PartyLedgerController extends Controller
{
    /**
     * The account for one party.
     *
     * Scoped by business on the lookup itself: a supplier ledger exposes what a
     * shop buys, at what price and what it still owes, and a bare `findOrFail`
     * would hand that to anyone who guessed an id.
     */
    public function show(Request $request, int $id)
    {
        $user = auth()->user();

        $party = Party::query()
            ->where('business_id', $user->business_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
        ]);

        $entries = $this->entries($user->business_id, $party->id);

        // Calculate balance from sum of purchases.dueAmount for consistency
        $closingBalance = (float) Purchase::query()
            ->where('business_id', $user->business_id)
            ->where('party_id', $party->id)
            ->sum('dueAmount');

        // Oldest first to carry the balance forward, then reversed for display:
        // a statement is read newest-first but only adds up oldest-first.
        usort($entries, function ($a, $b) {
            return [$a['date'], $a['id']] <=> [$b['date'], $b['id']];
        });

        $balance = 0.0;

        foreach ($entries as $index => $entry) {
            $balance += $entry['amount'];
            $entries[$index]['balance'] = round($balance, 2);
        }

        // A window, with everything before it folded into an opening balance.
        //
        // The statement has to be built over the whole account — a running
        // balance computed from a slice is wrong in a way nobody notices,
        // because it looks plausible on its own. So the balances are carried
        // across every movement first, and only then is the window cut. What
        // falls before it becomes the opening figure, exactly as a bank
        // statement does it, and the rows shown still add up to the closing
        // balance.
        //
        // Without this the endpoint returned every movement a supplier had ever
        // made, growing without limit for the shop's oldest accounts.
        $openingBalance = 0.0;

        if (! empty($validated['from_date']) || ! empty($validated['to_date'])) {
            $from = ! empty($validated['from_date'])
                ? Carbon::parse($validated['from_date'])->startOfDay()
                : null;
            $to = ! empty($validated['to_date'])
                ? Carbon::parse($validated['to_date'])->endOfDay()
                : null;

            $windowed = [];

            foreach ($entries as $entry) {
                $moment = Carbon::parse($entry['date']);

                if ($from !== null && $moment->lt($from)) {
                    // Before the window: it is part of the opening position.
                    $openingBalance = $entry['balance'];
                    continue;
                }

                if ($to !== null && $moment->gt($to)) {
                    continue;
                }

                $windowed[] = $entry;
            }

            $entries = $windowed;
            $closingBalance = $entries === []
                ? round($openingBalance, 2)
                : $entries[count($entries) - 1]['balance'];
        }

        $entries = array_reverse($entries);

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => [
                'party' => [
                    'id' => $party->id,
                    'name' => $party->name,
                    'phone' => $party->phone,
                    'gstin' => $party->gstin,
                    'type' => $party->type,
                ],
                // What the shop owes as of the last entry. Derived from the
                // statement rather than read off `parties.due`, so the figure
                // and the rows that explain it cannot disagree.
                'balance' => $closingBalance,
                // What was owed before the window opened. Zero on an unfiltered
                // statement, which starts from nothing by definition.
                'opening_balance' => round($openingBalance, 2),
                'counts' => [
                    'purchases' => count(array_filter(
                        $entries, fn ($e) => $e['type'] === 'purchase'
                    )),
                    'returns' => count(array_filter(
                        $entries, fn ($e) => $e['type'] === 'return'
                    )),
                    'payments' => count(array_filter(
                        $entries, fn ($e) => $e['type'] === 'payment'
                    )),
                ],
                'entries' => $entries,
            ],
        ]);
    }

    /**
     * Every movement on the account, in one shape.
     *
     * Sign convention is the shop's point of view: a purchase increases what is
     * owed, a return and a payment reduce it. That is what makes the running
     * balance mean something rather than being a sum of unrelated figures.
     *
     * @return array<int, array<string, mixed>>
     */
    private function entries(int $businessId, int $partyId): array
    {
        $entries = [];

        $purchases = Purchase::query()
            ->where('business_id', $businessId)
            ->where('party_id', $partyId)
            ->get([
                'id', 'invoiceNumber', 'reference_invoice_no', 'purchaseDate',
                'totalAmount', 'paidAmount', 'dueAmount', 'created_at',
            ]);

        foreach ($purchases as $purchase) {
            $entries[] = [
                'id' => (int) $purchase->id,
                'type' => 'purchase',
                'reference' => $purchase->invoiceNumber,
                // The supplier's own number, which is what their statement
                // will be written against.
                'supplier_reference' => $purchase->reference_invoice_no,
                'date' => optional($purchase->purchaseDate)
                    ? (string) $purchase->purchaseDate
                    : (string) $purchase->created_at,
                'amount' => (float) $purchase->totalAmount,
                'paid' => (float) $purchase->paidAmount,
                'due' => (float) $purchase->dueAmount,
            ];
        }

        $returns = PurchaseReturn::query()
            ->where('business_id', $businessId)
            ->whereHas('purchase', fn ($q) => $q->where('party_id', $partyId))
            ->with('details:id,purchase_return_id,return_amount')
            ->get(['id', 'purchase_id', 'invoice_no', 'return_date', 'created_at']);

        foreach ($returns as $return) {
            $amount = (float) $return->details->sum('return_amount');

            $entries[] = [
                'id' => (int) $return->id,
                'type' => 'return',
                'reference' => $return->invoice_no,
                'supplier_reference' => null,
                'date' => (string) ($return->return_date ?? $return->created_at),
                // Goods going back reduce what is owed.
                'amount' => -$amount,
                'paid' => 0.0,
                'due' => 0.0,
            ];
        }

        $payments = DueCollect::query()
            ->where('business_id', $businessId)
            ->where('party_id', $partyId)
            ->get([
                'id', 'invoiceNumber', 'payDueAmount', 'paymentType',
                'paymentDate', 'created_at',
            ]);

        foreach ($payments as $payment) {
            $entries[] = [
                'id' => (int) $payment->id,
                'type' => 'payment',
                'reference' => $payment->invoiceNumber,
                'supplier_reference' => null,
                'date' => (string) ($payment->paymentDate ?? $payment->created_at),
                'amount' => -(float) $payment->payDueAmount,
                'paid' => (float) $payment->payDueAmount,
                'due' => 0.0,
                'method' => $payment->paymentType,
            ];
        }

        return $entries;
    }
}
