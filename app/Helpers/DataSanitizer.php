<?php

namespace App\Helpers;

class DataSanitizer
{
    /**
     * Characters that change how text reads without being visible in it.
     *
     * The bidirectional overrides (U+202A–U+202E, U+2066–U+2069) reorder what
     * follows them, so a name can be made to display as something other than what
     * is stored — on a receipt, a report, or a picking list, where the reader has
     * no way to tell. The zero-width characters (U+200B–U+200D, U+FEFF) let two
     * different names look identical.
     *
     * None of them have a legitimate place in a product, category or customer
     * name, so they are removed rather than escaped.
     */
    private const INVISIBLE_CONTROLS = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    /**
     * Sanitize string input by trimming and removing extra whitespace
     */
    public static function sanitizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strip_tags($value);

        // Applied before whitespace is collapsed so anything left behind is
        // folded away by the same pass.
        $stripped = preg_replace(self::INVISIBLE_CONTROLS, '', $value);

        // preg_replace returns null on malformed UTF-8; keep the tag-stripped
        // value rather than discarding the field entirely.
        $value = $stripped ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Sanitize numeric input by ensuring it's a valid number
     */
    public static function sanitizeNumeric($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        
        return (float) filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }

    /**
     * Sanitize integer input
     */
    public static function sanitizeInteger($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        
        return (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }

    /**
     * Sanitize phone number
     */
    public static function sanitizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        
        // Remove all non-numeric characters except + for international format
        return preg_replace('/[^\d+]/', '', $phone);
    }

    /**
     * Sanitize email
     */
    public static function sanitizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        
        return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    }

    /**
     * Sanitize array of strings
     */
    public static function sanitizeStringArray(?array $array): ?array
    {
        if ($array === null) {
            return null;
        }
        
        return array_map(function ($item) {
            return self::sanitizeString($item);
        }, $array);
    }

    /**
     * Remove HTML tags from string
     */
    public static function stripHtml(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        
        return strip_tags($value);
    }

    /**
     * Sanitize and escape HTML entities
     */
    public static function escapeHtml(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
