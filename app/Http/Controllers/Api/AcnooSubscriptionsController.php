<?php

namespace App\Http\Controllers\Api;

use App\Models\Plan;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class AcnooSubscriptionsController extends Controller
{
    public function index()
    {
        $plans = Cache::remember('subscription_plans', 3600, function () {
            return Plan::whereStatus(1)->latest()->get();
        });
        
        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $plans,
        ]);
    }
}
