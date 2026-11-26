<?php

namespace App\Http\Requests\Api;

use App\Services\GstConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $businessId = auth()->user()->business_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:Retailer,Dealer,Wholesaler,Supplier,Manufacturer,Customer'],
            // One phone commonly covers a whole family, each billed as their own
            // party (mother, father, child) so dues and purchase history stay
            // separate. The phone still identifies who it's taken from - see
            // PartyDuplicateRuleTest, where a different name on the same phone
            // stays refused - but `relationship` is how one phone holds more than
            // one such identity, so it has to share the uniqueness key rather
            // than sit outside it.
            'phone' => [
                'nullable',
                'max:20',
                Rule::unique('parties', 'phone')->where(function ($query) use ($businessId) {
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
            'opening_balance_type' => ['required', 'in:due,advance,cash,credit'],
        
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

    public function messages(): array
    {
        return [
            'phone.unique' => 'The phone number has already been taken for this business.',
        ];
    }
}
