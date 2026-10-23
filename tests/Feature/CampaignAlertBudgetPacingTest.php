<?php

namespace Tests\Feature;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\CampaignHourlyPerformance;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\CriticalAgentAlert;
use App\Services\Agents\CampaignAlertService;
use App\Services\Campaigns\GoogleBudgetPacingHistory;
use App\Services\GoogleAds\CommonServices\GetCampaignBudgetPacing;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus as GoogleStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CampaignAlertBudgetPacingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-13 06:49:00', 'Australia/Sydney')->setTimezone('UTC'));
        Cache::flush();
        Notification::fake();
        Http::fake();
    }

    private function fixture(array $attributes = [], array $evidence = [], bool $fails = false): array
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '3141592653',
            'timezone' => 'UTC', 'currency_code' => 'USD']);
        $user = User::factory()->create();
        $customer->users()->attach($user->id);
        $campaign = Campaign::factory()->create($attributes + ['customer_id' => $customer->id,
            'status' => 'active', 'primary_status' => 'LEARNING', 'google_ads_campaign_id' => '456',
            'daily_budget' => '10.00', 'approved_daily_budget' => '10.00',
            'last_budget_changed_at' => CarbonImmutable::parse('2026-10-09 13:00:00', 'Australia/Sydney')->setTimezone('UTC'),
            'end_date' => '2026-10-26']);
        if ($campaign->last_budget_changed_at) {
            AgentActivity::record('budget_pacing', GoogleBudgetPacingHistory::ACTION, 'Confirmed test budget',
                $customer->id, $campaign->id, [
                    'campaign_resource' => $campaign->googleAdsResourceName(),
                    'budget_resource' => 'customers/3141592653/campaignBudgets/789',
                    'daily_budget_micros' => 10_000_000, 'currency_code' => 'AUD',
                    'account_timezone' => 'Australia/Sydney',
                    'confirmed_at' => $campaign->last_budget_changed_at->toIso8601String(),
                    'source' => 'google_budget_mutation_readback',
                ]);
        }
        $reader = new HourlyAlertPacingReader($evidence, $fails);
        $this->app->bind(GetCampaignBudgetPacing::class, fn () => $reader);

        return [$campaign->load('customer'), $reader, $user];
    }

    private function hourly(Campaign $campaign, string $date, string $spend, int $conversions = 0): void
    {
        CampaignHourlyPerformance::create(['campaign_id' => $campaign->id, 'customer_id' => $campaign->customer_id,
            'date' => $date, 'hour' => 0, 'day_of_week' => 1, 'platform' => 'google_ads',
            'impressions' => 100, 'clicks' => 10, 'conversions' => $conversions, 'spend' => $spend]);
    }

    public function test_incomplete_hourly_spend_and_old_spend_cannot_create_exhaustion_or_projected_spend_alerts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13 09:00:00', 'UTC'));
        [$campaign, $reader] = $this->fixture();
        foreach (range(1, 7) as $days) {
            $this->hourly($campaign, now()->subDays($days)->toDateString(), '1.00');
        }
        $this->hourly($campaign, now()->toDateString(), '999.00');

        $this->assertSame([], app(CampaignAlertService::class)->checkAlerts($campaign));
        $this->assertSame(1, $reader->calls);
        Notification::assertNothingSent();
        $this->assertSame(0, AgentActivity::where('campaign_id', $campaign->id)->where('agent_type', 'alert')->count());
        Http::assertNothingSent();
    }

    public function test_verified_excessive_pace_sends_one_currency_and_account_window_review_without_changing_limits(): void
    {
        [$campaign, $reader, $user] = $this->fixture(evidence: ['cost_micros' => 50_000_000]);
        $before = $campaign->only(['daily_budget', 'approved_daily_budget', 'status', 'spend_guardrails', 'spend_safety_hold']);
        $service = app(CampaignAlertService::class);
        $alerts = $service->checkAlerts($campaign);

        $this->assertCount(1, $alerts);
        $this->assertSame('budget_overpacing', $alerts[0]['type']);
        $this->assertSame('AUD', $alerts[0]['currency_code']);
        $this->assertSame('2026-10-10', $alerts[0]['window_from']);
        $this->assertSame('2026-10-12', $alerts[0]['window_through']);
        $this->assertSame(3, $alerts[0]['pacing']['complete_days']);
        $this->assertSame(50_000_000, $alerts[0]['pacing']['actual_spend_micros']);
        $this->assertSame(30_000_000, $alerts[0]['pacing']['expected_spend_micros']);
        $this->assertSame([], $service->checkAlerts($campaign), 'The hourly path must not issue both anomaly and exhaustion alerts or repeat its pacing alert.');
        $this->assertSame(2, $reader->calls, 'Evidence is freshly assessed even while notification delivery is on cooldown.');
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'budget_overpacing')->count());
        $this->assertSame($before, $campaign->fresh()->only(array_keys($before)));
        $alert = Notification::sent($user, CriticalAgentAlert::class)->sole();
        $this->assertSame('budget_overpacing', $alert->alertType);
        $mail = $alert->toMail($user);
        $body = implode(' ', [...$mail->introLines, ...$mail->outroLines]);
        $this->assertStringContainsString('AUD 50.00', $body);
        $this->assertStringContainsString('AUD 30.00', $body);
        $this->assertStringContainsString('2026-10-10 to 2026-10-12, Australia/Sydney', $body);
        $this->assertStringContainsString('not a billing limit or a month-end forecast', $body);
        $this->assertStringNotContainsString('daily budget cap', $body);
        $this->assertStringNotContainsString('run out', $body);
        $this->assertStringNotContainsString('projected to', $body);
        $this->assertStringNotContainsString('increasing', $body);
        $this->assertArrayNotHasKey('projected_spend', $alert->details);
        $this->assertArrayNotHasKey('today_spend', $alert->details);
        $this->assertSame(route('campaigns.show', $campaign), $mail->actionUrl);
        Http::assertNothingSent();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('heldSpendEvidence')]
    public function test_unknown_paused_incomplete_or_current_reduction_evidence_holds_hourly_spend_alerts(array $attributes, array $evidence, bool $fails): void
    {
        [$campaign] = $this->fixture($attributes, $evidence + ['cost_micros' => 999_000_000], $fails);
        $this->hourly($campaign, now()->toDateString(), '999.00');

        $this->assertSame([], app(CampaignAlertService::class)->checkAlerts($campaign));
        Notification::assertNothingSent();
        $this->assertSame(0, AgentActivity::where('campaign_id', $campaign->id)->where('agent_type', 'alert')->count());
        Http::assertNothingSent();
    }

    public static function heldSpendEvidence(): array
    {
        return [
            'api unavailable' => [[], [], true],
            'local pause' => [['status' => 'paused'], [], false],
            'google pause' => [[], ['status' => GoogleStatus::PAUSED], false],
            'unknown budget history' => [['last_budget_changed_at' => null], [], false],
            'budget reduced today' => [['last_budget_changed_at' => '2026-10-12T19:30:00Z'], [], false],
            'only two complete days' => [['last_budget_changed_at' => '2026-10-10T02:00:00Z'], [], false],
            'wrong live budget' => [[], ['daily_budget_micros' => 37_500_000], false],
            'missing complete spend' => [[], ['cost_micros' => null], false],
            'stale complete spend' => [[], ['checked_at' => '2026-10-10T00:00:00Z'], false],
            'incomplete current day' => [[], ['through' => '2026-10-13'], false],
        ];
    }

    public function test_failed_evidence_does_not_consume_the_verified_pacing_alert_cooldown(): void
    {
        [$campaign, $reader, $user] = $this->fixture(evidence: ['cost_micros' => 50_000_000], fails: true);
        $service = app(CampaignAlertService::class);
        $this->assertSame([], $service->checkAlerts($campaign));
        Notification::assertNothingSent();
        $reader->fails = false;
        $this->assertCount(1, $service->checkAlerts($campaign));
        $this->assertSame(1, Notification::sent($user, CriticalAgentAlert::class)->count());
    }

    public function test_conversion_drop_notifications_are_preserved_when_spend_evidence_is_unknown(): void
    {
        [$campaign, , $user] = $this->fixture(fails: true);
        $this->hourly($campaign, now()->subDays(5)->toDateString(), '1.00', 10);

        $alerts = app(CampaignAlertService::class)->checkAlerts($campaign);

        $this->assertSame(['conversion_drop'], array_column($alerts, 'type'));
        $this->assertSame(100.0, $alerts[0]['drop_percent']);
        Notification::assertSentTo($user, CriticalAgentAlert::class, fn ($alert) => $alert->alertType === 'conversion_drop');
        Http::assertNothingSent();
    }
}

class HourlyAlertPacingReader extends GetCampaignBudgetPacing
{
    public int $calls = 0;

    public function __construct(public array $evidence, public bool $fails) {}

    public function forCampaign(Campaign $campaign, string $effectiveAt, bool $trial = false): array
    {
        $this->calls++;
        if ($this->fails) {
            throw new \RuntimeException('Google pacing read unavailable.');
        }
        $effective = CarbonImmutable::parse($effectiveAt)->setTimezone('Australia/Sydney');
        $from = ($effective->isStartOfDay() ? $effective : $effective->addDay())->toDateString();

        return $this->evidence + ['status' => GoogleStatus::ENABLED, 'daily_budget_micros' => 10_000_000,
            'campaign_resource' => $campaign->googleAdsResourceName(), 'budget_resource' => 'customers/3141592653/campaignBudgets/789',
            'currency_code' => 'AUD', 'account_timezone' => 'Australia/Sydney', 'from' => $from,
            'through' => '2026-10-12', 'checked_at' => now()->toIso8601String(), 'source' => 'google_ads_api',
            'cost_micros' => 30_000_000];
    }
}
