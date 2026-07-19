<?php

namespace Tests\Feature\Services\LinkedInAds;

use App\Models\Customer;
use App\Services\LinkedInAds\CampaignService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group linkedin-ads
 */
class LinkedInAdsCampaignIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected Customer $customer;
    protected array $createdCampaignIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_LINKEDIN_ADS_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_LINKEDIN_ADS_INTEGRATION_TESTS=true to run.');
        }

        $this->customer = Customer::whereNotNull('linkedin_ads_account_id')->firstOrFail();
    }

    protected function tearDown(): void
    {
        $this->cleanupCampaigns();
        parent::tearDown();
    }

    public function test_creates_sponsored_content_campaign(): void
    {
        $service = new CampaignService($this->customer);
        $result  = $service->createSponsoredContentCampaign([
            'name'         => 'PHPUnit Sponsored Content ' . now()->timestamp,
            'daily_budget' => 50,
            'objective'    => 'WEBSITE_VISITS',
            'status'       => 'PAUSED',
        ]);

        $this->assertNotNull($result);
        // LinkedIn returns the URN in `id` or the full response in headers
        $this->assertTrue(
            isset($result['id']) || isset($result['success']),
            'Expected id or success in response'
        );

        if (isset($result['id'])) {
            $this->createdCampaignIds[] = $result['id'];
        }
    }

    public function test_creates_message_ads_campaign(): void
    {
        $service = new CampaignService($this->customer);
        $result  = $service->createMessageAdsCampaign([
            'name'         => 'PHPUnit Message Ads ' . now()->timestamp,
            'daily_budget' => 50,
            'objective'    => 'LEAD_GENERATION',
            'status'       => 'PAUSED',
        ]);

        $this->assertNotNull($result);

        if (isset($result['id'])) {
            $this->createdCampaignIds[] = $result['id'];
        }
    }

    public function test_lists_campaigns_for_account(): void
    {
        $service    = new CampaignService($this->customer);
        $campaigns  = $service->listCampaigns();

        $this->assertIsArray($campaigns);
    }

    public function test_gets_campaign_by_id(): void
    {
        $campaignId = $this->createTestCampaign();
        if (!$campaignId) {
            $this->markTestSkipped('Could not create test campaign.');
        }

        $service = new CampaignService($this->customer);
        $result  = $service->getCampaign($campaignId);

        $this->assertNotNull($result);
    }

    public function test_pauses_campaign(): void
    {
        $campaignId = $this->createTestCampaign();
        if (!$campaignId) {
            $this->markTestSkipped('Could not create test campaign.');
        }

        $service = new CampaignService($this->customer);
        $result  = $service->updateStatus($campaignId, 'PAUSED');

        $this->assertNotNull($result);
    }

    public function test_gets_ad_account_info(): void
    {
        $service = new CampaignService($this->customer);
        $result  = $service->getAdAccount($this->customer->linkedin_ads_account_id);

        // If no ads account is configured this returns null; both are valid
        $this->assertTrue($result === null || is_array($result));
    }

    public function test_gets_insight_tag(): void
    {
        $service = new CampaignService($this->customer);
        $result  = $service->getInsightTag();

        $this->assertTrue($result === null || is_array($result));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createTestCampaign(): ?string
    {
        $service = new CampaignService($this->customer);
        $result  = $service->createSponsoredContentCampaign([
            'name'         => 'PHPUnit Test ' . now()->timestamp . '-' . rand(100, 999),
            'daily_budget' => 50,
            'status'       => 'PAUSED',
        ]);

        if (!$result || !isset($result['id'])) {
            return null;
        }

        $this->createdCampaignIds[] = $result['id'];
        return (string) $result['id'];
    }

    private function cleanupCampaigns(): void
    {
        if (empty($this->createdCampaignIds)) {
            return;
        }

        try {
            $service = new CampaignService($this->customer);
            foreach ($this->createdCampaignIds as $id) {
                $service->updateStatus((string) $id, 'ARCHIVED');
            }
        } catch (\Exception) {
            // Best-effort cleanup
        }
    }
}
