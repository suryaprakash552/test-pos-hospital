<?php

namespace App\Http\Requests\Api;

use App\Rules\TenantOwned;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreExpenseRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'amount' => 'nullable|numeric|min:0',
            'upi_amount' => 'nullable|numeric|min:0',
            'card_amount' => 'nullable|numeric|min:0',
            'expense_category_id' => ['required', TenantOwned::in('expense_categories')],
            'expanseFor' => 'nullable|string|max:255',
            'paymentType' => 'nullable|string|max:50',
            'payment_type_id' => ['nullable', TenantOwned::in('payment_types')],
            'referenceNo' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:500',
            'expenseDate' => 'nullable|date',
        ];
    }

    public function messages()
    {
        return [
            'amount.numeric' => 'Amount must be a number.',
            'amount.min' => 'Amount cannot be negative.',
            'upi_amount.numeric' => 'UPI amount must be a number.',
            'upi_amount.min' => 'UPI amount cannot be negative.',
            'expense_category_id.required' => 'Expense category is required.',
            'expense_category_id.exists' => 'Selected expense category is invalid.',
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
