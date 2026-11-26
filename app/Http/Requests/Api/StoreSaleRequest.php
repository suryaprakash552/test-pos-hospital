<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $businessId = auth()->user()?->business_id;

        return [
            // A JSON string or the array itself.
            //
            // `prepareForValidation` already decodes both, but this rule said
            // `string` - so a client that sent the line items as an array (which
            // is what the purchase endpoint requires, and the obvious thing to
            // try) was refused by the validator immediately after the request had
            // gone to the trouble of accepting it. The two endpoints disagreeing
            // on the shape of the same field is the trap; this at least stops the
            // sale endpoint disagreeing with itself.
            'products' => ['required'],
            'products_payload' => ['required', 'array', 'min:1', 'max:250'],
            'products_payload.*.stock_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('stocks', 'id')->where(
                    fn ($query) => $query->where('business_id', $businessId)
                ),
            ],
            'products_payload.*.quantities' => ['required', 'numeric', 'gt:0'],
            'products_payload.*.price' => ['nullable', 'numeric', 'min:0'],
            'products_payload.*.conversion_price' => ['nullable', 'numeric', 'min:0'],
            'products_payload.*.lossProfit' => ['required', 'numeric'],
            'products_payload.*.product_name' => ['nullable', 'string', 'max:255'],
            // A row's discount, in the three shapes the cart can express one.
            //
            // These are not here to police the values — the pricing service
            // caps and rejects them itself. They are here because `validated()`
            // returns **only** the keys that have rules, and the controller
            // prices from `validated('products_payload')`. Without them, every
            // row discount was silently dropped between the request and the
            // calculator, which then fell back to the discount configured on the
            // product: a bill that read ₹4 off on the billing screen was saved
            // with ₹448 off, and the GST recomputed on that wrong base. The
            // preview endpoint reads the raw input, which is exactly why the
            // screen and the saved invoice disagreed.
            'products_payload.*.discount_flat' => ['nullable', 'numeric', 'min:0'],
            'products_payload.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'products_payload.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'saleDate' => 'nullable|date',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'rounding_option' => 'nullable|in:none,round_up,round_down,nearest_whole_number,nearest_0.05,nearest_0.1,nearest_0.5',
            'customer_id' => [
                'nullable',
                Rule::exists('customers', 'id')->where(
                    fn ($query) => $query->where('business_id', $businessId)
                ),
            ],
            // The import this sale was built from, when there is one.
            //
            // Present, the batch is closed inside the same transaction that
            // creates the sale, so an import cannot be committed twice — see
            // the note in AcnooSaleController::store, and
            // PurchaseController::store for the purchase-side original of
            // this same fix.
            'import_batch_id' => [
                'nullable',
                'integer',
                Rule::exists('sale_import_batches', 'id')->where(
                    fn ($query) => $query->where('business_id', $businessId)
                ),
            ],
            'doctor_id' => [
                'nullable',
                Rule::exists('doctors', 'id')->where(
                    fn ($query) => $query->where('business_id', $businessId)
                ),
            ],
            'payment_type_id' => [
                'nullable',
                Rule::exists('payment_types', 'id')->where(
                    fn ($query) => $query->where('business_id', $businessId)
                ),
            ],
            'dueAmount' => 'nullable|numeric|min:0',
            'paidAmount' => 'nullable|numeric|min:0',
            'paymentType' => 'nullable|string|in:cash,upi,card,split',
            'cash_amount' => 'nullable|numeric|min:0',
            'upi_amount' => 'nullable|numeric|min:0',
            'card_amount' => 'nullable|numeric|min:0',

            // UTR / reference from the customer's UPI app, and when the payment
            // actually cleared. Both are what a bank statement is keyed on, so
            // without them a disputed payment cannot be matched to a sale.
            // `upi_paid_at` is distinct from the sale date: a sale entered offline
            // and synced later has two different times.
            'upi_reference' => 'nullable|string|max:100',
            'upi_paid_at'   => 'nullable|date',
            // Evidence, never a requirement: a queue must not be held up because a
            // customer will not hand over their phone.
            'upi_confirmation_image' => 'nullable|image|max:4096',
            'discountAmount' => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0',
            'rowDiscount' => 'nullable|numeric|min:0',
            'totalAmount' => 'required|numeric|min:0',
        ];
    }

    protected function prepareForValidation(): void
    {
        $products = $this->input('products', '');

        // A client that sends the line items as an array rather than the JSON
        // string this endpoint expects used to reach `(string) $array` and throw
        // — so a malformed request came back as a 500 with no indication of what
        // was wrong. Accepting the array is the honest reading of the intent, and
        // anything else falls through to the validator's own message.
        $decoded = is_array($products)
            ? $products
            : json_decode((string) $products, true);

        $this->merge([
            'products_payload' => is_array($decoded) ? $decoded : null,
        ]);
    }

    public function messages()
    {
        return [
            'products.required' => 'Products are required.',
            'products_payload.required' => 'Products must contain valid JSON line items.',
            'products_payload.*.stock_id.distinct' => 'A stock batch cannot be added more than once.',
            'products_payload.*.quantities.gt' => 'Product quantity must be greater than zero.',
            'saleDate.date' => 'Sale date must be a valid date.',
            'image.image' => 'Image must be an image file.',
            'image.mimes' => 'Image must be a jpeg, png, jpg, gif, or svg file.',
            'rounding_option.in' => 'Invalid rounding option selected.',
            'paidAmount.min' => 'Paid amount cannot be negative.',
            'dueAmount.min' => 'Due amount cannot be negative.',
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
