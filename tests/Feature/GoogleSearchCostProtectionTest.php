<?php

namespace Tests\Feature;

use App\Features\AutoHealing;
use App\Jobs\CheckGoogleSearchDelivery;
use App\Models\AgentRun;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\GoogleSearchCostProtection;
use App\Services\Agents\GoogleSearchReachPlanner;
use App\Services\Agents\GoogleSearchReachRecovery;
use App\Services\Agents\SearchTermMiningAgent;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V26\Services\MutateGoogleAdsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateGoogleAdsResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class GoogleSearchCostProtectionTest extends TestCase
{
    use DatabaseTransactions;

    private function fixture(bool $profile = true): array
    {
        $customer = Customer::where('google_ads_customer_id', '1234567890')->first()
            ?? Customer::factory()->create(['google_ads_customer_id' => '1234567890']);
        $customer->update(['service_type' => 'managed', 'currency_code' => 'AUD', 'is_sandbox' => false]);
        Feature::for($customer)->activate(AutoHealing::class);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'active',
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/61', 'daily_budget' => '50.00',
            'approved_daily_budget' => '50.00', 'budget_confirmed_at' => now(),
            'spend_guardrails' => $profile ? ['automation_limits' => ['enabled' => true,
                'max_daily_budget_micros' => 50_000_000, 'max_cpc_bid_micros' => 10_000_000,
                'currency_code' => 'AUD', 'approved_at' => now()->toIso8601String(), 'source' => 'owner_approved_cost_repair']] : null]);
        $keyword = ['text' => 'google ads setup', 'match_type' => 'EXACT', 'status' => 'ENABLED',
            'ad_group_status' => 'ENABLED', 'ad_group_resource' => 'customers/1234567890/adGroups/7',
            'resource_name' => 'customers/1234567890/adGroupCriteria/7~1', 'ad_group_cpc_bid_micros' => 40_000_000,
            'keyword_cpc_bid_micros' => 0, 'cpc_bid_micros' => 40_000_000];
        $snapshot = ['status' => 2, 'channel' => 2, 'bidding_strategy' => BiddingStrategyType::MANUAL_CPC,
            'portfolio_bidding_strategy' => '', 'currency_code' => 'AUD', 'daily_budget_micros' => 50_000_000,
            'account_time_zone_verified' => true, 'time_zone' => 'Australia/Sydney',
            'actual_cpc_ceiling_micros' => 50_000_000, 'cpc_bid_ceiling_micros' => 50_000_000,
            'device_bid_modifiers' => [], 'unsupported_targeting' => [],
            'auction' => ['clicks' => 1, 'cost_micros' => 40_000_000, 'conversions' => 0],
            'keywords' => [$keyword, [...$keyword, 'resource_name' => 'customers/1234567890/adGroupCriteria/7~2',
                'keyword_cpc_bid_micros' => 50_000_000, 'cpc_bid_micros' => 50_000_000]]];

        return [$campaign->load('customer'), new ScriptedCostProtection($snapshot)];
    }

    public function test_inherited_and_explicit_bids_are_clamped_atomically_and_repeated_checks_do_not_write(): void
    {
        [$campaign, $service] = $this->fixture();
        $service->snapshot['keywords'][] = [...$service->snapshot['keywords'][0],
            'resource_name' => 'customers/1234567890/adGroupCriteria/7~3', 'status' => 'PAUSED',
            'keyword_cpc_bid_micros' => 60_000_000, 'cpc_bid_micros' => 60_000_000];
        $result = $service->check($campaign);

        $this->assertSame('verified', $result['status']);
        $this->assertSame(3, $result['actions_taken']);
        $this->assertSame([true, false], array_column($service->writes, 'validate_only'));
        $this->assertSame('applying', $service->journalStatusAtWrite);
        $this->assertSame('PAUSED', $service->snapshot['keywords'][2]['status']);
        $this->assertSame(50_000_000, $service->snapshot['daily_budget_micros']);
        $this->assertSame(10_000_000, $service->snapshot['keywords'][0]['ad_group_cpc_bid_micros']);
        $this->assertSame(10_000_000, $service->snapshot['keywords'][1]['keyword_cpc_bid_micros']);
        $this->assertSame('active', $campaign->fresh()->status->value);
        $this->assertTrue($result['forecast_required_before_expansion']);

        $this->assertSame('needs_review', $service->check($campaign)['status']);
        $this->assertCount(2, $service->writes);
    }

    public function test_one_expensive_click_is_reviewed_without_inventing_an_approved_limit(): void
    {
        [$campaign, $service] = $this->fixture(false);
        $result = $service->check($campaign);
        $this->assertSame('needs_review', $result['status']);
        $this->assertSame('approved_automation_cpc_limit_required', $result['reason']);
        $this->assertTrue($result['observed']['expensive_clicks']);
        $this->assertSame([], $service->writes);
        $this->assertNull($campaign->fresh()->spend_guardrails);

        $service->snapshot['auction']['cost_micros'] = 5_000_000;
        $this->assertSame('observing', $service->check($campaign)['status']);
    }

    public function test_manual_holds_paused_handover_trial_disabled_healing_and_expired_campaigns_are_untouched(): void
    {
        foreach (['hold', 'paused', 'handover', 'trial', 'healing_off', 'ended', 'revoked'] as $case) {
            [$campaign, $service] = $this->fixture();
            match ($case) {
                'hold' => $campaign->update(['spend_safety_hold' => ['reason' => 'manual_pause']]),
                'paused' => $campaign->update(['status' => 'paused']),
                'handover' => $campaign->customer->update(['service_type' => 'setup_only']),
                'trial' => $campaign->update(['spend_guardrails' => ['enabled' => true]]),
                'healing_off' => Feature::for($campaign->customer)->deactivate(AutoHealing::class),
                'ended' => $campaign->update(['end_date' => now()->subDay()]),
                'revoked' => $campaign->customer->update(['google_ads_link_status' => 'revoked']),
            };
            $this->assertSame('not_applicable', $service->check($campaign)['status'], $case);
            $this->assertSame(0, $service->inspections, $case);
            $this->assertSame([], $service->writes, $case);
        }
    }

    public function test_remote_pause_modifiers_unknown_targeting_and_wrong_currency_cannot_authorize_a_write(): void
    {
        foreach (['remote_pause', 'modifier', 'unknown', 'currency', 'timezone'] as $case) {
            [$campaign, $service] = $this->fixture();
            match ($case) {
                'remote_pause' => $service->snapshot['status'] = 3,
                'modifier' => $service->snapshot['device_bid_modifiers'] = [['bid_modifier' => 1.4]],
                'unknown' => $service->snapshot['unsupported_targeting'] = [['type' => 'unknown_bid_modifier']],
                'currency' => $service->snapshot['currency_code'] = 'USD',
                'timezone' => $service->snapshot['account_time_zone_verified'] = false,
            };
            $this->assertSame('needs_review', $service->check($campaign)['status'], $case);
            $this->assertSame([], $service->writes, $case);
        }
    }

    public function test_local_or_google_changes_during_validation_prevent_the_actual_write(): void
    {
        foreach (['local_hold', 'remote_bid'] as $case) {
            [$campaign, $service] = $this->fixture();
            $service->afterValidate = function () use ($case, $campaign, $service): void {
                if ($case === 'local_hold') {
                    $campaign->update(['spend_safety_hold' => ['reason' => 'manual_pause']]);
                } else {
                    $service->snapshot['keywords'][0]['cpc_bid_micros'] = 39_000_000;
                }
            };
            $this->assertSame('needs_review', $service->check($campaign)['status']);
            $this->assertSame([true], array_column($service->writes, 'validate_only'));
        }
    }

    public function test_ambiguous_mutations_are_never_replayed_and_are_only_verified_by_readback(): void
    {
        foreach ([false, true] as $remoteApplied) {
            [$campaign, $service] = $this->fixture();
            $service->throwAfterWrite = true;
            $service->applyLiveWrite = $remoteApplied;
            $this->assertSame('unverified', $service->check($campaign)['status']);
            $service->throwAfterWrite = false;
            $this->assertSame($remoteApplied ? 'verified' : 'needs_review', $service->check($campaign)['status']);
            $service->check($campaign);
            $this->assertSame([true, false], array_column($service->writes, 'validate_only'));
        }
    }

    public function test_local_permission_changes_during_the_final_google_inspection_stop_the_write(): void
    {
        foreach (['hold', 'paused', 'healing_off'] as $case) {
            [$campaign, $service] = $this->fixture();
            $service->afterInspection = function (int $inspection) use ($campaign, $case): void {
                if ($inspection !== 2) {
                    return;
                }
                match ($case) {
                    'hold' => $campaign->update(['spend_safety_hold' => ['reason' => 'manual_pause']]),
                    'paused' => $campaign->update(['status' => 'paused']),
                    'healing_off' => Feature::for($campaign->customer)->deactivate(AutoHealing::class),
                };
            };
            $this->assertSame('needs_review', $service->check($campaign)['status']);
            $this->assertSame([true], array_column($service->writes, 'validate_only'));
        }
    }

    public function test_partial_readback_cannot_report_a_successful_repair(): void
    {
        [$campaign, $service] = $this->fixture();
        $service->applyLiveWrite = false;
        $result = $service->check($campaign);
        $this->assertSame('unverified', $result['status']);
        $this->assertSame(0, $result['actions_taken']);
        $this->assertSame('unverified', $campaign->fresh()->search_delivery_state['cost_protection']['status']);
    }

    public function test_owner_pause_cannot_erase_an_ambiguous_write_and_allow_replay_on_resume(): void
    {
        [$campaign, $service] = $this->fixture();
        $service->throwAfterWrite = true;
        $service->applyLiveWrite = false;
        $this->assertSame('unverified', $service->check($campaign)['status']);
        $started = $campaign->fresh()->search_delivery_state['cost_protection']['mutation_started_at'];
        $campaign->update(['status' => 'paused']);
        $this->assertSame('not_applicable', $service->check($campaign)['status']);
        $this->assertSame($started, $campaign->fresh()->search_delivery_state['cost_protection']['mutation_started_at']);
        $campaign->update(['status' => 'active']);
        $service->throwAfterWrite = false;
        $this->assertSame('needs_review', $service->check($campaign)['status']);
        $this->assertSame([true, false], array_column($service->writes, 'validate_only'));
    }

    public function test_a_changed_limit_cannot_certify_an_unknown_earlier_write_or_allow_replay(): void
    {
        [$campaign, $service] = $this->fixture();
        $service->throwAfterWrite = true;
        $service->applyLiveWrite = false;
        $this->assertSame('unverified', $service->check($campaign)['status']);
        $limits = $campaign->fresh()->spend_guardrails;
        $limits['automation_limits']['max_cpc_bid_micros'] = 20_000_000;
        $campaign->update(['spend_guardrails' => $limits]);
        $service->throwAfterWrite = false;
        foreach ([1, 2] as $retry) {
            $result = $service->check($campaign);
            $this->assertSame('needs_review', $result['status']);
            $this->assertSame('approved_cost_limit_changed_after_unknown_write', $result['reason']);
        }
        $this->assertSame([true, false], array_column($service->writes, 'validate_only'));
    }

    public function test_hourly_job_reports_cost_review_instead_of_certifying_delivery_healthy(): void
    {
        [$campaign] = $this->fixture();
        $cost = $this->createMock(GoogleSearchCostProtection::class);
        $cost->expects($this->once())->method('check')->willReturn(['status' => 'needs_review',
            'reason' => 'approved_automation_cpc_limit_required', 'actions_taken' => 0]);
        $this->app->instance(GoogleSearchCostProtection::class, $cost);
        $recovery = $this->createMock(GoogleSearchReachRecovery::class);
        $recovery->expects($this->once())->method('check')->willReturn(['status' => 'delivering']);

        (new CheckGoogleSearchDelivery($campaign->id))->handle($recovery);

        $this->assertSame('needs_review', $campaign->fresh()->search_delivery_state['status']);
        $run = AgentRun::where('job', 'CheckGoogleSearchDelivery')->latest('id')->firstOrFail();
        $this->assertSame(AgentRun::STATUS_ATTENTION, $run->status);
        $this->assertSame('needs_review', $run->details['cost_protection']['status']);
    }

    public function test_verified_reach_recovery_is_counted_once_even_when_cost_health_still_needs_review(): void
    {
        [$campaign] = $this->fixture();
        $cost = $this->createMock(GoogleSearchCostProtection::class);
        $cost->method('check')->willReturn(['status' => 'needs_review', 'reason' => 'approved_automation_cpc_limit_required', 'actions_taken' => 0]);
        $this->app->instance(GoogleSearchCostProtection::class, $cost);
        $recovery = $this->createMock(GoogleSearchReachRecovery::class);
        $recovery->expects($this->exactly(2))->method('check')->willReturn(['status' => 'recovered']);

        (new CheckGoogleSearchDelivery($campaign->id))->handle($recovery);
        (new CheckGoogleSearchDelivery($campaign->id))->handle($recovery);

        $state = $campaign->fresh()->search_delivery_state;
        $this->assertSame('needs_review', $state['status']);
        $this->assertSame('recovered', $state['reach_status']);
        $this->assertSame([1, 0], AgentRun::where('job', 'CheckGoogleSearchDelivery')->where('scope', "Campaign {$campaign->id}")
            ->orderBy('id')->pluck('actions_taken')->all());
    }

    public function test_platform_writer_uses_atomic_validate_only_and_bid_only_field_masks(): void
    {
        [$campaign] = $this->fixture();
        $client = $this->createMock(GoogleAdsClient::class);
        $mutator = $this->createMock(GoogleAdsServiceClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn($mutator);
        $account = $this->createMock(GetCampaignStatus::class);
        $account->method('getClient')->willReturn($client);
        $this->app->bind(GetCampaignStatus::class, fn () => $account);
        $requests = [];
        $mutator->expects($this->exactly(2))->method('mutate')->willReturnCallback(function (MutateGoogleAdsRequest $request) use (&$requests) {
            $requests[] = $request;
            $this->assertFalse($request->getPartialFailure());
            $this->assertSame('1234567890', $request->getCustomerId());
            foreach ($request->getMutateOperations() as $operation) {
                $update = $operation->getAdGroupOperation() ?? $operation->getAdGroupCriterionOperation();
                $this->assertSame(['cpc_bid_micros'], iterator_to_array($update->getUpdateMask()->getPaths()));
                $this->assertSame(10_000_000, (int) $update->getUpdate()->getCpcBidMicros());
            }

            return new MutateGoogleAdsResponse;
        });
        $writer = new class extends GoogleSearchCostProtection
        {
            public function write(Campaign $campaign, array $changes, bool $validate): void
            {
                $this->mutate($campaign, $changes, $validate);
            }
        };
        $changes = [['type' => 'ad_group', 'resource_name' => 'customers/1234567890/adGroups/7',
            'before_micros' => 40_000_000, 'bid_micros' => 10_000_000],
            ['type' => 'keyword', 'resource_name' => 'customers/1234567890/adGroupCriteria/7~2',
                'before_micros' => 50_000_000, 'bid_micros' => 10_000_000]];
        $writer->write($campaign, $changes, true);
        $writer->write($campaign, $changes, false);
        $this->assertTrue($requests[0]->getValidateOnly());
        $this->assertFalse($requests[1]->getValidateOnly());
    }

    public function test_hourly_expensive_term_review_is_google_only_and_claimed_once_per_day(): void
    {
        [$campaign] = $this->fixture();
        $cost = $this->createMock(GoogleSearchCostProtection::class);
        $cost->expects($this->exactly(2))->method('check')->willReturn(['status' => 'needs_review',
            'reason' => 'reported_cpc_above_affordability_target', 'actions_taken' => 0,
            'limit_verified' => true, 'observed' => ['expensive_clicks' => true]]);
        $this->app->instance(GoogleSearchCostProtection::class, $cost);
        $miner = $this->createMock(SearchTermMiningAgent::class);
        $miner->expects($this->once())->method('mine')->with($this->callback(function (Campaign $current): bool {
            $this->assertSame('running', $current->fresh()->search_delivery_state['search_term_review']['status']);

            return true;
        }), true)->willReturn(['terms_analyzed' => 2, 'keywords_added' => [], 'negatives_added' => [], 'errors' => []]);
        $this->app->instance(SearchTermMiningAgent::class, $miner);
        $recovery = $this->createMock(GoogleSearchReachRecovery::class);
        $recovery->method('check')->willReturn(['status' => 'delivering']);
        (new CheckGoogleSearchDelivery($campaign->id))->handle($recovery);
        (new CheckGoogleSearchDelivery($campaign->id))->handle($recovery);

        $this->assertSame('checked', $campaign->fresh()->search_delivery_state['search_term_review']['status']);
        $this->assertSame(2, $campaign->fresh()->search_delivery_state['search_term_review']['terms_analyzed']);
        $this->assertSame('needs_review', $campaign->fresh()->search_delivery_state['status']);
    }

    public function test_hourly_mining_partial_writes_are_preserved_without_running_reach_recovery(): void
    {
        [$campaign] = $this->fixture();
        $cost = $this->createMock(GoogleSearchCostProtection::class);
        $cost->method('check')->willReturn(['status' => 'needs_review', 'actions_taken' => 0,
            'limit_verified' => true, 'observed' => ['expensive_clicks' => true]]);
        $this->app->instance(GoogleSearchCostProtection::class, $cost);
        $miner = $this->createMock(SearchTermMiningAgent::class);
        $errors = [['code' => 'keyword_batch_partial_apply', 'message' => 'The write could not be verified.']];
        $miner->expects($this->once())->method('mine')->willReturnCallback(function (Campaign $current) use ($errors): array {
            $current->update(['search_delivery_state' => [...$current->fresh()->search_delivery_state,
                'verification' => ['errors' => $errors], 'config_fingerprint' => 'before-unknown-keyword-write']]);

            return ['requires_review' => true, 'keywords_added' => [], 'negatives_added' => [], 'errors' => []];
        });
        $this->app->instance(SearchTermMiningAgent::class, $miner);
        $recovery = $this->createMock(GoogleSearchReachRecovery::class);
        $recovery->expects($this->never())->method('check');
        (new CheckGoogleSearchDelivery($campaign->id))->handle($recovery);
        $state = $campaign->fresh()->search_delivery_state;
        $this->assertSame('needs_review', $state['status']);
        $this->assertSame($errors, $state['verification']['errors']);
        $this->assertSame('before-unknown-keyword-write', $state['config_fingerprint']);
        $this->assertFalse($state['mutation_allowed']);
    }

    public function test_daily_mining_or_cost_journals_cannot_be_reset_by_reach_fingerprint_changes(): void
    {
        foreach (['keyword', 'cost'] as $kind) {
            [$campaign] = $this->fixture();
            $journal = $kind === 'keyword'
                ? ['verification' => ['errors' => [['code' => 'keyword_batch_partial_apply', 'message' => 'Unknown write.']]]]
                : ['cost_protection' => ['status' => 'unverified', 'mutation_started_at' => now()->toIso8601String()]];
            $campaign->update(['search_delivery_state' => ['config_fingerprint' => 'old-fingerprint', ...$journal]]);
            $planner = $this->createMock(GoogleSearchReachPlanner::class);
            $planner->expects($this->never())->method('assess');
            $recovery = new class($planner) extends GoogleSearchReachRecovery
            {
                public int $inspections = 0;

                protected function inspect(Campaign $campaign, \DateTimeInterface $since): ?array
                {
                    $this->inspections++;

                    return null;
                }
            };
            $result = $recovery->check($campaign);
            $this->assertSame('needs_review', $result['status']);
            $this->assertSame('old-fingerprint', $result['config_fingerprint']);
            $this->assertSame(0, $recovery->inspections);
            $this->assertEquals($journal[$kind === 'keyword' ? 'verification' : 'cost_protection'],
                $result[$kind === 'keyword' ? 'verification' : 'cost_protection']);
        }
    }
}

