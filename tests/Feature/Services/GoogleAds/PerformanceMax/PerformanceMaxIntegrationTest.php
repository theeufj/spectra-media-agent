<?php

namespace Tests\Feature\Services\GoogleAds\PerformanceMax;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\PerformanceMaxServices\AddAudienceSignals;
use App\Services\GoogleAds\PerformanceMaxServices\CreateAssetGroup;
use App\Services\GoogleAds\PerformanceMaxServices\CreatePerformanceMaxCampaign;
use App\Services\GoogleAds\PerformanceMaxServices\CreateTextAsset;
use App\Services\GoogleAds\PerformanceMaxServices\LinkAssetGroupAsset;
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
 * @group pmax
 */
class PerformanceMaxIntegrationTest extends TestCase
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

        $this->customer   = Customer::whereNotNull('google_ads_customer_id')->firstOrFail();
        $this->customerId = $this->customer->cleanGoogleCustomerId();
    }

    protected function tearDown(): void
    {
        $this->removeCampaigns();
        parent::tearDown();
    }

    public function test_creates_pmax_campaign_and_returns_resource_name(): void
    {
        $service  = new CreatePerformanceMaxCampaign($this->customer);
        $campaign = Campaign::factory()->make([
            'name'         => 'PHPUnit PMax Test ' . now()->timestamp,
            'daily_budget' => 5.00,
        ]);

        $resource = $service->create($this->customerId, $campaign);

        $this->assertNotNull($resource);
        $this->assertStringContainsString('customers/' . $this->customerId, $resource);
        $this->assertStringContainsString('/campaigns/', $resource);

        $this->createdCampaignResources[] = $resource;
    }

    public function test_creates_asset_group_inside_pmax_campaign(): void
    {
        $campaignResource = $this->createTestPMaxCampaign();

        $service  = new CreateAssetGroup($this->customer);
        $resource = $service->create(
            customerId:        $this->customerId,
            campaignResource:  $campaignResource,
            name:              'PHPUnit Asset Group',
            finalUrl:          'https://sitetospend.com',
        );

        $this->assertNotNull($resource);
        $this->assertStringContainsString('/assetGroups/', $resource);
    }

    public function test_creates_text_asset_headline(): void
    {
        $service  = new CreateTextAsset($this->customer);
        $resource = $service->create($this->customerId, 'Grow Your Business');

        $this->assertNotNull($resource);
        $this->assertStringContainsString('/assets/', $resource);
    }

    public function test_creates_text_asset_description(): void
    {
        $service  = new CreateTextAsset($this->customer);
        $resource = $service->create($this->customerId, 'Reach more customers with AI-powered advertising.');

        $this->assertNotNull($resource);
        $this->assertStringContainsString('/assets/', $resource);
    }

    public function test_adds_search_theme_signals_to_asset_group(): void
    {
        $campaignResource  = $this->createTestPMaxCampaign();
        $assetGroupService = new CreateAssetGroup($this->customer);
        $assetGroupResource = $assetGroupService->create(
            $this->customerId,
            $campaignResource,
            'PHPUnit Asset Group',
            'https://sitetospend.com',
        );

        $service = new AddAudienceSignals($this->customer);
        $count   = $service->addSearchThemes($this->customerId, $assetGroupResource, [
            'google ads management',
            'ppc agency',
            'digital marketing software',
        ]);

        $this->assertGreaterThan(0, $count);
    }

    public function test_links_text_asset_to_asset_group(): void
    {
        $campaignResource   = $this->createTestPMaxCampaign();
        $assetGroupService  = new CreateAssetGroup($this->customer);
        $assetGroupResource = $assetGroupService->create(
            $this->customerId,
            $campaignResource,
            'PHPUnit Asset Group',
            'https://sitetospend.com',
        );

        $textService  = new CreateTextAsset($this->customer);
        $assetResource = $textService->create($this->customerId, 'PHPUnit Headline');

        $linkService = new LinkAssetGroupAsset($this->customer);
        $result      = $linkService->link(
            $this->customerId,
            $assetGroupResource,
            $assetResource,
            'HEADLINE',
        );

        $this->assertTrue($result);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createTestPMaxCampaign(): string
    {
        $service  = new CreatePerformanceMaxCampaign($this->customer);
        $campaign = Campaign::factory()->make([
            'name'         => 'PHPUnit PMax ' . now()->timestamp . '-' . rand(100, 999),
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
            $mccId      = env('GOOGLE_ADS_MCC_CUSTOMER_ID', '8701023448');

            $oAuth2 = (new OAuth2TokenBuilder())->fromFile($configPath)->build();
            $client = (new GoogleAdsClientBuilder())
                ->fromFile($configPath)
                ->withOAuth2Credential($oAuth2)
                ->withLoginCustomerId($mccId)
                ->build();

            $ops = array_map(function (string $resource) {
                $c = new GoogleCampaign(['resource_name' => $resource, 'status' => CampaignStatus::REMOVED]);
                $op = new CampaignOperation();
                $op->setUpdate($c);
                $op->setUpdateMask(new FieldMask(['paths' => ['status']]));
                return $op;
            }, $this->createdCampaignResources);

            $client->getCampaignServiceClient()->mutateCampaigns(
                new MutateCampaignsRequest(['customer_id' => $this->customerId, 'operations' => $ops])
            );
        } catch (\Throwable $e) {
        }
    }
}
