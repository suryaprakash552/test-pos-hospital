<?php

namespace App\Http\Requests\Api;

use App\Support\Api\ProductSettingDefaults;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return collect(ProductSettingDefaults::allowedKeys())
            ->mapWithKeys(fn (string $key) => [$key => ['nullable', 'integer', 'in:0,1']])
            ->merge([
                'default_sale_price' => ['nullable', 'numeric', 'min:0'],
                'default_wholesale_price' => ['nullable', 'numeric', 'min:0'],
                'default_dealer_price' => ['nullable', 'numeric', 'min:0'],
                'default_batch_no' => ['nullable', 'string', 'max:100'],
                'expire_date_type' => ['nullable', 'string', 'max:50'],
                'mfg_date_type' => ['nullable', 'string', 'max:50'],
                'default_expired_date' => ['nullable', 'date'],
                'default_mfg_date' => ['nullable', 'date'],
            ])
            ->all();
    }
}
