<?php

namespace App\Services\Import;

/**
 * Decides which product a parsed row refers to — or admits it cannot.
 *
 * The important behaviour here is what it does when it is *not* sure. A silent
 * best guess is the worst outcome available: stock lands against the wrong
 * medicine, the shop finds out weeks later when a count disagrees, and there is
 * nothing on the record showing where it went wrong. So a row resolves to one
 * product or it is handed back as `ambiguous` or `unmatched` for a person to
 * settle, and the reason travels with it.
 */
class PurchaseLineMatcher
{
    use ProductNameMatching;

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed> the line attributes, ready to persist
     */
    public function match(int $businessId, array $row): array
    {
        $name = ImportProductIdentifier::isPresent($row['product_name'] ?? null)
            ? trim((string) $row['product_name']) : '';
        $code = ImportProductIdentifier::isPresent($row['product_code'] ?? null)
            ? trim((string) $row['product_code']) : '';

        $line = [
            'product_name' => $name !== '' ? $name : null,
            'product_code' => $code !== '' ? $code : null,
            'batch_no' => $this->text($row['batch_no'] ?? null),
            'quantity' => $this->number($row['quantity'] ?? null),
            'free_quantity' => $this->number($row['free_quantity'] ?? null),
            'purchase_price' => $this->number($row['purchase_price'] ?? null),
            'mrp' => $this->number($row['mrp'] ?? null),
            'discount_percent' => $this->number($row['discount_percent'] ?? null),
            'tax_percent' => $this->number($row['tax_percent'] ?? null),
            'expire_date' => $this->date($row['expire_date'] ?? null),
            'mfg_date' => $this->date($row['mfg_date'] ?? null),
        ];

        return $line + $this->resolveProduct($businessId, $name, $code);
    }
}
