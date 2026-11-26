<?php

namespace App\Services\Import;

final class ImportProductIdentifier
{
    /** Spreadsheet cells can look blank while containing non-breaking or zero-width spaces. */
    public static function isPresent(mixed $value): bool
    {
        $text = (string) ($value ?? '');
        $visible = preg_replace('/[\p{Z}\p{C}\s]+/u', '', $text);

        return $visible !== null && $visible !== '';
    }

    /** A row is reviewable when it names a product or supplies a real code. */
    public static function rowHasProduct(array $row): bool
    {
        return self::isPresent($row['product_name'] ?? null)
            || self::isPresent($row['product_code'] ?? null);
    }
}
