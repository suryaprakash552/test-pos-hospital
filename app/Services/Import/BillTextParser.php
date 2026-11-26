<?php

namespace App\Services\Import;

/**
 * Reads the text of a supplier's bill into rows.
 *
 * Shared by the PDF reader and the photographed-bill reader, because once the
 * text is out of the document they face the same problem, and it is not the one
 * the CSV parser faces: there are no columns. A PDF is a bag of positioned
 * glyphs and a photograph is a picture, so in neither case is there a header to
 * match a column against.
 *
 * What both do have is *shape*. A bill line is a description followed by a run
 * of numbers, in every layout, whatever the supplier calls the columns. A line
 * without that shape is a header, an address, a total or a footer.
 *
 * ## What this deliberately does not do
 *
 * It does not try to be certain. Every row goes to the same review screen as a
 * CSV import, with the product unmatched until someone confirms it, because a
 * misread rate is real money and a misread quantity is real stock. Extraction
 * that is 80% right and reviewed beats extraction that claims to be 100% right
 * and is not.
 */
class BillTextParser
{
    /** Matches the CSV parser: a cap so a pathological file cannot wedge a worker. */
    public const MAX_ROWS = 5000;

    /**
     * One amount: digits, with an optional currency mark glued to the front.
     *
     * A rate or total is almost never printed as bare digits — ₹, $, Rs. and a
     * bare `?` (what a PDF's own font falls back to for a rupee glyph it has no
     * mapping for; this exact document does it) all sit directly against the
     * number with no separating space. Requiring pure digits here is why a
     * genuine bill line failed to match at all: "1 CBC TEST 1 ?200 ?200" has
     * only one token, `1`, that is actually bare digits — the tail needs two or
     * three. `\p{Sc}` is Unicode's currency-symbol category, so this covers
     * ₹/$/€/£ without enumerating them; `?` is added explicitly because it is
     * not itself a currency symbol, only this rendering path's stand-in for one.
     */
    private const NUMBER = '[\p{Sc}?]?[\d,]+(?:\.\d+)?';

    /**
     * A cell with nothing in it, in the convention several bill layouts use for
     * "zero" or "not applicable" — a bare dash, most often in a discount column.
     *
     * Real bill, real failure: "DOLO SYP 60ML ... RATE 57.11 DIS % - AMT 57.11"
     * did not match at all, because the dash sits between two real numbers in
     * the trailing run this pattern expects to be nothing but numbers. The row
     * plainly has its three figures — qty, rate, amount — the dash is just the
     * fourth column saying there is no discount on this line.
     */
    private const EMPTY_CELL = '-{1,2}';

    private const NUMBER_OR_EMPTY = '(?:'.self::NUMBER.'|'.self::EMPTY_CELL.')';

    /**
     * The tail of a product row: a run of numbers, optionally with a batch and
     * an expiry among them. Anchored to the end of the line because that is
     * where the figures sit in every bill layout.
     *
     * A dash-for-empty cell may appear anywhere in this run without breaking
     * it, but the run must still contain real numbers — `rowFrom()` checks
     * that separately, so a line of nothing but dashes (which is not evidence
     * of a product row at all) is not mistaken for one just because it is
     * three cells long.
     */
    private const ROW_TAIL = '/^(?P<description>.*?)\s+(?P<numbers>(?:'.self::NUMBER_OR_EMPTY.'\s+){2,}'.self::NUMBER_OR_EMPTY.')\s*$/u';

    /** @return array<int, array<string, mixed>> */
    public function parse(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $rows = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }

