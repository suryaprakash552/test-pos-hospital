<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AcnooLanguageController extends Controller
{
    public function index()
    {
        $data = Cache::remember('language_list', 86400, function () {
            return json_decode(file_get_contents(base_path('lang/langlist.json')), true);
        });
        
        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $data,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'lang' => 'required|max:30|min:1|string'
        ]);

        $user = auth()->user();
        $user->update([
            'lang' => $request->lang
        ]);

        return response()->json([
            'message' => __('Language updated successfully.')
        ]);
    }
}
