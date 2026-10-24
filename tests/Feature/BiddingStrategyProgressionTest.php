<?php

namespace Tests\Feature;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\BiddingStrategyProgressionAgent;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBiddingStrategy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BiddingStrategyProgressionTest extends TestCase
{
    use DatabaseTransactions;

    private function fixture(string $current = 'MANUAL_CPC', array $attributes = []): array
    {
        $customer = Customer::where('google_ads_customer_id', '1234567890')->first() ?? Customer::factory()->create(['google_ads_customer_id' => '1234567890', 'service_type' => 'managed', 'is_sandbox' => false]);
        $campaign = Campaign::factory()->create($attributes + ['customer_id' => $customer->id, 'status' => 'active',
            'google_ads_campaign_id' => '456', 'daily_budget' => 50, 'approved_daily_budget' => 50, 'budget_confirmed_at' => now()]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'signed_off_at' => now(), 'deployment_status' => 'active', 'bidding_strategy' => ['name' => 'EnhancedCpc',
                'bid_strategy' => 'MANUAL_CPC', 'target_roas' => 9, 'parameters' => ['cpcBidCeilingMicros' => 4_000_000, 'targetRoas' => 9]]]);
        $strategy->forceFill(['google_ads_campaign_id' => '456'])->save();
        $writer = new ProgressionRecordingWriter;
        $writer->state = ['resource' => $campaign->googleAdsResourceName(), 'status' => 'ENABLED', 'channel' => 'SEARCH',
            'strategy' => $current, 'portfolio' => '', 'target_cpa_micros' => $current === 'TARGET_CPA' ? 20_000_000 : 0,
            'target_roas' => $current === 'TARGET_ROAS' ? 5.0 : 0.0, 'cpc_bid_ceiling_micros' => 0, 'currency' => 'AUD'];
        $agent = new RecordingProgressionAgent($writer);

