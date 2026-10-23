<?php

namespace Tests\Feature;

use App\Jobs\CheckGoogleCampaignSpendSafety;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\Campaigns\GoogleCampaignSpendSafety;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\CommonServices\GetCampaignSpendSafety;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Common\Metrics;
use Google\Ads\GoogleAds\V26\Common\TargetSpend;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Resources\AdGroupBidModifier;
use Google\Ads\GoogleAds\V26\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V26\Resources\Campaign as GoogleCampaign;
use Google\Ads\GoogleAds\V26\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V26\Resources\CampaignCriterion;
use Google\Ads\GoogleAds\V26\Resources\Customer as GoogleCustomer;
use Google\Ads\GoogleAds\V26\Services\Client\CampaignServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V26\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignResult;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignsResponse;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsRequest;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsResponse;
use Google\ApiCore\ApiException;
use Google\ApiCore\Testing\MockStatus;
use Google\ApiCore\Testing\MockTransport;
use Google\ApiCore\Testing\ReceivedRequest;
use Google\Auth\Credentials\InsecureCredentials;
use Google\Protobuf\Internal\Message;
use Google\Rpc\Code;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Real generated V26 clients and protobufs; the SDK transport never leaves this process. */
class GoogleSpendSafetyV26TransportTest extends TestCase
{
    use DatabaseTransactions;

    private MockTransport $transport;

    private GoogleAdsClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-10 00:30:00', 'UTC'));
        $this->transport = new MockTransport;
        $options = ['transport' => $this->transport, 'credentials' => new InsecureCredentials, 'disableRetries' => true];
        $search = new GoogleAdsServiceClient($options);
        $mutator = new CampaignServiceClient($options);
        $this->client = $this->createMock(GoogleAdsClient::class);
        $this->client->method('getGoogleAdsServiceClient')->willReturn($search);
        $this->client->method('getCampaignServiceClient')->willReturn($mutator);

