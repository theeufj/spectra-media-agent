<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Jobs\ActivateApprovedAiMaxPilot;
use App\Models\AgentActivity;
use App\Models\AgentRun;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\ApprovedAiMaxPilotActivation;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

class ApprovedAiMaxPilotActivationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Exceptions::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-26T09:15:52Z'));
        config(['conversions.google_ads_customer_id' => '1234567890']);
    }

    private function fixture(): array
    {
        $customer = Customer::where('google_ads_customer_id', '1234567890')->first() ?? Customer::factory()->create(['google_ads_customer_id' => '1234567890', 'currency_code' => 'AUD',
            'timezone' => 'Australia/Sydney', 'service_type' => 'managed', 'is_sandbox' => false]);
        $limits = ['enabled' => true, 'max_daily_budget_micros' => 50_000_000, 'max_cpc_bid_micros' => 10_000_000,
            'currency_code' => 'AUD', 'approved_at' => now()->subHour()->toIso8601String(), 'source' => 'owner_approved_cost_repair'];
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => '999',
            'status' => CampaignStatus::Active, 'platform_status' => 'ENABLED', 'end_date' => '2026-10-30',
            'landing_page_url' => 'https://sitetospend.com/google-ads-setup',
            'daily_budget' => '50.00', 'approved_daily_budget' => '50.00', 'budget_confirmed_at' => now(),
            'spend_guardrails' => ['automation_limits' => $limits]]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'campaign_type' => 'search',
            'deployment_status' => 'active', 'signed_off_at' => now(),
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/999',
            'conversion_goals' => ['primary_goal' => 'PURCHASE', 'selection_mode' => 'category', 'origin' => 'WEBSITE'],
            'bidding_strategy' => ['name' => 'ManualCpc', 'parameters' => ['cpcBidCeilingMicros' => 10_000_000]]]);
        $activation = new ScriptedApprovedAiMaxActivation;
        $request = $activation->request($campaign, $strategy, 'Owner approved fourteen days with A$50 daily and A$200 reported-spend review.');

        return [$campaign->fresh(['customer']), $strategy->fresh(), $activation, $request];
    }

    public function test_request_is_idempotent_and_waits_without_relaxing_current_spending_settings(): void
    {
        [$campaign, $strategy, $activation, $request] = $this->fixture();
        $old = $campaign->spend_guardrails;
        $this->assertSame($request['nonce'], $activation->request($campaign, $strategy, $request['approval'])['nonce']);
        $this->assertSame('awaiting_delivery_validation', $activation->advance($campaign->id, $request['nonce'])['status']);
        $fresh = $campaign->fresh();
        $this->assertSame(CampaignStatus::Active, $fresh->status);
        $this->assertSame($old, $fresh->spend_guardrails);
        $this->assertSame('2026-10-30', $fresh->end_date->toDateString());
        $this->assertSame($strategy->conversion_goals, $strategy->fresh()->conversion_goals);
        $this->assertTrue(CampaignSpendGuardrails::automaticChangesSuspended($fresh));
        $this->assertFalse(CampaignSpendGuardrails::canEnable($fresh, true));
        $this->assertFalse(CampaignSpendGuardrails::permitsKeywordBid($fresh, 10_000_001));
        $this->assertNull(CampaignSpendGuardrails::aiMaxPilot($fresh));
        $this->assertSame(['provision'], $activation->effects);
        $this->assertSame(1800, $activation->advance($campaign->id, $request['nonce'])['retry_after_seconds']);
        $this->assertSame(0, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ai_max_owner_request_completed')->count());
    }

    public function test_ready_destination_runs_journalled_pause_goal_prepare_and_activation_once(): void
    {
        [$campaign, $strategy, $activation, $request] = $this->fixture();
        $activation->ready = true;
        $this->travel(6)->hours();
        $result = $activation->advance($campaign->id, $request['nonce']);
        $this->assertSame('active', $result['status']);
        $this->assertFalse($result['actual_purchase_results_verified']);
        $this->assertSame(['provision', 'pause', 'reconcile', 'prepare', 'activate'], $activation->effects);
        $fresh = $campaign->fresh();
        $this->assertSame(CampaignStatus::Active, $fresh->status);
        $this->assertSame('active', $fresh->search_delivery_state['ai_max_request']['status']);
        $pilot = $fresh->spend_guardrails['ai_max_pilot'];
        $this->assertSame($request['nonce'], $pilot['owner_request_nonce']);
        $this->assertSame(51_000_000, $pilot['baseline_cost_micros']);
        $this->assertSame(now()->setTimezone('Australia/Sydney')->toIso8601String(), $pilot['starts_at']);
        $this->assertSame(now()->setTimezone('Australia/Sydney')->addDays(13)->endOfDay()->startOfSecond()->toIso8601String(), $pilot['ends_at']);
        $this->assertLessThanOrEqual(14 * 86400, \Carbon\CarbonImmutable::parse($pilot['starts_at'])->diffInSeconds(\Carbon\CarbonImmutable::parse($pilot['ends_at'])));
        $this->assertSame(50_000_000, $pilot['max_daily_budget_micros']);
        $this->assertSame(200_000_000, $pilot['review_spend_micros']);
        $this->assertSame(10_000_000, $fresh->spend_guardrails['automation_limits']['max_cpc_bid_micros']);
        $this->assertSame('exact_action', $strategy->fresh()->conversion_goals['selection_mode']);
        $this->assertSame(ScriptedApprovedAiMaxActivation::ACTION, $strategy->fresh()->conversion_goals['action_resource']);
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ai_max_owner_request_completed')->count());
        $this->assertTrue($activation->advance($campaign->id, $request['nonce'])['historical_result_only']);
        $this->assertCount(5, $activation->effects);
    }

    public function test_user_pause_hold_or_remote_settings_change_revokes_waiting_activation(): void
    {
        foreach (['pause', 'hold', 'remote_bid', 'remote_keywords', 'remote_goal'] as $change) {
            [$campaign, $strategy, $activation, $request] = $this->fixture();
            match ($change) {
                'pause' => $campaign->update(['status' => CampaignStatus::Paused]),
                'hold' => $campaign->update(['spend_safety_hold' => ['reason' => 'owner_pause']]),
                'remote_bid' => $activation->remote['actual_cpc_ceiling_micros'] = 9_000_000,
                'remote_keywords' => $activation->remote['keywords'] = [],
                'remote_goal' => $activation->remoteGoal = ['different' => true],
            };
            $this->assertSame('revoked', $activation->advance($campaign->id, $request['nonce'])['status'], $change);
            $this->assertSame([], $activation->effects, $change);
            $this->assertArrayNotHasKey('ai_max_pilot', $campaign->fresh()->spend_guardrails);
        }
    }

    public function test_expired_or_tampered_approval_cannot_activate_and_unknown_nonce_cannot_touch_request(): void
    {
        [$campaign, $strategy, $activation, $request] = $this->fixture();
        $this->assertSame('revoked', $activation->advance($campaign->id, 'other-nonce')['status']);
        $this->assertSame('waiting_for_conversion', $campaign->fresh()->search_delivery_state['ai_max_request']['status']);
        $state = $campaign->search_delivery_state;
        $state['ai_max_request']['review_spend_micros'] = 999_000_000;
        $campaign->update(['search_delivery_state' => $state]);
        $this->assertSame('needs_review', $activation->advance($campaign->id, $request['nonce'])['status']);
        $this->assertSame([], $activation->effects);
        [$campaign, $strategy, $activation, $request] = $this->fixture();
        $this->travel(25)->hours();
        $this->assertSame('needs_review', $activation->advance($campaign->id, $request['nonce'])['status']);
        $this->assertSame([], $activation->effects);
        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);
    }

    public function test_provider_validation_failure_is_reported_and_never_retried_as_a_write(): void
    {
        [$campaign, $strategy, $activation, $request] = $this->fixture();
        $activation->failAt = 'provision';
        try {
            $activation->advance($campaign->id, $request['nonce']);
            $this->fail('Expected provider failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('scripted provision failure', $exception->getMessage());
        }
        $this->assertSame('needs_review', $campaign->fresh()->search_delivery_state['ai_max_request']['status']);
        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);
        $this->assertSame('needs_review', $activation->advance($campaign->id, $request['nonce'])['status']);
        $this->assertSame(['provision'], $activation->effects);
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_ambiguous_pause_or_later_failure_never_replays_or_resumes(): void
    {
        foreach (['pause', 'reconcile', 'prepare', 'activate'] as $phase) {
            [$campaign, $strategy, $activation, $request] = $this->fixture();
            $activation->ready = true;
            $activation->failAt = $phase;
            try {
                $activation->advance($campaign->id, $request['nonce']);
                $this->fail('Expected '.$phase.' failure.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('scripted '.$phase.' failure', $exception->getMessage());
            }
            $fresh = $campaign->fresh();
            $this->assertSame(CampaignStatus::Paused, $fresh->status, $phase);
            $this->assertSame('needs_review', $fresh->search_delivery_state['ai_max_request']['status'], $phase);
            $this->assertSame('ai_max_owner_activation_unverified', $fresh->spend_safety_hold['reason'], $phase);
            $this->assertSame(1, $activation->containmentCalls, $phase);
            $this->assertArrayNotHasKey('verified_at', $fresh->spend_safety_hold, $phase);
            $effects = $activation->effects;
            $activation->advance($campaign->id, $request['nonce']);
            $this->assertSame($effects, $activation->effects, $phase);
        }
    }

    public function test_new_owner_hold_after_provider_read_or_preparation_blocks_next_effect(): void
    {
        foreach (['provision', 'prepare'] as $phase) {
            [$campaign, $strategy, $activation, $request] = $this->fixture();
            $activation->ready = true;
            $activation->afterEffect = function (string $effect) use ($phase, $campaign): void {
                if ($phase === $effect) {
                    $campaign->fresh()->update(['spend_safety_hold' => ['reason' => 'owner_pause']]);
                }
            };
            try {
                $activation->advance($campaign->id, $request['nonce']);
                $this->fail('Expected owner hold to block activation.');
            } catch (\RuntimeException) {
                $this->assertNotContains('activate', $activation->effects);
            }
            $this->assertSame('owner_pause', $campaign->fresh()->spend_safety_hold['reason']);
            $this->assertSame($phase === 'provision' ? ['provision'] : ['provision', 'pause', 'reconcile', 'prepare'], $activation->effects);
        }
    }

    public function test_owner_cancellation_during_goal_or_prepare_read_is_never_overwritten_by_next_phase(): void
    {
        foreach (['reconcile' => 'revoked', 'prepare' => 'cancelled'] as $phase => $decision) {
            [$campaign, $strategy, $activation, $request] = $this->fixture();
            $activation->ready = true;
            $activation->afterEffect = function (string $effect) use ($campaign, $phase, $decision): void {
                if ($effect === $phase) {
                    $fresh = $campaign->fresh();
                    $state = $fresh->search_delivery_state;
                    $state['ai_max_request']['status'] = $decision;
                    $state['ai_max_request']['message'] = 'Owner cancelled this activation.';
                    $fresh->update(['search_delivery_state' => $state]);
                }
            };
            try {
                $activation->advance($campaign->id, $request['nonce']);
                $this->fail('A late provider result must not replace owner cancellation.');
            } catch (\RuntimeException) {
                $this->assertSame($decision, $campaign->fresh()->search_delivery_state['ai_max_request']['status']);
                $this->assertSame('Owner cancelled this activation.', $campaign->fresh()->search_delivery_state['ai_max_request']['message']);
            }
            $this->assertNotContains('activate', $activation->effects);
            $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
            $effects = $activation->effects;
            $this->assertSame($decision, $activation->advance($campaign->id, $request['nonce'])['status']);
            $this->assertSame($effects, $activation->effects);
        }
    }

    public function test_final_status_enable_rechecks_owner_nonce_after_slow_readiness_reads(): void
    {
        [$campaign, $strategy, $activation, $request] = $this->fixture();
        $activation->ready = true;
        $activation->advance($campaign->id, $request['nonce']);
        $fresh = $campaign->fresh(['customer']);
        $status = new ScriptedAiMaxOwnerStatus($fresh, $fresh->spend_guardrails['ai_max_pilot']);
        $this->assertTrue($status->enable('1234567890', $fresh->googleAdsResourceName(), true)['success']);
        $status->afterRead = function () use ($campaign): void {
            $fresh = $campaign->fresh();
            $state = $fresh->search_delivery_state;
            $state['ai_max_request']['status'] = 'cancelled';
            $fresh->update(['search_delivery_state' => $state]);
        };
        $this->assertFalse($status->enable('1234567890', $fresh->googleAdsResourceName(), true)['success']);
    }

    public function test_completed_pilot_owner_restart_survives_request_expiry_but_incomplete_activation_does_not(): void
    {
        [$campaign, $strategy, $activation, $request] = $this->fixture();
        $activation->ready = true;
        $activation->advance($campaign->id, $request['nonce']);
        $this->travel(2)->days();
        $fresh = $campaign->fresh(['customer']);
        $pilot = $fresh->spend_guardrails['ai_max_pilot'];
        $this->assertTrue($activation->ownerRequestAllowsActivation($fresh, $pilot));
        $state = $fresh->search_delivery_state;
        $state['ai_max_request']['status'] = 'activating';
        $fresh->update(['search_delivery_state' => $state]);
        $this->assertFalse($activation->ownerRequestAllowsActivation($fresh, $pilot));
        $state['ai_max_request']['status'] = 'active';
        $state['ai_max_request']['approval'] = 'An altered unsigned approval.';
        $fresh->update(['search_delivery_state' => $state]);
        $this->assertFalse($activation->ownerRequestAllowsActivation($fresh, $pilot));
    }

    public function test_queue_job_records_expected_wait_and_releases_without_reporting_a_failure(): void
    {
        [$campaign, $strategy, $activation, $request] = $this->fixture();
        $job = new ActivateApprovedAiMaxPilot($campaign->id, $request['nonce']);
        $queued = $this->createMock(\Illuminate\Contracts\Queue\Job::class);
        $queued->expects($this->once())->method('attempts')->willReturn(1);
        $queued->expects($this->once())->method('release')->with(1800);
        $job->setJob($queued);
        $job->handle($activation);
        $run = AgentRun::where('job', 'ActivateApprovedAiMaxPilot')->latest('id')->firstOrFail();
        $this->assertSame(AgentRun::STATUS_ATTENTION, $run->status);
        $this->assertSame('awaiting_delivery_validation', $run->details['status']);
        $this->assertSame(0, $run->errors);
    }

    public function test_last_queue_attempt_exhausts_owner_request_without_pausing_existing_campaign(): void
    {
        [$campaign, $strategy, $activation, $request] = $this->fixture();
        $job = new ActivateApprovedAiMaxPilot($campaign->id, $request['nonce']);
        $queued = $this->createMock(\Illuminate\Contracts\Queue\Job::class);
        $queued->expects($this->once())->method('attempts')->willReturn(24);
        $queued->expects($this->never())->method('release');
        $job->setJob($queued);
        $job->handle($activation);
        $this->assertSame('needs_review', $campaign->fresh()->search_delivery_state['ai_max_request']['status']);
        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);
        $this->assertNull($campaign->fresh()->spend_safety_hold);
    }
}