        return [$campaign, $strategy, $writer, $agent];
    }

    public static function clickStrategies(): array
    {
        return [['MANUAL_CPC'], ['MAXIMIZE_CLICKS']];
    }

    #[DataProvider('clickStrategies')]
    public function test_click_based_strategy_uses_supported_conversion_bidding_and_synchronizes_all_saved_names(string $current): void
    {
        [$campaign, $strategy, $writer, $agent] = $this->fixture($current);
        $other = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Facebook Ads', 'bidding_strategy' => ['name' => 'Unrelated']]);

        $result = $agent->evaluate($campaign);

        $this->assertTrue($result['success']);
        $this->assertSame('MAXIMIZE_CONVERSIONS', $result['to']);
        $this->assertSame([['1234567890', $campaign->googleAdsResourceName(), 'MAXIMIZE_CONVERSIONS', null, null]], $writer->writes);
        $data = $strategy->fresh()->bidding_strategy;
        foreach (['name', 'bid_strategy', 'bidding_strategy_type'] as $key) {
            $this->assertSame('MAXIMIZE_CONVERSIONS', $data[$key]);
        }
        $this->assertArrayNotHasKey('target_roas', $data);
        $this->assertSame([], $data['parameters']);
        $this->assertSame(['name' => 'Unrelated'], $other->fresh()->bidding_strategy);
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'strategy_graduated')->count());
        $this->assertSame('50.00', $campaign->fresh()->daily_budget);
    }

    public function test_zero_conversions_do_not_trigger_an_automatic_switch(): void
    {
        [$campaign, , $writer, $agent] = $this->fixture();
        $agent->month = ['conversions' => 0, 'cost_micros' => 0];
        $this->assertTrue($agent->evaluate($campaign)['skipped']);
        $this->assertSame([], $writer->writes);
    }

    public function test_target_cpa_uses_actual_cost_micros_in_account_currency(): void
    {
        [$campaign, $strategy, $writer, $agent] = $this->fixture('MAXIMIZE_CONVERSIONS');
        // 30 conversions cost A$600: A$20 CPA, so the configured 90% target is A$18.
        $result = $agent->evaluate($campaign);
        $this->assertTrue($result['success']);
        $this->assertSame(18.0, $writer->writes[0][3]);
        $this->assertSame(18_000_000, $strategy->fresh()->bidding_strategy['parameters']['targetCpaMicros']);
    }

    public function test_roas_progression_requires_measured_value_and_never_uses_a_default(): void
    {
        [$campaign, , $writer, $agent] = $this->fixture('TARGET_CPA');
        $agent->month = ['conversions' => 100, 'cost_micros' => 2_000_000_000];
        $agent->recent = ['conversions' => 50, 'cost_micros' => 1_000_000_000];
        $this->assertTrue($agent->evaluate($campaign)['skipped']);
        $this->assertCount(0, $writer->writes);
        $agent->month['conversion_value'] = 10_000;
        $this->assertTrue($agent->evaluate($campaign)['success']);
        $this->assertSame(4.5, $writer->writes[0][4]);
    }

    public function test_unconfirmed_mutation_never_changes_saved_strategy_or_reports_success(): void
    {
        [$campaign, $strategy, $writer, $agent] = $this->fixture();
        $original = $strategy->bidding_strategy;
        $writer->accepted = false;
        $this->assertFalse($agent->evaluate($campaign)['success']);
        $this->assertSame($original, $strategy->fresh()->bidding_strategy);
        $this->assertFalse(AgentActivity::where('campaign_id', $campaign->id)->where('action', 'strategy_graduated')->exists());
    }

    public static function protectedCampaigns(): array
    {
        return [['paused'], ['ended'], ['hold'], ['trial'], ['unapproved'], ['over_budget'], ['setup_only'], ['future'], ['unsigned']];
    }

    #[DataProvider('protectedCampaigns')]
    public function test_automatic_changes_preserve_pause_hold_setup_date_and_approval_boundaries(string $case): void
    {
        [$campaign, $strategy, $writer, $agent] = $this->fixture();
        match ($case) {
            'paused' => $campaign->update(['status' => 'paused']),
            'ended' => $campaign->update(['end_date' => now()->subDay()]),
            'hold' => $campaign->update(['spend_safety_hold' => ['reason' => 'manual pause']]),
            'trial' => $campaign->update(['spend_guardrails' => ['enabled' => true]]),
            'unapproved' => $campaign->update(['budget_confirmed_at' => null]),
            'over_budget' => $campaign->update(['daily_budget' => 51]),
            'setup_only' => $campaign->customer->update(['service_type' => 'setup_only']),
            'future' => $campaign->update(['start_date' => now()->addDay()]),
            'unsigned' => $strategy->update(['signed_off_at' => null]),
            default => throw new \LogicException('Unknown guard case'),
        };
        $this->assertTrue($agent->evaluate($campaign)['skipped']);
        $this->assertTrue($agent->checkForRegression($campaign)['skipped']);
        $this->assertSame([], $writer->writes);
        $this->assertSame(0, $writer->reads);
    }

    public function test_remote_pause_or_portfolio_or_unknown_state_blocks_changes(): void
    {
        foreach ([['status' => 'PAUSED'], ['portfolio' => 'customers/1234567890/biddingStrategies/99'], ['resource' => 'customers/1234567890/campaigns/999']] as $state) {
            [$campaign, , $writer, $agent] = $this->fixture();
            $writer->state = array_replace($writer->state, $state);
            $this->assertTrue($agent->evaluate($campaign)['skipped']);
            $this->assertSame([], $writer->writes);
        }
    }

    public function test_pause_during_evidence_collection_and_remote_bidding_change_prevent_mutations(): void
    {
        [$campaign, , $writer, $agent] = $this->fixture();
        $agent->duringPerformance = fn () => $campaign->update(['status' => 'paused']);
        $this->assertTrue($agent->evaluate($campaign)['skipped']);
        $this->assertSame([], $writer->writes);

        [$campaign, , $writer, $agent] = $this->fixture();
        $agent->duringPerformance = function () use ($writer): void {
            $writer->state['strategy'] = 'MAXIMIZE_CLICKS';
        };
        $this->assertTrue($agent->evaluate($campaign)['skipped']);
        $this->assertSame([], $writer->writes);
    }

    public function test_wrong_or_ambiguous_google_targets_are_not_selected(): void
    {
        [$campaign, $strategy, $writer, $agent] = $this->fixture();
        $strategy->forceFill(['google_ads_campaign_id' => 'customers/9999999999/campaigns/456'])->save();
        $this->assertTrue($agent->evaluate($campaign)['skipped']);
        $this->assertSame([], $writer->writes);
        $strategy->forceFill(['google_ads_campaign_id' => '456'])->save();
        $duplicate = $strategy->replicate();
        $duplicate->save();
        $this->assertTrue($agent->evaluate($campaign)['skipped']);
        $this->assertSame([], $writer->writes);
    }

    public function test_cpa_regression_uses_remote_target_and_clears_every_obsolete_target(): void
    {
        [$campaign, $strategy, $writer, $agent] = $this->fixture('TARGET_CPA');
        $this->graduation($campaign, 'TARGET_CPA');
        $agent->recent = ['conversions' => 10, 'cost_micros' => 400_000_000];
        $this->assertTrue($agent->checkForRegression($campaign)['success']);
        $this->assertSame('MAXIMIZE_CONVERSIONS', $writer->writes[0][2]);
        $this->assertNull($writer->writes[0][3]);
        $this->assertArrayNotHasKey('target_cpa', $strategy->fresh()->bidding_strategy);
        $this->assertSame([], $strategy->fresh()->bidding_strategy['parameters']);
    }

    public function test_roas_regression_uses_measured_currency_cpa_without_inventing_a_target(): void
    {
        [$campaign, , $writer, $agent] = $this->fixture('TARGET_ROAS');
        $this->graduation($campaign, 'TARGET_ROAS');
        $agent->recent = ['conversions' => 10, 'cost_micros' => 400_000_000, 'conversion_value' => 200];
        $this->assertTrue($agent->checkForRegression($campaign)['success']);
        $this->assertSame(['TARGET_CPA', 44.0, null], array_slice($writer->writes[0], 2));
    }

    public function test_disabled_platform_opt_out_or_disconnected_manager_blocks_automatic_changes(): void
    {
        foreach (['platform', 'feature', 'link', 'local_pause'] as $guard) {
            [$campaign, , $writer, $agent] = $this->fixture();
            if ($guard === 'platform') {
                \App\Models\EnabledPlatform::updateOrCreate(['slug' => 'google'], ['name' => 'Google', 'is_enabled' => false]);
            } elseif ($guard === 'feature') {
                \Laravel\Pennant\Feature::for($campaign->customer)->deactivate(\App\Features\AutoOptimization::class);
            } elseif ($guard === 'link') {
                $campaign->customer->update(['google_ads_link_status' => 'pending']);
            } else {
                $campaign->update(['platform_status' => 'PAUSED']);
            }
            $this->assertTrue($agent->evaluate($campaign)['skipped']);
            $this->assertSame([], $writer->writes);
            \App\Models\EnabledPlatform::updateOrCreate(['slug' => 'google'], ['name' => 'Google', 'is_enabled' => true]);
            \Laravel\Pennant\Feature::for($campaign->customer)->activate(\App\Features\AutoOptimization::class);
            $campaign->customer->update(['google_ads_link_status' => null]);
        }
    }

    public function test_switch_or_opt_out_changed_while_researching_prevents_an_automatic_write(): void
    {
        foreach (['platform', 'feature'] as $guard) {
            [$campaign, , $writer, $agent] = $this->fixture();
            $agent->duringPerformance = function () use ($campaign, $guard): void {
                if ($guard === 'platform') {
                    \App\Models\EnabledPlatform::updateOrCreate(['slug' => 'google'], ['name' => 'Google', 'is_enabled' => false]);
                } else {
                    \Laravel\Pennant\Feature::for($campaign->customer)->deactivate(\App\Features\AutoOptimization::class);
                }
            };
            $this->assertTrue($agent->evaluate($campaign)['skipped']);
            $this->assertSame([], $writer->writes);
            \App\Models\EnabledPlatform::updateOrCreate(['slug' => 'google'], ['name' => 'Google', 'is_enabled' => true]);
            \Laravel\Pennant\Feature::for($campaign->customer)->activate(\App\Features\AutoOptimization::class);
        }
    }

    public function test_verified_deployment_and_saved_configuration_baseline_follow_confirmed_bidding(): void
    {
        [$campaign, $strategy, , $agent] = $this->fixture('MAXIMIZE_CONVERSIONS');
        $strategy->update(['deployment_status' => 'verified', 'execution_result' => ['metadata' => ['google_search_baseline' => ['bidding' => 'MAXIMIZE_CONVERSIONS']]]]);
        $this->assertTrue($agent->evaluate($campaign)['success']);
        $this->assertSame('MAXIMIZE_CONVERSIONS', $strategy->fresh()->execution_result['metadata']['google_search_baseline']['bidding']);
        $this->assertSame('MAXIMIZE_CONVERSIONS', $strategy->fresh()->execution_result['metadata']['expected_bidding']);
        $this->assertSame('TARGET_CPA', $strategy->fresh()->bidding_strategy['name']);
    }

    private function graduation(Campaign $campaign, string $to): void
    {
        $activity = AgentActivity::record('bidding', 'strategy_graduated', 'Previous verified change', $campaign->customer_id, $campaign->id,
            ['to' => $to, 'campaign_resource' => $campaign->googleAdsResourceName()]);
        $activity->forceFill(['created_at' => now()->subDays(15)])->save();
    }
}

