<?php

namespace Tests\Feature\Services\GoogleAds;

use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Deployment\GoogleAdsDeploymentStrategy;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBudget;
use App\Services\GoogleAds\CommonServices\AddKeyword;
use App\Services\GoogleAds\SearchServices\CreateSearchCampaign;
use App\Services\GoogleAds\SearchServices\CreateSearchAdGroup;
use App\Services\GoogleAds\SearchServices\CreateResponsiveSearchAd;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
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

    protected ?string $testSubAccountId = null;
    protected ?Customer $testCustomer = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_GOOGLE_ADS_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Skipping Google Ads integration tests. Set RUN_GOOGLE_ADS_INTEGRATION_TESTS=true to run.');
        }

        // Create a fresh test sub-account under the test MCC for each test run
        $this->testSubAccountId = $this->createTestSubAccount();
        $this->testCustomer = Customer::factory()->create([
            'google_ads_customer_id' => $this->testSubAccountId,
        ]);
    }

    /**
     * Create a fresh Google Ads test sub-account under the test MCC.
     */
    private function createTestSubAccount(): string
    {
        $mccCustomerId = config('googleads.mcc_customer_id');
        $this->assertNotEmpty($mccCustomerId, 'GOOGLE_ADS_MCC_CUSTOMER_ID must be set');

        $configPath = storage_path('app/google_ads_php.ini');
        $mccRefreshToken = config('googleads.mcc_refresh_token');
        $this->assertNotEmpty($mccRefreshToken, 'GOOGLE_ADS_MCC_REFRESH_TOKEN must be set');

        $oAuth2 = (new OAuth2TokenBuilder())
            ->fromFile($configPath)
            ->withRefreshToken($mccRefreshToken)
            ->build();

        $client = (new GoogleAdsClientBuilder())
            ->fromFile($configPath)
            ->withOAuth2Credential($oAuth2)
            ->withLoginCustomerId($mccCustomerId)
            ->build();

        $newCustomer = new \Google\Ads\GoogleAds\V22\Resources\Customer([
            'descriptive_name' => 'PHPUnit Test ' . date('Y-m-d H:i:s'),
            'currency_code' => 'AUD',
            'time_zone' => 'Australia/Sydney',
        ]);

        $request = new \Google\Ads\GoogleAds\V22\Services\CreateCustomerClientRequest([
            'customer_id' => $mccCustomerId,
            'customer_client' => $newCustomer,
        ]);

        $response = $client->getCustomerServiceClient()->createCustomerClient($request);
        $resourceName = $response->getResourceName();
        $customerId = str_replace('customers/', '', $resourceName);

        $this->assertNotEmpty($customerId, 'Failed to create test sub-account');

        return $customerId;
    }

    // ─── Full Deployment Pipeline ───────────────────────────────────

    public function test_can_deploy_search_campaign_to_google_ads()
    {
        $campaign = Campaign::create([
            'customer_id' => $this->testCustomer->id,
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

        $strategy = Strategy::create([
            'campaign_id' => $campaign->id,
            'platform' => 'google',
            'campaign_type' => 'search',
            'status' => 'approved',
            'ad_copy_strategy' => 'Test strategy',
            'imagery_strategy' => 'Test imagery',
            'video_strategy' => 'Test video',
        ]);

        AdCopy::create([
            'strategy_id' => $strategy->id,
            'platform' => 'google',
            'headlines' => ['Test Headline One', 'Test Headline Two', 'Test Headline Three'],
            'descriptions' => ['Test description line one here.', 'Test description line two here.'],
        ]);

        $deploymentService = new GoogleAdsDeploymentStrategy($this->testCustomer);
        $result = $deploymentService->deploy($campaign, $strategy);

        $this->assertTrue($result, 'Deployment should return true');

        $campaign->refresh();
        $strategy->refresh();

        $this->assertNotNull($campaign->google_ads_campaign_id, 'Campaign should have Google Ads campaign resource name');
        $this->assertNotNull($strategy->google_ads_ad_group_id, 'Strategy should have Google Ads ad group resource name');
    }

    // ─── Individual Service Tests ───────────────────────────────────

    public function test_can_create_search_campaign()
    {
        $service = new CreateSearchCampaign($this->testCustomer);

        $campaignResourceName = $service($this->testSubAccountId, [
            'businessName' => 'Unit Test Biz ' . time(),
            'budget' => 5.00,
            'startDate' => now()->format('Y-m-d'),
            'endDate' => now()->addMonth()->format('Y-m-d'),
        ]);

        $this->assertNotNull($campaignResourceName, 'Should return a campaign resource name');
        $this->assertStringContainsString('customers/', $campaignResourceName);
        $this->assertStringContainsString('campaigns/', $campaignResourceName);
    }

    public function test_can_create_ad_group_in_campaign()
    {
        // First create a campaign
        $campaignService = new CreateSearchCampaign($this->testCustomer);
        $campaignResourceName = $campaignService($this->testSubAccountId, [
            'businessName' => 'AdGroup Test ' . time(),
            'budget' => 5.00,
            'startDate' => now()->format('Y-m-d'),
            'endDate' => now()->addMonth()->format('Y-m-d'),
        ]);
        $this->assertNotNull($campaignResourceName);

        // Create ad group
        $adGroupService = new CreateSearchAdGroup($this->testCustomer);
        $adGroupResourceName = $adGroupService($this->testSubAccountId, $campaignResourceName, 'Test Ad Group');

        $this->assertNotNull($adGroupResourceName, 'Should return an ad group resource name');
        $this->assertStringContainsString('adGroups/', $adGroupResourceName);
    }

    public function test_can_create_responsive_search_ad()
    {
        // Create campaign + ad group
        $campaignService = new CreateSearchCampaign($this->testCustomer);
        $campaignResourceName = $campaignService($this->testSubAccountId, [
            'businessName' => 'RSA Test ' . time(),
            'budget' => 5.00,
            'startDate' => now()->format('Y-m-d'),
            'endDate' => now()->addMonth()->format('Y-m-d'),
        ]);

        $adGroupService = new CreateSearchAdGroup($this->testCustomer);
        $adGroupResourceName = $adGroupService($this->testSubAccountId, $campaignResourceName, 'RSA Test Group');

        // Create RSA
        $rsaService = new CreateResponsiveSearchAd($this->testCustomer);
        $adResourceName = $rsaService($this->testSubAccountId, $adGroupResourceName, [
            'finalUrls' => ['https://example.com'],
            'headlines' => ['Buy Now Today', 'Best Deals Here', 'Free Shipping'],
            'descriptions' => ['Shop our amazing products today.', 'Quality items at great prices.'],
        ]);

        $this->assertNotNull($adResourceName, 'Should return an ad resource name');
        $this->assertStringContainsString('adGroupAds/', $adResourceName);
    }

    public function test_can_add_keyword_to_ad_group()
    {
        // Create campaign + ad group
        $campaignService = new CreateSearchCampaign($this->testCustomer);
        $campaignResourceName = $campaignService($this->testSubAccountId, [
            'businessName' => 'Keyword Test ' . time(),
            'budget' => 5.00,
            'startDate' => now()->format('Y-m-d'),
            'endDate' => now()->addMonth()->format('Y-m-d'),
        ]);

        $adGroupService = new CreateSearchAdGroup($this->testCustomer);
        $adGroupResourceName = $adGroupService($this->testSubAccountId, $campaignResourceName, 'Keyword Test Group');

        // Add keyword
        $keywordService = new AddKeyword($this->testCustomer);
        $criterionResourceName = $keywordService($this->testSubAccountId, $adGroupResourceName, 'test keyword');

        $this->assertNotNull($criterionResourceName, 'Should return a criterion resource name');
        $this->assertStringContainsString('adGroupCriteria/', $criterionResourceName);
    }

    public function test_can_get_campaign_status()
    {
        // Create a campaign first
        $campaignService = new CreateSearchCampaign($this->testCustomer);
        $campaignResourceName = $campaignService($this->testSubAccountId, [
            'businessName' => 'Status Test ' . time(),
            'budget' => 5.00,
            'startDate' => now()->format('Y-m-d'),
            'endDate' => now()->addMonth()->format('Y-m-d'),
        ]);
        $this->assertNotNull($campaignResourceName);

        // Get status
        $statusService = new GetCampaignStatus($this->testCustomer);
        $status = $statusService($this->testSubAccountId, $campaignResourceName);

        $this->assertNotNull($status, 'Should return status data');
        $this->assertArrayHasKey('status', $status);
        $this->assertArrayHasKey('primary_status', $status);
    }

    public function test_can_update_campaign_budget()
    {
        // Create a campaign
        $campaignService = new CreateSearchCampaign($this->testCustomer);
        $campaignResourceName = $campaignService($this->testSubAccountId, [
            'businessName' => 'Budget Test ' . time(),
            'budget' => 5.00,
            'startDate' => now()->format('Y-m-d'),
            'endDate' => now()->addMonth()->format('Y-m-d'),
        ]);
        $this->assertNotNull($campaignResourceName);

        // Update budget to $15/day
        $budgetService = new UpdateCampaignBudget($this->testCustomer);
        $result = $budgetService($this->testSubAccountId, $campaignResourceName, 15_000_000);

        $this->assertTrue($result, 'Budget update should succeed');
    }

    public function test_get_performance_returns_null_for_new_campaign()
    {
        // Create a fresh campaign (no impressions yet)
        $campaignService = new CreateSearchCampaign($this->testCustomer);
        $campaignResourceName = $campaignService($this->testSubAccountId, [
            'businessName' => 'Perf Test ' . time(),
            'budget' => 5.00,
            'startDate' => now()->format('Y-m-d'),
            'endDate' => now()->addMonth()->format('Y-m-d'),
        ]);

        $perfService = new GetCampaignPerformance($this->testCustomer);
        $metrics = $perfService($this->testSubAccountId, $campaignResourceName, 'LAST_7_DAYS');

        // New campaign has no data, so null is expected
        $this->assertNull($metrics, 'New campaign should have no performance data');
    }

    public function test_idempotent_campaign_creation()
    {
        $name = 'Idempotent Test ' . time();

        $service = new CreateSearchCampaign($this->testCustomer);

        $first = $service($this->testSubAccountId, [
            'businessName' => $name,
            'budget' => 5.00,
            'startDate' => now()->format('Y-m-d'),
            'endDate' => now()->addMonth()->format('Y-m-d'),
        ]);

        $second = $service($this->testSubAccountId, [
            'businessName' => $name,
            'budget' => 5.00,
            'startDate' => now()->format('Y-m-d'),
            'endDate' => now()->addMonth()->format('Y-m-d'),
        ]);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertEquals($first, $second, 'Same campaign name should return existing campaign');
    }
}
