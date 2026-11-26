<?php

namespace App\Http\Controllers\Api;

use App\Models\Option;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class AcnooPrivacyController extends Controller
{
    public function index()
    {
        $policy = Cache::remember('privacy_policy', 86400, function () {
            $option = Option::where('key', 'policy')->first();
            return $option ? $option->value : 'Privacy Policy';
        });
        
        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $policy,
        ]);
    }
}
