<?php

namespace Tests\Feature\Services\GoogleAds;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\AddKeyword;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBudget;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use App\Services\GoogleAds\SearchServices\CreateResponsiveSearchAd;
use App\Services\GoogleAds\SearchServices\CreateSearchAdGroup;
use App\Services\GoogleAds\SearchServices\CreateSearchCampaign;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V22\Resources\Campaign as GoogleCampaign;
use Google\Ads\GoogleAds\V22\Services\CampaignOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignsRequest;
use Google\Protobuf\FieldMask;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group google-ads
 */
class SearchCampaignIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected Customer $customer;
    protected string $customerId;
    protected array $createdCampaignResources = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_GOOGLE_ADS_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_GOOGLE_ADS_INTEGRATION_TESTS=true to run.');
        }

        // Use the active sitetospend account directly
        $this->customer = Customer::whereNotNull('google_ads_customer_id')->firstOrFail();
        $this->customerId = $this->customer->cleanGoogleCustomerId();
    }

    protected function tearDown(): void
    {
        $this->removeCampaigns();
        parent::tearDown();
    }

    public function test_creates_search_campaign_and_returns_resource_name(): void
    {
        $service  = new CreateSearchCampaign($this->customer);
        $campaign = Campaign::factory()->make([
            'customer_id'  => $this->customer->id,
            'name'         => 'PHPUnit Search Test ' . now()->timestamp,
            'daily_budget' => 5.00,
        ]);

        $resource = $service->create($this->customerId, $campaign);

        $this->assertNotNull($resource);
        $this->assertStringContainsString('customers/' . $this->customerId, $resource);
        $this->assertStringContainsString('/campaigns/', $resource);

        $this->createdCampaignResources[] = $resource;
    }

    public function test_creates_ad_group_inside_campaign(): void
    {
        $campaignResource = $this->createTestCampaign();

        $service  = new CreateSearchAdGroup($this->customer);
        $resource = $service->create($this->customerId, $campaignResource, 'PHPUnit Ad Group');

        $this->assertNotNull($resource);
        $this->assertStringContainsString('/adGroups/', $resource);
    }

    public function test_creates_responsive_search_ad(): void
    {
        $campaignResource  = $this->createTestCampaign();
        $adGroupService    = new CreateSearchAdGroup($this->customer);
        $adGroupResource   = $adGroupService->create($this->customerId, $campaignResource, 'PHPUnit Ad Group');

        $service  = new CreateResponsiveSearchAd($this->customer);
        $resource = $service->create(
            customerId:       $this->customerId,
            adGroupResource:  $adGroupResource,
            headlines:        ['Buy Now', 'Great Deals', 'Shop Today'],
            descriptions:     ['Find what you need fast.', 'Quality products at great prices.'],
            finalUrl:         'https://sitetospend.com',
        );

        $this->assertNotNull($resource);
        $this->assertStringContainsString('/ads/', $resource);
    }

    public function test_adds_keyword_to_ad_group(): void
    {
        $campaignResource = $this->createTestCampaign();
        $adGroupService   = new CreateSearchAdGroup($this->customer);
        $adGroupResource  = $adGroupService->create($this->customerId, $campaignResource, 'PHPUnit Ad Group');

        $service  = new AddKeyword($this->customer);
        $resource = $service->add(
            customerId:      $this->customerId,
            adGroupResource: $adGroupResource,
            keyword:         'integration test keyword',
            matchType:       'EXACT',
        );

        $this->assertNotNull($resource);
        $this->assertStringContainsString('/adGroupCriteria/', $resource);
    }

    public function test_updates_campaign_status_to_paused(): void
    {
        $campaignResource = $this->createTestCampaign();

        $service = new UpdateCampaignStatus($this->customer);
        $result  = $service->update($this->customerId, $campaignResource, 'PAUSED');

        $this->assertTrue($result);

        // Verify via GetCampaignStatus
        $statusService = new GetCampaignStatus($this->customer);
        $status        = $statusService->get($this->customerId, $campaignResource);

        $this->assertEquals('PAUSED', $status);
    }

    public function test_updates_campaign_budget(): void
    {
        $campaignResource = $this->createTestCampaign();

        $service = new UpdateCampaignBudget($this->customer);
        $result  = $service->update($this->customerId, $campaignResource, 7.50);

        $this->assertTrue($result);
    }

    public function test_gets_campaign_performance_returns_structured_data(): void
    {
        // Use any existing live campaign from the DB
        $existingCampaign = Campaign::where('customer_id', $this->customer->id)
            ->whereNotNull('google_ads_campaign_id')
            ->first();

        if (!$existingCampaign) {
            $this->markTestSkipped('No existing Google Ads campaign in DB for this customer.');
        }

        $service = new GetCampaignPerformance($this->customer);
        $perf    = $service->get(
            $this->customerId,
            $existingCampaign->google_ads_campaign_id,
            now()->subDays(30)->toDateString(),
            now()->toDateString(),
        );

        $this->assertIsArray($perf);
        $this->assertArrayHasKey('spend', $perf);
        $this->assertArrayHasKey('clicks', $perf);
        $this->assertArrayHasKey('impressions', $perf);
        $this->assertArrayHasKey('conversions', $perf);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createTestCampaign(): string
    {
        $service  = new CreateSearchCampaign($this->customer);
        $campaign = Campaign::factory()->make([
            'customer_id'  => $this->customer->id,
            'name'         => 'PHPUnit Test ' . now()->timestamp . '-' . rand(100, 999),
            'daily_budget' => 5.00,
        ]);

        $resource = $service->create($this->customerId, $campaign);
        $this->createdCampaignResources[] = $resource;

        return $resource;
    }

    private function removeCampaigns(): void
    {
        if (empty($this->createdCampaignResources)) {
            return;
        }

        try {
            $configPath = storage_path('app/google_ads_php.ini');
            $mccId      = config('googleads.mcc_customer_id', env('GOOGLE_ADS_MCC_CUSTOMER_ID'));

            $oAuth2 = (new OAuth2TokenBuilder())->fromFile($configPath)->build();
            $client = (new GoogleAdsClientBuilder())
                ->fromFile($configPath)
                ->withOAuth2Credential($oAuth2)
                ->withLoginCustomerId($mccId)
                ->build();

            $ops = array_map(function (string $resource) {
                $campaign = new GoogleCampaign([
                    'resource_name' => $resource,
                    'status'        => CampaignStatus::REMOVED,
                ]);
                $op = new CampaignOperation();
                $op->setUpdate($campaign);
                $op->setUpdateMask(new FieldMask(['paths' => ['status']]));
                return $op;
            }, $this->createdCampaignResources);

            $client->getCampaignServiceClient()->mutateCampaigns(
                new MutateCampaignsRequest([
                    'customer_id' => $this->customerId,
                    'operations'  => $ops,
                ])
            );
        } catch (\Throwable $e) {
            // Don't let teardown failure mask test failure
        }
    }
}
