<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateBrandRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $business_id = auth()->user()->business_id;
        $brand_id = $this->route('brand')->id;

        return [
            'brandName' => [
                'required',
                'string',
                'max:255',
                'unique:brands,brandName,' . $brand_id . ',id,business_id,' . $business_id,
            ],
        ];
    }

    public function messages()
    {
        return [
            'brandName.required' => __('Brand name is required.'),
            'brandName.unique' => __('Brand name already exists.'),
            'brandName.max' => __('Brand name must not exceed 255 characters.'),
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'message' => __('Validation failed.'),
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
