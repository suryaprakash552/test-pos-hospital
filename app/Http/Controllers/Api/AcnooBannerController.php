<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AcnooBannerController extends Controller
{
    public function index()
    {
        try {
            $business_id = auth()->user()->business_id ?? null;
            $hasBusinessColumn = Schema::hasColumn('banners', 'business_id');

            $banners = Cache::remember("banners_{$business_id}", 86400, function () use ($business_id, $hasBusinessColumn) {
                return Banner::where('status', 1)
                    ->when($business_id && $hasBusinessColumn, function ($query) use ($business_id) {
                        $query->where('business_id', $business_id);
                    })
                    ->latest()
                    ->get()
                    ->map(function ($banner) {
                        // Check if path already starts with http (full URL) or is in public/uploads
                        if (str_starts_with($banner->imageUrl, 'http')) {
                            return $banner;
                        }
                        // Check if file exists in public/uploads (old seeder images)
                        if (file_exists(public_path($banner->imageUrl))) {
                            $banner->imageUrl = asset($banner->imageUrl);
                        } else {
                            // New storage-based images
                            $banner->imageUrl = asset('storage/' . $banner->imageUrl);
                        }
                        return $banner;
                    });
            });

            return response()->json([
                'message' => __('Data fetched successfully.'),
                'data' => $banners,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Failed to fetch banners.')
            ], 500);
        }
    }
}
