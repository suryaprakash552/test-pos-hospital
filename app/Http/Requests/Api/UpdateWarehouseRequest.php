<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateWarehouseRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $business_id = auth()->user()->business_id;
        $warehouse_id = $this->route('warehouse')->id ?? $this->route('id');

        return [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('warehouses')->ignore($warehouse_id)->where(function ($query) {
                    return $query->where('business_id', auth()->user()->business_id);
                }),
            ],
        ];
    }

    public function messages()
    {
        return [
            'name.required' => __('Warehouse name is required.'),
            'name.max' => __('Warehouse name must not exceed 255 characters.'),
            'email.email' => __('Please provide a valid email address.'),
            'email.unique' => __('Email already exists for this business.'),
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
