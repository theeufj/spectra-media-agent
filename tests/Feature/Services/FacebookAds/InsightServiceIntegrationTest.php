<?php

namespace Tests\Feature\Services\FacebookAds;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\FacebookAds\InsightService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group facebook
 */
class InsightServiceIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected Customer $customer;
    protected string $accountId;
    protected InsightService $service;
    protected Campaign $liveCampaign;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_FACEBOOK_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_FACEBOOK_INTEGRATION_TESTS=true to run.');
        }

        config(['services.facebook.system_user_token' => env('FACEBOOK_SYSTEM_USER_TOKEN')]);

        $this->customer = Customer::whereNotNull('facebook_ads_account_id')->firstOrFail();
        $this->accountId = $this->customer->facebook_ads_account_id;
        $this->service   = new InsightService($this->customer);

        $live = Campaign::where('customer_id', $this->customer->id)
            ->whereNotNull('facebook_ads_campaign_id')
            ->first();

        if (!$live) {
            $this->markTestSkipped('No deployed Facebook campaign found in DB.');
        }

        $this->liveCampaign = $live;
    }

    public function test_gets_campaign_insights_returns_array(): void
    {
        $result = $this->service->getCampaignInsights(
            $this->liveCampaign->facebook_ads_campaign_id,
            now()->subDays(30)->toDateString(),
            now()->toDateString(),
        );

        $this->assertIsArray($result);
    }

    public function test_campaign_insights_have_expected_fields(): void
    {
        $result = $this->service->getCampaignInsights(
            $this->liveCampaign->facebook_ads_campaign_id,
            now()->subDays(30)->toDateString(),
            now()->toDateString(),
        );

        foreach ($result as $row) {
            $this->assertArrayHasKey('impressions', $row);
            $this->assertArrayHasKey('spend', $row);
            $this->assertArrayHasKey('date_start', $row);
            $this->assertArrayHasKey('date_stop', $row);
        }
    }

    public function test_future_date_range_returns_empty_array(): void
    {
        $result = $this->service->getCampaignInsights(
            $this->liveCampaign->facebook_ads_campaign_id,
            now()->addDays(10)->toDateString(),
            now()->addDays(20)->toDateString(),
        );

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_gets_account_insights(): void
    {
        $result = $this->service->getAccountInsights(
            $this->accountId,
            now()->subDays(30)->toDateString(),
            now()->toDateString(),
        );

        $this->assertIsArray($result);
    }

    public function test_parse_action_extracts_lead_value(): void
    {
        $actions = [
            ['action_type' => 'link_click',                        'value' => '50'],
            ['action_type' => 'offsite_conversion.fb_pixel_lead',  'value' => '3'],
            ['action_type' => 'purchase',                          'value' => '1'],
        ];

        $leads = $this->service->parseAction($actions, 'offsite_conversion.fb_pixel_lead');

        $this->assertEquals(3.0, $leads);
    }

    public function test_parse_action_returns_zero_for_missing_type(): void
    {
        $actions = [
            ['action_type' => 'link_click', 'value' => '10'],
        ];

        $result = $this->service->parseAction($actions, 'purchase');

        $this->assertEquals(0.0, $result);
    }

    public function test_parse_action_returns_zero_for_null_input(): void
    {
        $result = $this->service->parseAction(null, 'purchase');

        $this->assertEquals(0.0, $result);
    }

    public function test_gets_insights_by_level(): void
    {
        $result = $this->service->getAccountInsightsByLevel(
            'act_' . $this->accountId,
            now()->subDays(7)->toDateString(),
            now()->toDateString(),
            'campaign',
        );

        $this->assertIsArray($result);
    }
}