class ScriptedApprovedAiMaxActivation extends ApprovedAiMaxPilotActivation
{
    public const ACTION = 'customers/1234567890/conversionActions/444';

    public bool $ready = false;

    public int $containmentCalls = 0;

    public array $effects = [];

    public ?string $failAt = null;

    public ?\Closure $afterEffect = null;

    public array $remoteGoal = ['goals' => ['purchase' => true]];

    public array $remote = ['status' => 2, 'channel' => 2, 'daily_budget_micros' => 50_000_000,
        'bidding_strategy' => BiddingStrategyType::MANUAL_CPC, 'actual_cpc_ceiling_micros' => 10_000_000,
        'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney', 'geo_target_constants' => ['geoTargetConstants/2840'],
        'end_date' => '2026-10-30', 'keywords' => [['resource_name' => 'customers/1234567890/adGroupCriteria/1~2',
            'text' => 'google ads setup', 'match_type' => 'EXACT', 'status' => 'ENABLED', 'cpc_bid_micros' => 10_000_000]]];

    protected function contain(Campaign $campaign): void
    {
        $this->containmentCalls++;
        $this->remote['status'] = 3;
    }

    protected function snapshot(Campaign $campaign): array
    {
        return $this->remote;
    }

    protected function goalConfiguration(Campaign $campaign): array
    {
        return $this->remoteGoal;
    }

