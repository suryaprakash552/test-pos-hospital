<?php

namespace App\Http\Requests;

use App\Rules\TenantOwned;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class SaleStoreRequest extends FormRequest
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
            'saleDate' => 'required|date',
            'customer_id' => ['nullable', 'integer', TenantOwned::in('customers')],
            'payment_type_id' => ['nullable', 'integer', TenantOwned::in('payment_types')],
            'doctor_id' => ['nullable', 'integer', TenantOwned::in('doctors')],
            'totalAmount' => 'required|numeric|min:0',
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
            'details' => 'required|array|min:1',
            'details.*.product_id' => ['required', 'integer', TenantOwned::in('products')],
            'details.*.stock_id' => ['nullable', 'integer', TenantOwned::in('stocks')],
            'details.*.quantities' => 'required|numeric|min:0',
            'details.*.price' => 'required|numeric|min:0',
            'details.*.discount' => 'nullable|numeric|min:0',
            'details.*.subtotal' => 'required|numeric|min:0',
            'rounding_option' => 'nullable|in:none,round_up,round_down,nearest_whole_number,nearest_0.05,nearest_0.1,nearest_0.5',
            'rounded_amount' => 'nullable|numeric',
            'order_management_id' => 'nullable|integer|exists:order_managements,id',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'saleDate.required' => __('validation.sale_date_required'),
            'saleDate.date' => __('validation.sale_date_date'),
            'totalAmount.required' => __('validation.total_amount_required'),
            'totalAmount.numeric' => __('validation.total_amount_numeric'),
            'totalAmount.min' => __('validation.total_amount_min'),
            'details.required' => __('validation.details_required'),
            'details.array' => __('validation.details_array'),
            'details.min' => __('validation.details_min'),
            'details.*.product_id.required' => __('validation.product_id_required'),
            'details.*.product_id.exists' => __('validation.product_id_exists'),
            'details.*.quantities.required' => __('validation.quantities_required'),
            'details.*.quantities.numeric' => __('validation.quantities_numeric'),
            'details.*.quantities.min' => __('validation.quantities_min'),
            'details.*.price.required' => __('validation.price_required'),
            'details.*.price.numeric' => __('validation.price_numeric'),
            'details.*.price.min' => __('validation.price_min'),
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
