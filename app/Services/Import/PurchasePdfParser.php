<?php

namespace App\Services\Import;

use JsonException;
use Smalot\PdfParser\Parser as PdfReader;
use Throwable;

/**
 * Reads a supplier's PDF bill.
 *
 * Only the extraction is PDF-specific — turning the extracted text into rows is
 * the same problem a photographed bill poses, and lives in [BillTextParser].
 */
class PurchasePdfParser
{
    public function __construct(private readonly BillTextParser $lines = new BillTextParser())
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function parse(string $contents): array
    {
        // Checked before parsing rather than after failing, so a file that is
        // not a PDF is refused with something a shop can act on instead of a
        // library exception.
        if (! self::looksLikePdf($contents)) {
            throw new \RuntimeException('That file is not a PDF.');
        }

        return $this->parseExtractedText($this->extractText($contents));
    }

    /**
     * Parse text already extracted from a PDF.
     *
     * Most invoice generators expose one visual row as one text line. Some
     * (including Localwell's A4 export) expose the table column-by-column:
     * DESCRIPTION, every description cell, PACK, every pack cell, and so on.
     * The ordinary line parser cannot infer a row from those isolated cells,
     * so use the labelled columns as a lossless fallback.
     *
     * Public mainly so a regression test can use the exact text emitted by
     * Smalot without checking a customer's invoice into source control.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseExtractedText(string $text): array
    {
        $structured = $this->parseStructuredRows($text);
        if ($structured !== []) {
            return $structured;
        }

        $rows = $this->lines->parse($text);

        return $rows !== [] ? $rows : $this->parseLabelledColumns($text);
    }

    /**
     * Read the versioned interchange rows emitted invisibly by our invoices.
     * The payload is URL-safe base64 JSON, so product names cannot break the
     * record with spaces, pipes or punctuation and PDF extractors see one token.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseStructuredRows(string $text): array
    {
        preg_match_all('/POSIMPORT1:([A-Za-z0-9_-]+)/', $text, $matches);
        $rows = [];

        foreach ($matches[1] ?? [] as $encoded) {
            $padding = (4 - strlen($encoded) % 4) % 4;
            $decoded = base64_decode(strtr($encoded.str_repeat('=', $padding), '-_', '+/'), true);
            if ($decoded === false) {
                continue;
            }

            try {
                $row = json_decode($decoded, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (! is_array($row) || trim((string) ($row['product_name'] ?? '')) === '') {
                continue;
            }

            $row['source_line'] = 'POSIMPORT1';
            $rows[] = $row;
        }

        return array_slice($rows, 0, BillTextParser::MAX_ROWS);
    }

    public static function looksLikePdf(string $contents): bool
    {
        return str_starts_with($contents, '%PDF-');
    }

    private function extractText(string $contents): string
    {
        try {
            return (new PdfReader())->parseContent($contents)->getText();
        } catch (Throwable $e) {
            throw new \RuntimeException(
                'This PDF could not be read. If it is a scan rather than a '
                .'digital bill, image recognition is not available yet. Use a text PDF, Excel or CSV.',
                previous: $e,
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function parseLabelledColumns(string $text): array
    {
        $cells = array_values(array_filter(array_map(
            static fn (string $line): string => trim(str_replace("\u{00A0}", ' ', $line)),
            preg_split('/\R/u', $text) ?: [],
        ), static fn (string $line): bool => $line !== ''));

        $aliases = [
            'DESCRIPTION' => 'product_name',
            'ITEM NAME' => 'product_name',
            'PRODUCT NAME' => 'product_name',
            'PACK' => 'pack',
            'COMP' => 'company',
            'COMPANY' => 'company',
            'SCH' => 'schedule',
            'RACK' => 'rack',
            'HSN' => 'hsn',
            'BATCH' => 'batch_no',
            'EXP' => 'expire_date',
            'EXPIRY' => 'expire_date',
            'QTY' => 'quantity',
            'QUANTITY' => 'quantity',
            'MRP' => 'mrp',
            'RATE' => 'purchase_price',
            'PRICE' => 'purchase_price',
            'DIS %' => 'discount',
            'DISCOUNT' => 'discount',
            'AMT' => 'amount',
            'AMOUNT' => 'amount',
        ];

        $headers = [];
        foreach ($cells as $index => $cell) {
            $key = strtoupper(preg_replace('/\s+/u', ' ', $cell) ?? $cell);
            if (isset($aliases[$key])) {
                $headers[] = [$index, $aliases[$key]];
            }
        }

        $descriptionHeader = null;
        foreach ($headers as $position => [, $field]) {
            if ($field === 'product_name') {
                $descriptionHeader = $position;
                break;
            }
        }
        if ($descriptionHeader === null) {
            return [];
        }

        // Stop at AMT/AMOUNT. Labels in the totals area must not be mistaken
        // for another table (for example "ROUND OFF" and "PAID/DUE").
        $columns = [];
        for ($i = $descriptionHeader; $i < count($headers); $i++) {
            [$start, $field] = $headers[$i];
            $end = $headers[$i + 1][0] ?? count($cells);
            $values = array_slice($cells, $start + 1, $end - $start - 1);
            $columns[$field] = $values;
            if ($field === 'amount') {
                break;
            }
        }

        $names = $columns['product_name'] ?? [];
        if ($names === []) {
            return [];
        }

        $rows = [];
        foreach ($names as $index => $name) {
            if (! preg_match('/\p{L}{3,}/u', $name)) {
                continue;
            }

            $value = static function (string $field) use ($columns, $index): ?string {
                $raw = trim((string) ($columns[$field][$index] ?? ''));
                return $raw === '' || preg_match('/^-{1,2}$/', $raw) ? null : $raw;
            };

            $numeric = static function (?string $raw): ?string {
                if ($raw === null) return null;
                $clean = preg_replace('/[^\d.\-]/', '', str_replace(',', '', $raw));
                return $clean !== '' && is_numeric($clean) ? $clean : null;
            };

            $rows[] = [
                'product_name' => trim($name),
                'batch_no' => $value('batch_no'),
                'quantity' => $numeric($value('quantity')),
                // The supplier's RATE is the purchase price; MRP remains the
                // maximum retail price regardless of their visual order.
                'purchase_price' => $numeric($value('purchase_price')),
                'mrp' => $numeric($value('mrp')),
                'expire_date' => $value('expire_date'),
                'source_line' => implode(' | ', array_filter([
                    $name,
                    $value('batch_no'),
                    $value('expire_date'),
                    $value('quantity'),
                    $value('purchase_price'),
                    $value('mrp'),
                ], static fn ($part): bool => $part !== null && $part !== '')),
            ];
        }

        return array_slice($rows, 0, BillTextParser::MAX_ROWS);
    }
}
