<?php

namespace App\Http\Controllers\Api;

use App\Models\Income;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreIncomeRequest;
use App\Http\Resources\IncomeResource;
use App\Helpers\DataSanitizer;

class AcnooIncomeController extends Controller
{
    public function index()
    {
        $data = Income::with('category:id,categoryName', 'payment_type:id,name')->where('business_id', auth()->user()->business_id)->latest()->get();

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => IncomeResource::collection($data),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreIncomeRequest $request)
    {
        $user = auth()->user();
        $business_id = $user->business_id;

        try {
            DB::beginTransaction();
            
            updateBalance($request->amount, 'decrement');

            $data = Income::create([
                'user_id' => $user->id,
                'business_id' => $business_id,
                'amount' => $request->amount,
                'income_category_id' => $request->income_category_id,
                'incomeFor' => DataSanitizer::sanitizeString($request->incomeFor),
                'paymentType' => DataSanitizer::sanitizeString($request->paymentType),
                'payment_type_id' => $request->payment_type_id,
                'referenceNo' => DataSanitizer::sanitizeString($request->referenceNo),
                'note' => DataSanitizer::sanitizeString($request->note),
                'incomeDate' => $request->incomeDate,
            ]);
            
            Log::info('Income created', [
                'business_id' => $business_id,
                'user_id' => $user->id,
                'income_id' => $data->id,
                'amount' => $request->amount,
            ]);
            
            DB::commit();

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => new IncomeResource($data->load('category:id,categoryName', 'payment_type:id,name')),
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Income creation failed', [
                'business_id' => $business_id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => __('Failed to create income.')
            ], 500);
        }
    }
}
