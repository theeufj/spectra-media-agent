<?php

namespace Tests\Unit\FacebookAds;

use App\Models\Customer;
use App\Services\FacebookAds\CampaignService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CampaignServiceTest extends TestCase
{
    private Customer $customer;
    private CampaignService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = new Customer([
            'name' => 'Test Company',
            'facebook_ads_account_id' => '123456789',
            'facebook_bm_owned' => true,
        ]);
        $this->customer->id = 1;

        config(['services.facebook.system_user_token' => 'test-token']);

        $this->service = new CampaignService($this->customer);
    }

    public function test_create_campaign_sends_correct_payload(): void
    {
        Http::fake([
            'https://graph.facebook.com/v22.0/act_123456789/campaigns' => Http::response([
                'id' => '23726385536',
            ], 200),
        ]);

        $result = $this->service->createCampaign(
            accountId: '123456789',
            campaignName: 'Spring Sale 2025',
            objective: 'OUTCOME_TRAFFIC',
            dailyBudget: 50000,
        );

        $this->assertNotNull($result);
        $this->assertEquals('23726385536', $result['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'act_123456789/campaigns')
                && $request->data()['name'] === 'Spring Sale 2025'
                && $request->data()['objective'] === 'OUTCOME_TRAFFIC'
                && $request->data()['daily_budget'] == 50000
                && $request->data()['bid_strategy'] === 'LOWEST_COST_WITHOUT_CAP';
        });
    }

    /**
     * @dataProvider objectiveNormalisationProvider
     */
    public function test_create_campaign_normalises_legacy_objectives(string $input, string $expected): void
    {
        Http::fake([
            'https://graph.facebook.com/v22.0/act_123456789/campaigns' => Http::response([
                'id' => '111',
            ], 200),
        ]);

        $this->service->createCampaign(
            accountId: '123456789',
            campaignName: 'Objective Test',
            objective: $input,
        );

        Http::assertSent(function ($request) use ($expected) {
            return $request->data()['objective'] === $expected;
        });
    }

    public static function objectiveNormalisationProvider(): array
    {
        return [
            'LINK_CLICKS → OUTCOME_TRAFFIC'      => ['LINK_CLICKS', 'OUTCOME_TRAFFIC'],
            'TRAFFIC → OUTCOME_TRAFFIC'           => ['TRAFFIC', 'OUTCOME_TRAFFIC'],
            'CONVERSIONS → OUTCOME_SALES'         => ['CONVERSIONS', 'OUTCOME_SALES'],
            'SALES → OUTCOME_SALES'               => ['SALES', 'OUTCOME_SALES'],
            'LEAD_GENERATION → OUTCOME_LEADS'     => ['LEAD_GENERATION', 'OUTCOME_LEADS'],
            'BRAND_AWARENESS → OUTCOME_AWARENESS' => ['BRAND_AWARENESS', 'OUTCOME_AWARENESS'],
            'REACH → OUTCOME_AWARENESS'           => ['REACH', 'OUTCOME_AWARENESS'],
            'ENGAGEMENT → OUTCOME_ENGAGEMENT'     => ['ENGAGEMENT', 'OUTCOME_ENGAGEMENT'],
            'OUTCOME_TRAFFIC passthrough'         => ['OUTCOME_TRAFFIC', 'OUTCOME_TRAFFIC'],
            'OUTCOME_SALES passthrough'           => ['OUTCOME_SALES', 'OUTCOME_SALES'],
        ];
    }

    public function test_create_campaign_returns_null_on_api_failure(): void
    {
        Http::fake([
            'https://graph.facebook.com/v22.0/act_123456789/campaigns' => Http::response([
                'error' => ['message' => 'Permissions error', 'code' => 200],
            ], 400),
        ]);

        Log::spy();

        $result = $this->service->createCampaign(
            accountId: '123456789',
            campaignName: 'Fail Test',
        );

        $this->assertNull($result);
    }

    public function test_list_campaigns_returns_data(): void
    {
        Http::fake([
            'https://graph.facebook.com/v22.0/act_123456789/campaigns*' => Http::response([
                'data' => [
                    ['id' => '001', 'name' => 'Campaign A', 'status' => 'ACTIVE'],
                    ['id' => '002', 'name' => 'Campaign B', 'status' => 'PAUSED'],
                ],
            ], 200),
        ]);

        $result = $this->service->listCampaigns('123456789');

        $this->assertCount(2, $result);
        $this->assertEquals('Campaign A', $result[0]['name']);
    }

    public function test_list_campaigns_returns_empty_on_api_error(): void
    {
        Http::fake([
            'https://graph.facebook.com/v22.0/act_123456789/campaigns*' => Http::response([
                'error' => ['message' => 'Permission denied', 'code' => 200],
            ], 403),
        ]);

        Log::spy();

        $result = $this->service->listCampaigns('123456789');

        $this->assertEmpty($result);
    }

    public function test_update_campaign_returns_true_on_success(): void
    {
        Http::fake([
            'https://graph.facebook.com/v22.0/23726385536' => Http::response([
                'success' => true,
            ], 200),
        ]);

        $result = $this->service->updateCampaign('23726385536', [
            'name' => 'Updated Name',
            'status' => 'PAUSED',
        ]);

        $this->assertTrue($result);
    }

    public function test_update_campaign_returns_false_on_failure(): void
    {
        Http::fake([
            'https://graph.facebook.com/v22.0/23726385536' => Http::response([
                'error' => ['message' => 'Permission denied', 'code' => 200],
            ], 400),
        ]);

        Log::spy();

        $result = $this->service->updateCampaign('23726385536', ['status' => 'ACTIVE']);

        $this->assertFalse($result);
    }
}
