<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreUnitRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $business_id = auth()->user()->business_id;

        return [
            'unitName' => [
                'required',
                'string',
                'max:255',
                'unique:units,unitName,NULL,id,business_id,' . $business_id,
            ],
        ];
    }

    public function messages()
    {
        return [
            'unitName.required' => __('Unit name is required.'),
            'unitName.unique' => __('Unit name already exists.'),
            'unitName.max' => __('Unit name must not exceed 255 characters.'),
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
