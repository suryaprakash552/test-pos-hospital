<?php

namespace App\Http\Requests;

use App\Rules\TenantOwned;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ProductStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'productName' => 'required|string|max:255',
            'productDescription' => 'nullable|string',
            'productCode' => 'nullable|string|max:100|unique:products,productCode',
            'productPicture' => 'nullable|image|max:2048',
            'category_id' => ['nullable', 'integer', TenantOwned::in('categories')],
            'unit_id' => ['nullable', 'integer', TenantOwned::in('units')],
            'brand_id' => ['nullable', 'integer', TenantOwned::in('brands')],
            'manufacturer_id' => ['nullable', 'integer', TenantOwned::in('manufacturers')],
            'vat_id' => ['nullable', 'integer', TenantOwned::in('vats')],
            'vat_type' => 'nullable|in:inclusive,exclusive',
            'vat_amount' => 'nullable|numeric|min:0',
            'profit_percent' => 'nullable|numeric|min:0|max:100',
            'productDealerPrice' => 'nullable|numeric|min:0',
            'productPurchasePrice' => 'nullable|numeric|min:0',
            'productSalePrice' => 'nullable|numeric|min:0',
            'productWholeSalePrice' => 'nullable|numeric|min:0',
            'productStock' => 'nullable|numeric|min:0',
            'alert_qty' => 'nullable|numeric|min:0',
            'expire_date' => 'nullable|date',
            'size' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
            'weight' => 'nullable|string|max:50',
            'capacity' => 'nullable|string|max:50',
            'model_id' => ['nullable', 'integer', TenantOwned::in('product_models')],
            'warehouse_id' => ['nullable', 'integer', TenantOwned::in('warehouses')],
            'product_type' => 'nullable|string|max:50',
            'rack' => 'nullable|string|max:50',
            'shelf' => 'nullable|string|max:50',
            'productUpperUnit' => ['nullable', 'integer', TenantOwned::in('units')],
            'productLowerUnit' => ['nullable', 'integer', TenantOwned::in('units')],
            'conversionValue' => 'nullable|numeric|min:0',
            'product_flat_discount' => 'nullable|numeric|min:0',
            'product_percent_discount' => 'nullable|numeric|min:0|max:100',
            'meta' => 'nullable|array',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'productName.required' => __('validation.product_name_required'),
            'productName.string' => __('validation.product_name_string'),
            'productName.max' => __('validation.product_name_max'),
            'productCode.unique' => __('validation.product_code_unique'),
            'productPicture.image' => __('validation.image_image'),
            'productPicture.max' => __('validation.image_max'),
            'category_id.exists' => __('validation.category_exists'),
            'unit_id.exists' => __('validation.unit_exists'),
            'brand_id.exists' => __('validation.brand_exists'),
            'manufacturer_id.exists' => __('validation.manufacturer_exists'),
            'vat_id.exists' => __('validation.vat_exists'),
            'vat_type.in' => __('validation.vat_type_invalid'),
            'profit_percent.max' => __('validation.profit_percent_max'),
            'warehouse_id.exists' => __('validation.warehouse_exists'),
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => __('validation.validation_failed'),
                'data' => null,
                'meta' => [],
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
