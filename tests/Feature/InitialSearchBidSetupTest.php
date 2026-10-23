<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionResult;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\UpdateAdGroupCpcBid;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use App\Services\GoogleAds\InitialSearchBidPlanner;
use App\Services\GoogleAds\InitialSearchBidSetup;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus as GoogleStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InitialSearchBidSetupTest extends TestCase
{
    use DatabaseTransactions;

    private const ACCOUNT = '1234567890';

    private const CAMPAIGN = 'customers/1234567890/campaigns/456';

    private const GROUP = 'customers/1234567890/adGroups/789';

    private const BID = 1_200_000;

    private ?array $remote;

    private array $proposal;

    private array $writes = [];

    private int $plannerCalls = 0;

    private mixed $afterPlan = null;

    private mixed $afterStatus = null;

    private mixed $afterWrite = null;

    private ?int $remoteStatus = GoogleStatus::PAUSED;

    private bool $writeAccepted = true;

    private bool $applyRemoteBid = true;

    protected function setUp(): void
    {
        parent::setUp();
        Exceptions::fake();
        $this->proposal = ['ready' => true, 'status' => 'estimated', 'cpc_bid_micros' => self::BID,
            'activation_ready' => false, 'evidence' => ['historical' => ['success' => true], 'forecast' => ['success' => true]]];

        $inspector = $this->createMock(InspectSearchDelivery::class);
        $inspector->method('inspect')->willReturnCallback(function (Campaign $campaign): ?array {
            $this->assertSame(self::CAMPAIGN, $campaign->google_ads_campaign_id);
            $this->assertSame(self::ACCOUNT, $campaign->customer->cleanGoogleCustomerId());

            return $this->remote;
        });
        $this->app->bind(InspectSearchDelivery::class, fn () => $inspector);
        $planner = $this->createMock(InitialSearchBidPlanner::class);
        $planner->method('plan')->willReturnCallback(function (Campaign $campaign, array $snapshot): array {
            $this->plannerCalls++;
            $this->assertSame(5_000_000, $snapshot['authorized_daily_budget_micros']);
            if ($this->afterPlan) {
                ($this->afterPlan)($campaign, $snapshot);
            }

            return $this->proposal;
        });
        $this->app->bind(InitialSearchBidPlanner::class, fn () => $planner);
        $status = $this->createMock(GetCampaignStatus::class);
        $status->method('__invoke')->willReturnCallback(function (string $account, string $resource): ?array {
            $this->assertSame(self::ACCOUNT, $account);
            $this->assertSame(self::CAMPAIGN, $resource);
            if ($this->afterStatus) {
                ($this->afterStatus)();
            }

            return $this->remoteStatus === null ? null : ['status' => $this->remoteStatus];
        });
        $this->app->bind(GetCampaignStatus::class, fn () => $status);
        $writer = $this->createMock(UpdateAdGroupCpcBid::class);
        $writer->method('__invoke')->willReturnCallback(function (string $account, string $resource, int $bid): bool {
            $this->writes[] = compact('account', 'resource', 'bid');
            $this->assertSame(self::ACCOUNT, $account);
            $this->assertSame(self::GROUP, $resource);
            if ($this->applyRemoteBid) {
                $this->setRemoteBid($bid);
            }
            if ($this->afterWrite) {
                ($this->afterWrite)();
            }

            return $this->writeAccepted;
        });
        $this->app->bind(UpdateAdGroupCpcBid::class, fn () => $writer);
    }

    private function fixture(string $service = 'setup_only'): array
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => self::ACCOUNT,
            'google_ads_link_status' => 'active', 'currency_code' => 'AUD', 'is_sandbox' => false,
            'service_type' => $service, 'setup_fee_paid_at' => $service === 'setup_only' ? now() : null]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'paused',
            'platform_status' => 'PAUSED', 'google_ads_campaign_id' => self::CAMPAIGN,
            'daily_budget' => '5.00', 'approved_daily_budget' => '5.00', 'budget_confirmed_at' => now(),
            'end_date' => now()->addMonth(), 'spend_safety_hold' => null, 'spend_guardrails' => null]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'campaign_type' => 'search', 'daily_budget' => '5.00', 'google_ads_campaign_id' => self::CAMPAIGN,
            'google_ads_ad_group_id' => self::GROUP, 'signed_off_at' => now(),
            'bidding_strategy' => ['name' => 'ManualCpc', 'parameters' => ['cpcBidCeilingMicros' => 2_000_000]],
            'execution_result' => ['metadata' => ['unrelated_evidence' => ['preserved' => true]]]]);
        $this->remote = ['status' => GoogleStatus::PAUSED, 'channel' => AdvertisingChannelType::SEARCH,
            'bidding_strategy' => BiddingStrategyType::MANUAL_CPC, 'daily_budget_micros' => 5_000_000,
            'actual_cpc_ceiling_micros' => 10_000, 'cpc_bid_ceiling_micros' => 10_000,
            'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney', 'account_time_zone_verified' => true,
            'google_search_enabled' => true, 'search_partners_enabled' => false, 'display_enabled' => false,
            'portfolio_bidding_strategy' => '', 'target_cpa_micros' => 0, 'approved_ads' => 1,
            'geo_target_constants' => ['geoTargetConstants/2036'], 'excluded_geo_target_constants' => [],
            'language_constants' => ['languageConstants/1000'], 'negative_keywords' => [], 'ad_schedule' => [],
            'device_bid_modifiers' => [], 'unsupported_targeting' => [], 'targeting_setting' => [],
            'ad_group_targeting_modes' => [], 'audience_criteria' => [],
            'keywords' => [['resource_name' => 'customers/1234567890/adGroupCriteria/789~100',
                'ad_group_resource' => self::GROUP, 'text' => 'google ads setup', 'match_type' => 'PHRASE',
                'status' => 'ENABLED', 'ad_group_status' => 'ENABLED', 'cpc_bid_micros' => 10_000,
                'keyword_cpc_bid_micros' => 0, 'ad_group_cpc_bid_micros' => 10_000]]];

        return [$customer, $campaign, $strategy];
    }

    private function setRemoteBid(int $bid): void
    {
        $this->remote['actual_cpc_ceiling_micros'] = $this->remote['cpc_bid_ceiling_micros'] = $bid;
        foreach ($this->remote['keywords'] as &$keyword) {
            $keyword['ad_group_cpc_bid_micros'] = $keyword['cpc_bid_micros'] = $bid;
        }
        unset($keyword);
    }

    /** @param-out ExecutionResult $result */
    private function configure(Customer $customer, Campaign $campaign, Strategy $strategy, ?ExecutionResult &$result = null): bool
    {
        $result = ExecutionResult::success();

        return (new InitialSearchBidSetup($customer))->configure($campaign, $strategy,
            self::ACCOUNT, self::CAMPAIGN, self::GROUP, $result);
    }

    #[DataProvider('services')]
    public function test_estimated_bid_is_verified_for_managed_and_paid_setup_without_changing_pause_or_budget(string $service): void
    {
        [$customer, $campaign, $strategy] = $this->fixture($service);

        $this->assertTrue($this->configure($customer, $campaign, $strategy, $result), $result->errorMessage());

        $this->assertSame([['account' => self::ACCOUNT, 'resource' => self::GROUP, 'bid' => self::BID]], $this->writes);
        $evidence = $strategy->fresh()->execution_result['metadata']['initial_search_bid'];
        $this->assertSame('verified', $evidence['status']);
        $this->assertSame(self::BID, $evidence['cpc_bid_micros']);
        $this->assertSame(self::CAMPAIGN, $evidence['campaign_resource']);
        $this->assertSame(self::GROUP, $evidence['ad_group_resource']);
        $this->assertFalse($evidence['activation_ready']);
        $this->assertNotEmpty($evidence['verified_at']);
        $this->assertSame($evidence, $result->metadata['initial_search_bid']);
        $this->assertTrue($strategy->fresh()->execution_result['metadata']['unrelated_evidence']['preserved']);
        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
        $this->assertSame('PAUSED', $campaign->fresh()->platform_status);
        $this->assertSame('5.00', $campaign->fresh()->daily_budget);
        $this->assertSame('5.00', $campaign->fresh()->approved_daily_budget);
        $this->assertSame('5.00', $strategy->fresh()->daily_budget);
        $this->assertSame(GoogleStatus::PAUSED, $this->remote['status']);
        $this->assertSame(5_000_000, $this->remote['daily_budget_micros']);
    }

    public static function services(): array
    {
        return ['managed' => ['managed'], 'paid setup' => ['setup_only']];
    }

    public function test_keyword_cost_research_selects_and_verifies_the_stronger_affordable_bid_on_a_paused_campaign(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $bidding = $strategy->bidding_strategy;
        $bidding['parameters']['initialCpcBidMicros'] = 1_800_000;
        $strategy->update(['bidding_strategy' => $bidding]);
        $historical = $this->createMock(\App\Services\GoogleAds\KeywordResearch\GenerateKeywordHistoricalMetrics::class);
        $historical->method('__invoke')->willReturn(['success' => true, 'keywords' => [[
            'text' => 'google ads setup', 'keyword' => 'google ads setup',
            'low_top_of_page_bid_micros' => 1_000_000, 'high_top_of_page_bid_micros' => 1_800_000,
        ]]]);
        $tested = [];
        $forecast = $this->createMock(\App\Services\GoogleAds\KeywordResearch\GenerateKeywordForecast::class);
        $forecast->method('__invoke')->willReturnCallback(function ($account, $keywords, $maxCpc, $rate, $days, $context) use (&$tested): array {
            $bid = $context['cpc_bid_ceiling_micros'];
            $tested[] = $bid;
            $this->assertSame(5_000_000, $context['daily_budget_micros']);
            $this->assertLessThanOrEqual(1_800_000, $bid);

            return ['success' => true, 'source' => 'google_keyword_forecast', 'clicks' => $days * ($bid === 1_800_000 ? 2 : 1),
                'cost_micros' => $days * ($bid === 1_800_000 ? 3_000_000 : 1_000_000), 'period_days' => $days,
                'forecast_period' => ['from' => $context['forecast_start_date'],
                    'through' => \Carbon\CarbonImmutable::parse($context['forecast_start_date'])->addDays($days - 1)->toDateString()],
                'context' => [...$context, 'keywords' => $keywords]];
        });
        $this->app->instance(InitialSearchBidPlanner::class, new class($historical, $forecast) extends InitialSearchBidPlanner
        {
            public function __construct(private \App\Services\GoogleAds\KeywordResearch\GenerateKeywordHistoricalMetrics $historical, private \App\Services\GoogleAds\KeywordResearch\GenerateKeywordForecast $forecast) {}

            protected function historicalProvider(Customer $customer): \App\Services\GoogleAds\KeywordResearch\GenerateKeywordHistoricalMetrics
            {
                return $this->historical;
            }

            protected function forecastProvider(Customer $customer): \App\Services\GoogleAds\KeywordResearch\GenerateKeywordForecast
            {
                return $this->forecast;
            }
        });
        $this->assertTrue($this->configure($customer, $campaign, $strategy, $result), $result->errorMessage());
        $this->assertSame([1_000_000, 1_800_000], $tested);
        $this->assertSame([['account' => self::ACCOUNT, 'resource' => self::GROUP, 'bid' => 1_800_000]], $this->writes);
        $this->assertSame(1_800_000, $this->remote['keywords'][0]['ad_group_cpc_bid_micros']);
        $this->assertSame(GoogleStatus::PAUSED, $this->remote['status']);
        $this->assertSame(5_000_000, $this->remote['daily_budget_micros']);
        $this->assertSame('5.00', $campaign->fresh()->approved_daily_budget);
        $this->assertSame('verified', $strategy->fresh()->execution_result['metadata']['initial_search_bid']['status']);
    }

    public function test_missing_estimates_stop_before_a_bid_mutation_or_verified_evidence(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $this->proposal = ['ready' => false, 'status' => 'needs_review', 'message' => 'Fresh estimates unavailable.'];

        $this->assertFalse($this->configure($customer, $campaign, $strategy, $result));

        $this->assertSame([], $this->writes);
        $this->assertSame('initial_search_bid_needs_review', $result->errors[0]->code);
        $this->assertArrayNotHasKey('initial_search_bid', $strategy->fresh()->execution_result['metadata']);
    }

    #[DataProvider('unsafeSnapshots')]
    public function test_missing_or_non_paused_google_snapshot_does_not_research_or_write(?int $status, bool $missing): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $this->remote = $missing ? null : [...$this->remote, 'status' => $status];

        $this->assertFalse($this->configure($customer, $campaign, $strategy, $result));

        $this->assertSame(0, $this->plannerCalls);
        $this->assertSame([], $this->writes);
        $this->assertSame('initial_search_bid_unverified', $result->errors[0]->code);
    }

    public static function unsafeSnapshots(): array
    {
        return ['missing snapshot' => [null, true], 'enabled' => [GoogleStatus::ENABLED, false], 'unknown' => [null, false]];
    }

    #[DataProvider('authorizationChanges')]
    public function test_authorization_or_settings_changed_during_research_cannot_reach_the_mutator(string $change): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $this->afterPlan = function () use ($change, $customer, $campaign, $strategy): void {
            match ($change) {
                'hold' => $campaign->update(['spend_safety_hold' => ['reason' => 'Owner review']]),
                'trial' => $campaign->update(['spend_guardrails' => ['enabled' => true]]),
                'ended' => $campaign->update(['end_date' => now()->subDay()]),
                'future start date' => $campaign->update(['start_date' => now()->addDays(3)]),
                'future end date' => $campaign->update(['end_date' => now()->addDays(7)]),
                'account' => $customer->update(['google_ads_customer_id' => '9876543210']),
                'budget decrease' => $campaign->update(['approved_daily_budget' => '4.00']),
                'budget increase' => [$campaign->update(['approved_daily_budget' => '6.00', 'daily_budget' => '6.00']),
                    $strategy->update(['daily_budget' => '6.00'])],
                'allocation' => $strategy->update(['daily_budget' => '4.00']),
                'campaign pointer' => $strategy->forceFill(['google_ads_campaign_id' => 'customers/1234567890/campaigns/457'])->save(),
                'group pointer' => $strategy->update(['google_ads_ad_group_id' => 'customers/1234567890/adGroups/790']),
                'live targeting' => $this->remote['geo_target_constants'] = ['geoTargetConstants/2840'],
                default => throw new \LogicException('Unknown test change'),
            };
        };

        $this->assertFalse($this->configure($customer, $campaign, $strategy, $result));

        $this->assertSame(1, $this->plannerCalls);
        $this->assertSame([], $this->writes);
        $this->assertSame('initial_search_bid_unverified', $result->errors[0]->code);
    }

    public static function authorizationChanges(): array
    {
        return array_combine($changes = ['hold', 'trial', 'ended', 'future start date', 'future end date', 'account', 'budget decrease', 'budget increase',
            'allocation', 'campaign pointer', 'group pointer', 'live targeting'], array_map(fn ($value) => [$value], $changes));
    }

    #[DataProvider('unsafeFinalStatuses')]
    public function test_last_google_status_must_still_be_paused_immediately_before_mutation(?int $status): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $this->remoteStatus = $status;

        $this->assertFalse($this->configure($customer, $campaign, $strategy, $result));

        $this->assertSame([], $this->writes);
        $this->assertNotSame('verified', $strategy->fresh()->execution_result['metadata']['initial_search_bid']['status'] ?? null);
    }

    public static function unsafeFinalStatuses(): array
    {
        return ['enabled' => [GoogleStatus::ENABLED], 'unavailable' => [null]];
    }

    public function test_a_hold_added_during_the_last_google_status_read_stops_the_write(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $this->afterStatus = fn () => $campaign->update(['spend_safety_hold' => ['reason' => 'New hold']]);

        $this->assertFalse($this->configure($customer, $campaign, $strategy, $result));

        $this->assertSame([], $this->writes);
    }

    #[DataProvider('finalApprovalChanges')]
    public function test_approval_changed_during_the_last_google_status_read_requires_new_estimates(string $change): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $this->afterStatus = function () use ($change, $campaign, $strategy): void {
            if ($change === 'CPC cap') {
                $strategy->update(['bidding_strategy' => ['name' => 'ManualCpc',
                    'parameters' => ['cpcBidCeilingMicros' => 500_000]]]);
            } else {
                $campaign->update(['daily_budget' => '1.00', 'approved_daily_budget' => '1.00']);
                $strategy->update(['daily_budget' => '1.00']);
            }
        };

        $this->assertFalse($this->configure($customer, $campaign, $strategy, $result));

        $this->assertSame([], $this->writes);
        $this->assertNotSame('verified', $strategy->fresh()->execution_result['metadata']['initial_search_bid']['status'] ?? null);
    }

    public static function finalApprovalChanges(): array
    {
        return ['CPC cap' => ['CPC cap'], 'campaign and allocation budget' => ['budget']];
    }

    #[DataProvider('unsafeProposals')]
    public function test_estimated_bid_cannot_exceed_approved_limits_or_use_fractional_cents(int $bid): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $this->proposal['cpc_bid_micros'] = $bid;

        $this->assertFalse($this->configure($customer, $campaign, $strategy, $result));

        $this->assertSame([], $this->writes);
    }

    public static function unsafeProposals(): array
    {
        return ['over daily budget' => [6_000_000], 'over approved CPC ceiling' => [2_010_000], 'fractional cent' => [1_200_001]];
    }

    public function test_an_initial_proposal_cannot_exceed_the_bid_accepted_before_campaign_creation(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $bidding = $strategy->bidding_strategy;
        $bidding['parameters']['initialCpcBidMicros'] = 1_000_000;
        $strategy->update(['bidding_strategy' => $bidding]);
        // The fresh estimate is below the owner's 2.00 ceiling, but above the
        // 1.00 starting bid they actually accepted in the researched proposal.
        $this->proposal['cpc_bid_micros'] = 1_200_000;

        $this->assertFalse($this->configure($customer, $campaign, $strategy, $result));

        $this->assertSame([], $this->writes);
        $this->assertSame(2_000_000, $strategy->fresh()->bidding_strategy['parameters']['cpcBidCeilingMicros']);
    }

    public function test_a_lower_fresh_estimate_preserves_the_accepted_initial_and_owner_limits(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $bidding = $strategy->bidding_strategy;
        $bidding['parameters']['initialCpcBidMicros'] = 1_500_000;
        $strategy->update(['bidding_strategy' => $bidding]);

        $this->assertTrue($this->configure($customer, $campaign, $strategy, $result), $result->errorMessage());

        $this->assertSame(self::BID, $this->writes[0]['bid']);
        $this->assertSame(1_500_000, $strategy->fresh()->bidding_strategy['parameters']['initialCpcBidMicros']);
        $this->assertSame(2_000_000, $strategy->fresh()->bidding_strategy['parameters']['cpcBidCeilingMicros']);
        $this->assertSame(GoogleStatus::PAUSED, $this->remote['status']);
        $this->assertSame(5_000_000, $this->remote['daily_budget_micros']);
    }

    public function test_a_changed_initial_proposal_during_the_final_status_read_stops_the_write(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $this->afterStatus = function () use ($strategy): void {
            $bidding = $strategy->fresh()->bidding_strategy;
            $bidding['parameters']['initialCpcBidMicros'] = 1_000_000;
            $strategy->update(['bidding_strategy' => $bidding]);
        };

        $this->assertFalse($this->configure($customer, $campaign, $strategy, $result));
        $this->assertSame([], $this->writes);
    }

    #[DataProvider('writeFailures')]
    public function test_failed_mutation_or_wrong_readback_never_records_verified_evidence(bool $accepted, bool $apply): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $this->writeAccepted = $accepted;
        $this->applyRemoteBid = $apply;

        $this->assertFalse($this->configure($customer, $campaign, $strategy, $result));

        $this->assertCount(1, $this->writes);
        $this->assertNotSame('verified', $strategy->fresh()->execution_result['metadata']['initial_search_bid']['status']);
        $this->assertSame('initial_search_bid_unverified', $result->errors[0]->code);
    }

    public static function writeFailures(): array
    {
        return ['mutator refused' => [false, false], 'old bid read back' => [true, false]];
    }

    public function test_retry_of_verified_bid_reads_current_settings_without_another_write_or_research(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $this->assertTrue($this->configure($customer, $campaign, $strategy));

        $this->assertTrue($this->configure($customer, $campaign, $strategy, $result), $result->errorMessage());

        $this->assertCount(1, $this->writes);
        $this->assertSame(1, $this->plannerCalls);
        $this->assertSame('verified', $strategy->fresh()->execution_result['metadata']['initial_search_bid']['status']);
    }

    public function test_retry_after_lost_acknowledgement_verifies_the_applied_bid_without_another_write(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $this->writeAccepted = false;
        $this->assertFalse($this->configure($customer, $campaign, $strategy));
        $this->writeAccepted = true;

        $this->assertTrue($this->configure($customer, $campaign, $strategy, $result), $result->errorMessage());

        $this->assertCount(1, $this->writes);
        $this->assertSame(1, $this->plannerCalls);
        $this->assertSame('verified', $strategy->fresh()->execution_result['metadata']['initial_search_bid']['status']);
    }

    #[DataProvider('futureDateChanges')]
    public function test_verified_quote_is_not_reused_after_local_future_serving_dates_change(string $field, int $days): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $this->assertTrue($this->configure($customer, $campaign, $strategy));
        $campaign->update([$field => now()->addDays($days)]);

        $this->assertTrue($this->configure($customer, $campaign, $strategy, $result), $result->errorMessage());

        $this->assertSame('existing_bid_preserved', $result->metadata['initial_search_bid']['status']);
        $this->assertFalse($result->metadata['initial_search_bid']['activation_ready']);
        $this->assertCount(1, $this->writes);
        $this->assertSame(1, $this->plannerCalls);
        $this->assertSame(self::BID, $this->remote['keywords'][0]['ad_group_cpc_bid_micros']);
    }

    public static function futureDateChanges(): array
    {
        return ['future start' => ['start_date', 3], 'future end' => ['end_date', 7]];
    }

    #[DataProvider('explicitBids')]
    public function test_custom_group_or_explicit_keyword_bids_are_preserved_without_research_or_mutation(int $groupBid, int $keywordBid): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $this->setRemoteBid($groupBid);
        $this->remote['keywords'][0]['keyword_cpc_bid_micros'] = $keywordBid;
        $this->remote['keywords'][0]['cpc_bid_micros'] = $keywordBid ?: $groupBid;
        $before = $this->remote;

        $this->assertTrue($this->configure($customer, $campaign, $strategy, $result), $result->errorMessage());

        $this->assertSame([], $this->writes);
        $this->assertSame(0, $this->plannerCalls);
        $this->assertSame($before, $this->remote);
        $this->assertSame('existing_bid_preserved', $result->metadata['initial_search_bid']['status']);
        $this->assertFalse($result->metadata['initial_search_bid']['activation_ready']);
    }

    public static function explicitBids(): array
    {
        return ['custom group' => [500_000, 0], 'keyword override' => [10_000, 500_000], 'explicit one cent keyword' => [10_000, 10_000]];
    }

    public function test_a_changed_targeting_readback_keeps_the_applied_bid_unverified(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $this->afterWrite = function (): void {
            $this->remote['search_partners_enabled'] = true;
        };

        $this->assertFalse($this->configure($customer, $campaign, $strategy, $result));

        $this->assertCount(1, $this->writes);
        $this->assertNotSame('verified', $strategy->fresh()->execution_result['metadata']['initial_search_bid']['status']);
    }
}
