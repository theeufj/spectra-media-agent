<?php

namespace Tests\Feature\Services\GoogleAds;

use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Deployment\GoogleAdsDeploymentStrategy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

/**
 * @group integration
 * @group google-ads
 */
class GoogleAdsDeploymentIntegrationTest extends TestCase
{
    use DatabaseTransactions;
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();

        // Skip if not explicitly asked to run integration tests
        if (!env('RUN_GOOGLE_ADS_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Skipping Google Ads integration tests. Set RUN_GOOGLE_ADS_INTEGRATION_TESTS=true to run.');
        }
    }

    public function test_can_deploy_search_campaign_to_google_ads()
    {
        // 1. Setup Test Customer (using the valid test client account)
        $customer = Customer::factory()->create([
            'google_ads_customer_id' => '6387517170', // The valid test client account
            // We don't set refresh token, so it falls back to MCC credentials from ini file
        ]);
        
        // 2. Setup Campaign
        $campaign = Campaign::create([
            'customer_id' => $customer->id,
            'name' => 'Integration Test Campaign ' . time(),
            'daily_budget' => 10.00,
            'total_budget' => 300.00,
            'landing_page_url' => 'https://example.com',
            'reason' => 'Integration Test',
            'goals' => 'Test Goals',
            'target_market' => 'Test Market',
            'voice' => 'Professional',
            'primary_kpi' => 'Conversions',
            'product_focus' => 'Test Product',
            'start_date' => now(),
            'end_date' => now()->addMonth(),
        ]);
        
        // 3. Setup Strategy
        $strategy = Strategy::create([
            'campaign_id' => $campaign->id,
            'platform' => 'google',
            'campaign_type' => 'search',
            'status' => 'approved',
            'ad_copy_strategy' => 'Test strategy',
            'imagery_strategy' => 'Test imagery',
            'video_strategy' => 'Test video',
        ]);

        // 4. Setup Ad Copy
        AdCopy::create([
            'strategy_id' => $strategy->id,
            'platform' => 'google',
            'headlines' => ['Headline 1', 'Headline 2', 'Headline 3'],
            'descriptions' => ['Description 1', 'Description 2'],
        ]);

        // 5. Run Deployment
        $deploymentService = new GoogleAdsDeploymentStrategy($customer);
        $result = $deploymentService->deploy($campaign, $strategy);

        // 6. Assertions
        $this->assertTrue($result, 'Deployment should return true');
        
        $campaign->refresh();
        $strategy->refresh();
        
        $this->assertNotNull($campaign->google_ads_campaign_id, 'Campaign should have Google Ads ID');
        $this->assertNotNull($strategy->google_ads_ad_group_id, 'Strategy should have Google Ads Ad Group ID');
        
        // Optional: Clean up (delete campaign) - this would require a delete service or raw API call
        // For now, we leave it as it's a test account.
    }
}