            $row = $this->rowFrom($line);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $this->withoutSerialNumbers($rows);
    }

    /**
     * Drops the "Sr." column when the bill has one.
     *
     * A serial number in its own cell arrives glued to the item — "1 Product 25"
     * — and a product that plainly exists then matches nothing. Stripping any
     * leading integer would be worse: "5 ML SYRINGE" and "500 MG TAB" are names
     * that begin with a number, and cutting it changes what was bought.
     *
     * So it is decided over the whole document rather than per line: if every
     * row starts with an integer and those integers count up, that is a serial
     * column. Two rows are enough to see it; one row is not a sequence and is
     * left alone.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function withoutSerialNumbers(array $rows): array
    {
        if (count($rows) < 2) {
            return $rows;
        }

        $serials = [];

        foreach ($rows as $row) {
            if (! preg_match('/^(\d{1,4})\s+(\p{L}.*)$/u', (string) $row['product_name'], $m)) {
                return $rows;
            }

            $serials[] = (int) $m[1];
        }

        // Each exactly one more than the last — a count, not merely an ascent.
        //
        // Ascending alone is not enough: "5 ML SYRINGE" followed by "500 MG
        // PARACETAMOL" ascends, and stripping those numbers changes what was
        // bought. A bill that skips a serial keeps its numbers, which a reviewer
        // can edit; a strength that gets cut off a medicine name is a silent
        // wrong order.
        foreach ($serials as $index => $serial) {
            if ($index > 0 && $serial !== $serials[$index - 1] + 1) {
                return $rows;
            }
        }

        return array_map(function (array $row) {
            $row['product_name'] = trim(
                preg_replace('/^\d{1,4}\s+/u', '', (string) $row['product_name']) ?? $row['product_name']
            );

            return $row;
        }, $rows);
    }

    /**
     * One line of text, if it has the shape of a product row.
     *
     * @return array<string, mixed>|null
     */
    private function rowFrom(string $line): ?array
    {
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);

        if ($line === '' || mb_strlen($line) < 6) {
            return null;
        }

        if (! preg_match(self::ROW_TAIL, $line, $matches)) {
            return null;
        }

        $description = trim($matches['description']);

        // A row has to name something. A line of bare figures is a totals row.
        if ($description === '' || ! preg_match('/\p{L}{3,}/u', $description)) {
            return null;
        }

        // Totals, taxes and carried-forward lines have the same shape as a
        // product row and are not goods.
        if ($this->isSummaryLine($description)) {
            return null;
        }

        // The currency mark that let a token count as a number in the first
        // place is not part of the number itself — `quantity`/`purchase_price`
        // go to a numeric field on the review screen, and "?200" there is not
        // an amount a person can edit, it is a string that happens to start
        // with one.
        $numbers = array_map(
            static fn (string $n): string => preg_replace('/^[\p{Sc}?]/u', '', $n) ?? $n,
            preg_split('/\s+/', trim($matches['numbers'])) ?: [],
        );

        // An empty cell (see EMPTY_CELL) is not itself evidence of a product
        // row — only the real numbers around it are — so a line of nothing
        // but dashes must not pass just because it is long enough.
        $realNumbers = array_filter(
            $numbers,
            static fn (string $n): bool => preg_match('/\d/', $n) === 1,
        );
        if (count($realNumbers) < 2) {
            return null;
        }

        // Positional extraction below reads specific numbers by index, so a
        // literal "-" there must read as "no value", not as the string a
        // reviewer would then see sitting in a numeric field.
        $numbers = array_map(
            static fn (string $n): ?string => preg_match('/\d/', $n) === 1 ? $n : null,
            $numbers,
        );

        // Leading serial numbers are common and are not a quantity.
        $description = preg_replace('/^\d+[.)]\s*/', '', $description) ?? $description;

        // A batch code is alphanumeric and sits inside the description in most
        // layouts; pulled out when it is obviously one so the reviewer does not
        // have to retype it.
        //
        // Three digits, not two, because a name like "VITAMIN B12" otherwise
        // reads as a batch — and a wrongly-detected batch is worse than a
        // missing one: it is also cut out of the product name, which is what
        // the matcher searches on.
        $batch = null;
        if (preg_match('/\b([A-Z]{1,4}\d{3,}[A-Z0-9]*)\b/u', $description, $b)) {
            $batch = $b[1];
        }

        $expiry = null;
        if (preg_match('#\b(\d{1,2}[/\-]\d{2,4})\b#', $description, $e)) {
            $expiry = $e[1];
        }

        // Whatever was recognised is taken out of the name. The matcher looks a
        // product up by name, and "AMOXYCILLIN 500MG AMX2401 08/27" matches
        // nothing.
        foreach (array_filter([$batch, $expiry]) as $token) {
            $description = str_replace($token, ' ', $description);
        }

        $description = trim(preg_replace('/\s+/u', ' ', $description) ?? $description);

        return [
            'product_name' => $description,
            'batch_no' => $batch,
            // Positional, and stated as a guess: the first figure on a bill line
            // is the quantity far more often than not, and the reviewer sees and
            // corrects it. `raw` keeps the whole line either way.
            'quantity' => $numbers[0] ?? null,
            'purchase_price' => $numbers[1] ?? null,
            'mrp' => $numbers[2] ?? null,
            'expire_date' => $expiry,
            'source_line' => $line,
        ];
    }

    private function isSummaryLine(string $description): bool
    {
        $needle = mb_strtolower($description);

        foreach ([
            'total', 'subtotal', 'sub total', 'grand', 'amount in words',
            'gst', 'cgst', 'sgst', 'igst', 'tax', 'round', 'balance',
            'carried', 'brought forward', 'discount', 'net payable', 'page',
        ] as $word) {
            if (str_contains($needle, $word)) {
                return true;
            }
        }

        return false;
    }
}
