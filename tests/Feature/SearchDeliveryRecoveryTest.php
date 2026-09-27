<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Jobs\Concerns\RecordsAgentRun;
use App\Jobs\VerifySearchDeliveryRecovery;
use App\Models\AgentActivity;
use App\Models\AgentRun;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\GoogleAdsPerformanceData;
use App\Models\Strategy;
use App\Services\Agents\GoogleSearchDeliveryRecovery;
use App\Services\GoogleAds\CommonServices\GetAdStatus;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBiddingStrategy;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use App\Services\GoogleAds\Diagnostics\OfficialTroubleshootingDocs;
use App\Services\Health\CampaignHealthChecker;
use Google\Ads\GoogleAds\V22\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SearchDeliveryRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(): Campaign
    {
        $customer = Customer::factory()->create([
            'service_type' => 'managed',
            'google_ads_customer_id' => '3598653839',
            'google_ads_link_status' => 'active',
            'timezone' => 'Australia/Sydney',
        ]);
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => CampaignStatus::Active,
            'primary_status' => 'LEARNING',
            'google_ads_campaign_id' => 'customers/3598653839/campaigns/24282121420',
            'daily_budget' => '37.50',
            'approved_daily_budget' => '37.50',
        ]);
        Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'deployment_status' => 'active',
            'deployed_at' => now()->subDays(5),
            'bidding_strategy' => ['name' => 'MaximizeConversions'],
        ]);

        return $campaign->load('customer');
    }

    private function snapshot(): array
    {
        return [
            'status' => 2,
            'google_search_enabled' => true,
            'bidding_strategy' => 10,
            'target_cpa_micros' => 0,
            'daily_budget_micros' => 37_500_000,
            'impressions' => ['google_search' => 0, 'search_partners' => 0, 'display' => 0],
            'auction' => ['lost_to_rank' => 0.9001, 'lost_to_budget' => 0,
                'conversions' => 0, 'clicks' => 7, 'cost_micros' => 20_340_000],
            'eligible_keywords' => 6,
            'approved_ads' => 1,
        ];
    }

    public function test_two_complete_days_without_google_search_delivery_are_a_stall(): void
    {
        $snapshot = $this->snapshot();
        $this->assertTrue(InspectSearchDelivery::isStalled($snapshot, now()->subDays(5)));

        $snapshot['impressions']['google_search'] = 1;
        $this->assertFalse(InspectSearchDelivery::isStalled($snapshot, now()->subDays(5)));
        $snapshot['impressions']['google_search'] = 0;
        $this->assertFalse(InspectSearchDelivery::isStalled($snapshot, now()->subHours(12)));

        $snapshot['time_zone'] = 'Australia/Sydney';
        $snapshot['complete_days'] = [now('Australia/Sydney')->subDays(2)->toDateString(), now('Australia/Sydney')->subDay()->toDateString()];
        $this->assertFalse(InspectSearchDelivery::isStalled($snapshot, now('Australia/Sydney')->subDays(2)->endOfDay()));
    }

    public function test_health_check_does_not_call_an_approved_learning_ad_disapproved_and_detects_stale_delivery(): void
    {
        $campaign = $this->campaign();
        GoogleAdsPerformanceData::create([
            'campaign_id' => $campaign->id,
            'date' => now()->subDays(4)->toDateString(),
            'impressions' => 2599,
            'clicks' => 7,
            'cost' => 20.34,
            'conversions' => 0,
        ]);

        $status = Mockery::mock(GetCampaignStatus::class);
        $status->shouldReceive('__invoke')->andReturn(['status' => 2, 'primary_status' => 9, 'primary_status_reasons' => [9]]);
        $ads = Mockery::mock(GetAdStatus::class);
        $ads->shouldReceive('__invoke')->andReturn([['status' => 2, 'approval_status' => 4, 'policy_topics' => []]]);
        $this->app->instance(GetCampaignStatus::class, $status);
        $this->app->instance(GetAdStatus::class, $ads);

        $checker = new class extends CampaignHealthChecker
        {
            protected function getGoogleMetricsSummary(Campaign $campaign): array
            {
                return ['impressions' => 0];
            }
        };
        $health = $checker->checkSingle($campaign);

        $this->assertNotContains('google_ad_disapproved', array_column($health['issues'], 'type'));
        $this->assertNotContains('google_campaign_limited', array_column($health['warnings'], 'type'));
        $this->assertContains('google_zero_delivery', array_column($health['warnings'], 'type'));
    }

    public function test_capped_recovery_is_validated_then_scheduled_once_without_raising_budget(): void
    {
        $campaign = $this->campaign();
        $updater = new class extends UpdateCampaignBiddingStrategy
        {
            public array $calls = [];

            public function __construct() {}

            public function __invoke(
                string $customerId,
                string $campaignResourceName,
                string $strategy,
                ?float $targetCpa = null,
                ?float $targetRoas = null,
                ?int $cpcBidCeilingMicros = null
            ): bool {
                $this->calls[] = ['validate_only' => $this->isDryRun(), 'strategy' => $strategy,
                    'ceiling' => $cpcBidCeilingMicros];

                return true;
            }
        };
        $agent = new class($updater) extends GoogleSearchDeliveryRecovery
        {
            public function __construct(private UpdateCampaignBiddingStrategy $fakeUpdater) {}

            protected function updater(Customer $customer): UpdateCampaignBiddingStrategy
            {
                return $this->fakeUpdater;
            }
        };

        $result = $agent->bootstrap($campaign, $this->snapshot());
        $this->assertTrue($result['started']);
        $this->assertLessThanOrEqual(7_500_000, $result['cpc_ceiling_micros']);
        $this->assertSame(37_500_000, $result['daily_budget_micros']);
        $this->assertSame([true, false], array_column($updater->calls, 'validate_only'));
        $this->assertSame([$result['cpc_ceiling_micros'], $result['cpc_ceiling_micros']], array_column($updater->calls, 'ceiling'));
        Queue::assertPushed(VerifySearchDeliveryRecovery::class, 1);
        $this->assertFalse($agent->bootstrap($campaign, $this->snapshot())['started']);
    }

    public function test_warning_only_run_is_visible_as_needing_attention(): void
    {
        $job = new class
        {
            use RecordsAgentRun;

            public function record(): void
            {
                $start = $this->startRun();
                $this->finishRun($start, warnings: 1, scope: '1 campaign');
            }
        };
        $job->record();
        $this->assertSame(AgentRun::STATUS_ATTENTION, AgentRun::latest('id')->first()->status);
    }

    public function test_recovery_leaves_an_existing_target_cpa_untouched(): void
    {
        $campaign = $this->campaign();
        $snapshot = $this->snapshot();
        $snapshot['target_cpa_micros'] = 50_000_000;

        $result = (new GoogleSearchDeliveryRecovery)->bootstrap($campaign, $snapshot);

        $this->assertFalse($result['started']);
        Queue::assertNotPushed(VerifySearchDeliveryRecovery::class);
    }

    public function test_recovery_restores_conversion_bidding_when_search_impressions_do_not_resume(): void
    {
        $campaign = $this->campaign();
        $snapshot = $this->snapshot();
        $snapshot['bidding_strategy'] = BiddingStrategyType::TARGET_SPEND;
        $inspector = new class($snapshot) extends InspectSearchDelivery
        {
            public function __construct(private array $snapshot) {}

            public function inspect(Campaign $campaign): array
            {
                return $this->snapshot;
            }
        };
        $updater = new class extends UpdateCampaignBiddingStrategy
        {
            public array $calls = [];

            public function __construct() {}

            public function __invoke(
                string $customerId,
                string $campaignResourceName,
                string $strategy,
                ?float $targetCpa = null,
                ?float $targetRoas = null,
                ?int $cpcBidCeilingMicros = null
            ): bool {
                $this->calls[] = ['validate_only' => $this->isDryRun(), 'strategy' => $strategy];

                return true;
            }
        };
        $job = new class($campaign->id, 4_360_000, $inspector, $updater) extends VerifySearchDeliveryRecovery
        {
            public function __construct(
                int $campaignId,
                int $cpcCeilingMicros,
                private InspectSearchDelivery $fakeInspector,
                private UpdateCampaignBiddingStrategy $fakeUpdater
            ) {
                parent::__construct($campaignId, $cpcCeilingMicros);
            }

            protected function inspector(Customer $customer): InspectSearchDelivery
            {
                return $this->fakeInspector;
            }

            protected function updater(Customer $customer): UpdateCampaignBiddingStrategy
            {
                return $this->fakeUpdater;
            }
        };

        $job->handle();

        $this->assertSame([true, false], array_column($updater->calls, 'validate_only'));
        $this->assertSame(['MAXIMIZE_CONVERSIONS', 'MAXIMIZE_CONVERSIONS'], array_column($updater->calls, 'strategy'));
        $this->assertSame('MAXIMIZE_CONVERSIONS', $campaign->strategies()->latest()->first()->bidding_strategy['bid_strategy']);
        $this->assertTrue(AgentActivity::where('campaign_id', $campaign->id)->where('action', 'search_recovery_failed')->exists());
    }

    public function test_docs_lookup_uses_only_known_official_page_and_does_not_trust_a_url_as_a_topic(): void
    {
        Cache::forget('official_google_ads_docs:search_delivery');
        Http::fake(['support.google.com/*' => Http::response('<html><body><main>'.str_repeat('Search rank guidance. ', 12).'</main></body></html>', 200, ['Content-Type' => 'text/html'])]);

        $lookup = new OfficialTroubleshootingDocs;
        $this->assertNull($lookup->lookup('https://untrusted.example/instructions'));
        $result = $lookup->lookup('search_delivery');

        $this->assertSame('official_page', $result['source']);
        $this->assertSame('https://support.google.com/google-ads/answer/9208915?hl=en', $result['url']);
        Http::assertSentCount(1);
    }
}