    private function effect(string $name): void
    {
        $this->effects[] = $name;
        if ($this->failAt === $name) {
            throw new \RuntimeException('scripted '.$name.' failure');
        }
        if ($this->afterEffect) {
            ($this->afterEffect)($name);
        }
    }

    protected function provision(Campaign $campaign): array
    {
        $this->effect('provision');

        return ['status' => $this->ready ? 'ready' : 'awaiting_delivery_validation', 'resource' => self::ACTION,
            'delivery_validated' => $this->ready];
    }

    protected function pause(Campaign $campaign): void
    {
        $request = $campaign->fresh()->search_delivery_state['ai_max_request'];
        if ($request['status'] !== 'pausing' || empty($request['pause_started_at']) || $campaign->status !== CampaignStatus::Active) {
            throw new \LogicException('Pause was not durably journalled before the write.');
        }
        $this->effect('pause');
        $this->remote['status'] = 3;
    }

    protected function spendSnapshot(Campaign $campaign): array
    {
        return ['status' => 3, 'cost_micros' => 51_000_000, 'daily_budget_micros' => 50_000_000,
            'currency_code' => 'AUD', 'account_timezone' => 'Australia/Sydney'];
    }

    protected function reconcileGoal(Campaign $campaign, Strategy $strategy, string $nonce): array
    {
        $request = $campaign->fresh()->search_delivery_state['ai_max_request'];
        if ($campaign->fresh()->status !== CampaignStatus::Paused || $request['status'] !== 'goals_pending'
            || $strategy->fresh()->conversion_goals['action_resource'] !== self::ACTION || $request['nonce'] !== $nonce) {
            throw new \LogicException('Reconciliation was not restricted to the paused owner request and selected goal.');
        }
        $this->effect('reconcile');

        return ['ready' => true, 'intent' => ['action_resource' => self::ACTION]];
    }

