<?php

namespace App\Http\Controllers\Api;

use App\Models\Option;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class AcnooTermsController extends Controller
{
    public function index()
    {
        $term = Cache::remember('terms_conditions', 86400, function () {
            $option = Option::where('key', 'term')->first();
            return $option ? $option->value : 'Terms & Conditions.';
        });
        
        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $term,
        ]);
    }
}
