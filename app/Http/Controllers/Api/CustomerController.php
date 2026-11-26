<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Helpers\HasUploader;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Http\Requests\Api\StoreCustomerRequest;
use App\Http\Requests\Api\UpdateCustomerRequest;
use App\Support\Api\ApiRequest;
use App\Support\Api\ContactBalance;

class CustomerController extends Controller
{
    use HasUploader;

    public function index(Request $request)
    {
        $user = auth()->user();
        $activeBranch = $user->active_branch;
        $search = ApiRequest::search($request);
        $perPage = ApiRequest::perPage($request, 50, 100);

        $query = Customer::query()
            ->select([
                'id',
                'type',
                'name',
                'email',
                'phone',
                'age',
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

        // Field-specific patient lookup used by counter and due filters.
        // Keep these grouped with the tenant query rather than OR-ing outside it.
        foreach (['name', 'phone'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, 'like', '%'.trim((string) $request->input($field)).'%');
            }
        }

        // Same reasoning as PartyController::index: the customer Due List
        // screen's date range had no server-side filter to land on, so it
        // searched only whatever the first unfiltered page happened to
        // contain.
        $query->when($request->filled('from_date'), fn ($q) =>
            $q->whereDate('created_at', '>=', $request->input('from_date')))
            ->when($request->filled('to_date'), fn ($q) =>
                $q->whereDate('created_at', '<=', $request->input('to_date')));

        if ($activeBranch) {
            $query->withSum([
                'sales_dues as branch_due' => fn ($q) => $q->where('branch_id', $activeBranch->id),
            ], 'dueAmount');
        }

        $customers = $query->paginate($perPage);

        if ($activeBranch) {
            $customers->getCollection()->transform(function ($customer) {
                $customer->due = (float) ($customer->branch_due ?? 0);
                unset($customer->branch_due);

                return $customer;
            });
        }

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $customers,
        ]);
    }

    public function store(StoreCustomerRequest $request)
    {
        $business_id = auth()->user()->business_id;

        $data = Customer::create($request->except('image', 'due', 'wallet', 'opening_balance', 'credit_limit') + [
                'relationship' => $request->relationship,
                'age' => $request->filled('age') ? (int) $request->age : 0,
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

    public function show(Customer $customer)
    {
        if ($customer->business_id !== auth()->user()->business_id) {
            return response()->json([
                'message' => __('Customer not found.')
            ], 404);
        }

        return response()->json([
            'data' => $customer
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $businessId = auth()->user()->business_id;

        if ($customer->business_id !== $businessId) {
            return response()->json([
                'message' => __('Customer not found.')
            ], 404);
        }

        $customer->update(
            $request->except('image', 'due', 'wallet', 'business_id', 'opening_balance', 'opening_balance_type', 'credit_limit') + [
                'image' => $request->image ? $this->upload($request, 'image', $customer->image) : $customer->image,
                'age' => $request->filled('age') ? (int) $request->age : $customer->age,
            ] + ContactBalance::adjustedPayload(
                $customer,
                // Absent means "unchanged", not "zero". The edit screen does not
                // render either field, and `adjustedPayload` treats a null opening
                // balance as 0 - which silently wiped the stored balance and moved
                // the contact's due by the old amount on any ordinary edit, such as
                // correcting a phone number.
                $request->filled('opening_balance')
                    ? (float) $request->opening_balance
                    : (float) ($customer->opening_balance ?? 0),
                $request->filled('opening_balance_type')
                    ? $request->opening_balance_type
                    : (string) ($customer->opening_balance_type ?? 'cash'),
                $request->filled('credit_limit')
                    ? (float) $request->credit_limit
                    : null,
            )
        );

        return response()->json([
            'message' => __('Customer updated successfully.'),
            'data' => $customer
        ]);
    }

    public function destroy($id)
    {
        $businessId = auth()->user()->business_id;

        $customer = Customer::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (!$customer) {
            return response()->json([
                'message' => 'Customer not found'
            ], 404);
        }


        if ($customer->dueCollects()->exists()) {
            return response()->json([
                'message' => 'Customer cannot be deleted because due exists.'
            ], 400);
        }

        if ($customer->image && file_exists($customer->image)) {
            Storage::delete($customer->image);
        }

        $customer->delete();

        return response()->json([
            'message' => 'Customer deleted successfully.'
        ]);
    }
}
