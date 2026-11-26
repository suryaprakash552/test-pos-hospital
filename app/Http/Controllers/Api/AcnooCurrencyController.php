<?php

namespace App\Http\Controllers\Api;

use App\Models\Currency;
use App\Models\UserCurrency;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\Controller;

class AcnooCurrencyController extends Controller
{
    public function index()
    {
        $currencies = Cache::remember('currency_list', 86400, function () {
            return Currency::orderBy('is_default', 'desc')->orderBy('status', 'desc')->get();
        });

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $currencies
        ]);
    }

    public function show($id)
    {
        $currency = Currency::findOrFail($id);
        $user = auth()->user();
        $business_id = $user->business_id;

        $user_currency = UserCurrency::where('business_id', $business_id)->first();

        if (!$user_currency) {
            return response()->json([
                'message' => __('User currency not found.')
            ], 404);
        }

        $user_currency->update([
            'name' => $currency->name,
            'code' => $currency->code,
            'rate' => $currency->rate,
            'symbol' => $currency->symbol,
            'position' => $currency->position,
            'country_name' => $currency->country_name,
        ]);

        Cache::forget("business_currency_" . $business_id);
        
        Log::info('Currency changed', [
            'business_id' => $business_id,
            'user_id' => $user->id,
            'currency_id' => $currency->id,
            'currency_code' => $currency->code,
        ]);

        return response()->json([
            'message' => __('Currency changed successfully'),
        ]);
    }
}
