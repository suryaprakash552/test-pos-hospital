<?php

namespace App\Http\Requests\Api;

use App\Rules\TenantOwned;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreIncomeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'amount' => 'required|numeric|min:0',
            'income_category_id' => ['required', TenantOwned::in('income_categories')],
            'incomeFor' => 'nullable|string|max:255',
            'paymentType' => 'nullable|string|max:50',
            'payment_type_id' => ['nullable', TenantOwned::in('payment_types')],
            'referenceNo' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:500',
            'incomeDate' => 'nullable|date',
        ];
    }

    public function messages()
    {
        return [
            'amount.required' => 'Amount is required.',
            'amount.numeric' => 'Amount must be a number.',
            'amount.min' => 'Amount cannot be negative.',
            'income_category_id.required' => 'Income category is required.',
            'income_category_id.exists' => 'Selected income category is invalid.',
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