    protected function preparePilot(Campaign $campaign, array $pilot): array
    {
        if ($campaign->fresh()->search_delivery_state['ai_max_request']['status'] !== 'preparing') {
            throw new \LogicException('Preparation was not durably journalled.');
        }
        $this->effect('prepare');
        $campaign->update(['spend_guardrails' => [...$campaign->spend_guardrails, 'ai_max_pilot' => $pilot],
            'end_date' => CarbonImmutable::parse($pilot['ends_at'])->toDateString()]);

        return ['ready' => true, 'status' => 'prepared'];
    }

    protected function activatePilot(Campaign $campaign): array
    {
        if ($campaign->fresh()->search_delivery_state['ai_max_request']['status'] !== 'activating') {
            throw new \LogicException('Activation was not durably journalled.');
        }
        $this->effect('activate');
        $campaign->applyPlatformStatus('ENABLED');

        return ['ready' => true, 'status' => 'active'];
    }
}

/** Exercise the real final restart guard without sending a Google mutation. */
class ScriptedAiMaxOwnerStatus extends UpdateCampaignStatus
{
    public ?\Closure $afterRead = null;

    public function __construct(private Campaign $campaign, private array $pilot)
    {
        parent::__construct($campaign->customer);
    }

    public function execute(string $customerId, string $campaignResourceName, string $status): array
    {
        return ['success' => $this->pilotRestartReady($this->campaign, $this->pilot)];
    }

    protected function checkPilotSpend(Campaign $campaign): void {}

    protected function inspectPilot(Campaign $campaign, array $pilot): array
    {
        if ($this->afterRead) {
            ($this->afterRead)();
        }

        return ['ready' => true];
    }
}
