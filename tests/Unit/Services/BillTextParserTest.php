<?php

namespace Tests\Unit\Services;

use App\Services\Import\BillTextParser;
use App\Services\Import\PurchasePdfParser;
use PHPUnit\Framework\TestCase;

/**
 * Reported: importing a real sales invoice PDF as a supplier bill came back
 * "No product rows were found," and a photographed bill "No product rows
 * could be read." Both share this parser.
 *
 * Root cause, confirmed against the actual PDF: `smalot/pdfparser` extracted
 * a genuine product line as `1 CBC TEST\t1 ?200\t?200` — the `?` is this
 * document's own font falling back for a rupee glyph it has no mapping for.
 * The row-tail regex required a run of *bare* digit groups, and a price is
 * essentially never printed bare — it carries ₹, $, Rs. or, as here, whatever
 * a font substitutes when it can't render the real symbol. So every genuine
 * product line failed to match at all.
 */
class BillTextParserTest extends TestCase
{
    public function test_localwell_columnar_pdf_text_is_reassembled_into_a_product_row(): void
    {
        $text = "BILL OF SUPPLY\n"
            ."1\nDESCRIPTION\nDOLO SYP 60ML\nPACK\n1'S\nCOMP\nMICRO\n"
            ."SCH\nH\nRACK\n-\nHSN\n3004\nBATCH\nDRSL431\n"
            ."EXP\n05/28\nQTY\n1\nMRP\n57.11\nRATE\n57.11\n"
            ."DIS %\n-\nAMT\n57.11\nREMARKS\nSUBTOTAL\n57.11";

        $rows = (new PurchasePdfParser())->parseExtractedText($text);

        $this->assertSame([[
            'product_name' => 'DOLO SYP 60ML',
            'batch_no' => 'DRSL431',
            'quantity' => '1',
            'purchase_price' => '57.11',
            'mrp' => '57.11',
            'expire_date' => '05/28',
            'source_line' => 'DOLO SYP 60ML | DRSL431 | 05/28 | 1 | 57.11 | 57.11',
        ]], $rows);
    }

    public function test_the_actual_failing_invoice_line_is_now_read(): void
    {
        // Exactly what Smalot\PdfParser extracted from the real S1558 PDF.
        $rows = (new BillTextParser())->parse(
            "SL\tItem\tQuantity Unit Price Total Price\n"
            ."1 CBC TEST\t1 ?200\t?200\n"
            ."2 UPT TEST\t1 ?100\t?100\n"
        );

        $this->assertCount(2, $rows);

        $this->assertSame('CBC TEST', $rows[0]['product_name']);
        $this->assertSame('1', $rows[0]['quantity']);
        $this->assertSame('200', $rows[0]['purchase_price']);
        $this->assertSame('200', $rows[0]['mrp']);

        $this->assertSame('UPT TEST', $rows[1]['product_name']);
        $this->assertSame('100', $rows[1]['purchase_price']);
    }

    public function test_a_rupee_sign_glued_to_the_amount_is_read(): void
    {
        $rows = (new BillTextParser())->parse('Paracetamol 500mg  10  ₹45.50  ₹60');

        $this->assertCount(1, $rows);
        $this->assertSame('45.50', $rows[0]['purchase_price']);
        $this->assertSame('60', $rows[0]['mrp']);
    }

    public function test_a_dollar_sign_is_read_too(): void
    {
        $rows = (new BillTextParser())->parse('Bandage Roll  20  $2.50  $3.00');

        $this->assertCount(1, $rows);
        $this->assertSame('2.50', $rows[0]['purchase_price']);
    }

    public function test_a_bare_number_still_reads_as_before(): void
    {
        $rows = (new BillTextParser())->parse('Amoxycillin 500mg  10  45.50  60');

        $this->assertCount(1, $rows);
        $this->assertSame('45.50', $rows[0]['purchase_price']);
    }

    public function test_a_line_with_no_number_at_all_is_not_a_row(): void
    {
        $rows = (new BillTextParser())->parse('Thank you for your business');

        $this->assertSame([], $rows);
    }

    /**
     * Reported from a real bill (Localwell software, "BILL OF SUPPLY"):
     * "DOLO SYP 60ML ... QTY 1 MRP 57.11 RATE 57.11 DIS % - AMT 57.11" -
     * a bare dash for "no discount" sits between two real numbers, in the
     * exact run of trailing figures this parser expects to be nothing but
     * numbers. The row has its three real figures - qty, rate, amount - the
     * dash is just a fourth column saying there is no discount on this line.
     */
    public function test_a_dash_for_an_empty_discount_column_does_not_break_the_row(): void
    {
        $rows = (new BillTextParser())->parse(
            'DOLO SYP 60ML 1 57.11 57.11 - 57.11'
        );

        $this->assertCount(1, $rows);
        $this->assertSame('1', $rows[0]['quantity']);
        $this->assertSame('57.11', $rows[0]['purchase_price']);
        $this->assertSame('57.11', $rows[0]['mrp']);
    }

    /** The dash itself must never land in a numeric field as literal text. */
    public function test_a_dash_that_lands_in_a_read_position_becomes_null_not_text(): void
    {
        $rows = (new BillTextParser())->parse(
            'Paracetamol 500mg 10 - 60'
        );

        $this->assertCount(1, $rows);
        $this->assertSame('10', $rows[0]['quantity']);
        $this->assertNull($rows[0]['purchase_price']);
        $this->assertSame('60', $rows[0]['mrp']);
    }

    /** A line that is dashes standing in for numbers is not evidence of a row. */
    public function test_a_line_of_only_dashes_is_not_mistaken_for_a_row(): void
    {
        $rows = (new BillTextParser())->parse('Some heading text - - -');

        $this->assertSame([], $rows);
    }

    /** One real number plus dashes still is not enough evidence. */
    public function test_one_real_number_among_dashes_is_still_not_a_row(): void
    {
        $rows = (new BillTextParser())->parse('Page footer note 5 - -');

        $this->assertSame([], $rows);
    }
}
