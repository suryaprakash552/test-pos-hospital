<?php

namespace App\Support\Api;

use Illuminate\Http\Request;

class ApiRequest
{
    public static function perPage(Request $request, int $default = 50, int $max = 100): int
    {
        $value = (int) $request->input('per_page', $default);

        return min(max($value, 1), $max);
    }

    public static function search(Request $request, string $key = 'search', int $maxLength = 120): ?string
    {
        $value = trim((string) $request->input($key, ''));

        if ($value === '') {
            return null;
        }

        return substr($value, 0, $maxLength);
    }
}