class ProgressionRecordingWriter extends UpdateCampaignBiddingStrategy
{
    public array $state;

    public array $writes = [];

    public int $reads = 0;

    public bool $accepted = true;

    public function __construct() {}

    public function readBiddingState(string $customerId, string $resource): ?array
    {
        $this->reads++;

        return $this->state;
    }

    public function __invoke(string $customerId, string $campaignResourceName, string $strategy, ?float $targetCpa = null, ?float $targetRoas = null, ?int $cpcBidCeilingMicros = null): bool
    {
        $this->writes[] = [$customerId, $campaignResourceName, $strategy, $targetCpa, $targetRoas];

        return $this->accepted;
    }
}

class RecordingProgressionAgent extends BiddingStrategyProgressionAgent
{
    public array $month = ['conversions' => 30, 'cost_micros' => 600_000_000, 'cost_per_conversion' => 20_000_000];

    public array $recent = ['conversions' => 15, 'cost_micros' => 300_000_000];

    public ?\Closure $duringPerformance = null;

    public function __construct(private ProgressionRecordingWriter $writer) {}

    protected function updater(Customer $customer): UpdateCampaignBiddingStrategy
    {
        return $this->writer;
    }

    protected function performance(Campaign $campaign, string $resource, string $range): ?array
    {
        ($this->duringPerformance ?? static fn () => null)();

        return $range === 'LAST_30_DAYS' ? $this->month : $this->recent;
    }
}
