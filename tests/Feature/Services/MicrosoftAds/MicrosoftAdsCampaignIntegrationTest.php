<?php

namespace Tests\Feature\Services\MicrosoftAds;

use App\Models\Customer;
use App\Services\MicrosoftAds\AdGroupService;
use App\Services\MicrosoftAds\CampaignService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group microsoft-ads
 */
class MicrosoftAdsCampaignIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected Customer $customer;
    protected array $createdCampaignIds = [];
    protected array $createdAdGroupIds  = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_MICROSOFT_ADS_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_MICROSOFT_ADS_INTEGRATION_TESTS=true to run.');
        }

        $this->customer = Customer::whereNotNull('microsoft_ads_account_id')->firstOrFail();
    }

    protected function tearDown(): void
    {
        $this->cleanupCampaigns();
        parent::tearDown();
    }

    public function test_creates_search_campaign(): void
    {
        $service = new CampaignService($this->customer);
        $result  = $service->createSearchCampaign([
            'name'         => 'PHPUnit Search Test ' . now()->timestamp,
            'daily_budget' => 50.00,
            'status'       => 'Paused',
        ]);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('CampaignIds', $result);
        $this->assertNotEmpty($result['CampaignIds']);

        $id = is_array($result['CampaignIds']) ? $result['CampaignIds'][0] : $result['CampaignIds'];
        $this->createdCampaignIds[] = $id;
    }

    public function test_gets_campaign_by_id(): void
    {
        $campaignId = $this->createTestCampaign();

        $service = new CampaignService($this->customer);
        $result  = $service->getCampaign($campaignId);

        $this->assertNotNull($result);
    }

    public function test_updates_campaign_budget(): void
    {
        $campaignId = $this->createTestCampaign();

        $service = new CampaignService($this->customer);
        $updated = $service->updateBudget($campaignId, 75.00);

        $this->assertTrue($updated);
    }

    public function test_pauses_campaign(): void
    {
        $campaignId = $this->createTestCampaign();

        $service = new CampaignService($this->customer);
        $updated = $service->updateStatus($campaignId, 'Paused');

        $this->assertTrue($updated);
    }

    public function test_creates_ad_group_inside_campaign(): void
    {
        if (!class_exists(AdGroupService::class)) {
            $this->markTestSkipped('AdGroupService not found.');
        }

        $campaignId = $this->createTestCampaign();

        $service = new AdGroupService($this->customer);
        $result  = $service->createAdGroup([
            'campaign_id' => $campaignId,
            'name'        => 'PHPUnit Ad Group ' . now()->timestamp,
            'cpc_bid'     => 1.50,
            'status'      => 'Paused',
        ]);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('AdGroupIds', $result);

        $id = is_array($result['AdGroupIds']) ? $result['AdGroupIds'][0] : $result['AdGroupIds'];
        $this->createdAdGroupIds[] = $id;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createTestCampaign(): string
    {
        $service = new CampaignService($this->customer);
        $result  = $service->createSearchCampaign([
            'name'         => 'PHPUnit Test ' . now()->timestamp . '-' . rand(100, 999),
            'daily_budget' => 50.00,
            'status'       => 'Paused',
        ]);

        $id = is_array($result['CampaignIds']) ? $result['CampaignIds'][0] : $result['CampaignIds'];
        $this->createdCampaignIds[] = $id;

        return (string) $id;
    }

    private function cleanupCampaigns(): void
    {
        if (empty($this->createdCampaignIds)) {
            return;
        }

        try {
            $service = new CampaignService($this->customer);
            foreach ($this->createdCampaignIds as $id) {
                $service->updateStatus((string) $id, 'Deleted');
            }
        } catch (\Exception $e) {
            // Best-effort cleanup — log but don't fail teardown
        }
    }
}
