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
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
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
            'start_date' => now('Australia/Sydney')->startOfDay()->subDays(3),
            'end_date' => now('Australia/Sydney')->startOfDay()->addDays(30), 'spend_guardrails' => $trial ? ['enabled' => true,
                'started_at' => now()->subHours(20)->toIso8601String(), 'max_cpc_bid_micros' => 3_000_000,
                'max_daily_budget_micros' => 10_000_000] : null]);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'campaign_type' => 'search', 'deployment_status' => 'active', 'deployed_at' => now()->subDays(3)]);

        return $campaign->load('customer');
    }

    private function snapshot(): array
    {
        return ['status' => 2, 'channel' => 2, 'google_search_enabled' => true,
            'start_date' => now('Australia/Sydney')->startOfDay()->subDays(3)->toDateString(),
            'end_date' => now('Australia/Sydney')->startOfDay()->addDays(30)->toDateString(),
            'bidding_strategy' => BiddingStrategyType::TARGET_SPEND, 'cpc_bid_ceiling_micros' => 3_000_000, 'daily_budget_micros' => 10_000_000, 'actual_cpc_ceiling_micros' => 3_000_000,
            'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney', 'approved_ads' => 1, 'eligible_keywords' => 1,
            'geo_target_constants' => ['geoTargetConstants/2036'], 'language_constants' => ['languageConstants/1000'],
            'negative_keywords' => [], 'ad_schedule' => [], 'excluded_geo_target_constants' => [],
            'device_bid_modifiers' => [], 'unsupported_targeting' => [], 'search_partners_enabled' => false,
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
                'selection_reason' => 'A commercial buyer looking for this campaign’s offered service.',
                'negative_conflict' => false, 'forecasted' => true, 'forecast' => ['success' => true, 'clicks' => 10,
                    'impressions' => 100, 'cost_micros' => 10_000_000, 'period_days' => 30]];
        }

        return ['status' => 'evidence_ready', 'auto_repair_safe' => true, 'candidate_keywords' => $keywords,
            'issues' => [['code' => 'limited_keyword_demand', 'message' => 'The current keyword theme has limited demand.']],
            'forecast' => ['success' => true, 'clicks' => 0, 'impressions' => 0, 'period_days' => 30],
            'proposal' => ['summary' => 'Add forecasted relevant terms at the existing bid ceiling.',
                'candidate_keywords' => $keywords, 'blocked_by' => []]];
    }

    private function rankSnapshot(): array
    {
        $snapshot = $this->snapshot();
        $through = now('Australia/Sydney')->subHours(3)->startOfHour()->toImmutable();
        $from = $through->startOfDay()->subDay();
        $snapshot['measurement'] = ['started_at' => $from->toIso8601String(), 'from' => $from->toIso8601String(),
            'through' => $through->toIso8601String(), 'complete_hours' => (int) (($through->timestamp - $from->timestamp) / 3600),
            'impressions' => 13, 'clicks' => 0, 'cost_micros' => 0, 'conversions' => 0];
        $snapshot['rank_evidence'] = ['status' => 'verified', 'checked_at' => now()->toIso8601String(),
            'account_timezone' => 'Australia/Sydney', 'from' => $from->toDateString(), 'through' => $from->toDateString(),
            'complete_days' => 1, 'impressions' => 13, 'clicks' => 0, 'cost_micros' => 0,
            'lost_to_rank' => 0.9001, 'lost_to_budget' => 0.0, 'impression_share' => 0.0999];

        return $snapshot;
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
        $watchdog->postRepairImpressions = 5;
        $watchdog->postRepairClicks = 3;
        $watchdog->postRepairHours = 1;
        $verified = $watchdog->check($campaign);

        $this->assertSame('recovered', $verified['status']);
        $this->assertSame(5, $verified['verification']['impressions']);
        $this->assertCount(3, $watchdog->added);
        $this->assertEquals(10, $campaign->fresh()->daily_budget);
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'reach_recovery_verified')->count());
    }

    public static function insufficientRepairTraffic(): array
    {
        return ['zero traffic' => [0], 'limited positive traffic' => [3]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('insufficientRepairTraffic')]
    public function test_old_safe_proposal_cannot_authorize_a_repair_when_fresh_combined_reach_is_zero_or_limited(int $clicks): void
    {
        $campaign = $this->makeCampaign();
        $planner = new FakeSearchReachPlanner(fn () => $this->diagnosis());
        $planner->mutationEvidence = fn ($evidence) => array_replace_recursive($evidence, ['forecast' => ['clicks' => $clicks, 'impressions' => $clicks * 10]]);
        $watchdog = new FakeSearchReachWatchdog($planner, $this->snapshot());
        $state = $watchdog->check($campaign);

        $this->assertSame('needs_review', $state['status']);
        $this->assertSame('repair_reach_unverified', $state['blocked_reason']);
        $this->assertSame([], $watchdog->added);
        $this->assertFalse(isset($state['repair_attempts']));
        $this->assertContains($clicks === 0 ? 'search_reach_no_forecast_traffic' : 'search_reach_below_useful_threshold',
            array_column($state['repair_reach_gate']['reach_gate']['issues'], 'code'));
    }

    public function test_a_fresh_readback_forecast_failure_preserves_the_partial_repair_for_review(): void
    {
        $campaign = $this->makeCampaign();
        $planner = new FakeSearchReachPlanner(fn () => $this->diagnosis());
        $planner->mutationEvidence = fn ($evidence, $check) => $check > 1
            ? array_replace_recursive($evidence, ['forecast' => ['success' => false]]) : $evidence;
        $watchdog = new FakeSearchReachWatchdog($planner, $this->snapshot());
        $state = $watchdog->check($campaign);

        $this->assertSame('needs_review', $state['status']);
        $this->assertCount(1, $watchdog->added);
        $this->assertCount(1, $state['repair_attempts']);
        $this->assertContains('repair_reach_readback_unverified', array_column($state['repair']['errors'], 'code'));
        $watchdog->check($campaign);
        $this->assertCount(1, $watchdog->added, 'An unverified partial result cannot trigger a second repair.');
    }

    public function test_repair_retains_close_variant_historical_evidence_and_commercial_selection_reason(): void
    {
        $campaign = $this->makeCampaign();
        $planner = new FakeSearchReachPlanner(fn () => $this->diagnosis());
        $planner->mutationEvidence = function ($evidence) {
            $evidence['historical']['keywords'] = ['google ads software 0' => ['text' => 'google ads software', 'avg_monthly_searches' => 10],
                'ppc software' => ['text' => 'ppc software', 'avg_monthly_searches' => 1]];

            return $evidence;
        };
        $watchdog = new FakeSearchReachWatchdog($planner, $this->snapshot());
        $state = $watchdog->check($campaign);

        $this->assertSame('verifying', $state['status']);
        $added = $state['repair']['added_keywords'][0];
        $this->assertSame(10, $added['historical_metrics']['avg_monthly_searches']);
        $this->assertSame('google ads software', $added['historical_metrics']['text']);
        $this->assertStringContainsString('commercial buyer', $added['selection_reason']);
        $this->assertNotEmpty($added['historical_checked_at']);
        $this->assertSame(2, $planner->mutationChecks);
    }

    public function test_spending_hold_approved_during_fresh_forecast_stops_the_first_remote_write(): void
    {
        $campaign = $this->makeCampaign();
        $planner = new FakeSearchReachPlanner(fn () => $this->diagnosis());
        $planner->mutationEvidence = function ($evidence) use ($campaign) {
            $campaign->fresh()->forceFill(['spend_safety_hold' => ['reason' => 'spend_review']])->save();

            return $evidence;
        };
        $watchdog = new FakeSearchReachWatchdog($planner, $this->snapshot());
        $state = $watchdog->check($campaign);

        $this->assertSame('approval_required', $state['status']);
        $this->assertSame('spend_safety_hold', $state['blocked_reason']);
        $this->assertSame([], $watchdog->added);
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
        $snapshot['measurement']['clicks'] = 3;
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
        $snapshot['measurement']['clicks'] = 3;
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
        $snapshot['measurement']['clicks'] = 3;
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
        $snapshot['measurement']['clicks'] = 3;
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
        $snapshot['measurement']['clicks'] = 3;
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
        $watchdog->postRepairImpressions = 5;
        $watchdog->postRepairClicks = 3;
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

    public function test_sustained_current_window_rank_loss_delegates_creative_repair_before_keyword_changes_even_with_a_good_forecast(): void
    {
        $campaign = $this->makeCampaign(true);
        $snapshot = $this->rankSnapshot();
        $trial = $campaign->spend_guardrails;
        $trial['started_at'] = now()->subDays(3)->toIso8601String();
        $trial['allow_ad_rank_repairs'] = true;
        $campaign->update(['spend_guardrails' => $trial]);
        $before = $campaign->fresh()->spend_guardrails;
        $diagnosis = $this->diagnosis();
        $diagnosis['forecast']['clicks'] = 60;
        $watchdog = $this->watchdog($snapshot, $diagnosis);
        $watchdog->rankResult = ['status' => 'verifying', 'mutation_allowed' => false, 'issues' => [],
            'started_at' => now()->toIso8601String(), 'metadata' => ['phase' => 'awaiting_policy_review', 'rank_recovery_verified' => false]];
        $state = $watchdog->check($campaign);

        $this->assertSame('verifying', $state['status']);
        $this->assertSame('rank_limited', $state['ad_rank']['diagnosis']['status']);
        $this->assertSame('verified', $state['ad_rank']['diagnosis']['evidence']['status']);
        $this->assertCount(1, $watchdog->rankCalls);
        $this->assertSame([], $this->researchCalls, 'A passing keyword forecast cannot suppress actual rank loss.');
        $this->assertSame([], $watchdog->added);
        $this->assertSame($before, $campaign->fresh()->spend_guardrails);
        $this->assertEquals(10, $campaign->fresh()->daily_budget);
        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);
        Http::assertNothingSent();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unverifiedRankEvidence')]
    public function test_partial_stale_missing_or_prior_configuration_rank_evidence_cannot_delegate_a_new_repair(array $override): void
    {
        $campaign = $this->makeCampaign();
        $snapshot = $this->rankSnapshot();
        $snapshot['rank_evidence'] = $override + $snapshot['rank_evidence'];
        $diagnosis = $this->diagnosis();
        $diagnosis['issues'] = [];
        $diagnosis['forecast']['clicks'] = 60;
        $watchdog = $this->watchdog($snapshot, $diagnosis);
        $state = $watchdog->check($campaign);

        $this->assertSame('low_traffic', $state['status']);
        $this->assertNotSame('rank_limited', $state['ad_rank']['diagnosis']['status']);
        $this->assertNotSame('verified', $state['ad_rank']['diagnosis']['evidence']['status']);
        $this->assertSame([], $watchdog->rankCalls);
        $this->assertSame([], $watchdog->added);
        Notification::assertNothingSent();
    }

    public static function unverifiedRankEvidence(): array
    {
        return [[['status' => 'collecting_evidence']], [['status' => 'unavailable']], [['lost_to_rank' => null]],
            [['checked_at' => '2020-01-01T00:00:00Z']], [['from' => '2020-01-01']], [['complete_days' => 99]],
            [['account_timezone' => 'UTC']], [['clicks' => null]]];
    }

    public function test_pending_creative_repair_is_rechecked_during_its_new_measurement_window_without_claiming_rank_recovery(): void
    {
        $campaign = $this->makeCampaign();
        $snapshot = $this->rankSnapshot();
        $watchdog = $this->watchdog($snapshot, $this->diagnosis());
        $watchdog->rankResult = ['status' => 'verifying', 'mutation_allowed' => false, 'issues' => [],
            'metadata' => ['phase' => 'observing_delivery', 'rank_recovery_verified' => false]];
        $this->assertSame('verifying', $watchdog->check($campaign)['status']);
        $watchdog->snapshot['approved_ad_resources'] = ['customers/1234567890/adGroupAds/10~99'];
        $state = $watchdog->check($campaign);

        $this->assertCount(2, $watchdog->rankCalls);
        $this->assertSame('verifying', $state['status']);
        $this->assertSame(0, $state['measurement']['complete_hours']);
        $this->assertFalse($state['ad_rank']['repair']['metadata']['rank_recovery_verified']);
        $this->assertSame([], $watchdog->added);
    }

    public function test_rank_repair_hold_preserves_the_actual_service_reason(): void
    {
        $campaign = $this->makeCampaign();
        $watchdog = $this->watchdog($this->rankSnapshot(), $this->diagnosis());
        $watchdog->rankResult = ['status' => 'held', 'mutation_allowed' => false, 'issues' => [],
            'metadata' => ['reason' => 'spend_safety_hold']];
        $state = $watchdog->check($campaign);

        $this->assertSame('approval_required', $state['status']);
        $this->assertSame('spend_safety_hold', $state['blocked_reason']);
    }

    private function coolingRankAttempt(Campaign $campaign, array $overrides = []): AgentActivity
    {
        return AgentActivity::record('ad_rank', 'ad_rank_repair', 'Paused creative staging is waiting for Google read quota.',
            $campaign->customer_id, $campaign->id, array_replace(['phase' => 'staging_assets',
                'customer_id' => $campaign->customer_id, 'google_customer_id' => $campaign->customer->cleanGoogleCustomerId(),
                'campaign_resource' => $campaign->googleAdsResourceName(), 'quota_source' => 'spend',
                'next_check_at' => now()->addSeconds(900)->toIso8601String()], $overrides), 'pending');
    }

    public function test_a_scoped_durable_rank_read_cooldown_skips_inspection_research_and_mutations_without_refreshing_old_proof(): void
    {
        $this->freezeTime();
        $campaign = $this->makeCampaign();
        $campaign->update(['search_delivery_state' => ['status' => 'verifying', 'checked_at' => now()->subMinutes(20)->toIso8601String(),
            'evaluation_started_at' => now()->subDays(2)->toIso8601String(), 'config_fingerprint' => 'existing_live_fingerprint',
            'measurement' => $this->snapshot()['measurement'], 'verified_limits' => ['checked_at' => now()->subMinutes(20)->toIso8601String()],
            'other_agent' => ['keep' => true], 'ad_rank' => ['diagnosis' => ['status' => 'collecting_evidence']]]]);
        $before = $campaign->fresh()->search_delivery_state;
        $attempt = $this->coolingRankAttempt($campaign);
        $watchdog = $this->watchdog($this->snapshot(), $this->diagnosis());
        $watchdog->onInspect = fn () => throw new \LogicException('Google inspection is forbidden during the known read cooldown.');
        $watchdog->rankResult = ['status' => 'verifying', 'mutation_allowed' => true, 'issues' => [],
            'metadata' => ['attempt_id' => $attempt->id, 'reason' => 'google_api_quota_cooldown',
                'next_check_at' => $attempt->details['next_check_at'], 'actions_taken' => 0, 'phase' => 'staging_assets']];

        $state = $watchdog->check($campaign);

        $this->assertSame('verifying', $state['status']);
        $this->assertSame('google_api_quota_cooldown', $state['blocked_reason']);
        $this->assertFalse($state['mutation_allowed']);
        $this->assertSame(now()->toIso8601String(), $state['checked_at']);
        $this->assertSame($before['measurement'], $state['measurement']);
        $this->assertSame($before['evaluation_started_at'], $state['evaluation_started_at']);
        $this->assertSame($before['config_fingerprint'], $state['config_fingerprint']);
        $this->assertSame($before['verified_limits'], $state['verified_limits']);
        $this->assertSame($before['other_agent'], $state['other_agent']);
        $this->assertSame(0, $watchdog->inspectCount);
        $this->assertSame([[]], $watchdog->rankSnapshots, 'Old evidence is not supplied as fresh rank recovery proof.');
        $this->assertSame([], $watchdog->added);
        $this->assertSame([], $this->researchCalls);
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_local_owner_pause_still_blocks_a_cooling_rank_repair_before_delegation_or_any_google_read(): void
    {
        $campaign = $this->makeCampaign();
        $this->coolingRankAttempt($campaign);
        $campaign->update(['status' => CampaignStatus::Paused]);
        $watchdog = $this->watchdog($this->snapshot(), $this->diagnosis());

        $state = $watchdog->check($campaign);

        $this->assertSame('paused', $state['status']);
        $this->assertSame(0, $watchdog->inspectCount);
        $this->assertSame([], $watchdog->rankCalls);
        $this->assertSame([], $watchdog->added);
        $this->assertSame([], $this->researchCalls);
    }

    public function test_coordinator_permission_holds_during_cooldown_are_preserved_without_first_reading_google(): void
    {
        $campaign = $this->makeCampaign();
        $this->coolingRankAttempt($campaign);
        $campaign->update(['spend_safety_hold' => ['reason' => 'owner_review']]);
        $watchdog = $this->watchdog($this->snapshot(), $this->diagnosis());
        $watchdog->rankResult = ['status' => 'held', 'mutation_allowed' => false, 'issues' => [],
            'metadata' => ['reason' => 'spend_safety_hold', 'actions_taken' => 0]];

        $state = $watchdog->check($campaign);

        $this->assertSame('approval_required', $state['status']);
        $this->assertSame('spend_safety_hold', $state['blocked_reason']);
        $this->assertSame(0, $watchdog->inspectCount);
        $this->assertCount(1, $watchdog->rankCalls);
        $this->assertSame([], $watchdog->added);
        $this->assertSame([], $this->researchCalls);
        Http::assertNothingSent();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('untrustedRankCooldowns')]
    public function test_unscoped_expired_or_unproven_cooldowns_cannot_suppress_normal_delivery_inspection(array $overrides): void
    {
        $campaign = $this->makeCampaign();
        $this->coolingRankAttempt($campaign, $overrides);
        $watchdog = $this->watchdog($this->snapshot(), $this->diagnosis());

        $watchdog->check($campaign);

        $this->assertGreaterThan(0, $watchdog->inspectCount);
        Http::assertNothingSent();
    }

    public static function untrustedRankCooldowns(): array
    {
        return [[['google_customer_id' => '9999999999']], [['campaign_resource' => 'customers/1234567890/campaigns/999']],
            [['next_check_at' => '2020-01-01T00:00:00Z']], [['quota_source' => 'mutateAssets']]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('durablePendingRankPhases')]
    public function test_a_durable_pending_attempt_resumes_after_a_transient_display_hold_without_fresh_rank_days(string $displayStatus, string $durableStatus, string $phase): void
    {
        $campaign = $this->makeCampaign();
        $campaign->update(['search_delivery_state' => ['ad_rank' => ['repair' => ['status' => $displayStatus,
            'metadata' => ['reason' => 'another_rank_repair_running']]]]]);
        $attempt = AgentActivity::record('ad_rank', 'ad_rank_repair', 'Existing staged creative repair',
            $campaign->customer_id, $campaign->id, ['phase' => $phase, 'started_at' => now()->subMinutes(30)->toIso8601String()], $durableStatus);
        $snapshot = $this->snapshot();
        $snapshot['measurement']['complete_hours'] = 2;
        $snapshot['rank_evidence'] = ['status' => 'collecting_evidence'];
        $watchdog = $this->watchdog($snapshot, $this->diagnosis());
        $watchdog->rankResult = ['status' => $phase === 'preparing' ? 'needs_review' : 'verifying',
            'mutation_allowed' => false, 'issues' => [], 'metadata' => ['attempt_id' => $attempt->id, 'phase' => $phase]];
        $state = $watchdog->check($campaign);

        $this->assertCount(1, $watchdog->rankCalls);
        $this->assertSame('collecting_evidence', $watchdog->rankCalls[0]['status']);
        $this->assertSame($attempt->id, $state['ad_rank']['repair']['metadata']['attempt_id']);
        $this->assertSame($phase === 'preparing' ? 'needs_review' : 'verifying', $state['status']);
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->count());
        $this->assertSame([], $watchdog->added);
        $this->assertSame([], $this->researchCalls);
        Http::assertNothingSent();
    }

    public static function durablePendingRankPhases(): array
    {
        return [['held', 'running', 'preparing'], ['held', 'running', 'staging_assets'],
            ['unavailable', 'pending', 'awaiting_policy_review'], ['held', 'running', 'activating'],
            ['unavailable', 'pending', 'observing_delivery']];
    }

    public function test_a_superseded_completed_or_foreign_customer_attempt_cannot_resume_staging(): void
    {
        $campaign = $this->makeCampaign();
        AgentActivity::record('ad_rank', 'ad_rank_repair', 'Older pending attempt', $campaign->customer_id, $campaign->id,
            ['phase' => 'staging_assets'], 'running');
        AgentActivity::record('ad_rank', 'ad_rank_repair', 'Completed outcome', $campaign->customer_id, $campaign->id,
            ['phase' => 'completed'], 'completed');
        AgentActivity::record('ad_rank', 'ad_rank_repair', 'Foreign customer record', Customer::factory()->create()->id, $campaign->id,
            ['phase' => 'staging_assets'], 'running');
        $snapshot = $this->snapshot();
        $snapshot['measurement']['complete_hours'] = 2;
        $snapshot['rank_evidence'] = ['status' => 'collecting_evidence'];
        $watchdog = $this->watchdog($snapshot, $this->diagnosis());
        $watchdog->check($campaign);

        $this->assertSame([], $watchdog->rankCalls);
        $this->assertSame([], $watchdog->added);
    }

    public function test_thirteen_impressions_without_clicks_are_low_traffic_even_when_forecasts_pass(): void
    {
        $campaign = $this->makeCampaign();
        $snapshot = $this->snapshot();
        $snapshot['measurement']['impressions'] = 13;
        $snapshot['rank_evidence'] = ['status' => 'collecting_evidence'];
        $diagnosis = $this->diagnosis();
        $diagnosis['issues'] = [];
        $diagnosis['forecast']['clicks'] = 60;
        $watchdog = $this->watchdog($snapshot, $diagnosis);
        $state = $watchdog->check($campaign);

        $this->assertSame('low_traffic', $state['status']);
        $this->assertSame('insufficient_meaningful_traffic', $state['blocked_reason']);
        $this->assertSame([], $watchdog->rankCalls);
        $this->assertSame([], $watchdog->added);
    }

    public function test_keyword_repair_cannot_be_verified_by_a_few_impressions_without_meaningful_clicks(): void
    {
        $campaign = $this->makeCampaign();
        $watchdog = $this->watchdog($this->snapshot(), $this->diagnosis());
        $this->assertSame('verifying', $watchdog->check($campaign)['status']);
        $watchdog->postRepairImpressions = 20;
        $watchdog->postRepairHours = 24;
        $state = $watchdog->check($campaign);

        $this->assertSame('needs_review', $state['status']);
        $this->assertSame('repair_did_not_restore_search_traffic', $state['blocked_reason']);
        $this->assertSame(0, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'reach_recovery_verified')->count());
    }
}

class FakeSearchReachPlanner extends GoogleSearchReachPlanner
{
    public ?\Closure $mutationEvidence = null;

    public int $mutationChecks = 0;

    public function __construct(private \Closure $assessment) {}

    public function assess(Campaign $campaign, array $snapshot, bool $research = false): array
    {
        return ($this->assessment)($campaign, $snapshot, $research);
    }

    public function assessMutation(Campaign $campaign, array $snapshot, array $keywords): array
    {
        $this->mutationChecks++;
        $period = 30;
        $start = now($snapshot['time_zone'])->startOfDay()->addDay();
        $evidence = ['status' => 'evidence_ready', 'issues' => [], 'historical' => ['success' => true,
            'checked_at' => now()->toIso8601String(), 'keywords' => array_map(fn ($keyword) => ['text' => $keyword['text'], 'avg_monthly_searches' => 1], $keywords)],
            'forecast' => ['success' => true, 'auto_repair_safe' => true, 'source' => 'google_keyword_forecast',
                'checked_at' => now()->toIso8601String(), 'caveats' => [], 'period_days' => $period,
                'forecast_period' => ['from' => $start->toDateString(), 'through' => $start->copy()->addDays($period - 1)->toDateString()],
                'clicks' => 60, 'impressions' => 300, 'cost_micros' => 10_000_000,
                'context' => ['bidding_strategy' => $snapshot['bidding_strategy'], 'cpc_bid_ceiling_micros' => $snapshot['actual_cpc_ceiling_micros'],
                    'daily_budget_micros' => $snapshot['daily_budget_micros'], 'currency_code' => $snapshot['currency_code'],
                    'time_zone' => $snapshot['time_zone'], 'keywords' => $keywords, 'geo_target_constants' => $snapshot['geo_target_constants'],
                    'language_constants' => $snapshot['language_constants'], 'negative_keywords' => $snapshot['negative_keywords']]]];

        return $this->mutationEvidence ? ($this->mutationEvidence)($evidence, $this->mutationChecks) : $evidence;
    }
}

/** Scripted provider behavior; no Google request or synthetic conversion is sent. */
class FakeSearchReachWatchdog extends GoogleSearchReachRecovery
{
    public array $added = [];

    public int $postRepairImpressions = 0;

    public int $postRepairHours = 0;

    public int $postRepairClicks = 0;

    public array $rankCalls = [];

    public array $rankSnapshots = [];

    public array $rankResult = ['status' => 'held', 'mutation_allowed' => false, 'issues' => [], 'metadata' => []];

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
            $snapshot['measurement']['clicks'] = $this->postRepairClicks;
            $snapshot['measurement']['started_at'] = $since->format(DATE_ATOM);
            $snapshot['measurement']['from'] = $since->format(DATE_ATOM);
        }

        return $snapshot;
    }

    protected function repairAdRank(Campaign $campaign, array $snapshot, array $rankDiagnosis): array
    {
        $this->rankCalls[] = $rankDiagnosis;
        $this->rankSnapshots[] = $snapshot;

        return $this->rankResult;
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
