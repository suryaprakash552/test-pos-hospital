<?php

namespace App\Http\Controllers\Api\Customer;

use App\Models\QuickActionBanner;
use App\Helpers\HasUploader;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiRequest;

class QuickActionBannerController extends Controller
{
    use HasUploader;

    public function index(Request $request)
    {
        $user = auth()->user();
        $search = ApiRequest::search($request);
        $perPage = ApiRequest::perPage($request, 50, 100);

        $query = QuickActionBanner::query()
            ->select(['id', 'business_id', 'type', 'image', 'created_at', 'updated_at'])
            ->forBusiness((int) $user->business_id)
            ->latest();

        if ($search) {
            $query->where('type', 'like', '%' . $search . '%');
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $banners = $query->paginate($perPage);

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $banners,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        $user = auth()->user();

        if (!$user->business_id) {
            return response()->json([
                'message' => __('Business ID is required.')
            ], 422);
        }

        $banner = QuickActionBanner::create([
            'business_id' => $user->business_id,
            'type' => $request->type,
            'image' => $request->image ? $this->upload($request, 'image') : NULL,
        ]);

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data' => $banner,
        ]);
    }

    public function show(QuickActionBanner $banner)
    {
        if ($banner->business_id !== auth()->user()->business_id) {
            return response()->json([
                'message' => __('Banner not found.')
            ], 404);
        }

        return response()->json([
            'data' => $banner
        ]);
    }

   public function update(Request $request, QuickActionBanner $banner)
    {
        $businessId = (int) auth()->user()->business_id;

        if ((int) $banner->business_id !== $businessId) {
            return response()->json([
                'message' => __('Banner not found.')
            ], 404);
        }

        $request->validate([
            'type' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        $banner->update([
            'type' => $request->type,
            'image' => $request->image
                ? $this->upload($request, 'image', $banner->image)
                : $banner->image,
        ]);

        return response()->json([
            'message' => __('Banner updated successfully.'),
            'data' => $banner
        ]);
    }

    public function destroy($id)
    {
        $businessId = auth()->user()->business_id;

        $banner = QuickActionBanner::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (!$banner) {
            return response()->json([
                'message' => 'Banner not found'
            ], 404);
        }

        if ($banner->image && file_exists($banner->image)) {
            unlink($banner->image);
        }

        $banner->delete();

        return response()->json([
            'message' => 'Banner deleted successfully.'
        ]);
    }
}
