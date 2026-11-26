<?php

namespace Tests\Unit;

use App\Services\Import\ImportProductIdentifier;
use App\Services\Import\PurchaseCsvParser;
use PHPUnit\Framework\TestCase;

class ImportProductIdentifierTest extends TestCase
{
    public function test_invisible_spreadsheet_cells_do_not_create_product_rows(): void
    {
        $this->assertFalse(ImportProductIdentifier::rowHasProduct([
            'product_name' => "\u{00A0}\u{200B}",
            'product_code' => "\u{FEFF}",
            'quantity' => '1987.94',
        ]));

        $rows = (new PurchaseCsvParser)->parse(
            "Product Name,Product Code,Quantity\n"
            ."Medicine A,,2\n"
            ."\u{00A0}\u{200B},,1987.94\n"
            .",ABC123,1\n"
        );

        $this->assertCount(2, $rows);
        $this->assertSame('Medicine A', $rows[0]['product_name']);
        $this->assertSame('ABC123', $rows[1]['product_code']);
    }
}
