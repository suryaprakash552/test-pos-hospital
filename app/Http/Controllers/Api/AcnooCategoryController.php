<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Helpers\DataSanitizer;
use App\Http\Requests\Api\StoreCategoryRequest;
use App\Http\Requests\Api\UpdateCategoryRequest;
use App\Services\CacheService;
use Illuminate\Support\Facades\Log;
use App\Support\LookupDeletionGuard;

class AcnooCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $business_id = auth()->user()->business_id;

            if (app()->environment('testing')) {
                $data = Category::where('business_id', $business_id)->latest()->get();
            } else {
                $data = CacheService::remember(
                    "categories_{$business_id}",
                    function () use ($business_id) {
                        return Category::where('business_id', $business_id)->latest()->get();
                    },
                    86400,
                    [CacheService::TAG_CATEGORIES]
                );
            }

            return response()->json([
                'message' => __('Data fetched successfully.'),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Category list failed', [
                'business_id' => auth()->user()->business_id ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('Failed to fetch categories.')
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCategoryRequest $request)
    {
        try {
            $business_id = auth()->user()->business_id;

            $data = Category::create([
                'categoryName' => DataSanitizer::sanitizeString($request->categoryName),
                'variationCapacity' => filter_var($request->variationCapacity, FILTER_VALIDATE_BOOLEAN),
                'variationColor' => filter_var($request->variationColor, FILTER_VALIDATE_BOOLEAN),
                'variationSize' => filter_var($request->variationSize, FILTER_VALIDATE_BOOLEAN),
                'variationType' => filter_var($request->variationType, FILTER_VALIDATE_BOOLEAN),
                'variationWeight' => filter_var($request->variationWeight, FILTER_VALIDATE_BOOLEAN),
                // Opt-in, so an existing category keeps behaving as it did and nobody
                // adds a whole category by mistapping it.
                'bulk_add_enabled' => filter_var($request->bulk_add_enabled, FILTER_VALIDATE_BOOLEAN),
                'default_quantity' => max(1, (int) ($request->default_quantity ?? 20)),
                'min_order_quantity' => max(0, (int) ($request->min_order_quantity ?? 0)),
                'business_id' => $business_id
            ]);

            CacheService::clearCategoriesCache();

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Category creation failed', [
                'business_id' => auth()->user()->business_id ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('Failed to create category.')
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCategoryRequest $request, Category $category)
    {
        $user = auth()->user();

        if ($category->business_id != $user->business_id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }

        try {
            // Only what the request actually carries.
            //
            // Every field used to be written unconditionally, reading an absent
            // one as its zero value: `filter_var(null, FILTER_VALIDATE_BOOLEAN)`
            // is false, and the quantities fell back to their defaults. The app
            // sends only the name and the variation flags when renaming a
            // category, so each rename silently switched bulk-add off and reset
            // default_quantity to 20 and min_order_quantity to 0.
            //
            // An update writes what it was given; anything omitted keeps the
            // value it already had.
            $changes = [
                'categoryName' => DataSanitizer::sanitizeString($request->categoryName),
            ];

            foreach ([
                'variationCapacity',
                'variationColor',
                'variationSize',
                'variationType',
                'variationWeight',
                'bulk_add_enabled',
            ] as $flag) {
                if ($request->has($flag)) {
                    $changes[$flag] = filter_var($request->input($flag), FILTER_VALIDATE_BOOLEAN);
                }
            }

            if ($request->has('default_quantity')) {
                // Floored at 1: a bulk add of zero silently adds nothing.
                $changes['default_quantity'] = max(1, (int) $request->input('default_quantity'));
            }

            if ($request->has('min_order_quantity')) {
                $changes['min_order_quantity'] = max(0, (int) $request->input('min_order_quantity'));
            }

            $category->update($changes);

            CacheService::clearCategoriesCache();

            return response()->json([
                'message' => __('Data saved successfully.'),
                'data' => $category,
            ]);
        } catch (\Exception $e) {
            Log::error('Category update failed', [
                'business_id' => $user->business_id,
                'category_id' => $category->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('Failed to update category.')
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category)
    {
        $user = auth()->user();

        if ($category->business_id != $user->business_id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }

        // products.category_id cascades, so deleting this would take the
        // products with it. Checked here so the refusal names a count;
        // the RESTRICT constraint is what actually enforces it.
        $blocked = LookupDeletionGuard::blockedMessage(
            'category_id',
            $category->id,
            'category'
        );

        if ($blocked !== null) {
            return response()->json(['message' => $blocked], 409);
        }

        try {
            $category->delete();

            CacheService::clearCategoriesCache();

            return response()->json([
                'message' => __(':noun deleted.', ['noun' => __('Category')]),
            ]);
        } catch (\Exception $e) {
            Log::error('Category deletion failed', [
                'business_id' => $user->business_id,
                'category_id' => $category->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('Failed to delete category.')
            ], 500);
        }
    }
}
