<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every read-only report answers, for a tenant that has no data yet.
 *
 * The smoke test that existed covered four report routes out of roughly ninety.
 * The faults this session kept finding - a column removed by a rename, a query
 * left unscoped - surface as a 500 on exactly these endpoints, and an empty tenant
 * is the case most likely to divide by zero or dereference a missing relation.
 *
 * This asserts the weaker property deliberately: not that the figures are right,
 * but that the endpoint returns rather than throws. Correctness of the numbers is
 * pinned by the calculation tests.
 */
class ReportEndpointSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);
    }

    /** @return array<string, array{string}> */
    public static function provideReportEndpoints(): array
    {
        $paths = [
            '/api/v1/dashboard',
            '/api/v1/summary',
            '/api/v1/due-summary',
            '/api/v1/product-report',
            '/api/v1/staff-reports',
            '/api/v1/stock-audit-report',
            '/api/v1/follow-up-report',
            '/api/v1/gst-report',
            '/api/v1/branch-gst-report',
            '/api/v1/loyalty-reports/customers',
            '/api/v1/loyalty-reports/renewals',
            '/api/v1/loyalty-reports/expired',
            '/api/v1/loyalty-reports/upgrades',
            '/api/v1/loyalty-reports/utilisation',
            '/api/v1/loyalty-reports/amount-received',
        ];

        return array_combine(
            $paths,
            array_map(fn ($path) => [$path], $paths),
        );
    }

    /** @dataProvider provideReportEndpoints */
    public function test_a_report_answers_for_an_empty_tenant(string $path): void
    {
        $response = $this->actingAs($this->user)->getJson($path);

        $this->assertLessThan(
            500,
            $response->status(),
            "GET {$path} returned {$response->status()}: ".mb_substr($response->getContent(), 0, 300),
        );
    }

    public function test_dashboard_uses_a_default_duration_when_the_app_sends_no_filter(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/dashboard')
            ->assertSuccessful()
            ->assertJsonPath('message', 'Data fetched successfully.')
            ->assertJsonStructure([
                'data' => [
                    'total_sales_card',
                    'cleared_due_card',
                    'purchase_cleared_due_card',
                ],
            ]);
    }

    /** @dataProvider provideReportEndpoints */
    public function test_a_report_answers_for_a_date_range(string $path): void
    {
        // Date filtering is where the report queries assemble their own SQL, so
        // it exercises a different path from the unfiltered call above.
        $response = $this->actingAs($this->user)->getJson($path.'?'.http_build_query([
            'from_date' => now()->subMonth()->toDateString(),
            'to_date' => now()->toDateString(),
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->toDateString(),
        ]));

        $this->assertLessThan(
            500,
            $response->status(),
            "GET {$path} with a date range returned {$response->status()}: "
                .mb_substr($response->getContent(), 0, 300),
        );
    }
}
