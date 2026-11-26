<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A receipt must show what the customer was charged.
 *
 * The sale slip rebuilt its own grand total from the parts, adding
 * `$sale->tax_amount` - which is not a column on `sales` and therefore resolved to
 * null. Every printed total came out short by exactly the tax. The PDF invoice had
 * a second fault of the same kind: it *added* the discount to an already-gross
 * subtotal, printing more than was charged.
 *
 * Both now print the stored `totalAmount`. These read the templates rather than
 * rendering them: rendering needs a full sale graph, a business and a logged-in
 * user, while the property being protected - that the total is not re-derived in a
 * view - is a source-level one.
 */
class PrintedTotalsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function provideMoneyTemplates(): array
    {
        $paths = [
            'sale slip' => 'Modules/ThermalPrinterAddon/resources/views/sales/3_inch_80mm.blade.php',
            'purchase slip' => 'Modules/ThermalPrinterAddon/resources/views/purchases/3_inch_80mm.blade.php',
            'sale pdf' => 'Modules/Business/resources/views/sales/pdf.blade.php',
            'sale invoice' => 'Modules/Business/resources/views/sales/invoice.blade.php',
        ];

        return array_map(fn ($path) => [$path], $paths);
    }

    /** @dataProvider provideMoneyTemplates */
    public function test_a_document_does_not_rebuild_its_own_total(string $path): void
    {
        $source = $this->executableSource($path);

        // `$subtotal + $something` in a printed document means the total is being
        // derived in the view instead of read from the record.
        $this->assertDoesNotMatchRegularExpression(
            '/\$subtotal\s*\+\s*\$/',
            $source,
            "{$path} rebuilds a total from its parts. Print the stored "
                .'`totalAmount` instead, so the document cannot disagree with the '
                .'amount that was actually charged.',
        );
    }

    /**
     * The template with its Blade comments removed.
     *
     * The comments explaining these very faults name the offending column, so
     * scanning the raw source would match the explanation rather than any code.
     */
    private function executableSource(string $path): string
    {
        $source = (string) file_get_contents(base_path($path));

        return preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? $source;
    }

    /** @dataProvider provideMoneyTemplates */
    public function test_a_document_does_not_read_a_column_that_does_not_exist(string $path): void
    {
        $source = $this->executableSource($path);

        // `tax_amount` is not a column on `sales` or `purchases`, and there is no
        // accessor for it - so it silently reads as null and drops out of any sum.
        $this->assertStringNotContainsString(
            'tax_amount',
            $source,
            "{$path} reads `tax_amount`, which does not exist. The column is "
                .'`vat_amount`; reading the wrong name yields null and silently '
                .'removes the tax from whatever it is added to.',
        );
    }
}
