<?php

namespace App\Http\Controllers\Api;

use App\Models\Party;
use App\Models\Business;
use App\Helpers\HasUploader;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Http\Requests\Api\StorePartyRequest;
use App\Http\Requests\Api\UpdatePartyRequest;
use App\Support\Api\ApiRequest;
use App\Support\Api\ContactBalance;
use App\Support\LookupDeletionGuard;

class PartyController extends Controller
{
    use HasUploader;

    public function index(Request $request)
    {
        $user = auth()->user();
        $activeBranch = $user->active_branch;
        $search = ApiRequest::search($request);
        $perPage = ApiRequest::perPage($request, 50, 100);

        $query = Party::query()
            ->select([
                'id',
                'type',
                'name',
                'email',
                'phone',
                'relationship',
                'due',
                'wallet',
                'image',
                'status',
                'address',
                'business_id',
                'credit_limit',
                'opening_balance',
                'opening_balance_type',
                'created_at',
                'updated_at',
            ])
            ->forBusiness((int) $user->business_id)
            ->latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%');
            });
        }

        // Interactive pickers should not have to download every party and
        // discard customers on the device. Party types can be combined (for
        // example "Customer, Supplier"), so retain the existing contains
        // semantics while accepting only the known values.
        $type = trim((string) $request->input('type', ''));
        if (in_array(strtolower($type), ['supplier', 'customer'], true)) {
            $query->where('type', 'like', '%' . ucfirst(strtolower($type)) . '%');
        }

        // Same pattern the due-collection reports already filter on
        // (`AcnooDueController`/`CustomerDueController`), applied here to the
        // party's own creation date. The Due List screens have carried this
        // date-range picker in their UI with nowhere to send it — the app
        // fetched one unfiltered page and searched only within it — so the
        // filter looked broken no matter what range was picked.
        $query->when($request->filled('from_date'), fn ($q) =>
            $q->whereDate('created_at', '>=', $request->input('from_date')))
            ->when($request->filled('to_date'), fn ($q) =>
                $q->whereDate('created_at', '<=', $request->input('to_date')));

        $query->withSum('purchases_dues as calculated_due', 'dueAmount');

        $parties = $query->paginate($perPage);

        $parties->getCollection()->transform(function ($party) {
            $party->due = (float) ($party->calculated_due ?? 0);
            unset($party->calculated_due);

            return $party;
        });

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $parties,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePartyRequest $request)
    {
        $business_id = auth()->user()->business_id;

        $data = Party::create($request->except('image', 'due', 'wallet', 'opening_balance', 'credit_limit') + [
                'relationship' => $request->relationship,
                'image' => $request->image ? $this->upload($request, 'image') : NULL,
                'business_id' => $business_id,
            ] + ContactBalance::openingPayload(
                $request->opening_balance,
                $request->opening_balance_type,
                $request->credit_limit
            ));

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data' => $data,
        ]);
    }

    public function show(Party $party)
    {
        if ($party->business_id !== auth()->user()->business_id) {
            return response()->json([
                'message' => __('Party not found.')
            ], 404);
        }

        if (env('MESSAGE_ENABLED')) {
            if ($party->due) {
                $business = Business::findOrFail($party->business_id);
                $response = sendMessage($party->phone, dueMessage($party, $business->companyName));

                if ($response->successful()) {
                    return response()->json([
                        'message' => __('Message has been send successfully.'),
                    ]);
                }

                return response()->json([
                    'message' => __('Something was wrong, Please contact with admin.'),
                ], 406);
            } else {
                return response()->json([
                    'message' => __('This party has no due balance.'),
                ], 406);
            }
        } else {
            return response()->json([
                'message' => __('Message has been disabled by admin.'),
            ], 406);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePartyRequest $request, Party $party)
    {
        $businessId = auth()->user()->business_id;

        if ($party->business_id !== $businessId) {
            return response()->json([
                'message' => __('Party not found.')
            ], 404);
        }

        $party->update(
            $request->except('image', 'due', 'wallet', 'opening_balance', 'opening_balance_type', 'credit_limit', 'business_id') + [
                'relationship' => $request->relationship,
                'image' => $request->image ? $this->upload($request, 'image', $party->image) : $party->image,
            ] + ContactBalance::adjustedPayload(
                $party,
                // Absent means "unchanged", not "zero". The edit screen does not
                // render either field, and `adjustedPayload` treats a null opening
                // balance as 0 - which silently wiped the stored balance and moved
                // the contact's due by the old amount on any ordinary edit, such as
                // correcting a phone number.
                $request->filled('opening_balance')
                    ? (float) $request->opening_balance
                    : (float) ($party->opening_balance ?? 0),
                $request->filled('opening_balance_type')
                    ? $request->opening_balance_type
                    : (string) ($party->opening_balance_type ?? 'cash'),
                $request->filled('credit_limit')
                    ? (float) $request->credit_limit
                    : null,
            )
        );

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data'    => $party,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $businessId = auth()->user()->business_id;

        $party = Party::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (!$party) {
            return response()->json([
                'message' => __('Party not found.')
            ], 404);
        }

        // Balance validation
        if ($party->due > 0) {
            return response()->json([
                'message' => __('This party cannot be deleted due to pending balances.')
            ], 400);
        }

        // The balance check alone is not enough. A long-standing customer who
        // always paid on time has a zero balance and years of sales behind them,
        // and sales.party_id cascaded — so the best customers were the easiest
        // to erase, along with the revenue they represent.
        // No sales check: sales.party_id was renamed to customer_id and points
        // at `customers` now. Parties are the supplier side, so purchases and
        // due collections are what a party's history actually is.
        $blocked = LookupDeletionGuard::blockedByHistory([
            'purchase(s)' => ['purchases', 'party_id'],
            'due collection(s)' => ['due_collects', 'party_id'],
        ], $party->id, 'party');

        if ($blocked !== null) {
            return response()->json(['message' => $blocked], 409);
        }

        if ($party->image && file_exists($party->image)) {
            Storage::delete($party->image);
        }

        $party->delete();

        return response()->json([
            'message' => __('Data deleted successfully.')
        ]);
    }
}
