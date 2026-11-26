<?php

namespace App\Http\Requests\Api;

use App\Rules\TenantOwned;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateVatRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'vat_ids' => 'required_if:rate,null|array',
            'vat_ids.*' => [TenantOwned::in('vats')],
            'rate' => 'required_if:vat_ids,null|numeric',
            'status' => 'nullable|boolean',
        ];
    }

    public function messages()
    {
        return [
            'name.required' => 'VAT name is required.',
            'vat_ids.required_if' => 'VAT IDs are required when rate is not provided.',
            'rate.required_if' => 'Rate is required when VAT IDs are not provided.',
            'vat_ids.*.exists' => 'One or more selected VAT IDs are invalid.',
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
