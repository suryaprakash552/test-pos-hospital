<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Follow-ups, filtered by who the medicine was for.
 *
 * Relationship is recorded on the sale from August 2026; before that only the
 * customer's standing relationship existed. The filter has to match either, or
 * it silently hides every follow-up raised before the column was added.
 */
class FollowUpReportTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);

        $this->actingAs($this->user);
    }

    private function followUp(string $invoice, ?string $saleRelationship, ?string $customerRelationship): void
    {
        $customer = Customer::factory()->create([
            'business_id' => $this->business->id,
            'relationship' => $customerRelationship,
        ]);

        Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'customer_id' => $customer->id,
            'invoiceNumber' => $invoice,
            'saleDate' => now(),
            'follow_up_date' => now()->addDays(3),
            'totalAmount' => 100,
            'relationship' => $saleRelationship,
        ]);
    }

    /** @return array<int, string> */
    private function invoices(array $query = []): array
    {
        $query += [
            'from_date' => now()->subYear()->toDateString(),
            'to_date' => now()->addYear()->toDateString(),
        ];

        $data = $this->getJson('/api/v1/follow-up-report?'.http_build_query($query))
            ->assertOk()
            ->json('data');

        return array_map(fn ($row) => $row['invoice_number'], $data ?? []);
    }

    public function test_it_filters_by_the_sales_own_relationship(): void
    {
        $this->followUp('FOR-WIFE', saleRelationship: 'wife', customerRelationship: 'self');
        $this->followUp('FOR-SELF', saleRelationship: 'self', customerRelationship: 'self');

        $found = $this->invoices(['relationship' => 'wife']);

        $this->assertContains('FOR-WIFE', $found);
        $this->assertNotContains('FOR-SELF', $found);
    }

    /** Older follow-ups have no relationship of their own. */
    public function test_it_falls_back_to_the_customers_relationship(): void
    {
        $this->followUp('LEGACY', saleRelationship: null, customerRelationship: 'father');

        $this->assertContains('LEGACY', $this->invoices(['relationship' => 'father']));
    }

    public function test_without_the_filter_every_follow_up_is_listed(): void
    {
        $this->followUp('FOR-WIFE', saleRelationship: 'wife', customerRelationship: 'self');
        $this->followUp('LEGACY', saleRelationship: null, customerRelationship: 'father');

        $found = $this->invoices();

        $this->assertContains('FOR-WIFE', $found);
        $this->assertContains('LEGACY', $found);
    }

    /** The row reports the sale's own relationship, not the customer's. */
    public function test_the_row_reports_the_sales_relationship(): void
    {
        $this->followUp('FOR-WIFE', saleRelationship: 'wife', customerRelationship: 'self');

        $row = $this->getJson('/api/v1/follow-up-report?from_date='
            .now()->subYear()->toDateString().'&to_date='.now()->addYear()->toDateString())
            ->assertOk()
            ->json('data.0');

        $this->assertSame('wife', $row['relationship_name']);
    }

    /** The report is a page, and says how many there are. */
    public function test_the_report_is_paginated(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->followUp('FU-'.$i, saleRelationship: 'self', customerRelationship: 'self');
        }

        $response = $this->getJson('/api/v1/follow-up-report?per_page=2&from_date='
            .now()->subYear()->toDateString().'&to_date='.now()->addYear()->toDateString())
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(5, $response->json('meta.total'));
    }
}
