<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\UserCurrency;
use Illuminate\Support\Facades\Cache;

/**
 * NOT WIRED UP. Nothing references this class - the equivalent logic currently
 * lives in app/Helpers/Helper.php. Wire it up or delete it; editing it alone
 * changes no behaviour.
 */
class CurrencyService
{
    /**
     * Format currency amount.
     */
    public function format(float $amount, string $type = "icon", int $decimals = 2, ?Currency $currency = null, bool $abbreviate = false, bool $apply_rounding = false): string
    {
        $currency = $currency ?? $this->defaultCurrency();

        if ($apply_rounding) {
            $amount = $this->roundSale((float) $amount);
        }

        if ($abbreviate) {
            $amount = $this->formatNumber($amount, $decimals);
        } else {
            $has_fraction = $amount != floor($amount);
            $amount = $has_fraction ? number_format($amount, $decimals) : number_format($amount, 0);
        }

        if ($type == "icon" || $type == "symbol") {
            return $currency->position == "right" 
                ? $amount . $currency->symbol 
                : $currency->symbol . $amount;
        } else {
            return $currency->position == "right" 
                ? $amount . ' ' . $currency->code 
                : $currency->code . ' ' . $amount;
        }
    }

    /**
     * Format number with abbreviations.
     */
    public function formatNumber(float $number, int $decimals = 2): string
    {
        if ($number >= 1e9) {
            return $this->removeTrailingZeros($number / 1e9, $decimals) . "B";
        } elseif ($number >= 1e6) {
            return $this->removeTrailingZeros($number / 1e6, $decimals) . "M";
        } elseif ($number >= 1e3) {
            return $this->removeTrailingZeros($number / 1e3, $decimals) . "K";
        } else {
            return $this->removeTrailingZeros($number, $decimals);
        }
    }

    /**
     * Remove trailing zeros from number.
     */
    protected function removeTrailingZeros(float $number, int $decimals = 2): string
    {
        return rtrim(rtrim(number_format($number, $decimals, '.', ''), '0'), '.');
    }

    /**
     * Convert amount to words.
     */
    public function amountInWords(float $amount, int $decimals = 2): string
    {
        if (!extension_loaded('intl')) {
            return '';
        }

        $has_fraction = fmod($amount, 1) != 0;
        $amount = $has_fraction ? round($amount, $decimals) : round($amount);

        $formatter = new \NumberFormatter('en_US', \NumberFormatter::SPELLOUT);
        $words = $formatter->format($amount);

        return $words . ' ' . ($this->businessCurrency()->name ?? '');
    }

    /**
     * Convert money between currencies.
     */
    public function convertMoney(float $amount, Currency $currency): float
    {
        if ($currency->code == $this->defaultCurrency('code') || $amount == 0) {
            return round($amount, 2);
        } else {
            return $amount * $currency->rate / $this->defaultCurrency()->rate;
        }
    }

    /**
     * Get default currency.
     */
    public function defaultCurrency(?string $key = null, ?Currency $currency = null)
    {
        $currency = $currency ?? Cache::remember('default_currency', 3600, function () {
            $currency = Currency::whereIsDefault(1)->first();

            if (!$currency) {
                $currency = (object)[
                    'name' => 'US Dollar',
                    'code' => 'USD',
                    'rate' => 1,
                    'symbol' => '$',
                    'position' => 'left',
                    'status' => true,
                    'is_default' => true,
                ];
            }

            return $currency;
        });

        return $key ? $currency->$key : $currency;
    }

    /**
     * Get business currency.
     */
    public function businessCurrency(?int $businessId = null)
    {
        $businessId = $businessId ?? auth()->user()?->business_id;

        return Cache::remember("business_currency_{$businessId}", 3600, function () use ($businessId) {
            $businessCurrency = UserCurrency::where('business_id', $businessId)->first() 
                ?? Currency::where('is_default', 1)->first();

            if ($businessCurrency) {
                return (object)[
                    'name' => $businessCurrency->name,
                    'rate' => $businessCurrency->rate,
                    'code' => $businessCurrency->code,
                    'symbol' => $businessCurrency->symbol,
                    'position' => $businessCurrency->position,
                ];
            }

            return $this->defaultCurrency();
        });
    }

    /**
     * Round sale amount.
     */
    protected function roundSale(?float $amount = null, ?string $roundOption = null): float|string
    {
        $businessId = auth()->user()?->business_id;

        if (is_null($roundOption)) {
            $roundOption = Cache::remember("business_sale_rounding_{$businessId}", 3600, function () use ($businessId) {
                return \App\Models\Option::where('key', 'business-settings')
                    ->whereJsonContains('value->business_id', $businessId)
                    ->first()
                    ->value['sale_rounding_option'] ?? 'none';
            });
        }

        if (is_null($amount)) {
            return $roundOption;
        }

        return match ($roundOption) {
            'round_up' => ceil($amount),
            'round_down' => floor($amount),
            'nearest_whole_number' => round($amount),
            'nearest_0.05' => round($amount * 20) / 20,
            'nearest_0.1' => round($amount * 10) / 10,
            'nearest_0.5' => round($amount * 2) / 2,
            default => $amount,
        };
    }
}
