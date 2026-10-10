<?php

namespace Tests\Feature;

use App\Jobs\CheckGoogleCampaignSpendSafety;
use App\Jobs\Scheduled\DispatchCampaignSpendSafetyChecks;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Campaigns\CampaignBudgetService;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\Campaigns\GoogleCampaignSpendSafety;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBiddingStrategy;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBudget;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V22\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus as GoogleStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignSpendSafetyTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(array $attributes = []): Campaign
    {
        $customer = Customer::where('google_ads_customer_id', '123-456-7890')->first()
            ?? Customer::factory()->create(['google_ads_customer_id' => '123-456-7890', 'service_type' => 'managed']);

        return Campaign::factory()->create($attributes + [
            'customer_id' => $customer->id, 'status' => 'active', 'platform_status' => 'ENABLED',
            'primary_status' => 'ELIGIBLE', 'end_date' => now()->addMonth(),
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/999',
        ]);
    }

    private function trial(array $changes = []): array
    {
        return $changes + [
            'enabled' => true, 'baseline_cost_micros' => 194_450_000, 'baseline_conversions' => 0,
            'max_spend_micros' => 50_000_000, 'max_daily_budget_micros' => 10_000_000,
            'max_cpc_bid_micros' => 3_000_000, 'goal_cpa_micros' => 50_000_000,
        ];
    }

    private function safety(array $changes = []): FakeGoogleCampaignSpendSafety
    {
        $service = new FakeGoogleCampaignSpendSafety;
        $service->data = $changes + [
            'status' => GoogleStatus::ENABLED, 'campaign_type' => AdvertisingChannelType::SEARCH,
            'bidding_strategy' => BiddingStrategyType::TARGET_SPEND, 'cpc_bid_ceiling_micros' => 3_000_000,
            'daily_budget_micros' => 10_000_000, 'cost_micros' => 194_450_000, 'conversions' => 0.0,
            'matured_cost_micros' => 194_450_000, 'window_matured_cost_micros' => 194_450_000,
            'window_conversions' => 0.0, 'account_timezone' => 'Australia/Sydney', 'currency_code' => 'AUD',
            'amplifying_bid_modifiers' => [],
        ];

        return $service;
    }

    public function test_a_single_group_all_zero_conversion_campaign_is_stopped_and_verified_without_a_learning_gate(): void
    {
        $campaign = $this->campaign(['created_at' => now()->subDays(3)]);
        // There is deliberately no local ad-group count or feature/subscription gate.
        $safety = $this->safety();
        $result = $safety->check($campaign);

        $this->assertSame('paused', $result['action']);
        $this->assertSame(1, $safety->pauses);
        $this->assertSame('paused', $campaign->fresh()->status->value);
        $this->assertSame('PAUSED', $campaign->fresh()->platform_status);
        $this->assertNotEmpty($campaign->fresh()->spend_safety_hold['verified_at']);
        $activity = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'campaign_spend_safety_paused')->firstOrFail();
        $this->assertSame('PAUSED', $activity->details['verified_platform_status']);
        $this->assertSame('no_conversions_after_matured_spend', $activity->details['reason']);
    }

    public function test_recent_spend_gets_conversion_reporting_time_and_a_reported_conversion_prevents_the_soft_stop(): void
    {
        $campaign = $this->campaign();
        $recent = $this->safety(['window_matured_cost_micros' => 0]);
        $this->assertSame('observed', $recent->check($campaign)['action']);
        $this->assertSame(0, $recent->pauses);

        $converted = $this->safety(['window_conversions' => 1.0]);
        $this->assertSame('observed', $converted->check($campaign)['action']);
        $this->assertSame(0, $converted->pauses);
    }

    public function test_explicit_trial_spend_is_measured_from_its_baseline_and_todays_cap_stops_even_with_conversions(): void
    {
        $campaign = $this->campaign(['spend_guardrails' => $this->trial()]);
        $below = $this->safety(['cost_micros' => 244_449_999]);
        $this->assertSame('observed', $below->check($campaign)['action']);
        $this->assertSame(0, $below->pauses);

        $over = $this->safety(['cost_micros' => 246_450_000, 'conversions' => 1.0]);
        $result = $over->check($campaign);
        $this->assertSame('trial_spend_cap_reached', $result['reason']);
        $this->assertSame(2_000_000, $result['overshoot_micros']);
        $activity = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'campaign_spend_safety_paused')->firstOrFail();
        $this->assertSame(52_000_000, $activity->details['trial_spend_micros']);
        $this->assertSame(2_000_000, $activity->details['observed_cap_overshoot_micros']);
        $this->assertStringContainsString('not a hard billing cap', $activity->details['reporting_note']);
    }

    public function test_zero_conversion_trial_goal_uses_only_matured_post_baseline_spend(): void
    {
        $campaign = $this->campaign(['spend_guardrails' => $this->trial(['max_spend_micros' => 100_000_000])]);
        $safety = $this->safety(['cost_micros' => 254_450_000, 'matured_cost_micros' => 244_450_000]);
        $this->assertSame('trial_no_conversions_after_goal_spend', $safety->check($campaign)['reason']);
    }

    public function test_budget_drift_and_an_uncapped_strategy_cause_a_trial_stop(): void
    {
        foreach ([
            ['daily_budget_micros' => 10_000_001, 'reason' => 'trial_daily_budget_exceeded'],
            ['cpc_bid_ceiling_micros' => 0, 'reason' => 'trial_bidding_limit_missing'],
            ['bidding_strategy' => BiddingStrategyType::MAXIMIZE_CONVERSIONS, 'reason' => 'trial_bidding_limit_missing'],
            ['amplifying_bid_modifiers' => [['resource' => 'device', 'multiplier' => 2.0]], 'reason' => 'trial_bid_modifier_exceeded'],
        ] as $scenario) {
            $campaign = $this->campaign(['spend_guardrails' => $this->trial()]);
            $this->assertSame($scenario['reason'], $this->safety($scenario)->check($campaign)['reason']);
        }
    }

    public function test_failed_remote_pause_preserves_the_hold_without_claiming_success(): void
    {
        $campaign = $this->campaign();
        $safety = $this->safety();
        $safety->pauseSucceeded = false;
        try {
            $safety->check($campaign);
            $this->fail('The rejected platform pause must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rejected', $e->getMessage());
        }
        $this->assertSame('active', $campaign->fresh()->status->value);
        $this->assertNotEmpty($campaign->fresh()->spend_safety_hold);
        $this->assertFalse(CampaignSpendGuardrails::canEnable($campaign->fresh(), true));
        $this->assertSame(0, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'campaign_spend_safety_paused')->count());
    }

    public function test_failed_readback_is_retried_and_a_verified_hold_does_not_duplicate_activity(): void
    {
        $campaign = $this->campaign();
        $safety = $this->safety();
        $safety->verifiedPaused = false;
        try {
            $safety->check($campaign);
            $this->fail('The read-back must be verified.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not verified', $e->getMessage());
        }
        $this->assertSame('active', $campaign->fresh()->status->value);
        $safety->verifiedPaused = true;
        $this->assertSame('paused', $safety->check($campaign)['action']);
        $safety->data['status'] = GoogleStatus::PAUSED;
        $this->assertSame('held', $safety->check($campaign)['action']);
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'campaign_spend_safety_paused')->count());
    }

    public function test_manually_paused_campaign_drift_is_restored_and_automatic_enable_is_refused(): void
    {
        $campaign = $this->campaign(['status' => 'paused', 'platform_status' => 'PAUSED']);
        $safety = $this->safety(['cost_micros' => 0, 'window_matured_cost_micros' => 0]);
        $this->assertSame('manual_pause_drift', $safety->check($campaign)['reason']);
        $result = (new UpdateCampaignStatus($campaign->customer))->enable('1234567890', $campaign->googleAdsResourceName(), true);
        $this->assertFalse($result['success']);
        $this->assertFalse(CampaignSpendGuardrails::canEnable($campaign->fresh(), true));
    }

    public function test_fresh_trial_configuration_captures_latest_baseline_and_requires_explicit_release_before_restart(): void
    {
        $campaign = $this->campaign(['status' => 'paused', 'platform_status' => 'PAUSED', 'spend_safety_hold' => ['reason' => 'manual_pause']]);
        $safety = $this->safety(['status' => GoogleStatus::PAUSED, 'cost_micros' => 198_450_000]);
        $trial = $safety->configureBoundedTrial($campaign, 50_000_000, 10_000_000, 3_000_000, 50_000_000, 'Owner approved the A$50 test.');
        $this->assertSame(198_450_000, $trial['baseline_cost_micros']);
        $this->assertSame('AUD', $trial['currency_code']);
        $this->assertSame('paused', $campaign->fresh()->status->value);
        $this->assertFalse(CampaignSpendGuardrails::canEnable($campaign->fresh(), true));
        $safety->releaseForApprovedRestart($campaign, 'Owner approved restart after the keyword repair.');
        $this->assertNull($campaign->fresh()->spend_safety_hold);
        $this->assertTrue(CampaignSpendGuardrails::canEnable($campaign->fresh(), true));
        $this->assertFalse(CampaignSpendGuardrails::canEnable($campaign->fresh()));
        $this->assertSame(0, $safety->pauses);
    }

    public function test_later_reported_spend_updates_the_saved_hold_and_records_meaningful_overshoot_once(): void
    {
        $campaign = $this->campaign(['spend_guardrails' => $this->trial()]);
        $safety = $this->safety(['cost_micros' => 244_450_000]);
        $this->assertSame('paused', $safety->check($campaign)['action']);
        $safety->data['status'] = GoogleStatus::PAUSED;
        $safety->data['cost_micros'] = 246_450_000;
        $this->assertSame('held', $safety->check($campaign)['action']);
        $this->assertSame('held', $safety->check($campaign)['action']);
        $this->assertSame(2_000_000, $campaign->fresh()->spend_safety_hold['observed_cap_overshoot_micros']);
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'trial_spend_overshoot_reported')->count());
    }

    public function test_an_exhausted_trial_cannot_be_released_without_a_new_approved_budget_and_baseline(): void
    {
        $campaign = $this->campaign(['status' => 'paused', 'spend_guardrails' => $this->trial(), 'spend_safety_hold' => ['reason' => 'trial_spend_cap_reached']]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('still fails its spend limits');
        $this->safety(['status' => GoogleStatus::PAUSED, 'cost_micros' => 244_450_000])->releaseForApprovedRestart($campaign, 'Attempted restart.');
    }

    public function test_common_mutators_reject_trial_increases_and_strategy_changes_before_calling_google(): void
    {
        $campaign = $this->campaign(['spend_guardrails' => $this->trial(), 'daily_budget' => 37.50, 'approved_daily_budget' => 37.50]);
        $this->assertFalse((new UpdateCampaignBudget($campaign->customer))('1234567890', $campaign->googleAdsResourceName(), 11_000_000));
        $bids = new UpdateCampaignBiddingStrategy($campaign->customer);
        $this->assertFalse($bids('1234567890', $campaign->googleAdsResourceName(), 'MAXIMIZE_CONVERSIONS'));
        $this->assertFalse($bids('1234567890', $campaign->googleAdsResourceName(), 'MAXIMIZE_CLICKS', null, null, 3_000_001));
        $this->assertFalse($bids('1234567890', $campaign->googleAdsResourceName(), 'MAXIMIZE_CLICKS'));
        $this->assertTrue(CampaignSpendGuardrails::permitsBiddingStrategy($campaign, 'MAXIMIZE_CLICKS', 3_000_000));
        $this->assertSame(10.0, app(CampaignBudgetService::class)->ceiling($campaign));
        $this->assertFalse(CampaignSpendGuardrails::permitsBudget($campaign, 10.01));
        $this->assertTrue(CampaignSpendGuardrails::permitsBudget($campaign, 10.00));
    }

    public function test_fanout_is_plan_independent_and_has_a_dedicated_queue_and_worker(): void
    {
        $active = $this->campaign(['primary_status' => null]);
        $held = $this->campaign(['status' => 'paused', 'primary_status' => 'PAUSED', 'spend_safety_hold' => ['reason' => 'manual_pause']]);
        $draft = $this->campaign(['status' => 'draft', 'primary_status' => null]);
        (new DispatchCampaignSpendSafetyChecks)->handle();
        Queue::assertPushed(CheckGoogleCampaignSpendSafety::class, fn ($job) => $job->campaignId === $active->id && $job->queue === 'spend-safety');
        Queue::assertPushed(CheckGoogleCampaignSpendSafety::class, fn ($job) => $job->campaignId === $held->id);
        Queue::assertNotPushed(CheckGoogleCampaignSpendSafety::class, fn ($job) => $job->campaignId === $draft->id);
        $this->assertSame(['spend-safety'], config('horizon.defaults.spend-safety.queue'));
        $this->assertStringContainsString('new Scheduled\\DispatchCampaignSpendSafetyChecks)->everyFifteenMinutes()', file_get_contents(base_path('routes/console.php')));
    }

    public function test_safety_failure_is_visible_and_rethrown_for_queue_retry(): void
    {
        $campaign = $this->campaign();
        $safety = $this->safety();
        $safety->verifiedPaused = false;
        try {
            (new CheckGoogleCampaignSpendSafety($campaign->id))->handle($safety);
            $this->fail('Job must retry an unverified stop.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not verified', $e->getMessage());
        }
        $activity = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'campaign_spend_safety_failed')->firstOrFail();
        $this->assertSame('failed', $activity->status);
        $this->assertDatabaseHas('agent_runs', ['job' => 'CheckGoogleCampaignSpendSafety', 'status' => 'failed', 'errors' => 1]);
    }

    public function test_a_credit_top_up_cannot_resume_a_campaign_with_a_manual_or_spend_safety_hold(): void
    {
        $campaign = $this->campaign([
            'status' => 'paused', 'platform_status' => 'PAUSED', 'google_ads_campaign_id' => null,
            'microsoft_ads_campaign_id' => 'ms-999', 'spend_safety_hold' => ['reason' => 'manual_pause'],
        ]);
        $resume = new \ReflectionMethod(\App\Services\AdSpendBillingService::class, 'resumeAllCampaigns');
        $resume->invoke(app(\App\Services\AdSpendBillingService::class), $campaign->customer);
        $this->assertSame('paused', $campaign->fresh()->status->value);
    }

    public function test_a_credit_top_up_cannot_resume_a_bounded_trial_without_explicit_restart_review(): void
    {
        $campaign = $this->campaign([
            'status' => 'paused', 'platform_status' => 'PAUSED', 'google_ads_campaign_id' => null,
            'microsoft_ads_campaign_id' => 'ms-trial', 'spend_guardrails' => $this->trial(),
        ]);
        $resume = new \ReflectionMethod(\App\Services\AdSpendBillingService::class, 'resumeAllCampaigns');
        $resume->invoke(app(\App\Services\AdSpendBillingService::class), $campaign->customer);
        $this->assertSame('paused', $campaign->fresh()->status->value);
    }

    public function test_manual_mutation_limits_see_trial_limits_saved_after_the_model_was_loaded(): void
    {
        $campaign = $this->campaign();
        $stale = $campaign->fresh();
        $campaign->update(['spend_guardrails' => $this->trial()]);
        $this->assertFalse(CampaignSpendGuardrails::permitsKeywordBid($stale, 3_000_001));
        $this->assertFalse(CampaignSpendGuardrails::permitsBudget($stale, 10.01));
        $this->assertFalse(CampaignSpendGuardrails::permitsBidModifier($stale, 1.2));
        $this->assertFalse(CampaignSpendGuardrails::permitsBiddingStrategy($stale, 'MAXIMIZE_CONVERSIONS', null));
    }
}

class FakeGoogleCampaignSpendSafety extends GoogleCampaignSpendSafety
{
    public array $data = [];

    public int $pauses = 0;

    public bool $pauseSucceeded = true;

    public bool $verifiedPaused = true;

    protected function snapshot(Campaign $campaign): array
    {
        return $this->data;
    }

    protected function pause(Campaign $campaign): bool
    {
        $this->pauses++;

        return $this->pauseSucceeded;
    }

    protected function isPaused(Campaign $campaign): bool
    {
        return $this->verifiedPaused;
    }
}
