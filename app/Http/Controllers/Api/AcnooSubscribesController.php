<?php

namespace App\Http\Controllers\Api;

use App\Models\PlanSubscribe;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Schema;

class AcnooSubscribesController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $subscribes = PlanSubscribe::with('plan:id,subscriptionName')
            ->when(Schema::hasColumn('plan_subscribes', 'business_id'), function ($query) use ($user) {
                $query->where('business_id', $user->business_id);
            })
            ->latest()
            ->get();

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $subscribes
        ]);
    }
}
