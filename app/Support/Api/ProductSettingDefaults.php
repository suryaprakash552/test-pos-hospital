<?php

namespace App\Support\Api;

class ProductSettingDefaults
{
    public static function modules(): array
    {
        return [
            'show_product_type_single' => '1',
            'show_product_category' => '1',
            'show_alert_qty' => '1',
            'show_product_unit' => '1',
            'show_exclusive_price' => '1',
            'show_inclusive_price' => '1',
            'show_profit_percent' => '1',
            'show_product_sale_price' => '1',
            'show_product_price' => '1',
            'show_product_stock' => '1',
            'default_sale_price' => null,
            'default_wholesale_price' => null,
            'default_dealer_price' => null,
            'default_batch_no' => null,
            'expire_date_type' => null,
            'mfg_date_type' => null,
            'default_expired_date' => null,
            'default_mfg_date' => null,
            'show_product_code' => '0',
            'show_product_brand' => '0',
            'show_model_no' => '0',
            'show_product_manufacturer' => '0',
            'show_product_image' => '0',
            'show_vat_id' => '0',
            'show_vat_type' => '0',
            'show_action' => '0',
            'show_product_dealer_price' => '0',
            'show_product_wholesale_price' => '0',
            'show_batch_no' => '0',
            'show_expire_date' => '0',
            'show_mfg_date' => '0',
            'show_product_type_variant' => '0',
            'show_product_batch_no' => '0',
            'show_product_expire_date' => '0',
            // Toggles the app has always read back and posted, but which were
            // absent here - so `validated()` dropped them and they could never
            // be turned on. Defaulting them off preserves current behaviour for
            // a business that has not touched them.
            'show_product_description' => '0',
            'show_product_maximum_discount' => '0',
            'show_product_rack' => '0',
            'show_product_shelf' => '0',
            'show_conversion_value' => '0',
            'show_upper_selected_unit' => '0',
            'show_lower_selected_unit' => '0',
            'show_product_flat_discount' => '0',
            'show_product_percent_discount' => '0',
        ];
    }

    public static function allowedKeys(): array
    {
        return array_values(array_unique(array_merge(array_keys(self::modules()), [
            'show_product_name',
            'show_capacity',
            'show_weight',
            'show_color',
            'show_type',
            'show_size',
        ])));
    }
}
