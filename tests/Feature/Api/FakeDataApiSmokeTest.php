<?php

namespace Tests\Feature\Api;

use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Doctor;
use App\Models\ExpenseCategory;
use App\Models\IncomeCategory;
use App\Models\Manufacturer;
use App\Models\Party;
use App\Models\PaymentType;
use App\Models\Product;
use App\Models\ProductModel;
use App\Models\Purchase;
use App\Models\Rack;
use App\Models\Relationship;
use App\Models\Sale;
use App\Models\Shelf;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vat;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FakeDataApiSmokeTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Product $product;
    private Stock $stock;
    private Purchase $purchase;
    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedFakePharmacyTenant();
    }

    public function test_authenticated_read_apis_handle_fake_tenant_data_without_server_errors(): void
    {
        foreach ($this->readEndpoints() as $path) {
            $response = $this->actingAs($this->user)->getJson($path);

            $this->assertApiDidNotCrash($response, 'GET', $path);
        }
    }

    public function test_authenticated_mutation_apis_validate_or_accept_fake_payloads_without_server_errors(): void
    {
        foreach ($this->mutationEndpoints() as $method => $requests) {
            foreach ($requests as $path => $payload) {
                $response = $this->actingAs($this->user)->json($method, $path, $payload);

                $this->assertApiDidNotCrash($response, $method, $path);
            }
        }
    }

    private function seedFakePharmacyTenant(): void
    {
        $this->business = Business::factory()->create([
            'companyName' => 'Smoke Care Pharmacy',
        ]);

        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'owner',
        ]);

        $this->actingAs($this->user);

        $unit = Unit::factory()->create(['business_id' => $this->business->id]);
        $brand = Brand::factory()->create(['business_id' => $this->business->id]);
        $manufacturer = Manufacturer::factory()->create(['business_id' => $this->business->id]);
        $category = Category::factory()->create(['business_id' => $this->business->id]);
        $warehouse = Warehouse::factory()->create(['business_id' => $this->business->id]);

        Rack::factory()->create(['business_id' => $this->business->id]);
        Shelf::factory()->create(['business_id' => $this->business->id]);

        $vat = Vat::create([
            'business_id' => $this->business->id,
            'name' => 'GST 5%',
            'rate' => 5,
            'status' => 1,
        ]);

        $paymentType = PaymentType::create([
            'business_id' => $this->business->id,
            'name' => 'Cash',
            'status' => 1,
        ]);

        ProductModel::create([
            'business_id' => $this->business->id,
            'name' => 'Tablet',
            'status' => 1,
        ]);

        Relationship::create([
            'business_id' => $this->business->id,
            'relationship' => 'Regular',
        ]);

        ExpenseCategory::create([
            'business_id' => $this->business->id,
            'categoryName' => 'Utilities',
            'status' => 1,
        ]);

        IncomeCategory::create([
            'business_id' => $this->business->id,
            'categoryName' => 'Service',
            'status' => 1,
        ]);

        $customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Aarav Patient',
            'type' => 'Customer',
            'phone' => '9000000001',
            'due' => 20,
            'wallet' => 5,
        ]);

        $party = Party::create([
            'business_id' => $this->business->id,
            'name' => 'Medline Supplier',
            'type' => 'Supplier',
            'phone' => '9000000002',
            'due' => 10,
        ]);

        $doctor = Doctor::create([
            'business_id' => $this->business->id,
            'name' => 'Dr Smoke',
            'qualification' => 'MBBS',
            'specialization' => 'General',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id,
            'productName' => 'Paracetamol 500',
            'productCode' => 'SMOKE-PARA-500',
            'unit_id' => $unit->id,
            'brand_id' => $brand->id,
            'manufacturer_id' => $manufacturer->id,
            'category_id' => $category->id,
            'warehouse_id' => $warehouse->id,
            'vat_id' => $vat->id,
            'productPurchasePrice' => 8,
            'productSalePrice' => 12,
            'productWholeSalePrice' => 10,
            'productDealerPrice' => 9,
            'productStock' => 50,
            'alert_qty' => 5,
            'rack' => 'R1',
            'shelf' => 'S1',
        ]);

        $this->stock = Stock::create([
            'business_id' => $this->business->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $this->product->id,
            'batch_no' => 'SMK-B1',
            'productStock' => 50,
            'productPurchasePrice' => 8,
            'productSalePrice' => 12,
            'productWholeSalePrice' => 10,
            'productDealerPrice' => 9,
            'expire_date' => now()->addYear(),
        ]);

        $this->purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'party_id' => $party->id,
            'payment_type_id' => $paymentType->id,
            'invoiceNumber' => 'SMK-PUR-1',
            'purchaseDate' => now(),
            'totalAmount' => 400,
            'paidAmount' => 400,
            'dueAmount' => 0,
            'isPaid' => 1,
            'paymentType' => 'cash',
        ]);

        $this->sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'customer_id' => $customer->id,
            'doctor_id' => $doctor->id,
            'payment_type_id' => $paymentType->id,
            'invoiceNumber' => 'SMK-SALE-1',
            'saleDate' => now(),
            'totalAmount' => 120,
            'paidAmount' => 120,
            'dueAmount' => 0,
            'isPaid' => 1,
            'paymentType' => 'cash',
        ]);
    }

    private function readEndpoints(): array
    {
        return [
            '/api/v1/summary',
            '/api/v1/due-summary',
            '/api/v1/dashboard',
            '/api/v1/staff-reports',
            '/api/v1/parties',
            '/api/v1/customers',
            '/api/v1/customers/' . Customer::where('business_id', $this->business->id)->value('id'),
            '/api/v1/relationships',
            '/api/v1/users',
            '/api/v1/units',
            '/api/v1/brands',
            '/api/v1/manufacturers',
            '/api/v1/categories',
            '/api/v1/products',
            '/api/v1/products/' . $this->product->id,
            '/api/v1/expired-products',
            '/api/v1/products-by-location?search=R1',
            '/api/v1/stocks',
            '/api/v1/stocks/' . $this->stock->id,
            '/api/v1/low-stock-products',
            '/api/v1/follow-up-report',
            '/api/v1/stock-audit',
            '/api/v1/stock-audit-report',
            '/api/v1/purchase/batch-details/' . $this->product->id . '/SMK-B1',
            '/api/v1/search-purchase-invoice?search=SMK-PUR',
            '/api/v1/racks',
            '/api/v1/shelfs',
            '/api/v1/business',
            '/api/v1/business-categories',
            '/api/v1/purchase',
            '/api/v1/sales',
            '/api/v1/search-invoice-id?search=SMK-SALE',
            '/api/v1/product-report',
            '/api/v1/purchase-order',
            '/api/v1/sales-return',
            '/api/v1/purchases-return',
            '/api/v1/invoices',
            '/api/v1/dues',
            '/api/v1/customer-dues',
            '/api/v1/expense-categories',
            '/api/v1/expenses',
            '/api/v1/income-categories',
            '/api/v1/incomes',
            '/api/v1/doctors',
            '/api/v1/doctors/' . Doctor::where('business_id', $this->business->id)->value('id'),
            '/api/v1/banners',
            '/api/v1/lang',
            '/api/v1/profile',
            '/api/v1/plans',
            '/api/v1/subscribes',
            '/api/v1/currencies',
            '/api/v1/vats',
            '/api/v1/payment-types',
            '/api/v1/warehouses',
            '/api/v1/product-models',
            '/api/v1/product-settings',
            '/api/v1/business-settings',
            '/api/v1/new-invoice',
            '/api/v1/invoice-settings',
        ];
    }

    private function mutationEndpoints(): array
    {
        return [
            'POST' => [
                '/api/v1/units' => ['unitName' => 'Strip'],
                '/api/v1/brands' => ['brandName' => 'Smoke Pharma'],
                '/api/v1/manufacturers' => ['manufacturerName' => 'Smoke Labs'],
                '/api/v1/racks' => ['name' => 'Rack Smoke'],
                '/api/v1/shelfs' => ['name' => 'Shelf Smoke'],
                '/api/v1/payment-types' => ['name' => 'UPI'],
                '/api/v1/product-models' => ['name' => 'Capsule'],
                '/api/v1/product-settings' => ['show_product_price' => 1],
                '/api/v1/invoice-settings/update' => ['invoice_size' => 'a4'],
                '/api/v1/profit-loss-report' => [
                    'from_date' => now()->subMonth()->toDateString(),
                    'to_date' => now()->toDateString(),
                    'page' => 1,
                    'per_page' => 20,
                ],
            ],
            'PUT' => [
                '/api/v1/counter' => ['amount' => 500],
            ],
        ];
    }

    private function assertApiDidNotCrash(TestResponse $response, string $method, string $path): void
    {
        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            "{$method} {$path} returned {$response->getStatusCode()}: " . $response->getContent()
        );
    }
}
