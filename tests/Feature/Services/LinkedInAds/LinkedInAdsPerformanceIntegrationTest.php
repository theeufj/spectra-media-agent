<?php

namespace Tests\Feature\Services\LinkedInAds;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\LinkedInAds\PerformanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group linkedin-ads
 */
class LinkedInAdsPerformanceIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected Customer $customer;
    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_LINKEDIN_ADS_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_LINKEDIN_ADS_INTEGRATION_TESTS=true to run.');
        }

        $this->customer = Customer::whereNotNull('linkedin_ads_account_id')->firstOrFail();

        $campaign = Campaign::where('customer_id', $this->customer->id)
            ->whereNotNull('linkedin_campaign_id')
            ->first();

        if (!$campaign) {
            $this->markTestSkipped('No LinkedIn campaign found in DB for this customer.');
        }

        $this->campaign = $campaign;
    }

    public function test_syncs_performance_data_and_returns_row_count(): void
    {
        $service = new PerformanceService($this->customer);
        $stored  = $service->syncPerformance($this->campaign, 30);

        $this->assertIsInt($stored);
        $this->assertGreaterThanOrEqual(0, $stored);
    }

    public function test_synced_data_stored_in_database(): void
    {
        $service = new PerformanceService($this->customer);
        $stored  = $service->syncPerformance($this->campaign, 14);

        if ($stored > 0) {
            $this->assertDatabaseHas('linked_in_ads_performance_data', [
                'campaign_id' => $this->campaign->id,
            ]);
        } else {
            $this->assertSame(0, $stored);
        }
    }

    public function test_performance_summary_returns_expected_keys(): void
    {
        $service  = new PerformanceService($this->customer);
        $summary  = $service->getPerformanceSummary(30);

        $this->assertIsArray($summary);
        $this->assertArrayHasKey('impressions', $summary);
        $this->assertArrayHasKey('clicks', $summary);
        $this->assertArrayHasKey('cost', $summary);
        $this->assertArrayHasKey('conversions', $summary);
        $this->assertArrayHasKey('ctr', $summary);
        $this->assertArrayHasKey('cpc', $summary);
        $this->assertArrayHasKey('cpa', $summary);
        $this->assertArrayHasKey('roas', $summary);
        $this->assertArrayHasKey('days', $summary);
    }

    public function test_performance_summary_values_are_non_negative(): void
    {
        $service = new PerformanceService($this->customer);
        $summary = $service->getPerformanceSummary(30);

        foreach (['impressions', 'clicks', 'cost', 'conversions', 'ctr', 'cpc', 'cpa', 'roas'] as $key) {
            $this->assertGreaterThanOrEqual(0, $summary[$key], "Field {$key} should not be negative");
        }
    }
}
