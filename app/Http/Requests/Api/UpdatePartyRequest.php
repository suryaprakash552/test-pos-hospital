<?php

namespace App\Http\Requests\Api;

use App\Services\GstConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $businessId = auth()->user()->business_id;
        $party = $this->route('party');

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:Retailer,Dealer,Wholesaler,Supplier,Manufacturer,Customer'],
            // See StorePartyRequest: `relationship` shares the uniqueness key
            // with phone, so family members on one number can each still be
            // edited without tripping over each other's record.
            'phone' => [
                'nullable',
                'max:20',
                Rule::unique('parties', 'phone')
                    ->ignore($party?->id)
                    ->where(function ($query) use ($businessId) {
                        $query->where('business_id', $businessId);

                        if (filled($this->input('relationship'))) {
                            $query->where('relationship', $this->input('relationship'));
                        } else {
                            $query->whereNull('relationship');
                        }
                    }),
            ],
            'relationship' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,svg', 'max:2048'],
            'address' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'opening_balance' => ['nullable', 'numeric', 'min:-999999999999.99', 'max:999999999999.99'],
            // Optional on update: this screen does not render the field, and an
            // edit that never mentions it must leave it as it was rather than
            // being refused. The controller falls back to the stored value.
            'opening_balance_type' => ['nullable', 'in:due,advance,cash,credit'],
        
            // The buyer's or supplier's registration number.
            //
            // Validated rather than stored as typed: a malformed GSTIN is worse
            // than a blank one, because the invoice is accepted at the till and
            // the filing that carries it is rejected months later. Same check
            // the shop's own GSTIN goes through.
            'gstin' => [
                'nullable',
                'string',
                'size:15',
                function ($attribute, $value, $fail) {
                    if (filled($value) && ! GstConfiguration::isValidGstin($value)) {
                        $fail('The entered GSTIN format is invalid.');
                    }
                },
            ],
        ];
    }
}