class ScriptedCostProtection extends GoogleSearchCostProtection
{
    public int $inspections = 0;

    public array $writes = [];

    public ?\Closure $afterValidate = null;

    public ?\Closure $afterInspection = null;

    public bool $throwAfterWrite = false;

    public bool $applyLiveWrite = true;

    public ?string $journalStatusAtWrite = null;

    public function __construct(public array $snapshot) {}

    protected function inspect(Campaign $campaign): ?array
    {
        $this->inspections++;
        ($this->afterInspection ?? static fn () => null)($this->inspections);

        return $this->snapshot;
    }

    protected function mutate(Campaign $campaign, array $changes, bool $validateOnly): void
    {
        $this->writes[] = ['changes' => $changes, 'validate_only' => $validateOnly];
        if ($validateOnly) {
            ($this->afterValidate ?? static fn () => null)();

            return;
        }
        $this->journalStatusAtWrite = $campaign->fresh()->search_delivery_state['cost_protection']['status'];
        if ($this->applyLiveWrite) {
            foreach ($this->snapshot['keywords'] as &$row) {
                foreach ($changes as $change) {
                    if ($change['type'] === 'ad_group' && $row['ad_group_resource'] === $change['resource_name']) {
                        $row['ad_group_cpc_bid_micros'] = $change['bid_micros'];
                    }
                    if ($change['type'] === 'keyword' && $row['resource_name'] === $change['resource_name']) {
                        $row['keyword_cpc_bid_micros'] = $change['bid_micros'];
                    }
                }
                $row['cpc_bid_micros'] = $row['keyword_cpc_bid_micros'] ?: $row['ad_group_cpc_bid_micros'];
            }
            unset($row);
            $this->snapshot['actual_cpc_ceiling_micros'] = $this->snapshot['cpc_bid_ceiling_micros'] = 10_000_000;
        }
        if ($this->throwAfterWrite) {
            throw new \RuntimeException('Transport failed after the request may have been sent.');
        }
    }
}
