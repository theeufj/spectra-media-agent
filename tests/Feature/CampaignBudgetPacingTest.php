<?php

namespace Tests\Feature;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Models\GoogleAdsPerformanceData;
use App\Models\User;
use App\Notifications\CriticalAgentAlert;
use App\Services\Agents\HealthCheckAgent;
use App\Services\Campaigns\GoogleBudgetPacingHistory;
use App\Services\GoogleAds\CommonServices\GetCampaignBudgetPacing;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBudget;
use App\Services\Health\CampaignBudgetPacing;
use App\Services\Health\CampaignHealthChecker;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus as GoogleStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CampaignBudgetPacingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-13 06:49:00', 'Australia/Sydney')->setTimezone('UTC'));
        Notification::fake();
    }

    private function fixture(array $attributes = [], array $evidence = [], bool $confirmed = true): array
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '3141592653',
            'timezone' => 'UTC', 'currency_code' => 'USD']);
        $user = User::factory()->create();
        $customer->users()->attach($user->id);
        $campaign = Campaign::factory()->create($attributes + ['customer_id' => $customer->id, 'status' => 'active',
            'primary_status' => 'LEARNING', 'google_ads_campaign_id' => '456', 'daily_budget' => '10.00',
            'approved_daily_budget' => '10.00', 'last_budget_changed_at' => CarbonImmutable::parse('2026-10-09 13:00:00', 'Australia/Sydney')->setTimezone('UTC'),
            'end_date' => '2026-10-26']);
        if ($confirmed) {
            $this->proof($campaign, $campaign->last_budget_changed_at?->toIso8601String() ?? now()->subDays(4)->toIso8601String());
        }
        $reader = new class($evidence) extends GetCampaignBudgetPacing
        {
            public array $calls = [];

            public bool $fails = false;

            public function __construct(public array $evidence) {}

            public function forCampaign(Campaign $campaign, string $effectiveAt, bool $trial = false): array
            {
                $this->calls[] = [$campaign->id, $effectiveAt, $trial];
                if ($this->fails) {
                    throw new \RuntimeException('Google request failed.');
                }
                $effective = CarbonImmutable::parse($effectiveAt)->setTimezone('Australia/Sydney');
                $from = ($effective->isStartOfDay() ? $effective : $effective->addDay())->toDateString();

                return $this->evidence + ['status' => GoogleStatus::ENABLED, 'daily_budget_micros' => 10_000_000,
                    'campaign_resource' => $campaign->googleAdsResourceName(), 'budget_resource' => 'customers/3141592653/campaignBudgets/789',
                    'currency_code' => 'AUD', 'account_timezone' => 'Australia/Sydney', 'from' => $from,
                    'through' => now('Australia/Sydney')->subDay()->toDateString(), 'checked_at' => now()->toIso8601String(), 'source' => 'google_ads_api',
                    'cost_micros' => 30_000_000, 'reported_lifetime_cost_micros' => 184_450_000];
            }
        };
        $this->app->bind(GetCampaignBudgetPacing::class, fn () => $reader);

        return [$campaign->load('customer'), $reader, $user];
    }

    private function budgetSnapshot(Campaign $campaign, int $micros = 10_000_000): array
    {
        return ['campaign_resource' => $campaign->googleAdsResourceName(), 'budget_resource' => 'customers/3141592653/campaignBudgets/789',
            'daily_budget_micros' => $micros, 'currency_code' => 'AUD', 'account_timezone' => 'Australia/Sydney'];
    }

    private function proof(Campaign $campaign, string $confirmedAt, int $micros = 10_000_000): void
    {
        AgentActivity::record('budget_pacing', GoogleBudgetPacingHistory::ACTION, 'Confirmed test readback',
            $campaign->customer_id, $campaign->id, $this->budgetSnapshot($campaign, $micros) + [
                'confirmed_at' => $confirmedAt, 'source' => 'google_budget_mutation_readback',
            ]);
    }

    private function trial(): array
    {
        return ['enabled' => true, 'started_at' => '2026-10-10T13:00:00+11:00', 'baseline_cost_micros' => 184_450_000,
            'max_spend_micros' => 50_000_000, 'max_daily_budget_micros' => 10_000_000, 'max_cpc_bid_micros' => 3_000_000,
            'currency_code' => 'AUD', 'account_timezone' => 'Australia/Sydney', 'approval' => 'Approved test'];
    }

    private function healthPacing(Campaign $campaign): array
    {
        return (new \ReflectionMethod(CampaignHealthChecker::class, 'checkBudgetPacing'))->invoke(app(CampaignHealthChecker::class), $campaign);
    }

    public function test_old_monthly_and_lifetime_spend_are_excluded_from_the_new_trial(): void
    {
        [$campaign, $reader] = $this->fixture(['spend_guardrails' => $this->trial()], ['cost_micros' => 0]);
        GoogleAdsPerformanceData::create(['campaign_id' => $campaign->id, 'date' => '2026-10-01', 'cost' => '184.45',
            'impressions' => 100, 'clicks' => 10, 'conversions' => 0]);
        $before = $campaign->fresh()->spend_guardrails;
        $pacing = app(CampaignBudgetPacing::class)->assess($campaign);
        $this->assertSame('bounded_trial', $pacing['basis']);
        $this->assertSame('collecting_evidence', $pacing['status']);
        $this->assertSame(2, $pacing['complete_days']);
        $this->assertSame(0, $pacing['trial_reported_spend_micros']);
        $this->assertSame(50_000_000, $pacing['trial_remaining_reported_spend_micros']);
        $this->assertNull($pacing['pacing_ratio']);
        $this->assertSame('AUD', $pacing['currency_code'], 'The Google account currency overrides the stale customer default.');
        $this->assertSame([], $this->healthPacing($campaign)['warnings']);
        app(HealthCheckAgent::class)->checkMonthlyPacing($campaign);
        Notification::assertNothingSent();
        $this->assertTrue($reader->calls[0][2]);
        $this->assertSame($before, $campaign->fresh()->spend_guardrails, 'Pacing cannot change the approved baseline or limits.');
        $this->assertNull($campaign->fresh()->spend_safety_hold);
    }

    public function test_budget_reduction_compares_only_the_new_complete_day_window_and_has_no_month_end_projection(): void
    {
        [$campaign] = $this->fixture();
        GoogleAdsPerformanceData::create(['campaign_id' => $campaign->id, 'date' => '2026-10-01', 'cost' => '184.45',
            'impressions' => 100, 'clicks' => 10, 'conversions' => 0]);
        $pacing = app(CampaignBudgetPacing::class)->assess($campaign);
        $this->assertSame('ready', $pacing['status']);
        $this->assertSame('2026-10-10', $pacing['from']);
        $this->assertSame('2026-10-12', $pacing['through']);
        $this->assertSame(3, $pacing['complete_days'], 'Complete days follow Sydney, even though it is still October 12 UTC.');
        $this->assertSame(30_000_000, $pacing['actual_spend_micros']);
        $this->assertSame(30_000_000, $pacing['expected_spend_micros']);
        $this->assertEquals(1, $pacing['pacing_ratio']);
        $this->assertSame([], $this->healthPacing($campaign)['warnings']);
        app(HealthCheckAgent::class)->checkMonthlyPacing($campaign);
        Notification::assertNothingSent();
    }

    public function test_real_current_window_overpacing_still_reaches_health_and_customer_alerts_with_currency_and_dates(): void
    {
        [$campaign, , $user] = $this->fixture(evidence: ['cost_micros' => 50_000_000]);
        $health = $this->healthPacing($campaign);
        $this->assertSame('overspending', $health['warnings'][0]['type']);
        $this->assertSame(50_000_000, $health['warnings'][0]['pacing']['actual_spend_micros']);
        app(HealthCheckAgent::class)->checkMonthlyPacing($campaign);
        Notification::assertSentTo($user, CriticalAgentAlert::class, function ($notification) {
            $this->assertSame('budget_overpacing', $notification->alertType);
            $this->assertStringContainsString('AUD 50.00', $notification->message);
            $this->assertStringContainsString('2026-10-10 to 2026-10-12, Australia/Sydney', $notification->message);
            $this->assertStringNotContainsString('will exhaust', $notification->message);
            $this->assertArrayNotHasKey('projected_spend', $notification->details);
            $this->assertArrayNotHasKey('monthly_budget', $notification->details);

            return true;
        });
    }

    public function test_a_verified_zero_is_underpacing_but_an_api_failure_is_unknown_and_never_a_zero(): void
    {
        [$campaign, $reader, $user] = $this->fixture(evidence: ['cost_micros' => 0]);
        $pacing = app(CampaignBudgetPacing::class)->assess($campaign);
        $this->assertSame('ready', $pacing['status']);
        $this->assertSame(0, $pacing['actual_spend_micros']);
        app(HealthCheckAgent::class)->checkMonthlyPacing($campaign);
        Notification::assertSentTo($user, CriticalAgentAlert::class, fn ($alert) => $alert->alertType === 'budget_underpacing');
        Notification::fake();
        $reader->fails = true;
        $pacing = app(CampaignBudgetPacing::class)->assess($campaign);
        $this->assertSame('unavailable', $pacing['status']);
        $this->assertNull($pacing['actual_spend_micros']);
        $this->assertSame([], $this->healthPacing($campaign)['warnings']);
        app(HealthCheckAgent::class)->checkMonthlyPacing($campaign);
        Notification::assertNothingSent();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unverifiedEvidence')]
    public function test_incomplete_stale_or_mismatched_evidence_cannot_issue_spend_pacing_warnings(array $evidence): void
    {
        [$campaign] = $this->fixture(evidence: $evidence);
        $pacing = app(CampaignBudgetPacing::class)->assess($campaign);
        $this->assertSame('unavailable', $pacing['status']);
        $this->assertNull($pacing['actual_spend_micros']);
        $this->assertSame([], $this->healthPacing($campaign)['warnings']);
        app(HealthCheckAgent::class)->checkMonthlyPacing($campaign);
        Notification::assertNothingSent();
    }

    public static function unverifiedEvidence(): array
    {
        return [['evidence' => ['cost_micros' => null]], ['evidence' => ['checked_at' => null]],
            ['evidence' => ['checked_at' => '2026-10-10T00:00:00Z']], ['evidence' => ['through' => '2026-10-13']],
            ['evidence' => ['daily_budget_micros' => 37_500_000]], ['evidence' => ['account_timezone' => 'unknown']]];
    }

    public function test_budget_timestamps_record_initial_budget_and_real_edits_but_not_generic_health_updates(): void
    {
        $campaign = Campaign::factory()->create(['daily_budget' => '37.50']);
        $this->assertTrue($campaign->last_budget_changed_at->equalTo(now()));
        $this->travel(1)->days();
        $campaign->update(['daily_budget' => '10.00']);
        $changed = $campaign->fresh()->last_budget_changed_at;
        $this->assertTrue($changed->equalTo(now()));
        $this->travel(1)->days();
        $campaign->update(['last_checked_at' => now()]);
        $this->assertTrue($campaign->fresh()->last_budget_changed_at->equalTo($changed));
        $explicit = now()->subDays(10);
        $campaign->update(['daily_budget' => '20.00', 'last_budget_changed_at' => $explicit]);
        $this->assertTrue($campaign->fresh()->last_budget_changed_at->equalTo($explicit));
    }

    public function test_legacy_unknown_budget_history_does_not_infer_a_pacing_window_from_lifetime_spend_or_health_updates(): void
    {
        [$campaign, $reader] = $this->fixture(['last_budget_changed_at' => null, 'budget_confirmed_at' => now()->subDays(20)], confirmed: false);
        $campaign->update(['last_checked_at' => now()]);
        $pacing = app(CampaignBudgetPacing::class)->assess($campaign);
        $this->assertSame('platform_budget_effective_date_unverified', $pacing['reason']);
        $this->assertSame('unavailable', $pacing['status']);
        $this->assertSame([], $reader->calls);
        app(HealthCheckAgent::class)->checkMonthlyPacing($campaign);
        Notification::assertNothingSent();
    }

    public function test_a_trial_report_below_its_immutable_baseline_is_unknown_not_zero(): void
    {
        [$campaign] = $this->fixture(['spend_guardrails' => $this->trial()], ['reported_lifetime_cost_micros' => 100_000_000]);
        $pacing = app(CampaignBudgetPacing::class)->assess($campaign);
        $this->assertSame('trial_reported_spend_unverified', $pacing['reason']);
        $this->assertSame('unavailable', $pacing['status']);
        $this->assertArrayNotHasKey('trial_reported_spend_micros', $pacing);
        $this->assertNull($pacing['actual_spend_micros']);
    }

    public function test_missing_stored_platform_days_are_unknown_and_explicit_zero_days_can_be_compared(): void
    {
        [$campaign] = $this->fixture(['google_ads_campaign_id' => null, 'facebook_ads_campaign_id' => 'fb-1']);
        $campaign->customer->update(['timezone' => 'Australia/Sydney', 'currency_code' => 'AUD']);
        FacebookAdsPerformanceData::create(['campaign_id' => $campaign->id, 'facebook_campaign_id' => 'fb-1', 'date' => '2026-10-10', 'cost' => '0.00']);
        $this->assertSame('unavailable', app(CampaignBudgetPacing::class)->assess($campaign)['status']);
        foreach (['2026-10-11', '2026-10-12'] as $date) {
            FacebookAdsPerformanceData::create(['campaign_id' => $campaign->id, 'facebook_campaign_id' => 'fb-1', 'date' => $date, 'cost' => '0.00']);
        }
        $pacing = app(CampaignBudgetPacing::class)->assess($campaign);
        $this->assertSame('ready', $pacing['status']);
        $this->assertSame(0, $pacing['actual_spend_micros']);
        FacebookAdsPerformanceData::where('campaign_id', $campaign->id)->whereDate('date', '2026-10-12')
            ->update(['updated_at' => now()->subDays(3)]);
        $this->assertSame('unavailable', app(CampaignBudgetPacing::class)->assess($campaign)['status']);
    }

    public function test_saving_a_desired_budget_without_google_confirmation_cannot_authorize_a_pacing_alert(): void
    {
        [$campaign, $reader] = $this->fixture(confirmed: false);
        $pacing = app(CampaignBudgetPacing::class)->assess($campaign);
        $this->assertSame('platform_budget_effective_date_unverified', $pacing['reason']);
        $this->assertSame('unavailable', $pacing['status']);
        $this->assertSame([], $reader->calls);
        app(HealthCheckAgent::class)->checkMonthlyPacing($campaign);
        Notification::assertNothingSent();
    }

    public function test_delayed_retry_confirmation_starts_a_later_window_and_never_reuses_the_desired_edit_date(): void
    {
        [$campaign, $reader] = $this->fixture(confirmed: false);
        $this->proof($campaign, now()->subDays(6)->toIso8601String(), 37_500_000);
        $service = new FakePacingBudgetMutation($campaign->customer, [$this->budgetSnapshot($campaign, 37_500_000)], false);
        $this->assertFalse($service('3141592653', $campaign->googleAdsResourceName(), 10_000_000));
        $this->assertSame('unavailable', app(CampaignBudgetPacing::class)->assess($campaign)['status']);
        $service = new FakePacingBudgetMutation($campaign->customer, [$this->budgetSnapshot($campaign, 37_500_000), $this->budgetSnapshot($campaign)]);
        $this->assertTrue($service('3141592653', $campaign->googleAdsResourceName(), 10_000_000));
        $pacing = app(CampaignBudgetPacing::class)->assess($campaign);
        $this->assertSame('collecting_evidence', $pacing['status']);
        $this->assertSame(0, $pacing['complete_days']);
        $this->assertSame('2026-10-14', $pacing['from']);
        $this->assertTrue(CarbonImmutable::parse($reader->calls[0][1])->equalTo(now()));
        $this->travel(4)->days();
        $pacing = app(CampaignBudgetPacing::class)->assess($campaign);
        $this->assertSame('ready', $pacing['status']);
        $this->assertSame('2026-10-14', $pacing['from']);
        $this->assertSame(3, $pacing['complete_days']);
        $this->assertSame(30_000_000, $pacing['expected_spend_micros']);
    }

    public function test_reasserting_the_same_confirmed_budget_does_not_restart_its_window(): void
    {
        [$campaign] = $this->fixture();
        $before = app(GoogleBudgetPacingHistory::class)->latest($campaign);
        $count = AgentActivity::where('campaign_id', $campaign->id)->where('action', GoogleBudgetPacingHistory::ACTION)->count();
        $service = new FakePacingBudgetMutation($campaign->customer, [$this->budgetSnapshot($campaign), $this->budgetSnapshot($campaign)]);
        $this->assertTrue($service('3141592653', $campaign->googleAdsResourceName(), 10_000_000));
        $this->assertSame($before, app(GoogleBudgetPacingHistory::class)->latest($campaign));
        $this->assertSame($count, AgentActivity::where('campaign_id', $campaign->id)->where('action', GoogleBudgetPacingHistory::ACTION)->count());
        $this->assertSame('ready', app(CampaignBudgetPacing::class)->assess($campaign)['status']);
    }

    public function test_a_newer_desired_edit_needs_new_confirmation_even_when_its_amount_returns_to_the_previous_budget(): void
    {
        [$campaign] = $this->fixture();
        $old = app(GoogleBudgetPacingHistory::class)->latest($campaign);
        $campaign->update(['daily_budget' => '9.00']);
        $campaign->update(['daily_budget' => '10.00']);
        $this->assertSame('unavailable', app(CampaignBudgetPacing::class)->assess($campaign)['status']);
        $service = new FakePacingBudgetMutation($campaign->customer, [$this->budgetSnapshot($campaign), $this->budgetSnapshot($campaign)]);
        $this->assertTrue($service('3141592653', $campaign->googleAdsResourceName(), 10_000_000));
        $new = app(GoogleBudgetPacingHistory::class)->latest($campaign);
        $this->assertNotSame($old['confirmed_at'], $new['confirmed_at']);
        $this->assertTrue(CarbonImmutable::parse($new['confirmed_at'])->equalTo(now()));
        $this->assertSame('collecting_evidence', app(CampaignBudgetPacing::class)->assess($campaign)['status']);
    }

    public function test_unverified_readback_or_dry_run_cannot_create_budget_timing_proof(): void
    {
        [$campaign] = $this->fixture(confirmed: false);
        foreach ([null, $this->budgetSnapshot($campaign, 37_500_000), $this->budgetSnapshot($campaign) + ['unused' => true]] as $index => $readback) {
            if ($index === 2) {
                $readback['budget_resource'] = 'customers/3141592653/campaignBudgets/999';
            }
            $service = new FakePacingBudgetMutation($campaign->customer, [$this->budgetSnapshot($campaign), $readback]);
            $this->assertFalse($service('3141592653', $campaign->googleAdsResourceName(), 10_000_000));
            $this->assertNull(app(GoogleBudgetPacingHistory::class)->latest($campaign));
        }
        $dry = (new FakePacingBudgetMutation($campaign->customer, [$this->budgetSnapshot($campaign)]))->dryRun();
        $this->assertTrue($dry('3141592653', $campaign->googleAdsResourceName(), 10_000_000));
        $this->assertNull(app(GoogleBudgetPacingHistory::class)->latest($campaign));
    }

    public function test_current_resource_currency_and_timezone_must_match_the_confirmed_budget_proof(): void
    {
        [$campaign, $reader] = $this->fixture();
        foreach ([['budget_resource' => 'customers/3141592653/campaignBudgets/999'], ['currency_code' => 'USD'], ['account_timezone' => 'UTC'],
            ['campaign_resource' => 'customers/3141592653/campaigns/999']] as $mismatch) {
            $reader->evidence = $mismatch;
            $pacing = app(CampaignBudgetPacing::class)->assess($campaign);
            $this->assertSame('unavailable', $pacing['status']);
            $this->assertSame('confirmed_platform_budget_context_changed', $pacing['reason']);
        }
    }

    public function test_original_trial_baseline_can_establish_its_window_but_a_later_unverified_desired_edit_cannot(): void
    {
        [$campaign] = $this->fixture(['spend_guardrails' => $this->trial()], ['cost_micros' => 0], confirmed: false);
        $pacing = app(CampaignBudgetPacing::class)->assess($campaign);
        $this->assertSame('collecting_evidence', $pacing['status']);
        $this->assertSame('confirmed_bounded_trial_baseline', $pacing['budget_effective_source']);
        $this->assertSame(0, $pacing['trial_reported_spend_micros']);
        $campaign->update(['daily_budget' => '9.00']);
        $pacing = app(CampaignBudgetPacing::class)->assess($campaign);
        $this->assertSame('unavailable', $pacing['status']);
        $this->assertSame('platform_budget_effective_date_unverified', $pacing['reason']);
        $this->assertSame('independent_spend_safety_monitor', $pacing['trial_spend_source']);
        $this->assertNull($pacing['actual_spend_micros']);
    }
}

/** Mock only the Google transport, retaining real mutation/readback/proof behavior. */
class FakePacingBudgetMutation extends UpdateCampaignBudget
{
    public function __construct(Customer $customer, private array $snapshots, private bool $accepted = true)
    {
        $this->customer = $customer;
    }

    protected function ensureClient(): void {}

    protected function readBudget(string $customerId, string $campaignResourceName): ?array
    {
        return array_shift($this->snapshots);
    }

    protected function writeBudget(string $customerId, string $budgetResourceName, int $micros): bool
    {
        return $this->accepted;
    }
}
