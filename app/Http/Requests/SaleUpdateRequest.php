<?php

namespace App\Http\Requests;

use App\Rules\TenantOwned;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class SaleUpdateRequest extends FormRequest
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
            'invoiceNumber' => 'nullable|string|max:100',
            'saleDate' => 'nullable|date',
            'customer_id' => ['nullable', 'integer', TenantOwned::in('customers')],
            'payment_type_id' => ['nullable', 'integer', TenantOwned::in('payment_types')],
            'doctor_id' => ['nullable', 'integer', TenantOwned::in('doctors')],
            'totalAmount' => 'nullable|numeric|min:0',
            'paidAmount' => 'nullable|numeric|min:0',
            'dueAmount' => 'nullable|numeric|min:0',
            'discountAmount' => 'nullable|numeric|min:0',
            'changeAmount' => 'nullable|numeric|min:0',
            'cashAmount' => 'nullable|numeric|min:0',
            'upiAmount' => 'nullable|numeric|min:0',
            'cardAmount' => 'nullable|numeric|min:0',
            'paymentNote' => 'nullable|string|max:500',
            'saleNote' => 'nullable|string|max:500',
            'shippingAddress' => 'nullable|string|max:500',
            'rounding_option' => 'nullable|in:none,round_up,round_down,nearest_whole_number,nearest_0.05,nearest_0.1,nearest_0.5',
            'rounded_amount' => 'nullable|numeric',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'saleDate.date' => __('validation.sale_date_date'),
            'totalAmount.numeric' => __('validation.total_amount_numeric'),
            'totalAmount.min' => __('validation.total_amount_min'),
            'customer_id.exists' => __('validation.customer_exists'),
            'payment_type_id.exists' => __('validation.payment_type_exists'),
            'doctor_id.exists' => __('validation.doctor_exists'),
            'rounding_option.in' => __('validation.rounding_option_invalid'),
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
