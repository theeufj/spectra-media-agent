<?php

namespace Tests\Unit\FacebookAds;

use App\Models\Customer;
use App\Services\FacebookAds\AdSetService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AdSetServiceTest extends TestCase
{
    private Customer $customer;
    private AdSetService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = new Customer([
            'name' => 'Test Company',
            'facebook_ads_account_id' => '123456789',
            'facebook_bm_owned' => true,
        ]);
        $this->customer->id = 1;

        // Set system user token so BaseFacebookAdsService resolves it
        config(['services.facebook.system_user_token' => 'test-token']);

        $this->service = new AdSetService($this->customer);
    }

    public function test_create_ad_set_injects_advantage_audience_flag(): void
    {
        Http::fake([
            'https://graph.facebook.com/v22.0/act_123456789/adsets' => Http::response([
                'id' => '9876543210',
            ], 200),
        ]);

        $result = $this->service->createAdSet(
            accountId: '123456789',
            campaignId: '111222333',
            adSetName: 'Test Ad Set',
            targeting: [
                'geo_locations' => ['countries' => ['US']],
                'age_min' => 25,
                'age_max' => 55,
            ],
        );

        $this->assertNotNull($result);
        $this->assertEquals('9876543210', $result['id']);

        Http::assertSent(function ($request) {
            $targeting = json_decode($request->data()['targeting'] ?? '{}', true);
            return isset($targeting['targeting_automation']['advantage_audience'])
                && $targeting['targeting_automation']['advantage_audience'] === 1
                && $targeting['geo_locations']['countries'] === ['US'];
        });
    }

    public function test_create_ad_set_with_empty_targeting_still_adds_advantage_audience(): void
    {
        Http::fake([
            'https://graph.facebook.com/v22.0/act_123456789/adsets' => Http::response([
                'id' => '9876543210',
            ], 200),
        ]);

        $result = $this->service->createAdSet(
            accountId: '123456789',
            campaignId: '111222333',
            adSetName: 'Empty Targeting Set',
        );

        $this->assertNotNull($result);

        Http::assertSent(function ($request) {
            $targeting = json_decode($request->data()['targeting'] ?? '{}', true);
            return isset($targeting['targeting_automation']['advantage_audience'])
                && $targeting['targeting_automation']['advantage_audience'] === 1;
        });
    }

    public function test_create_ad_set_preserves_existing_advantage_audience_setting(): void
    {
        Http::fake([
            'https://graph.facebook.com/v22.0/act_123456789/adsets' => Http::response([
                'id' => '9876543210',
            ], 200),
        ]);

        $this->service->createAdSet(
            accountId: '123456789',
            campaignId: '111222333',
            adSetName: 'Manual Override Set',
            targeting: [
                'targeting_automation' => ['advantage_audience' => 0],
                'geo_locations' => ['countries' => ['GB']],
            ],
        );

        Http::assertSent(function ($request) {
            $targeting = json_decode($request->data()['targeting'] ?? '{}', true);
            // Existing value should be preserved, not overwritten
            return $targeting['targeting_automation']['advantage_audience'] === 0;
        });
    }

    public function test_create_ad_set_uses_impressions_billing_event(): void
    {
        Http::fake([
            'https://graph.facebook.com/v22.0/act_123456789/adsets' => Http::response([
                'id' => '9876543210',
            ], 200),
        ]);

        $this->service->createAdSet(
            accountId: '123456789',
            campaignId: '111222333',
            adSetName: 'Billing Test',
        );

        Http::assertSent(function ($request) {
            return $request->data()['billing_event'] === 'IMPRESSIONS';
        });
    }

    public function test_create_ad_set_returns_null_on_api_failure(): void
    {
        Http::fake([
            'https://graph.facebook.com/v22.0/act_123456789/adsets' => Http::response([
                'error' => ['message' => 'Invalid parameter', 'code' => 100],
            ], 400),
        ]);

        Log::spy();

        $result = $this->service->createAdSet(
            accountId: '123456789',
            campaignId: '111222333',
            adSetName: 'Will Fail',
        );

        $this->assertNull($result);
    }

    public function test_list_ad_sets_returns_data(): void
    {
        Http::fake([
            'https://graph.facebook.com/v22.0/111222333/adsets*' => Http::response([
                'data' => [
                    ['id' => '001', 'name' => 'Ad Set 1', 'status' => 'ACTIVE'],
                    ['id' => '002', 'name' => 'Ad Set 2', 'status' => 'PAUSED'],
                ],
            ], 200),
        ]);

        Log::spy();

        $result = $this->service->listAdSets('111222333');

        $this->assertCount(2, $result);
        $this->assertEquals('Ad Set 1', $result[0]['name']);
    }

    public function test_list_ad_sets_returns_empty_on_no_data(): void
    {
        Http::fake([
            'https://graph.facebook.com/v22.0/111222333/adsets*' => Http::response([
                'data' => [],
            ], 200),
        ]);

        $result = $this->service->listAdSets('111222333');

        $this->assertEmpty($result);
    }
}
