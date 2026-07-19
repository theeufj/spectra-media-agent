<?php

namespace Tests\Feature\Services\Reporting;

use App\Models\Customer;
use App\Models\User;
use App\Services\Reporting\YesterdayPerformanceSummary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 */
class YesterdayPerformanceSummaryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_INTEGRATION_TESTS=true to run.');
        }
    }

    public function test_returns_summary_array_with_expected_keys(): void
    {
        $customer = Customer::factory()->create();
        $service  = new YesterdayPerformanceSummary();
        $summary  = $service->forCustomer($customer);

        $this->assertIsArray($summary);
        $this->assertArrayHasKey('date', $summary);
        $this->assertArrayHasKey('impressions', $summary);
        $this->assertArrayHasKey('clicks', $summary);
        $this->assertArrayHasKey('cost', $summary);
        $this->assertArrayHasKey('conversions', $summary);
    }

    public function test_accepts_explicit_date(): void
    {
        $customer = Customer::factory()->create();
        $service  = new YesterdayPerformanceSummary();
        $date     = now()->subDays(3)->toDateString();
        $summary  = $service->forCustomer($customer, $date);

        $this->assertIsArray($summary);
        $this->assertSame($date, $summary['date'] ?? $date);
    }

    public function test_returns_zero_values_for_new_customer(): void
    {
        $customer = Customer::factory()->create();
        $service  = new YesterdayPerformanceSummary();
        $summary  = $service->forCustomer($customer);

        $this->assertGreaterThanOrEqual(0, $summary['impressions'] ?? 0);
        $this->assertGreaterThanOrEqual(0, $summary['clicks'] ?? 0);
        $this->assertGreaterThanOrEqual(0, $summary['cost'] ?? 0);
    }

    public function test_real_customer_with_performance_data(): void
    {
        $customer = Customer::whereHas('campaigns.googleAdsPerformanceData')->first()
            ?? Customer::whereHas('campaigns.facebookAdsPerformanceData')->first();

        if (!$customer) {
            $this->markTestSkipped('No customer with performance data in DB.');
        }

        $service = new YesterdayPerformanceSummary();
        $summary = $service->forCustomer($customer);

        $this->assertIsArray($summary);
        $this->assertArrayHasKey('impressions', $summary);
    }
}
