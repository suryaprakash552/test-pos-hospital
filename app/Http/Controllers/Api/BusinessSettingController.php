<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Option;

class BusinessSettingController extends Controller
{
    public function index()
    {
        $setting = Option::where('key', 'business-settings')
            ->whereJsonContains('value->business_id', auth()->user()->business_id)
            ->first();
        $value = $setting?->value ?? [];

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => [
                // Keep this response aligned with BusinessSettingModel in the app.
                'pictureUrl' => $value['invoice_logo'] ?? null,
                'sale_rounding_option' => $value['sale_rounding_option'] ?? 'none',
                'purchase_rounding_option' => $value['purchase_rounding_option'] ?? 'none',
                'overall_discount_flat' => $value['overall_discount_flat'] ?? 0,
                'overall_discount_percent' => $value['overall_discount_percent'] ?? 0,
            ],
        ]);
    }
}
