<?php

namespace Tests\Feature\Services\FacebookAds;

use App\Models\Customer;
use App\Services\FacebookAds\AdService;
use App\Services\FacebookAds\AdSetService;
use App\Services\FacebookAds\CampaignService;
use App\Services\FacebookAds\CreativeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * @group integration
 * @group facebook
 */
class CampaignLifecycleIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected Customer $customer;
    protected string $accountId;

    protected array $createdCampaignIds = [];
    protected array $createdAdSetIds    = [];
    protected array $createdAdIds       = [];
    protected array $createdCreativeIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_FACEBOOK_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_FACEBOOK_INTEGRATION_TESTS=true to run.');
        }

        config(['services.facebook.system_user_token' => env('FACEBOOK_SYSTEM_USER_TOKEN')]);
        config(['services.facebook.page_id'           => env('FACEBOOK_PAGE_ID')]);

        $this->customer  = Customer::whereNotNull('facebook_ads_account_id')->firstOrFail();
        $this->accountId = $this->customer->facebook_ads_account_id;
    }

    protected function tearDown(): void
    {
        $this->deleteCreatedResources();
        parent::tearDown();
    }

    public function test_creates_traffic_campaign_and_returns_id(): void
    {
        $service    = new CampaignService($this->customer);
        $result     = $service->createCampaign(
            accountId:    $this->accountId,
            campaignName: 'PHPUnit Traffic Test ' . now()->timestamp,
            objective:    'OUTCOME_TRAFFIC',
            dailyBudget:  500,
            status:       'PAUSED',
        );

        $this->assertNotNull($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertIsString($result['id']);

        $this->createdCampaignIds[] = $result['id'];
    }

    public function test_creates_leads_campaign(): void
    {
        $service = new CampaignService($this->customer);
        $result  = $service->createCampaign(
            accountId:    $this->accountId,
            campaignName: 'PHPUnit Leads Test ' . now()->timestamp,
            objective:    'OUTCOME_LEADS',
            dailyBudget:  500,
            status:       'PAUSED',
        );

        $this->assertNotNull($result);
        $this->assertArrayHasKey('id', $result);

        $this->createdCampaignIds[] = $result['id'];
    }

    public function test_creates_ad_set_inside_campaign(): void
    {
        $campaignId = $this->createTestCampaign();

        $service = new AdSetService($this->customer);
        $result  = $service->createAdSet(
            accountId:   $this->accountId,
            campaignId:  $campaignId,
            adSetName:   'PHPUnit Ad Set ' . now()->timestamp,
            targeting:   ['geo_locations' => ['countries' => ['US', 'AU']]],
            status:      'PAUSED',
        );

        $this->assertNotNull($result);
        $this->assertArrayHasKey('id', $result);

        $this->createdAdSetIds[] = $result['id'];
    }

    public function test_creates_image_creative(): void
    {
        $service = new CreativeService($this->customer);
        $result  = $service->createImageCreative(
            accountId:    $this->accountId,
            creativeName: 'PHPUnit Creative ' . now()->timestamp,
            imageUrl:     'https://picsum.photos/1200/628',
            headline:     'Grow Your Business Fast',
            description:  'AI-powered Google Ads management that actually converts.',
            callToAction: 'LEARN_MORE',
            linkUrl:      $this->customer->website ?? 'https://sitetospend.com',
        );

        $this->assertNotNull($result);
        $this->assertArrayHasKey('id', $result);

        $this->createdCreativeIds[] = $result['id'];
    }

    public function test_creates_full_ad_stack_campaign_adset_creative_ad(): void
    {
        $campaignId = $this->createTestCampaign();

        $adSetService = new AdSetService($this->customer);
        $adSetResult  = $adSetService->createAdSet(
            accountId:  $this->accountId,
            campaignId: $campaignId,
            adSetName:  'PHPUnit Ad Set ' . now()->timestamp,
            status:     'PAUSED',
        );
        $this->createdAdSetIds[] = $adSetResult['id'];

        $creativeService = new CreativeService($this->customer);
        $creativeResult  = $creativeService->createImageCreative(
            accountId:    $this->accountId,
            creativeName: 'PHPUnit Creative ' . now()->timestamp,
            imageUrl:     'https://picsum.photos/1200/628',
            headline:     'Test Headline',
            description:  'Test description for integration test.',
        );
        $this->createdCreativeIds[] = $creativeResult['id'];

        $adService = new AdService($this->customer);
        $adResult  = $adService->createAd(
            accountId:  $this->accountId,
            adSetId:    $adSetResult['id'],
            adName:     'PHPUnit Ad ' . now()->timestamp,
            creativeId: $creativeResult['id'],
            status:     'PAUSED',
        );

        $this->assertNotNull($adResult);
        $this->assertArrayHasKey('id', $adResult);

        $this->createdAdIds[] = $adResult['id'];
    }

    public function test_pauses_and_resumes_ad(): void
    {
        $campaignId = $this->createTestCampaign();

        $adSetService = new AdSetService($this->customer);
        $adSetResult  = $adSetService->createAdSet(
            accountId:  $this->accountId,
            campaignId: $campaignId,
            adSetName:  'PHPUnit Ad Set',
            status:     'ACTIVE',
        );
        $this->createdAdSetIds[] = $adSetResult['id'];

        $creativeService = new CreativeService($this->customer);
        $creativeResult  = $creativeService->createImageCreative(
            $this->accountId, 'PHPUnit Creative', 'https://picsum.photos/1200/628', 'Headline', 'Description',
        );
        $this->createdCreativeIds[] = $creativeResult['id'];

        $adService = new AdService($this->customer);
        $adResult  = $adService->createAd($this->accountId, $adSetResult['id'], 'PHPUnit Ad', $creativeResult['id'], 'ACTIVE');
        $this->createdAdIds[] = $adResult['id'];

        $this->assertTrue($adService->pauseAd($adResult['id']));
        $this->assertTrue($adService->resumeAd($adResult['id']));
    }

    public function test_lists_campaigns_for_account(): void
    {
        $service = new CampaignService($this->customer);
        $result  = $service->listCampaigns($this->accountId);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('id', $result[0]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createTestCampaign(): string
    {
        $service = new CampaignService($this->customer);
        $result  = $service->createCampaign(
            accountId:    $this->accountId,
            campaignName: 'PHPUnit Test ' . now()->timestamp . '-' . rand(100, 999),
            objective:    'OUTCOME_TRAFFIC',
            dailyBudget:  500,
            status:       'PAUSED',
        );

        $this->createdCampaignIds[] = $result['id'];

        return $result['id'];
    }

    private function deleteCreatedResources(): void
    {
        $token   = config('services.facebook.system_user_token');
        $baseUrl = 'https://graph.facebook.com/v22.0';

        // Delete in reverse order: ads → ad sets → campaigns (creatives auto-deleted or orphaned)
        foreach ($this->createdAdIds as $id) {
            Http::withToken($token)->delete("{$baseUrl}/{$id}");
        }
        foreach ($this->createdAdSetIds as $id) {
            Http::withToken($token)->delete("{$baseUrl}/{$id}");
        }
        foreach ($this->createdCampaignIds as $id) {
            Http::withToken($token)->delete("{$baseUrl}/{$id}");
        }
        foreach ($this->createdCreativeIds as $id) {
            Http::withToken($token)->delete("{$baseUrl}/{$id}");
        }
    }
}
