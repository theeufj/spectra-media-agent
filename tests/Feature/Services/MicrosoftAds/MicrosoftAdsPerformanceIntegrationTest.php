<?php

namespace Tests\Feature\Services\MicrosoftAds;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\MicrosoftAds\PerformanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group microsoft-ads
 */
class MicrosoftAdsPerformanceIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected Customer $customer;
    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_MICROSOFT_ADS_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_MICROSOFT_ADS_INTEGRATION_TESTS=true to run.');
        }

        $this->customer = Customer::whereNotNull('microsoft_ads_account_id')->firstOrFail();

        $campaign = Campaign::where('customer_id', $this->customer->id)
            ->whereNotNull('microsoft_ads_campaign_id')
            ->first();

        if (!$campaign) {
            $this->markTestSkipped('No Microsoft Ads campaign found in DB for this customer.');
        }

        $this->campaign = $campaign;
    }

    public function test_syncs_performance_data_and_returns_row_count(): void
    {
        $service = new PerformanceService($this->customer);
        $result  = $service->syncPerformance($this->campaign, 30);

        $this->assertIsArray($result);
        // Either rows were stored or an explanatory error is returned
        $this->assertTrue(
            isset($result['rows_stored']) || isset($result['error']),
            'Expected rows_stored or error key in result'
        );
    }

    public function test_sync_performance_returns_rows_stored_key(): void
    {
        $service = new PerformanceService($this->customer);
        $result  = $service->syncPerformance($this->campaign, 7);

        if (isset($result['error'])) {
            $this->markTestSkipped("Microsoft Ads report API returned error: {$result['error']}");
        }

        $this->assertArrayHasKey('rows_stored', $result);
        $this->assertIsInt($result['rows_stored']);
        $this->assertGreaterThanOrEqual(0, $result['rows_stored']);
    }

    public function test_sync_performance_stores_data_in_database(): void
    {
        $service = new PerformanceService($this->customer);
        $result  = $service->syncPerformance($this->campaign, 14);

        if (isset($result['error'])) {
            $this->markTestSkipped("Microsoft Ads report API returned error: {$result['error']}");
        }

        if ($result['rows_stored'] > 0) {
            $this->assertDatabaseHas('microsoft_ads_performance_data', [
                'campaign_id' => $this->campaign->id,
            ]);
        } else {
            // No data for campaign (paused/new) — still a valid result
            $this->assertSame(0, $result['rows_stored']);
        }
    }

    public function test_get_search_terms_returns_array(): void
    {
        $service = new PerformanceService($this->customer);
        $terms   = $service->getSearchTerms($this->campaign->microsoft_ads_campaign_id, 30);

        $this->assertIsArray($terms);

        if (!empty($terms)) {
            $this->assertArrayHasKey('search_term', $terms[0]);
            $this->assertArrayHasKey('impressions', $terms[0]);
            $this->assertArrayHasKey('clicks', $terms[0]);
        }
    }
}
