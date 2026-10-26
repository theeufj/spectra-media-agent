<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\GoogleAds\ConfigureAiMaxPilot;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

class ConfigureAiMaxPilotTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Exceptions::fake();
        CarbonImmutable::setTestNow('2026-10-26T06:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function fixture(): array
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890', 'currency_code' => 'AUD',
            'timezone' => 'Australia/Sydney', 'service_type' => 'managed']);
        $old = ['enabled' => true, 'max_daily_budget_micros' => 50_000_000, 'max_cpc_bid_micros' => 10_000_000,
            'currency_code' => 'AUD', 'approved_at' => now()->subHour()->toIso8601String(), 'source' => 'owner_approved_cost_repair'];
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => '999',
            'status' => CampaignStatus::Paused, 'platform_status' => 'PAUSED', 'end_date' => '2026-10-30',
            'daily_budget' => '50.00', 'approved_daily_budget' => '50.00', 'budget_confirmed_at' => now(),
            'spend_guardrails' => ['automation_limits' => $old]]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/999',
            'bidding_strategy' => ['name' => 'ManualCpc', 'parameters' => ['cpcBidCeilingMicros' => 10_000_000]]]);
        $pilot = ['enabled' => true, 'permits_conversion_bidding' => true, 'acknowledges_no_cpc_cap' => true,
            'approval' => 'Owner approved the US$999 purchase pilot.', 'source' => 'owner_approved_ai_max',
            'approved_at' => now()->subMinute()->toIso8601String(), 'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->setTimezone('Australia/Sydney')->addDays(13)->endOfDay()->startOfSecond()->toIso8601String(), 'currency_code' => 'AUD', 'account_timezone' => 'Australia/Sydney',
            'max_daily_budget_micros' => 50_000_000, 'baseline_cost_micros' => 180_000_000, 'review_spend_micros' => 200_000_000,
            'selected_conversion_action' => 'customers/1234567890/conversionActions/444',
            'strategy_id' => $strategy->id, 'landing_page' => 'https://example.com/one-time-setup',
            'geo_target_constants' => ['geoTargetConstants/2840']];

        return [$campaign->fresh(['customer']), $pilot, new ScriptedAiMaxCoordinator($customer)];
    }

    public function test_preparation_is_atomic_keeps_campaign_paused_and_preserves_the_old_limits(): void
    {
        [$campaign, $pilot, $coordinator] = $this->fixture();
        $old = $campaign->spend_guardrails['automation_limits'];
        $result = $coordinator->prepare($campaign, $pilot);
        $this->assertSame('prepared', $result['status']);
        $this->assertTrue($result['ready']);
        $fresh = $campaign->fresh();
        $this->assertSame(CampaignStatus::Paused, $fresh->status);
        $this->assertSame('PAUSED', $fresh->platform_status);
        $this->assertSame($old, $fresh->spend_guardrails['automation_limits']);
        $this->assertSame('2026-11-08', $fresh->end_date->toDateString());
        $this->assertNotEmpty($fresh->spend_guardrails['ai_max_pilot']['settings_fingerprint']);
        $this->assertNotEmpty($fresh->search_delivery_state['ai_max_setup']['prepare_started_at']);
        $this->assertSame([true, false], array_column($coordinator->mutations, 'validate_only'));
        $operations = $coordinator->mutations[1]['operations'];
        $this->assertCount(2, $operations);
        $google = $operations[0]->getCampaignOperation()->getUpdate();
        $this->assertTrue($google->getAiMaxSetting()->getEnableAiMax());
        $this->assertSame(3, $google->getStatus(), 'Preparation atomically pauses the campaign even if Google UI resumed it after the last read.');
        $this->assertTrue($google->hasMaximizeConversions());
        $this->assertSame(0, $google->getMaximizeConversions()->getTargetCpaMicros());
        $this->assertCount(4, $google->getTextGuidelines()->getMessagingRestrictions());
        $paths = iterator_to_array($operations[0]->getCampaignOperation()->getUpdateMask()->getPaths());
        $this->assertSame(['status', 'ai_max_setting.enable_ai_max', 'maximize_conversions.target_cpa_micros',
            'end_date_time', 'asset_automation_settings', 'text_guidelines.term_exclusions',
            'text_guidelines.messaging_restrictions'], $paths);
        $this->assertFalse($operations[1]->getAdGroupOperation()->getUpdate()->getAiMaxAdGroupSetting()->getDisableSearchTermMatching());
        $this->assertSame(0, $coordinator->enableCalls);
    }

    public function test_validation_only_and_new_owner_holds_never_install_or_apply_a_pilot(): void
    {
        [$campaign, $pilot, $coordinator] = $this->fixture();
        $this->assertSame('validated', $coordinator->dryRun()->prepare($campaign, $pilot)['status']);
        $this->assertArrayNotHasKey('ai_max_pilot', $campaign->fresh()->spend_guardrails);
        $this->assertCount(1, $coordinator->mutations);
        $coordinator->dryRun(false);
        $coordinator->afterValidation = function () use ($campaign): void {
            $campaign->update(['spend_safety_hold' => ['reason' => 'owner_pause']]);
        };
        $result = $coordinator->prepare($campaign, $pilot);
        $this->assertFalse($result['ready']);
        $this->assertArrayNotHasKey('ai_max_pilot', $campaign->fresh()->spend_guardrails);
        $this->assertSame([true, true], array_column($coordinator->mutations, 'validate_only'));
    }

    public function test_unverified_preparation_is_read_back_without_ever_replaying_the_write(): void
    {
        [$campaign, $pilot, $coordinator] = $this->fixture();
        $coordinator->throwBeforeApply = true;
        $this->assertFalse($coordinator->prepare($campaign, $pilot)['ready']);
        $this->assertNotEmpty($campaign->fresh()->search_delivery_state['ai_max_setup']['prepare_started_at']);
        $this->assertFalse($coordinator->prepare($campaign, $pilot)['ready']);
        $this->assertCount(2, $coordinator->mutations);
        $coordinator->apply($coordinator->mutations[1]['operations']);
        $this->assertSame('prepared', $coordinator->prepare($campaign, $pilot)['status']);
        $this->assertCount(2, $coordinator->mutations);
        $changed = [...$pilot, 'review_spend_micros' => 300_000_000];
        $this->assertFalse($coordinator->prepare($campaign, $changed)['ready']);
        $this->assertCount(2, $coordinator->mutations);
    }

    public function test_activation_uses_fresh_proof_and_ambiguous_success_is_verified_without_a_second_enable(): void
    {
        [$campaign, $pilot, $coordinator] = $this->fixture();
        $this->assertTrue($coordinator->prepare($campaign, $pilot)['ready']);
        $coordinator->throwAfterEnable = true;
        $result = $coordinator->activate($campaign);
        $this->assertSame('active', $result['status']);
        $this->assertFalse($result['actual_conversions_verified']);
        $this->assertSame(1, $coordinator->enableCalls);
        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);
        $this->assertSame('ENABLED', $campaign->fresh()->platform_status);
        $this->assertSame('active', $coordinator->activate($campaign)['status']);
        $this->assertSame(1, $coordinator->enableCalls);
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ai_max_pilot_activated')->count());
    }

    public function test_spend_exhaustion_and_owner_holds_prevent_or_safely_stop_activation(): void
    {
        [$campaign, $pilot, $coordinator] = $this->fixture();
        $this->assertTrue($coordinator->prepare($campaign, $pilot)['ready']);
        $coordinator->remote['reported_lifetime_cost_micros'] = 380_000_000;
        $this->assertFalse($coordinator->activate($campaign)['ready']);
        $this->assertSame(0, $coordinator->enableCalls);
        $coordinator->remote['reported_lifetime_cost_micros'] = 180_000_000;
        $coordinator->beforeEnable = function () use ($campaign): void {
            $campaign->update(['spend_safety_hold' => ['reason' => 'owner_pause', 'keep' => true]]);
        };
        $this->assertFalse($coordinator->activate($campaign)['ready']);
        $this->assertSame(1, $coordinator->enableCalls);
        $this->assertSame(1, $coordinator->pauseCalls);
        $this->assertNotEmpty($campaign->fresh()->search_delivery_state['ai_max_setup']['pause_verified_at']);
        $this->assertEquals(['reason' => 'owner_pause', 'keep' => true], $campaign->fresh()->spend_safety_hold);
        $this->assertSame(3, $coordinator->remote['status']);
        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
        $this->assertFalse($coordinator->activate($campaign)['ready']);
        $this->assertSame(1, $coordinator->enableCalls);
    }

    public function test_deferred_request_revocation_during_validation_stops_the_configuration_write(): void
    {
        [$campaign, $pilot, $coordinator] = $this->fixture();
        $pilot['owner_request_nonce'] = 'approved-request';
        $request = ['enabled' => true, 'nonce' => 'approved-request', 'customer_id' => $campaign->customer_id,
            'google_customer_id' => '1234567890', 'campaign_resource' => $campaign->googleAdsResourceName(),
            'strategy_id' => $pilot['strategy_id'], 'status' => 'preparing', 'expires_at' => now()->addHour()->toIso8601String()];
        $campaign->update(['search_delivery_state' => ['ai_max_request' => $request]]);
        $coordinator->afterValidation = function () use ($campaign): void {
            $state = $campaign->fresh()->search_delivery_state;
            $state['ai_max_request']['enabled'] = false;
            $campaign->update(['search_delivery_state' => $state]);
        };
        $this->assertFalse($coordinator->prepare($campaign, $pilot)['ready']);
        $this->assertSame([true], array_column($coordinator->mutations, 'validate_only'));
        $this->assertArrayNotHasKey('ai_max_pilot', $campaign->fresh()->spend_guardrails);
        $this->assertFalse($campaign->fresh()->search_delivery_state['ai_max_request']['enabled']);
    }

    public function test_snapshot_adapter_reads_fresh_lifetime_cost_even_without_a_legacy_trial(): void
    {
        [$campaign] = $this->fixture();
        $adapter = new ScriptedAiMaxSnapshotAdapter($campaign->customer);
        $snapshot = $adapter->readSnapshot($campaign);
        $this->assertSame(180_000_000, $snapshot['reported_lifetime_cost_micros']);
        $this->assertNotEmpty($snapshot['reported_spend_checked_at']);
        $this->assertSame(['1234567890', 'customers/1234567890/campaigns/999'], $adapter->request);
        $adapter->cost = 181_000_000;
        $this->assertSame(181_000_000, $adapter->readSnapshot($campaign)['reported_lifetime_cost_micros']);
        $adapter->missing = true;
        $this->expectException(\RuntimeException::class);
        $adapter->readSnapshot($campaign);
    }

    public function test_wrong_scope_active_campaign_or_changed_targeting_is_not_reconfigured(): void
    {
        [$campaign, $pilot, $coordinator] = $this->fixture();
        $coordinator->remote['status'] = 2;
        $this->assertFalse($coordinator->prepare($campaign, $pilot)['ready']);
        $this->assertSame([], $coordinator->mutations);
        $coordinator->remote['status'] = 3;
        $coordinator->extraIssue = 'The pilot requires its approved presence-only markets and Google Search inventory.';
        $this->assertFalse($coordinator->prepare($campaign, $pilot)['ready']);
        $this->assertSame([], $coordinator->mutations);
    }
}