        foreach ([GetCampaignSpendSafety::class, GetCampaignStatus::class, UpdateCampaignStatus::class] as $class) {
            $this->app->bind($class, fn ($app, $parameters) => $this->service($class, $parameters['customer']));
        }
    }

    /**
     * Skip credential discovery; all request creation, pagination, decoding and
     * exception handling below the service constructor remain real.
     *
     * @template T of BaseGoogleAdsService
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function service(string $class, Customer $customer): BaseGoogleAdsService
    {
        $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($service, $customer);
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($service, $this->client);

        return $service;
    }

    private function campaign(array $attributes = []): Campaign
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123-456-7890', 'service_type' => 'managed']);

        return Campaign::factory()->create($attributes + [
            'customer_id' => $customer->id, 'status' => 'active', 'platform_status' => 'ENABLED',
            'primary_status' => 'ELIGIBLE', 'end_date' => now()->addMonth(), 'google_ads_campaign_id' => '999',
        ]);
    }

    private function response(Message $response): void
    {
        $this->transport->addResponse($response, new MockStatus(Code::OK));
    }

    private function metrics(int $cost, float $conversions = 0): GoogleAdsRow
    {
        return new GoogleAdsRow(['metrics' => new Metrics(['cost_micros' => $cost, 'conversions' => $conversions])]);
    }

    private function snapshot(int $status = CampaignStatus::ENABLED, bool $pagination = false, bool $modifiers = false): void
    {
        $this->response(new SearchGoogleAdsResponse(['results' => [new GoogleAdsRow([
            'customer' => new GoogleCustomer(['time_zone' => 'America/Los_Angeles', 'currency_code' => 'AUD']),
            'campaign' => new GoogleCampaign([
                'resource_name' => 'customers/1234567890/campaigns/999', 'start_date_time' => '2026-09-01 23:59:59',
                'status' => $status, 'advertising_channel_type' => AdvertisingChannelType::SEARCH,
                'bidding_strategy_type' => BiddingStrategyType::TARGET_SPEND,
                'target_spend' => new TargetSpend(['cpc_bid_ceiling_micros' => 3_000_000]),
            ]),
            'campaign_budget' => new CampaignBudget(['amount_micros' => 10_000_000]),
        ])]]));
        if ($pagination) {
            $this->response(new SearchGoogleAdsResponse(['results' => [$this->metrics(2_147_483_648)], 'next_page_token' => 'lifetime-page-2']));
            $this->response(new SearchGoogleAdsResponse(['results' => [$this->metrics(2_147_483_649)]]));
        } else {
            $this->response(new SearchGoogleAdsResponse(['results' => [$this->metrics(60_000_001)]]));
        }
        // Lifetime matured, window matured, and recent conversion queries.
        for ($index = 0; $index < 3; $index++) {
            $this->response(new SearchGoogleAdsResponse(['results' => [$this->metrics(60_000_001)]]));
        }
        $this->response(new SearchGoogleAdsResponse(['results' => $modifiers ? [new GoogleAdsRow(['campaign_criterion' => new CampaignCriterion([
            'resource_name' => 'customers/1234567890/campaignCriteria/1', 'bid_modifier' => 1.1,
        ])])] : []]));
        $this->response(new SearchGoogleAdsResponse(['results' => $modifiers ? [new GoogleAdsRow(['ad_group_bid_modifier' => new AdGroupBidModifier([
            'resource_name' => 'customers/1234567890/adGroupBidModifiers/2~3', 'bid_modifier' => 1.2,
        ])])] : []]));
        $this->response(new SearchGoogleAdsResponse(['results' => $modifiers ? [new GoogleAdsRow(['ad_group_criterion' => new AdGroupCriterion([
            'resource_name' => 'customers/1234567890/adGroupCriteria/4~5', 'bid_modifier' => 1.3,
        ])])] : []]));
    }

    private function pauseAccepted(): void
    {
        $this->response(new MutateCampaignsResponse(['results' => [new MutateCampaignResult([
            'resource_name' => 'customers/1234567890/campaigns/999',
        ])]]));
    }

    private function readback(int $status): void
    {
        $this->response(new SearchGoogleAdsResponse(['results' => [new GoogleAdsRow([
            'campaign' => new GoogleCampaign(['status' => $status]),
        ])]]));
    }

    public function test_real_v26_search_requests_decode_local_datetime_integer_metrics_and_paginated_results(): void
    {
        $campaign = $this->campaign();
        $this->snapshot(pagination: true, modifiers: true);

        $snapshot = $this->service(GetCampaignSpendSafety::class, $campaign->customer)->forCampaign($campaign);

        $this->assertSame(4_294_967_297, $snapshot['cost_micros']);
        $this->assertSame('2026-09-01', $snapshot['campaign_start_date']);
        $this->assertSame('America/Los_Angeles', $snapshot['account_timezone']);
        $this->assertSame('2026-10-07', $snapshot['matured_through']);
        $this->assertSame(10_000_000, $snapshot['daily_budget_micros']);
        foreach ([1.1, 1.2, 1.3] as $index => $multiplier) {
            $this->assertEqualsWithDelta($multiplier, $snapshot['amplifying_bid_modifiers'][$index]['multiplier'], 0.000001);
        }
        $calls = $this->transport->popReceivedCalls();
        $this->assertCount(9, $calls);
        foreach ($calls as $call) {
            $this->assertSame('/google.ads.googleads.v26.services.GoogleAdsService/Search', $call->getFuncCall());
            $request = $call->getRequestObject();
            $this->assertInstanceOf(SearchGoogleAdsRequest::class, $request);
            $this->assertSame('1234567890', $request->getCustomerId());
            $this->assertStringContainsString("campaign.resource_name = 'customers/1234567890/campaigns/999'", $request->getQuery());
        }
        $this->assertStringContainsString('campaign.start_date_time', $calls[0]->getRequestObject()->getQuery());
        $this->assertStringNotContainsString('segments.date', $calls[1]->getRequestObject()->getQuery());
        $this->assertSame('lifetime-page-2', $calls[2]->getRequestObject()->getPageToken());
        $this->assertStringContainsString("BETWEEN '2026-09-01' AND '2026-10-07'", $calls[3]->getRequestObject()->getQuery());
    }

    public function test_a_real_v26_pause_request_is_only_recorded_as_success_after_a_paused_readback(): void
    {
        $campaign = $this->campaign();
        $this->snapshot();
        $this->pauseAccepted();
        $this->readback(CampaignStatus::PAUSED);

        $result = (new GoogleCampaignSpendSafety)->check($campaign);

        $this->assertSame('paused', $result['action']);
        $this->assertSame('paused', $campaign->fresh()->status->value);
        $this->assertNotEmpty($campaign->fresh()->spend_safety_hold['verified_at']);
        $calls = $this->transport->popReceivedCalls();
        $this->assertCount(10, $calls);
        $this->assertPauseRequest($calls[8]);
        $this->assertStringContainsString('campaign.primary_status', $calls[9]->getRequestObject()->getQuery());
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'campaign_spend_safety_paused')->count());
    }

    public function test_a_rejected_v26_mutation_preserves_an_unverified_hold_and_the_job_rethrows(): void
    {
        $campaign = $this->campaign();
        $this->snapshot();
        $this->transport->addResponse(new MutateCampaignsResponse, new MockStatus(Code::PERMISSION_DENIED, 'Pause denied'));

        $this->assertUnverifiedFailure($campaign, 'rejected');

        $calls = $this->transport->popReceivedCalls();
        $this->assertCount(9, $calls);
        $this->assertPauseRequest($calls[8]);
    }

    public function test_an_enabled_readback_remains_unverified_then_a_retry_verifies_without_a_second_pause(): void
    {
        $campaign = $this->campaign();
        $this->snapshot();
        $this->pauseAccepted();
        $this->readback(CampaignStatus::ENABLED);
        $this->assertUnverifiedFailure($campaign, 'not verified');
        $requestedAt = $campaign->fresh()->spend_safety_hold['requested_at'];
        $firstCalls = $this->transport->popReceivedCalls();
        $this->assertCount(10, $firstCalls);
        $this->assertPauseRequest($firstCalls[8]);

        $this->snapshot(CampaignStatus::PAUSED);
        $this->readback(CampaignStatus::PAUSED);
        $this->assertSame('paused', (new GoogleCampaignSpendSafety)->check($campaign)['action']);
        $this->assertSame($requestedAt, $campaign->fresh()->spend_safety_hold['requested_at']);
        $this->assertCount(9, $this->transport->popReceivedCalls());

        $this->snapshot(CampaignStatus::PAUSED);
        $this->assertSame('held', (new GoogleCampaignSpendSafety)->check($campaign)['action']);
        $this->assertCount(8, $this->transport->popReceivedCalls());
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'campaign_spend_safety_paused')->count());
    }

    public function test_an_sdk_readback_error_cannot_claim_a_pause_or_silently_consume_the_queue_retry(): void
    {
        $campaign = $this->campaign();
        $this->snapshot();
        $this->pauseAccepted();
        $this->transport->addResponse(new SearchGoogleAdsResponse, new MockStatus(Code::DEADLINE_EXCEEDED, 'Read-back deadline exceeded'));

        $this->assertUnverifiedFailure($campaign, 'not verified');

        $this->assertCount(10, $this->transport->popReceivedCalls());
    }

    public function test_an_sdk_snapshot_failure_is_reported_and_rethrown_without_zero_spend_or_local_pause(): void
    {
        $campaign = $this->campaign();
        $this->transport->addResponse(new SearchGoogleAdsResponse, new MockStatus(Code::INVALID_ARGUMENT, 'Unsupported API request'));
        try {
            (new CheckGoogleCampaignSpendSafety($campaign->id))->handle(new GoogleCampaignSpendSafety);
            $this->fail('A failed live snapshot must reach the queue retry.');
        } catch (ApiException $e) {
            $this->assertStringContainsString('Unsupported API request', $e->getMessage());
        }

        $this->assertSame('active', $campaign->fresh()->status->value);
        $this->assertNull($campaign->fresh()->spend_safety_hold);
        $this->assertSame(0, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'campaign_spend_safety_paused')->count());
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'campaign_spend_safety_failed')->count());
        $calls = $this->transport->popReceivedCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('/google.ads.googleads.v26.services.GoogleAdsService/Search', $calls[0]->getFuncCall());
    }

    private function assertUnverifiedFailure(Campaign $campaign, string $message): void
    {
        try {
            (new CheckGoogleCampaignSpendSafety($campaign->id))->handle(new GoogleCampaignSpendSafety);
            $this->fail('The queue job must fail while the platform pause remains unverified.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $fresh = $campaign->fresh();
        $this->assertSame('active', $fresh->status->value);
        $this->assertSame('ENABLED', $fresh->platform_status);
        $this->assertNotEmpty($fresh->spend_safety_hold);
        $this->assertArrayNotHasKey('verified_at', $fresh->spend_safety_hold);
        $this->assertFalse(CampaignSpendGuardrails::canEnable($fresh, true));
        $this->assertSame(0, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'campaign_spend_safety_paused')->count());
        $this->assertDatabaseHas('agent_runs', ['job' => 'CheckGoogleCampaignSpendSafety', 'status' => 'failed', 'errors' => 1]);
    }

    private function assertPauseRequest(ReceivedRequest $call): void
    {
        $this->assertSame('/google.ads.googleads.v26.services.CampaignService/MutateCampaigns', $call->getFuncCall());
        $request = $call->getRequestObject();
        $this->assertInstanceOf(MutateCampaignsRequest::class, $request);
        $this->assertSame('1234567890', $request->getCustomerId());
        $this->assertFalse($request->getValidateOnly());
        $this->assertCount(1, $request->getOperations());
        $operation = $request->getOperations()[0];
        $this->assertSame('customers/1234567890/campaigns/999', $operation->getUpdate()->getResourceName());
        $this->assertSame(CampaignStatus::PAUSED, $operation->getUpdate()->getStatus());
        $this->assertSame(['status'], iterator_to_array($operation->getUpdateMask()->getPaths()));
    }
}
