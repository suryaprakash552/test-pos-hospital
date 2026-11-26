<?php

namespace App\Http\Requests\Api;

use App\Rules\TenantOwned;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $business_id = auth()->user()->business_id;

        return [
            'unit_id' => ['nullable', TenantOwned::in('units')],
            'brand_id' => ['nullable', TenantOwned::in('brands')],
            'manufacturer_id' => ['nullable', TenantOwned::in('manufacturers')],
            'productName' => 'required|string|max:250',
            'category_id' => ['nullable', TenantOwned::in('categories')],
            'model_id' => ['nullable', TenantOwned::in('product_models')],
            'productPicture' => 'required|image|max:2048',
            'vat_id' => ['nullable', TenantOwned::in('vats')],
            'rack' => 'nullable|string|max:50',
            'shelf' => 'nullable|string|max:50',
            'upperSelectedUnit' => ['nullable', TenantOwned::in('units')],
            'lowerSelectedUnit' => ['nullable', TenantOwned::in('units')],
            'conversionValue' => 'nullable|numeric|min:0',
            // HSN for goods, SAC for services — the same column serves both. Digits
            // only and 4-8 long, which is the range Indian GST uses; a code outside
            // it would be rejected at filing time rather than here.
            'hsn_code' => 'nullable|string|regex:/^[0-9]{4,8}$/',
            // Cess sits outside the CGST/SGST/IGST split and is added on top.
            'cess_rate' => 'nullable|numeric|min:0|max:100',
            'productCode' => [
                'nullable',
                Rule::unique('products')->where(function ($query) use ($business_id) {
                    return $query->where('business_id', $business_id);
                }),
            ],
            'productDescription' => 'nullable|string',
            'alert_qty' => 'nullable|numeric|min:0',
            'expire_date' => 'nullable|date',
            'productStock' => 'nullable|numeric|min:0',
            'maxProductStock' => 'nullable|numeric|min:0',
            'profit_percent' => 'nullable|numeric|min:0',
            'productSalePrice' => 'nullable|numeric|min:0',
            'productDealerPrice' => 'nullable|numeric|min:0',
            'productWholeSalePrice' => 'nullable|numeric|min:0',
            'productPurchasePrice' => 'nullable|numeric|min:0',
            'product_flat_discount' => 'nullable|numeric|min:0',
            'product_percent_discount' => 'nullable|numeric|min:0',
            // Every spelling the clients have used for this column. `simple`
            // and `variant` were the only ones accepted, while the till has
            // always sent `single` or `batch` — so a product created from the
            // till was rejected with a validation error naming a field the
            // user never filled in.
            'product_type' => 'nullable|string|in:simple,single,variant,batch',
            'mfg_date' => 'nullable|date',
            'batch_no' => 'nullable|array',
            'batch_no.*' => 'nullable|string',
        ];
    }

    public function messages()
    {
        return [
            'productName.required' => 'Product name is required.',
            'productPicture.required' => 'Product image is required.',
            'productPicture.image' => 'Product image must be an image file.',
            'productPicture.max' => 'Product image must not exceed 2MB.',
            'productCode.unique' => 'Product code already exists for this business.',
            'conversionValue.min' => 'Conversion value cannot be negative.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
