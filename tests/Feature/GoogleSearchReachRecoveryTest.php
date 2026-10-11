<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Jobs\CheckGoogleSearchDelivery;
use App\Jobs\Scheduled\DispatchGoogleSearchDeliveryChecks;
use App\Models\AgentActivity;
use App\Models\AgentRun;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Notifications\CriticalAgentAlert;
use App\Services\Agents\GoogleSearchReachPlanner;
use App\Services\Agents\GoogleSearchReachRecovery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoogleSearchReachRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::fake();
    }

    private function makeCampaign(bool $trial = false): Campaign
    {
        $customer = Customer::factory()->create(['service_type' => 'managed',
            'google_ads_customer_id' => '1234567890', 'google_ads_link_status' => 'active']);
        $customer->users()->attach(User::factory()->create()->id);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id,
            'status' => CampaignStatus::Active, 'google_ads_campaign_id' => 'customers/1234567890/campaigns/123',
            'daily_budget' => '10.00', 'approved_daily_budget' => '10.00', 'budget_confirmed_at' => now()->subDays(2),
            'end_date' => now()->addDays(30), 'spend_guardrails' => $trial ? ['enabled' => true,
                'started_at' => now()->subHours(20)->toIso8601String(), 'max_cpc_bid_micros' => 3_000_000,
                'max_daily_budget_micros' => 10_000_000] : null]);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'campaign_type' => 'search', 'deployment_status' => 'active', 'deployed_at' => now()->subDays(3)]);

        return $campaign->load('customer');
    }

    private function snapshot(): array
    {
        return ['status' => 2, 'channel' => 2, 'google_search_enabled' => true,
            'bidding_strategy' => 2, 'daily_budget_micros' => 10_000_000, 'actual_cpc_ceiling_micros' => 3_000_000,
            'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney', 'approved_ads' => 1, 'eligible_keywords' => 1,
            'geo_target_constants' => ['geoTargetConstants/2036'], 'language_constants' => ['languageConstants/1000'],
            'negative_keywords' => [], 'ad_schedule' => [],
            'keywords' => [['text' => 'ppc software', 'match_type' => 'EXACT', 'status' => 'ENABLED',
                'ad_group_status' => 'ENABLED', 'ad_group_resource' => 'customers/1234567890/adGroups/10',
                'resource_name' => 'customers/1234567890/adGroupCriteria/10~1']],
            // Historical traffic must never conceal a failed current test.
            'impressions' => ['google_search' => 100],
            'measurement' => ['started_at' => now()->subHours(20)->toIso8601String(),
                'from' => now()->subHours(19)->toIso8601String(), 'through' => now()->subHours(3)->toIso8601String(),
                'complete_hours' => 16, 'impressions' => 0, 'clicks' => 0, 'cost_micros' => 0, 'conversions' => 0]];
    }

    private function diagnosis(int $candidates = 1): array
    {
        $keywords = [];
        for ($i = 0; $i < $candidates; $i++) {
            $keywords[] = ['text' => 'google ads software '.$i, 'match_type' => $i % 2 ? 'PHRASE' : 'EXACT',
                'ad_group_resource' => 'customers/1234567890/adGroups/10', 'relevant' => true,
                'negative_conflict' => false, 'forecasted' => true, 'forecast' => ['success' => true, 'clicks' => 10,
                    'impressions' => 100, 'cost_micros' => 10_000_000, 'period_days' => 30]];
        }

        return ['status' => 'evidence_ready', 'auto_repair_safe' => true, 'candidate_keywords' => $keywords,
            'issues' => [['code' => 'limited_keyword_demand', 'message' => 'The current keyword theme has limited demand.']],
            'forecast' => ['success' => true, 'clicks' => 0, 'impressions' => 0, 'period_days' => 30],
            'proposal' => ['summary' => 'Add forecasted relevant terms at the existing bid ceiling.',
                'candidate_keywords' => $keywords, 'blocked_by' => []]];
    }

    private function watchdog(array $snapshot, array $diagnosis): FakeSearchReachWatchdog
    {
        $planner = new FakeSearchReachPlanner(function ($campaign, $observed, $research) use ($diagnosis): array {
            $this->researchCalls[] = $research;

            return $diagnosis;
        });

        return new FakeSearchReachWatchdog($planner, $snapshot);
    }

    private array $researchCalls = [];

    public function test_trial_diagnoses_and_alerts_once_without_touching_keywords_bids_or_budget(): void
    {
        $campaign = $this->makeCampaign(true);
        $watchdog = $this->watchdog($this->snapshot(), $this->diagnosis());
        $state = $watchdog->check($campaign);
        $again = $watchdog->check($campaign);

        $this->assertSame('approval_required', $state['status']);
        $this->assertSame('approved_bounded_trial', $state['blocked_reason']);
        $this->assertSame('limited_keyword_demand', $state['diagnosis']['issues'][0]['code']);
        $this->assertSame([true, false], $this->researchCalls);
        $this->assertSame($state['last_alert_at'], $again['last_alert_at']);
        $this->assertCount(0, $watchdog->added);
        $this->assertEquals(10, $campaign->fresh()->daily_budget);
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'reach_needs_review')->count());
        $this->assertSame(0, $state['measurement']['impressions']);
        $user = $campaign->customer->users()->firstOrFail();
        $alert = Notification::sent($user, CriticalAgentAlert::class)->sole();
        $mail = $alert->toMail($user);
        $body = implode(' ', [...$mail->introLines, ...$mail->outroLines]);
        $this->assertStringContainsString('0 impressions and 0 clicks across 16 complete reporting hours', $body);
        $this->assertStringContainsString('AUD 10.00/day', $body);
        $this->assertStringContainsString('Maximum cost-per-click bid at this check: AUD 3.00', $body);
        $this->assertStringContainsString('Keyword research and delivery checks continue', $body);
        $this->assertStringContainsString('Ask your administrator', $body);
        $this->assertStringNotContainsString('approved bounded trial', $body);
        $this->assertStringNotContainsString('Add forecasted relevant terms', $body);
        $this->assertSame('Review delivery report', $mail->actionText);
        $this->assertSame(route('campaigns.show', $campaign), $mail->actionUrl);
        Http::assertNothingSent();
    }

    public function test_initial_hours_save_reach_warning_without_claiming_a_stall_or_sending_alert(): void
    {
        $campaign = $this->makeCampaign(true);
        $snapshot = $this->snapshot();
        $snapshot['measurement']['complete_hours'] = 2;
        $watchdog = $this->watchdog($snapshot, $this->diagnosis());
        $state = $watchdog->check($campaign);

        $this->assertSame('low_reach', $state['status']);
        $this->assertSame('collecting_complete_hours', $state['blocked_reason']);
        $this->assertSame([false], $this->researchCalls);
        $this->assertFalse(isset($state['last_alert_at']));
        $this->assertCount(0, $watchdog->added);
        Notification::assertNothingSent();
    }

    public function test_forecasted_keywords_are_bounded_then_actual_post_repair_traffic_is_verified(): void
    {
        $campaign = $this->makeCampaign();
        $watchdog = $this->watchdog($this->snapshot(), $this->diagnosis(4));
        $state = $watchdog->check($campaign);

        $this->assertSame('verifying', $state['status']);
        $this->assertCount(3, $watchdog->added);
        $this->assertSame([3_000_000, 3_000_000, 3_000_000], array_column($watchdog->added, 'cap'));
        $this->assertSame(['EXACT', 'PHRASE', 'EXACT'], array_column($watchdog->added, 'match_type'));
        $this->assertSame([false, false, false], array_column($watchdog->added, 'set_keyword_bid'));
        $this->assertSame(0, $state['verification']['impressions']);
        $watchdog->postRepairImpressions = 2;
        $watchdog->postRepairHours = 1;
        $verified = $watchdog->check($campaign);

        $this->assertSame('recovered', $verified['status']);
        $this->assertSame(2, $verified['verification']['impressions']);
        $this->assertCount(3, $watchdog->added);
        $this->assertEquals(10, $campaign->fresh()->daily_budget);
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'reach_recovery_verified')->count());
    }

    public function test_negative_conflicts_and_missing_existing_cpc_cap_require_review(): void
    {
        $campaign = $this->makeCampaign();
        $snapshot = $this->snapshot();
        $snapshot['negative_keywords'] = [['scope' => 'campaign', 'text' => 'software', 'match_type' => 'BROAD']];
        $watchdog = $this->watchdog($snapshot, $this->diagnosis());
        $this->assertSame('approval_required', $watchdog->check($campaign)['status']);
        $this->assertCount(0, $watchdog->added);
        $watchdog->snapshot['negative_keywords'] = [];
        $watchdog->snapshot['actual_cpc_ceiling_micros'] = 0;
        $campaign->update(['search_delivery_state' => null]);

        $this->assertSame('existing_cpc_cap_required', $watchdog->check($campaign)['blocked_reason']);
        $this->assertCount(0, $watchdog->added);
    }

    public function test_broad_or_unforecasted_candidates_and_unapproved_budget_are_not_applied(): void
    {
        $campaign = $this->makeCampaign();
        $diagnosis = $this->diagnosis();
        $diagnosis['candidate_keywords'][0]['match_type'] = 'BROAD';
        $watchdog = $this->watchdog($this->snapshot(), $diagnosis);
        $this->assertSame('forecasted_relevant_keywords_required', $watchdog->check($campaign)['blocked_reason']);
        $this->assertCount(0, $watchdog->added);
        $campaign->update(['budget_confirmed_at' => null, 'search_delivery_state' => null]);
        $watchdog = $this->watchdog($this->snapshot(), $this->diagnosis());
        $this->assertSame('approved_budget_unverified', $watchdog->check($campaign)['blocked_reason']);
        $this->assertCount(0, $watchdog->added);
    }

    public function test_disabled_search_inventory_or_unapproved_ads_allow_research_without_keyword_writes(): void
    {
        $campaign = $this->makeCampaign();
        $snapshot = $this->snapshot();
        $snapshot['google_search_enabled'] = false;
        $watchdog = $this->watchdog($snapshot, $this->diagnosis());
        $state = $watchdog->check($campaign);
        $this->assertSame('search_inventory_unavailable', $state['blocked_reason']);
        $this->assertNotEmpty($state['diagnosis']['proposal']['blocked_by']);
        $this->assertCount(0, $watchdog->added);
        $campaign->update(['search_delivery_state' => null]);
        $watchdog->snapshot['google_search_enabled'] = true;
        $watchdog->snapshot['approved_ads'] = 0;
        $state = $watchdog->check($campaign);

        $this->assertSame('approved_search_ad_required', $state['blocked_reason']);
        $this->assertSame([true, true], $this->researchCalls);
        $this->assertCount(0, $watchdog->added);
    }

    public function test_remote_configuration_change_resets_the_measurement_instead_of_reusing_old_traffic(): void
    {
        $campaign = $this->makeCampaign(true);
        $snapshot = $this->snapshot();
        $snapshot['measurement']['impressions'] = 20;
        $diagnosis = $this->diagnosis();
        $diagnosis['issues'] = [];
        $watchdog = $this->watchdog($snapshot, $diagnosis);
        $this->assertSame('delivering', $watchdog->check($campaign)['status']);
        $watchdog->snapshot['actual_cpc_ceiling_micros'] = 2_000_000;
        $reset = $watchdog->check($campaign);

        $this->assertSame(0, $reset['measurement']['impressions']);
        $this->assertSame(0, $reset['measurement']['complete_hours']);
        $this->assertNotSame('delivering', $reset['status']);
        $this->assertCount(0, $watchdog->added);
    }

    public function test_failure_after_remote_creation_preserves_attempt_and_blocks_repeat_additions(): void
    {
        $campaign = $this->makeCampaign();
        $watchdog = $this->watchdog($this->snapshot(), $this->diagnosis());
        $watchdog->failReadback = true;
        $failed = $watchdog->check($campaign);

        $this->assertSame('unavailable', $failed['status']);
        $this->assertCount(1, $failed['repair_attempts']);
        $this->assertCount(1, $failed['repair']['added_keywords']);
        $this->assertNotEmpty($failed['repair']['errors']);
        $watchdog->failReadback = false;
        $watchdog->postRepairImpressions = 5;
        $again = $watchdog->check($campaign);
        $this->assertSame('needs_review', $again['status']);
        $this->assertCount(1, $watchdog->added);
    }

    public function test_verification_timeout_escalates_without_a_second_repair(): void
    {
        $campaign = $this->makeCampaign();
        $watchdog = $this->watchdog($this->snapshot(), $this->diagnosis());
        $this->assertSame('verifying', $watchdog->check($campaign)['status']);
        $watchdog->postRepairHours = 24;
        $failed = $watchdog->check($campaign);

        $this->assertSame('needs_review', $failed['status']);
        $this->assertSame('repair_did_not_restore_search_traffic', $failed['blocked_reason']);
        $this->assertCount(1, $watchdog->added);
    }

    public function test_a_new_hold_between_research_and_mutation_prevents_keyword_creation(): void
    {
        $campaign = $this->makeCampaign();
        $watchdog = $this->watchdog($this->snapshot(), $this->diagnosis());
        $watchdog->onInspect = function ($observed, $count) {
            if ($count === 2) {
                $observed->update(['spend_safety_hold' => ['reason' => 'owner_review']]);
            }
        };
        $state = $watchdog->check($campaign);

        $this->assertSame('approval_required', $state['status']);
        $this->assertSame('spend_safety_hold', $state['blocked_reason']);
        $this->assertCount(0, $watchdog->added);
    }

    public function test_paused_or_nonsearch_campaigns_cannot_be_enabled_or_repaired(): void
    {
        $campaign = $this->makeCampaign();
        $campaign->update(['status' => CampaignStatus::Paused]);
        $watchdog = $this->watchdog($this->snapshot(), $this->diagnosis());
        $this->assertSame('paused', $watchdog->check($campaign)['status']);
        $this->assertSame(0, $watchdog->inspectCount);
        $campaign->update(['status' => CampaignStatus::Active]);
        $campaign->strategies()->update(['campaign_type' => 'performance_max']);
        $this->assertSame('not_deployed_search_campaign', $watchdog->check($campaign)['blocked_reason']);
        $this->assertCount(0, $watchdog->added);
        $this->assertSame(0, $watchdog->inspectCount);
    }

    public function test_read_failure_cannot_keep_a_previous_green_status(): void
    {
        $campaign = $this->makeCampaign(true);
        $snapshot = $this->snapshot();
        $snapshot['measurement']['impressions'] = 20;
        $diagnosis = $this->diagnosis();
        $diagnosis['issues'] = [];
        $watchdog = $this->watchdog($snapshot, $diagnosis);
        $this->assertSame('delivering', $watchdog->check($campaign)['status']);
        $watchdog->onInspect = fn () => throw new \RuntimeException('Provider unreachable');
        $state = $watchdog->check($campaign);

        $this->assertSame('unavailable', $state['status']);
        $this->assertNotEmpty($state['errors']);
        $this->assertFalse($state['mutation_allowed']);
    }

    public function test_hourly_success_keeps_researched_proposal_but_fresh_forecast_failure_never_reuses_it(): void
    {
        $campaign = $this->makeCampaign(true);
        $baseline = $this->diagnosis(0);
        $baseline['auto_repair_safe'] = false;
        $baseline['proposal']['summary'] = 'An hourly baseline assessment.';
        $responses = [$this->diagnosis(), $baseline, [
            'status' => 'unavailable', 'issues' => [], 'candidate_keywords' => [],
            'proposal' => ['summary' => 'Fresh provider evidence is unavailable.']]];
        $calls = [];
        $planner = new FakeSearchReachPlanner(function ($c, $s, $research) use (&$responses, &$calls): array {
            $calls[] = $research;

            return array_shift($responses) ?? throw new \LogicException('Unexpected assessment.');
        });
        $watchdog = new FakeSearchReachWatchdog($planner, $this->snapshot());
        $first = $watchdog->check($campaign);
        $next = $watchdog->check($campaign);
        $failed = $watchdog->check($campaign);

        $this->assertCount(1, $next['diagnosis']['candidate_keywords']);
        $this->assertSame($first['diagnosis']['proposal']['summary'], $next['diagnosis']['proposal']['summary']);
        $this->assertSame('unavailable', $failed['status']);
        $this->assertSame('unavailable', $failed['diagnosis']['status']);
        $this->assertCount(0, $failed['diagnosis']['candidate_keywords']);
        $this->assertSame([true, false, false], $calls);
    }

    public function test_spending_hold_keeps_read_only_research_running_even_if_google_paused_campaign(): void
    {
        $campaign = $this->makeCampaign();
        $campaign->update(['status' => CampaignStatus::Paused, 'spend_safety_hold' => ['reason' => 'review']]);
        $snapshot = $this->snapshot();
        $snapshot['status'] = 3;
        $watchdog = $this->watchdog($snapshot, $this->diagnosis());
        $state = $watchdog->check($campaign);

        $this->assertSame('approval_required', $state['status']);
        $this->assertSame('spend_safety_hold', $state['blocked_reason']);
        $this->assertSame([true], $this->researchCalls);
        $this->assertCount(0, $watchdog->added);
        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
    }

    public function test_a_few_impressions_do_not_hide_insufficient_forecast_reach(): void
    {
        $campaign = $this->makeCampaign(true);
        $snapshot = $this->snapshot();
        $snapshot['measurement']['impressions'] = 8;
        $diagnosis = $this->diagnosis();
        $diagnosis['issues'] = [['code' => 'insufficient_forecast_reach', 'message' => 'Forecast reach is insufficient.']];
        $watchdog = $this->watchdog($snapshot, $diagnosis);
        $state = $watchdog->check($campaign);

        $this->assertSame('approval_required', $state['status']);
        $this->assertSame([false, true], $this->researchCalls);
        $this->assertCount(0, $watchdog->added);
    }

    public function test_good_actual_traffic_with_unavailable_forecast_is_unverified(): void
    {
        $campaign = $this->makeCampaign(true);
        $snapshot = $this->snapshot();
        $snapshot['measurement']['impressions'] = 20;
        $watchdog = $this->watchdog($snapshot, ['status' => 'unavailable', 'issues' => [],
            'proposal' => ['summary' => 'Current forecasts are unavailable.']]);
        $state = $watchdog->check($campaign);

        $this->assertSame('unavailable', $state['status']);
        $this->assertNotSame('delivering', $state['status']);
        $this->assertCount(0, $watchdog->added);
    }

    public function test_low_historical_volume_is_advisory_when_actual_traffic_and_forecast_are_adequate(): void
    {
        $campaign = $this->makeCampaign();
        $snapshot = $this->snapshot();
        $snapshot['measurement']['impressions'] = 20;
        $diagnosis = $this->diagnosis();
        $diagnosis['forecast']['clicks'] = 35;
        $diagnosis['forecast']['impressions'] = 300;
        $watchdog = $this->watchdog($snapshot, $diagnosis);
        $state = $watchdog->check($campaign);

        $this->assertSame('delivering', $state['status']);
        $this->assertSame('limited_keyword_demand', $state['diagnosis']['issues'][0]['code']);
        $this->assertSame([false], $this->researchCalls);
        $this->assertCount(0, $watchdog->added);
        Notification::assertNothingSent();
    }

    public function test_prior_traffic_does_not_certify_current_delivery_without_serving_ads_or_keyword_evidence(): void
    {
        $campaign = $this->makeCampaign();
        $snapshot = $this->snapshot();
        $snapshot['measurement']['impressions'] = 20;
        $snapshot['approved_ads'] = 0;
        $diagnosis = $this->diagnosis();
        $diagnosis['forecast']['clicks'] = 35;
        $watchdog = $this->watchdog($snapshot, $diagnosis);

        $this->assertSame('needs_review', $watchdog->check($campaign)['status']);
        $this->assertCount(0, $watchdog->added);
        $campaign->update(['search_delivery_state' => null]);
        $snapshot['approved_ads'] = 1;
        unset($snapshot['eligible_keywords']);
        $watchdog = $this->watchdog($snapshot, $diagnosis);
        $state = $watchdog->check($campaign);

        $this->assertSame('needs_review', $state['status']);
        $this->assertSame('search_keyword_eligibility_unavailable', $state['blocked_reason']);
        $this->assertCount(0, $watchdog->added);
    }

    public function test_lost_ad_approval_or_paused_ad_group_cannot_confirm_recovery_from_prior_impressions(): void
    {
        $campaign = $this->makeCampaign();
        $watchdog = $this->watchdog($this->snapshot(), $this->diagnosis());
        $this->assertSame('verifying', $watchdog->check($campaign)['status']);
        $watchdog->postRepairImpressions = 20;
        $watchdog->snapshot['approved_ads'] = 0;
        $state = $watchdog->check($campaign);

        $this->assertSame('needs_review', $state['status']);
        $this->assertSame('partial_repair_unresolved', $state['blocked_reason']);
        $this->assertSame(0, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'reach_recovery_verified')->count());

        // Reset only the fixture's independent scenario; the real workflow
        // leaves an unresolved repair for explicit administrator review.
        $campaign->update(['search_delivery_state' => null]);
        $watchdog = $this->watchdog($this->snapshot(), $this->diagnosis());
        $this->assertSame('verifying', $watchdog->check($campaign)['status']);
        $watchdog->postRepairImpressions = 20;
        $watchdog->snapshot['keywords'][0]['ad_group_status'] = 'PAUSED';
        $watchdog->snapshot['eligible_keywords'] = 0;
        $state = $watchdog->check($campaign);

        $this->assertSame('needs_review', $state['status']);
        $this->assertSame('partial_repair_unresolved', $state['blocked_reason']);
        $this->assertCount(1, $state['repair']['errors']);
        $this->assertSame(0, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'reach_recovery_verified')->count());
    }

    public function test_second_verification_inspection_must_still_have_current_serving_evidence(): void
    {
        $campaign = $this->makeCampaign();
        $watchdog = $this->watchdog($this->snapshot(), $this->diagnosis());
        $this->assertSame('verifying', $watchdog->check($campaign)['status']);
        $watchdog->postRepairImpressions = 20;
        $secondInspection = $watchdog->inspectCount + 2;
        $watchdog->onInspect = function ($campaign, $count) use ($watchdog, $secondInspection): void {
            if ($count === $secondInspection) {
                $watchdog->snapshot['approved_ads'] = 0;
            }
        };
        $state = $watchdog->check($campaign);

        $this->assertSame('needs_review', $state['status']);
        $this->assertSame('approved_search_ad_required', $state['blocked_reason']);
        $this->assertSame(0, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'reach_recovery_verified')->count());
    }

    public function test_newer_provider_baseline_is_saved_and_recovered_actions_are_not_recounted_hourly(): void
    {
        $campaign = $this->makeCampaign();
        $watchdog = $this->watchdog($this->snapshot(), $this->diagnosis());
        $this->assertSame('verifying', $watchdog->check($campaign)['status']);
        $watchdog->postRepairImpressions = 2;
        (new CheckGoogleSearchDelivery($campaign->id))->handle($watchdog);
        (new CheckGoogleSearchDelivery($campaign->id))->handle($watchdog);
        $runs = AgentRun::where('job', 'CheckGoogleSearchDelivery')->where('scope', "Campaign {$campaign->id}")
            ->orderBy('id')->get();

        $this->assertSame([1, 0], $runs->pluck('actions_taken')->all());
        $campaign->update(['search_delivery_state' => null, 'spend_guardrails' => ['enabled' => true,
            'started_at' => now()->subHours(20)->toIso8601String(), 'max_cpc_bid_micros' => 3_000_000,
            'max_daily_budget_micros' => 10_000_000]]);
        $snapshot = $this->snapshot();
        $snapshot['evaluation_started_at'] = now()->subHours(8)->toIso8601String();
        $watchdog = $this->watchdog($snapshot, $this->diagnosis());
        $state = $watchdog->check($campaign);
        $this->assertSame($snapshot['evaluation_started_at'], $state['evaluation_started_at']);
    }

    public function test_fanout_is_queued_and_attention_is_reported_for_a_trial_requiring_review(): void
    {
        $campaign = $this->makeCampaign(true);
        (new DispatchGoogleSearchDeliveryChecks)->handle();
        Queue::assertPushed(CheckGoogleSearchDelivery::class, fn ($job) => $job->campaignId === $campaign->id);
        $watchdog = $this->watchdog($this->snapshot(), $this->diagnosis());
        (new CheckGoogleSearchDelivery($campaign->id))->handle($watchdog);
        $run = AgentRun::where('job', 'CheckGoogleSearchDelivery')->latest('id')->firstOrFail();

        $this->assertSame('attention', $run->status);
        $this->assertSame('approval_required', $run->details['delivery_status']);
        $this->assertSame(0, $run->actions_taken);
        $this->assertSame(1, $run->warnings);
    }
}

