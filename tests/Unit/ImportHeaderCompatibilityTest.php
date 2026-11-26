<?php

namespace Tests\Unit;

use App\Services\Import\PurchaseCsvParser;
use PHPUnit\Framework\TestCase;

class ImportHeaderCompatibilityTest extends TestCase
{
    public function test_preamble_and_template_headers_are_supported(): void
    {
        $rows = (new PurchaseCsvParser)->parse("Supplier invoice\nProduct Name,Quantity,Rate,MRP\nAmox,2,10,15\n");
        $this->assertCount(1, $rows);
        $this->assertSame('Amox', $rows[0]['product_name']);
        $this->assertSame('10', $rows[0]['purchase_price']);
    }

    public function test_canonical_field_names_are_supported(): void
    {
        $rows = (new PurchaseCsvParser)->parse("product_name,quantity,purchase_price,expire_date\nAmox,2,10,2028-01-31\n");
        $this->assertSame('2028-01-31', $rows[0]['expire_date']);
        $this->assertSame('2', $rows[0]['quantity']);
    }
}
