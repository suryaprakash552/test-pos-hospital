<?php

namespace Tests\Feature\Api;

use App\Imports\ProductImport;
use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\Product;
use App\Services\GstPricingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * §15: what an imported product inherits, and what the sheet is not allowed to get
 * wrong.
 *
 * Before this, a blank tax-applicability column meant "exclusive" — so importing into
 * an inclusive-pricing shop silently added tax on top of prices that already contained
 * it, across the whole catalogue at once. Bad HSN codes were quietly discarded, which
 * surfaces only when a return is filed months later.
 */
class ProductImportGstTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeBusiness(GstPricingMethod::INCLUDED);
    }

    private function makeBusiness(?string $method): void
    {
        $category = BusinessCategory::factory()->create([
            'name'             => 'Pharmacy ' . uniqid(),
            'default_vat_type' => $method,
        ]);

        $this->business = Business::factory()->create([
            'business_category_id' => $category->id,
            'default_vat_type'     => null,
        ]);
    }

    /**
     * A row in the import template's column order. Columns past 17 were appended so
     * an older sheet keeps working.
     */
    private function row(array $overrides = []): array
    {
        $row = [
            0  => 'Paracetamol',
            1  => 'Medicines',
            2  => 'Box',
            3  => 'Acme',
            4  => 10,      // stock
            5  => 'P-001', // code
            6  => 40,      // purchase price
            7  => 60,      // sale price
            8  => 55,
            9  => 50,
            10 => 'GST18', // vat name
            11 => 18,      // vat percent
            12 => '',      // tax applicability — blank on purpose
            13 => 5,
            14 => null,
            15 => 'B1',
            16 => 'Model',
            17 => null,
            18 => '',      // hsn
            19 => 0,       // cess
        ];

        return array_replace($row, $overrides);
    }

    private function import(array $rows): ProductImport
    {
        $import = new ProductImport($this->business->id);

        $sheet = collect([
            array_fill(0, 20, 'header'),
            ...$rows,
        ])->map(fn ($row) => collect($row));

        // The header is only skipped when its first cell says so.
        $sheet[0] = collect(array_replace(array_fill(0, 20, ''), [0 => 'Product Name']));

        $import->collection(new Collection($sheet->all()));

        return $import;
    }

    public function test_a_blank_method_inherits_the_shops(): void
    {
        $this->import([$this->row()]);

        $this->assertSame(
            GstPricingMethod::INCLUDED,
            Product::where('productCode', 'P-001')->value('vat_type'),
        );
    }

    public function test_a_stated_method_wins_over_the_shops(): void
    {
        $this->import([$this->row([12 => GstPricingMethod::EXCLUDED])]);

        $this->assertSame(
            GstPricingMethod::EXCLUDED,
            Product::where('productCode', 'P-001')->value('vat_type'),
        );
    }

    public function test_an_unknown_method_is_rejected(): void
    {
        $import = $this->import([$this->row([12 => 'sometimes'])]);

        $this->assertDatabaseMissing('products', ['productCode' => 'P-001']);
        $this->assertStringContainsString('not a valid GST pricing method', $import->errors()[0]);
    }

    /** §15: an invalid GST rate is an error, not a silent zero. */
    public function test_an_impossible_rate_is_rejected(): void
    {
        $import = $this->import([$this->row([11 => 180])]);

        $this->assertDatabaseMissing('products', ['productCode' => 'P-001']);
        $this->assertStringContainsString('not a valid GST rate', $import->errors()[0]);
    }

    public function test_a_negative_rate_is_rejected(): void
    {
        $this->import([$this->row([11 => -5])]);

        $this->assertDatabaseMissing('products', ['productCode' => 'P-001']);
    }

    public function test_a_valid_hsn_is_stored(): void
    {
        $this->import([$this->row([18 => '30049099'])]);

        $this->assertSame(
            '30049099',
            Product::where('productCode', 'P-001')->value('hsn_code'),
        );
    }

    /**
     * Previously discarded without a word, which surfaces at filing time — months
     * later, and far from the file that caused it.
     */
    public function test_a_malformed_hsn_is_reported_not_discarded(): void
    {
        $import = $this->import([$this->row([18 => '30-49'])]);

        $this->assertDatabaseMissing('products', ['productCode' => 'P-001']);
        $this->assertStringContainsString('not a valid HSN/SAC code', $import->errors()[0]);
    }

    public function test_no_hsn_is_still_importable(): void
    {
        // Not every shop files HSN-wise, and requiring it would block bulk entry.
        $this->import([$this->row()]);

        $product = Product::where('productCode', 'P-001')->first();

        $this->assertNotNull($product);
        $this->assertNull($product->hsn_code);
    }

    /** §6: an out-of-scope shop charges nothing, whatever the sheet says. */
    public function test_a_not_applicable_shop_charges_no_tax(): void
    {
        $this->makeBusiness(GstPricingMethod::NOT_APPLICABLE);

        $this->import([$this->row()]);

        $product = Product::where('productCode', 'P-001')->first();

        $this->assertSame(GstPricingMethod::NOT_APPLICABLE, $product->vat_type);
        $this->assertEquals(0, $product->vat_amount);
    }

    /** One bad row must not cost the good ones. */
    public function test_good_rows_survive_a_bad_one(): void
    {
        $import = $this->import([
            $this->row([5 => 'P-001']),
            $this->row([5 => 'P-002', 11 => 999]),
            $this->row([5 => 'P-003']),
        ]);

        $this->assertDatabaseHas('products', ['productCode' => 'P-001']);
        $this->assertDatabaseMissing('products', ['productCode' => 'P-002']);
        $this->assertDatabaseHas('products', ['productCode' => 'P-003']);

        $this->assertSame(2, $import->importedCount());
        $this->assertCount(1, $import->errors());
    }
}
