<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $businessId = auth()->user()->business_id;

        return [
            'products' => ['required', 'array', 'min:1'],
            'products.*.product_id' => [
                'required',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('business_id', $businessId)),
            ],
            'products.*.quantities' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'products.*.free_quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'products.*.productSalePrice' => ['required', 'numeric', 'gt:0'],
            'products.*.productPurchasePrice' => ['required', 'numeric', 'min:0'],
            'products.*.conversion_purchase_price' => ['required', 'numeric', 'min:0'],
            'products.*.conversion_quantities' => ['required', 'numeric', 'min:0'],
            'products.*.conversion_value' => ['required', 'numeric', 'gt:0'],
            'products.*.conversion_price' => ['required', 'numeric', 'min:0'],
            'products.*.expire_date' => ['required', 'date'],
            'products.*.mfg_date' => ['required', 'date'],
            'products.*.upper_selected_unit' => ['required'],
            'products.*.lower_selected_unit' => ['required'],
            'products.*.total_purchase_price' => ['required', 'numeric', 'min:0'],
            'products.*.total_price' => ['required', 'numeric', 'min:0'],
            'party_id' => [
                'required',
                Rule::exists('parties', 'id')->where(fn ($query) => $query->where('business_id', $businessId)),
            ],
            // The import this purchase was built from, when there is one.
            //
            // Present, the batch is closed inside the same transaction that
            // creates the purchase, so an import cannot be committed twice —
            // see the note in `PurchaseController::store`.
            'import_batch_id' => [
                'nullable',
                'integer',
                Rule::exists('purchase_import_batches', 'id')->where(
                    fn ($query) => $query->where('business_id', $businessId)
                ),
            ],
            // The number printed on the supplier's own bill.
            //
            // Optional: a delivery taken over the counter or arranged by phone
            // has no reference to record, and a blank is a fact about that
            // purchase rather than a missing field.
            'reference_invoice_no' => ['nullable', 'string', 'max:100'],
            'paidAmount' => ['nullable', 'numeric', 'min:0'],
            'dueAmount' => ['nullable', 'numeric', 'min:0'],
            'paymentType' => ['nullable', 'string'],
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'upi_amount' => ['nullable', 'numeric', 'min:0'],
            'card_amount' => ['nullable', 'numeric', 'min:0'],
            // A correction is a source-document action and must say why it is
            // being made. It is stored in immutable inventory change history,
            // never copied onto historic sale lines.
            'correction_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * A line cannot claim less sellable stock than it bought.
     *
     * A purchase carries the quantity in two units: `quantities` counts strips or
     * boxes, and `conversion_quantities` counts the same stock in the unit a sale
     * draws down. The second is what the till checks before accepting a sale, so
     * it decides what the shop can actually sell — and the two arriving out of
     * step is how a shop ends up unable to sell what it is holding.
     *
     * The rule is a floor rather than an equality, because both are legitimate:
     * three strips of ten is thirty tablets, and three strips *plus* five loose
     * tablets is thirty-five. What cannot be true is fewer: three strips of ten
     * are never twenty tablets.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ((array) $this->input('products', []) as $i => $line) {
                $quantities = (float) ($line['quantities'] ?? 0);
                $free       = (float) ($line['free_quantity'] ?? 0);
                $conversion = (float) ($line['conversion_value'] ?? 1);
                $sellable   = (float) ($line['conversion_quantities'] ?? 0);

                // A conversion of zero would mean a strip holds nothing; treat it
                // as unconverted rather than dividing the stock away.
                if ($conversion <= 0) {
                    $conversion = 1;
                }

                $received = $quantities + $free;
                $minimum = $received * $conversion;

                if ($received > 1_000_000 || $minimum > 1_000_000_000) {
                    $validator->errors()->add(
                        "products.{$i}.free_quantity",
                        'The combined received quantity is too large.',
                    );
                }

                // A hair of tolerance for the floating point that survives a JSON
                // round trip; the faults this catches are whole units apart.
                if ($sellable + 0.001 < $minimum) {
                    $validator->errors()->add(
                        "products.{$i}.conversion_quantities",
                        "This line buys {$quantities} at {$conversion} per unit, "
                        . "which is {$minimum} to sell, but only {$sellable} was "
                        . 'recorded as available. The shop would be unable to sell '
                        . 'what it is holding.',
                    );
                }
            }
        });
    }
}