class FakeSearchReachPlanner extends GoogleSearchReachPlanner
{
    public function __construct(private \Closure $assessment) {}

    public function assess(Campaign $campaign, array $snapshot, bool $research = false): array
    {
        return ($this->assessment)($campaign, $snapshot, $research);
    }
}

/** Scripted provider behavior; no Google request or synthetic conversion is sent. */
class FakeSearchReachWatchdog extends GoogleSearchReachRecovery
{
    public array $added = [];

    public int $postRepairImpressions = 0;

    public int $postRepairHours = 0;

    public int $inspectCount = 0;

    public bool $failReadback = false;

    public ?\Closure $onInspect = null;

    public function __construct(GoogleSearchReachPlanner $planner, public array $snapshot)
    {
        parent::__construct($planner);
    }

    protected function inspect(Campaign $campaign, \DateTimeInterface $since): ?array
    {
        $this->inspectCount++;
        if ($this->onInspect) {
            ($this->onInspect)($campaign, $this->inspectCount);
        }
        if ($this->failReadback && $this->added) {
            throw new \RuntimeException('Provider read-back unavailable');
        }
        $snapshot = $this->snapshot;
        if ($this->added) {
            $snapshot['measurement']['complete_hours'] = $this->postRepairHours;
            $snapshot['measurement']['impressions'] = $this->postRepairImpressions;
            $snapshot['measurement']['started_at'] = $since->format(DATE_ATOM);
            $snapshot['measurement']['from'] = $since->format(DATE_ATOM);
        }

        return $snapshot;
    }

    protected function addKeyword(Customer $customer, array $candidate, int $cap): ?string
    {
        $resource = 'customers/1234567890/adGroupCriteria/10~'.(count($this->added) + 20);
        $this->added[] = ['text' => $candidate['text'], 'match_type' => $candidate['match_type'], 'cap' => $cap,
            'set_keyword_bid' => $candidate['set_keyword_bid'] ?? false];
        $this->snapshot['keywords'][] = ['text' => $candidate['text'], 'match_type' => $candidate['match_type'],
            'status' => 'ENABLED', 'ad_group_status' => 'ENABLED', 'ad_group_resource' => $candidate['ad_group_resource'],
            'resource_name' => $resource];
        if ($candidate['set_keyword_bid'] ?? false) {
            $this->snapshot['keywords'][array_key_last($this->snapshot['keywords'])]['cpc_bid_micros'] = $cap;
        }

        return $resource;
    }
}
