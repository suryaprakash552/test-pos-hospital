<?php

namespace App\Http\Controllers\Api;

use App\Models\Expense;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreExpenseRequest;
use App\Http\Requests\Api\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Helpers\DataSanitizer;

class AcnooExpenseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();

        $data = Expense::with(
                'category:id,categoryName',
                'payment_type:id,name'
            )
            ->when($user->role == 'staff', function ($query) use ($user) {
                // staff -> only their expenses
                $query->where('user_id', $user->id);
            }, function ($query) use ($user) {
                // shop-owner -> all business expenses
                $query->where('business_id', $user->business_id);
            })
            ->latest()
            ->get();

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => ExpenseResource::collection($data),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreExpenseRequest $request)
    {

        $user = auth()->user();
        $business_id = $user->business_id;
        
        $cashAmount = $request->amount ?? 0;
        $upiAmount  = $request->upi_amount ?? 0;
        $cardAmount = $request->card_amount ?? 0;

        $totalExpense = $cashAmount + $upiAmount + $cardAmount;

        try {
            DB::beginTransaction();
            
            updateBalance($totalExpense, 'decrement');

            $data = Expense::create([
                'user_id' => $user->id,
                'business_id' => $business_id,
                'expense_category_id' => $request->expense_category_id,
                'amount' => $cashAmount,
                'upi_amount' => $upiAmount,
                'card_amount' => $cardAmount,
                'expanseFor' => DataSanitizer::sanitizeString($request->expanseFor),
                'paymentType' => DataSanitizer::sanitizeString($request->paymentType),
                'payment_type_id' => $request->payment_type_id,
                'referenceNo' => DataSanitizer::sanitizeString($request->referenceNo),
                'note' => DataSanitizer::sanitizeString($request->note),
                'expenseDate' => $request->expenseDate,
            ]);
            
            Log::info('Expense created', [
                'business_id' => $business_id,
                'user_id' => $user->id,
                'expense_id' => $data->id,
                'amount' => $totalExpense,
            ]);
            
            DB::commit();

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => new ExpenseResource($data->load('category:id,categoryName', 'payment_type:id,name', 'user:id,name')),
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Expense creation failed', [
                'business_id' => $business_id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => __('Failed to create expense.')
            ], 500);
        }
    }

    /**
     * Display an expense that belongs to the authenticated business.
     *
     * The resource route exposes GET /expenses/{expense}; without this action
     * Laravel raised a BadMethodCallException and the mobile detail/edit flow
     * surfaced a generic server error.
     */
    public function show(Expense $expense)
    {
        $user = auth()->user();

        if ($expense->business_id !== $user->business_id
            || ($user->role === 'staff' && $expense->user_id !== $user->id)) {
            abort(404);
        }

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => new ExpenseResource(
                $expense->load(
                    'category:id,categoryName',
                    'payment_type:id,name',
                    'user:id,name',
                ),
            ),
        ]);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense)
    {
        $user = auth()->user();
        
        if ($expense->business_id != $user->business_id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }
        
        if ($user->role == 'staff' && $expense->user_id != $user->id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }

        $oldTotal = ($expense->amount ?? 0) + ($expense->upi_amount ?? 0) + ($expense->card_amount ?? 0);

        try {
            DB::beginTransaction();
            
            updateBalance($oldTotal, 'increment');

            $cashAmount = $request->amount ?? 0;
            $upiAmount = $request->upi_amount ?? 0;
            $cardAmount = $request->card_amount ?? 0;

            $newTotal = $cashAmount + $upiAmount + $cardAmount;

            updateBalance($newTotal, 'decrement');

            $expense->update([
                'expense_category_id' => $request->expense_category_id,
                'amount' => $cashAmount,
                'upi_amount' => $upiAmount,
                'card_amount' => $cardAmount,
                'expanseFor' => $request->expanseFor,
                'paymentType' => $request->paymentType,
                'payment_type_id' => $request->payment_type_id,
                'referenceNo' => $request->referenceNo,
                'note' => $request->note,
                'expenseDate' => $request->expenseDate,
            ]);
            
            Log::info('Expense updated', [
                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'expense_id' => $expense->id,
                'old_amount' => $oldTotal,
                'new_amount' => $newTotal,
            ]);
            
            DB::commit();

            return response()->json([
                'message' => __('Expense updated successfully.'),
                'data' => $expense
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Expense update failed', [
                'business_id' => $user->business_id,
                'expense_id' => $expense->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => __('Failed to update expense.')
            ], 500);
        }
    }

    public function destroy(Expense $expense)
    {
        $user = auth()->user();
        
        if ($expense->business_id != $user->business_id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }
        
        if ($user->role == 'staff' && $expense->user_id != $user->id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }
        
        // Every tender. `store()` decremented the balance by cash + upi + card,
        // so the delete must return all three — a card-paid expense that gave
        // back only its (zero) cash share left the shop balance overstated.
        $totalAmount = ($expense->amount ?? 0) + ($expense->upi_amount ?? 0) + ($expense->card_amount ?? 0);

        try {
            DB::beginTransaction();

            updateBalance($totalAmount, 'increment');

            $expense->delete();
            
            Log::info('Expense deleted', [
                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'expense_id' => $expense->id,
                'amount' => $totalAmount,
            ]);
            
            DB::commit();

            return response()->json([
                'message' => __('Expense deleted successfully.')
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Expense deletion failed', [
                'business_id' => $user->business_id,
                'expense_id' => $expense->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => __('Failed to delete expense.')
            ], 500);
        }
    }
}