class ScriptedAiMaxCoordinator extends ConfigureAiMaxPilot
{
    public array $mutations = [];

    public array $remote;

    public bool $applied = false;

    public bool $throwBeforeApply = false;

    public bool $throwAfterEnable = false;

    public int $enableCalls = 0;

    public int $pauseCalls = 0;

    public ?\Closure $afterValidation = null;

    public ?\Closure $beforeEnable = null;

    public ?string $extraIssue = null;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->remote = ['status' => 3, 'channel' => 2, 'bidding_strategy' => BiddingStrategyType::MANUAL_CPC,
            'daily_budget_micros' => 50_000_000, 'reported_lifetime_cost_micros' => 180_000_000,
            'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney', 'actual_cpc_ceiling_micros' => 10_000_000,
            'end_date' => '2026-10-30', 'end_date_time' => '2026-10-30 23:59:59',
            'negative_keywords' => [['scope' => 'campaign', 'text' => 'jobs', 'match_type' => 'BROAD']],
            'geo_target_constants' => ['geoTargetConstants/2840'],
            'keywords' => [['text' => 'google ads setup', 'match_type' => 'EXACT', 'status' => 'ENABLED', 'ad_group_status' => 'ENABLED']]];
    }

    protected function snapshot(Campaign $campaign): ?array
    {
        return $this->remote;
    }

    protected function inspection(Campaign $campaign, array $pilot): array
    {
        $issues = $this->applied ? [] : ['Google has not confirmed AI Max is enabled.'];
        if ($this->extraIssue) {
            $issues[] = $this->extraIssue;
        }

        return ['ready' => $this->applied && ! $this->extraIssue, 'issues' => $issues,
            'fingerprint' => hash('sha256', $this->applied ? 'configured' : 'before'),
            'observed' => ['campaign' => [], 'ad_groups' => [['adGroup' => [
                'resourceName' => 'customers/1234567890/adGroups/10', 'status' => 'ENABLED', 'type' => 'SEARCH_STANDARD']]]]];
    }

    protected function spendCheck(Campaign $campaign): void {}

    protected function mutate(Campaign $campaign, array $operations, bool $validateOnly): void
    {
        $this->mutations[] = ['validate_only' => $validateOnly, 'operations' => $operations];
        if ($validateOnly) {
            ($this->afterValidation ?? static function (): void {})();

            return;
        }
        if ($this->throwBeforeApply) {
            throw new \RuntimeException('Outcome unavailable.');
        }
        $this->apply($operations);
    }

    public function apply(array $operations): void
    {
        $this->applied = true;
        $this->remote['bidding_strategy'] = BiddingStrategyType::MAXIMIZE_CONVERSIONS;
        $this->remote['actual_cpc_ceiling_micros'] = 0;
        $this->remote['end_date_time'] = $operations[0]->getCampaignOperation()->getUpdate()->getEndDateTime();
        $this->remote['end_date'] = substr($this->remote['end_date_time'], 0, 10);
    }

    protected function enable(Campaign $campaign): void
    {
        $this->enableCalls++;
        ($this->beforeEnable ?? static function (): void {})();
        $this->remote['status'] = 2;
        if ($this->throwAfterEnable) {
            throw new \RuntimeException('Activation response unavailable.');
        }
    }

    protected function pause(Campaign $campaign): void
    {
        $this->pauseCalls++;
        $this->remote['status'] = 3;
    }
}

class ScriptedAiMaxSnapshotAdapter extends ConfigureAiMaxPilot
{
    public array $request = [];

    public int $cost = 180_000_000;

    public bool $missing = false;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }

    public function readSnapshot(Campaign $campaign): ?array
    {
        return $this->snapshot($campaign);
    }

    protected function deliverySnapshot(Campaign $campaign): ?array
    {
        return ['status' => 3]; // Ordinary diagnostics do not supply lifetime spend outside legacy trials.
    }

    protected function lifetimeRows(string $customerId, string $resource): iterable
    {
        $this->request = [$customerId, $resource];
        if ($this->missing) {
            return [];
        }

        return [new \Google\Ads\GoogleAds\V26\Services\GoogleAdsRow([
            'campaign' => new \Google\Ads\GoogleAds\V26\Resources\Campaign(['resource_name' => $resource]),
            'metrics' => new \Google\Ads\GoogleAds\V26\Common\Metrics(['cost_micros' => $this->cost]),
        ])];
    }
}
