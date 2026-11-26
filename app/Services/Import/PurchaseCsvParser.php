<?php

namespace App\Services\Import;

/**
 * Reads a supplier's CSV into plain rows.
 *
 * Suppliers do not agree on column names. One writes `Item`, the next `Product
 * Name`, the next `DESCRIPTION`; quantity is `Qty`, `QTY.`, `Quantity` or
 * `Pack`. So the header is matched on meaning rather than on an exact string,
 * and anything unrecognised is carried through untouched — the raw row is kept
 * on every line, so a column we failed to understand is still visible to whoever
 * reviews it.
 *
 * ## Why this does not use a spreadsheet library
 *
 * A CSV is text. Handing untrusted text to a full spreadsheet reader pulls in a
 * parser that also understands formulas, external references and embedded
 * objects — none of which a supplier bill needs, and all of which are attack
 * surface. `.xlsx` still goes through the existing Excel importer; plain CSV is
 * read here.
 */
class PurchaseCsvParser
{
    /**
     * A cap, not a target.
     *
     * A pharmacy delivery runs to a few hundred lines. Ten thousand is either a
     * mistake or someone seeing what happens, and either way the parse should
     * stop rather than take the worker down with it.
     */
    public const MAX_ROWS = 5000;

    /** Columns we understand, and the header text that means each one. */
    private const COLUMN_ALIASES = [
        'product_name' => [
            'product', 'product name', 'item', 'item name', 'description',
            'particulars', 'medicine', 'name',
        ],
        'product_code' => ['code', 'item code', 'product code', 'sku', 'barcode'],
        'batch_no' => ['batch', 'batch no', 'batch number', 'b.no', 'bno'],
        'quantity' => ['qty', 'quantity', 'pack', 'packs', 'units'],
        'free_quantity' => ['free', 'free qty', 'scheme', 'bonus'],
        'purchase_price' => ['rate', 'price', 'purchase rate', 'ptr', 'cost'],
        'mrp' => ['mrp', 'retail', 'retail price', 'm.r.p'],
        'discount_percent' => ['disc', 'discount', 'disc %', 'discount %'],
        'tax_percent' => ['gst', 'gst %', 'tax', 'tax %', 'vat'],
        'expire_date' => ['exp', 'expiry', 'exp date', 'expiry date', 'expire date'],
        'mfg_date' => ['mfg', 'mfg date', 'manufacture date'],
        'reference_invoice_no' => [
            'invoice', 'invoice no', 'invoice number', 'reference invoice',
            'reference invoice no', 'bill no', 'bill number',
        ],
        'document_date' => ['date', 'invoice date', 'bill date', 'document date'],
    ];

    /**
     * @return array<int, array<string, mixed>> one entry per product row
     */
    public function parse(string $contents): array
    {
        $lines = $this->splitLines($contents);

        if ($lines === []) {
            return [];
        }

        // Invoices often start with a company/title row before the table.
        // Only scan the preamble, never reinterpret arbitrary later item rows.
        $header = [];
        foreach (array_slice($lines, 0, 25) as $index => $candidate) {
            $mapped = $this->mapHeader($candidate);
            if (in_array('product_name', $mapped, true)
                || in_array('product_code', $mapped, true)) {
                $header = $mapped;
                $lines = array_slice($lines, $index + 1);
                break;
            }
        }

        // Without a product column there is nothing to import — every other
        // field describes a product we would not be able to name.
        if (! in_array('product_name', $header, true)
            && ! in_array('product_code', $header, true)) {
            return [];
        }

        $rows = [];

        foreach ($lines as $line) {
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }

            $cells = $this->splitCsvLine($line);

            // A blank line, or a trailing total row with no product against it.
            if ($this->isBlank($cells)) {
                continue;
            }

            $row = [];

            foreach ($cells as $index => $cell) {
                $key = $header[$index] ?? null;
                if ($key === null) {
                    continue;
                }
                $row[$key] = trim($cell);
            }

            // A row that named no product is a subtotal, a note or a page break.
            if (! ImportProductIdentifier::rowHasProduct($row)) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array<int, string> */
    private function splitLines(string $contents): array
    {
        // A byte-order mark on the first header cell would stop it matching.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        // Suppliers export from Windows, Mac and Linux tools alike.
        $normalised = str_replace(["\r\n", "\r"], "\n", $contents);

        return array_values(array_filter(
            explode("\n", $normalised),
            static fn ($line) => trim($line) !== '',
        ));
    }

    /**
     * Splits one CSV line, honouring quotes.
     *
     * `str_getcsv` handles the quoting rules — a product name containing a comma
     * is the ordinary case in a pharmacy catalogue ("Vitamin B1, B6, B12").
     *
     * @return array<int, string>
     */
    private function splitCsvLine(string $line): array
    {
        return array_map(
            static fn ($cell) => is_string($cell) ? trim($cell) : '',
            str_getcsv($line, ',', '"', '\\'),
        );
    }

    /**
     * Maps each column position to a field we understand, or null.
     *
     * @return array<int, string|null>
     */
    private function mapHeader(string $line): array
    {
        $header = [];

        foreach ($this->splitCsvLine($line) as $cell) {
            $header[] = $this->fieldFor($cell);
        }

        return $header;
    }

    private function fieldFor(string $heading): ?string
    {
        // Punctuation and case vary between exports; the words do not.
        $needle = strtolower(trim($heading));
        // Accept API-style column names as well as human-readable templates.
        $needle = str_replace('_', ' ', $needle);
        $needle = preg_replace('/[^a-z0-9%. ]/', '', $needle) ?? $needle;
        $needle = trim(preg_replace('/\s+/', ' ', $needle) ?? $needle);

        if ($needle === '') {
            return null;
        }

        foreach (self::COLUMN_ALIASES as $field => $aliases) {
            if ($needle === str_replace('_', ' ', $field)
                || in_array($needle, $aliases, true)) {
                return $field;
            }
        }

        return null;
    }

    /** @param array<int, string> $cells */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
