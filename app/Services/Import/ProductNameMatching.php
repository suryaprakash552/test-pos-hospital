<?php

namespace App\Services\Import;

use App\Models\Product;

/**
 * Decides which product a parsed row refers to — or admits it cannot.
 *
 * Shared by the purchase and sale line matchers: which of a shop's products
 * a bill line names is the same question in both directions. What differs
 * is what happens *after* a product is found — a purchase creates a batch,
 * a sale has to pick one that already exists — which is why this stops at
 * the product and leaves the rest to each matcher.
 *
 * The important behaviour here is what it does when it is *not* sure. A
 * silent best guess is the worst outcome available: stock (or a sale)
 * lands against the wrong medicine, the shop finds out weeks later when a
 * count disagrees, and there is nothing on the record showing where it
 * went wrong. So a row resolves to one product or it is handed back as
 * `ambiguous` or `unmatched` for a person to settle, and the reason
 * travels with it.
 */
trait ProductNameMatching
{
    /** @return array{product_id: ?int, match_status: string, note: ?string} */
    private function resolveProduct(int $businessId, string $name, string $code): array
    {
        // A code is an identifier and a name is a description, so the code
        // wins when both are present: two products can share a name, and a
        // supplier writing the same code twice means the same thing twice.
        if ($code !== '') {
            $byCode = Product::query()
                ->where('business_id', $businessId)
                ->where('productCode', $code)
                ->get(['id']);

            if ($byCode->count() === 1) {
                return [
                    'product_id' => (int) $byCode->first()->id,
                    'match_status' => 'matched',
                    'note' => null,
                ];
            }

            if ($byCode->count() > 1) {
                return [
                    'product_id' => null,
                    'match_status' => 'ambiguous',
                    'note' => "More than one product carries the code \"{$code}\".",
                ];
            }
        }

        if ($name === '') {
            return [
                'product_id' => null,
                'match_status' => 'unmatched',
                'note' => 'The row named no product.',
            ];
        }

        $exact = Product::query()
            ->where('business_id', $businessId)
            ->whereRaw('LOWER(productName) = ?', [mb_strtolower($name)])
            ->get(['id']);

        if ($exact->count() === 1) {
            return [
                'product_id' => (int) $exact->first()->id,
                'match_status' => 'matched',
                'note' => null,
            ];
        }

        if ($exact->count() > 1) {
            return [
                'product_id' => null,
                'match_status' => 'ambiguous',
                'note' => "More than one product is named \"{$name}\".",
            ];
        }

        // Nothing matched exactly. A near match is offered as a *candidate*
        // to choose from, never selected: "PARACETAMOL 500" and
        // "PARACETAMOL 650" are one substring apart and are not the same
        // medicine.
        $candidates = Product::query()
            ->where('business_id', $businessId)
            ->where('productName', 'LIKE', '%'.$name.'%')
            ->limit(2)
            ->get(['id', 'productName']);

        if ($candidates->count() === 1) {
            return [
                'product_id' => null,
                'match_status' => 'ambiguous',
                'note' => 'Did you mean "'.$candidates->first()->productName.'"?',
            ];
        }

        if ($candidates->count() > 1) {
            return [
                'product_id' => null,
                'match_status' => 'ambiguous',
                'note' => 'Several products look similar to "'.$name.'".',
            ];
        }

        return [
            'product_id' => null,
            'match_status' => 'unmatched',
            'note' => 'No product matches "'.$name.'". Pick one, or skip the row.',
        ];
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /**
     * A number as written on a bill.
     *
     * Suppliers write `1,250.00`, `₹1250`, `1250/-` and `10 %`. Anything that
     * is not part of a number is stripped before parsing; a cell that holds
     * no digits at all is absent rather than zero, because zero is a real
     * quantity and "we could not read it" is not.
     */
    private function number(mixed $value): ?float
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return null;
        }

        $cleaned = preg_replace('/[^0-9.\-]/', '', $text) ?? '';

        if ($cleaned === '' || ! is_numeric($cleaned)) {
            return null;
        }

        return (float) $cleaned;
    }

    /**
     * A date as written on a bill.
     *
     * Pharmacy expiry is usually `MM/YY` or `MM-YYYY` with no day at all,
     * which `strtotime` reads as a day-in-the-current-month. An expiry is
     * the *end* of its month, so a short form is expanded deliberately
     * rather than guessed.
     */
    private function date(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return null;
        }

        // MM/YY or MM/YYYY — the common pharmacy expiry form.
        if (preg_match('#^(\d{1,2})[/\-.](\d{2}|\d{4})$#', $text, $m)) {
            $month = (int) $m[1];
            $year = (int) $m[2];

            if ($month < 1 || $month > 12) {
                return null;
            }

            if ($year < 100) {
                $year += 2000;
            }

            return date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
        }

        $timestamp = strtotime($text);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
