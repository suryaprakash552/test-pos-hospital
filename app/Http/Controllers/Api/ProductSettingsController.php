<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProductSettingsRequest;
use App\Models\ProductSetting;
use App\Support\Api\ProductSettingDefaults;

class ProductSettingsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $businessId = auth()->user()->business_id;
        $data = ProductSetting::forBusiness((int) $businessId)->first();

        if ($data) {
            $responseData = $data;
        } else {
            $responseData = [
                'business_id' => $businessId,
                'modules' => ProductSettingDefaults::modules(),
            ];
        }

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $responseData,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProductSettingsRequest $request)
    {
        $businessId = auth()->user()->business_id;
        $existing = ProductSetting::forBusiness((int) $businessId)->first();

        // Layer the submitted keys over what is already stored, not over the
        // defaults. Starting from the defaults meant any key the client did not
        // send silently reverted - so saving the toggle drawer wiped settings it
        // does not even render, such as `show_warehouse` and the default prices.
        $modules = array_replace(
            ProductSettingDefaults::modules(),
            is_array($existing?->modules) ? $existing->modules : [],
            $request->validated(),
        );

        ProductSetting::updateOrCreate(
            ['business_id' => $businessId],
            ['modules' => self::normaliseToggles($modules)]
        );

        return response()->json([
            'message' => __('Data saved successfully.'),
        ]);
    }

    /**
     * One type per toggle in the JSON column.
     *
     * `modules` is free-form JSON and had been written both ways depending on
     * how the value reached `validated()` — a multipart post from the till
     * leaves "1" a string, a JSON post from the web panel leaves it an int. Two
     * of the businesses on the server ended up storing ints, and the till
     * declares every one of these fields as a string: the mismatch threw
     * `type 'int' is not a subtype of type 'String?'` out of the settings fetch
     * and took the product edit form down with it for those shops.
     *
     * Strings, because that is what `ProductSettingDefaults` already emits and
     * what the other five rows hold. Only the `show_*` toggles are touched —
     * the default prices and date modes alongside them hold real values.
     */
    private static function normaliseToggles(array $modules): array
    {
        foreach ($modules as $key => $value) {
            if (! str_starts_with($key, 'show_') || $value === null) {
                continue;
            }

            $modules[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        return $modules;
    }
}
