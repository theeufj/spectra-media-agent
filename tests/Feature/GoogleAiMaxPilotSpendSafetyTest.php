<?php

namespace Tests\Feature;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\SpectraConversionEvent;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\Campaigns\GoogleCampaignSpendSafety;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus as GoogleStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GoogleAiMaxPilotSpendSafetyTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(array $pilotChanges = []): Campaign
    {
        $customer = Customer::where('google_ads_customer_id', '1234567890')->first()
            ?? Customer::factory()->create(['google_ads_customer_id' => '1234567890', 'currency_code' => 'AUD', 'service_type' => 'managed']);
        $pilot = $pilotChanges + ['enabled' => true, 'source' => 'owner_approved_ai_max', 'approval' => 'Owner approved the pilot.',
            'approved_at' => now()->subMinutes(2)->toIso8601String(), 'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addDays(13)->toIso8601String(), 'currency_code' => 'AUD', 'account_timezone' => 'Australia/Sydney',
            'max_daily_budget_micros' => 50_000_000, 'baseline_cost_micros' => 196_800_000, 'review_spend_micros' => 200_000_000,
            'permits_conversion_bidding' => true, 'acknowledges_no_cpc_cap' => true,
            'selected_conversion_action' => 'customers/1234567890/conversionActions/444'];

        return Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => '999', 'status' => 'active',
            'platform_status' => 'ENABLED', 'end_date' => now()->addDays(13), 'approved_daily_budget' => '50.00',
            'budget_confirmed_at' => now(), 'spend_guardrails' => ['ai_max_pilot' => $pilot]]);
    }

    private function safety(array $changes = []): AiMaxSpendSafetyProbe
    {
        $service = new AiMaxSpendSafetyProbe;
        $service->data = $changes + ['status' => GoogleStatus::ENABLED, 'campaign_type' => AdvertisingChannelType::SEARCH,
            'daily_budget_micros' => 50_000_000, 'bidding_strategy' => BiddingStrategyType::MAXIMIZE_CONVERSIONS,
            'cost_micros' => 196_800_000, 'window_matured_cost_micros' => 196_800_000,
            'window_conversions' => 0.0, 'currency_code' => 'AUD', 'account_timezone' => 'Australia/Sydney'];

        return $service;
    }

    public function test_pilot_review_uses_only_reported_post_baseline_spend_and_pauses_even_after_a_purchase(): void
    {
        $campaign = $this->campaign();
        $service = $this->safety(['cost_micros' => 396_799_999]);
        $this->assertSame('observed', $service->check($campaign)['action']);
        $this->assertSame(0, $service->pauses);
        $service->data['cost_micros'] = 397_800_000;
        $service->data['window_conversions'] = 1.0;
        $result = $service->check($campaign);
        $this->assertSame('ai_max_reported_spend_review_reached', $result['reason']);
        $this->assertSame('paused', $result['action']);
        $this->assertSame(1_000_000, $result['overshoot_micros']);
        $this->assertSame(1, $service->pauses);
        $this->assertSame('paused', $campaign->fresh()->status->value);
        $this->assertFalse(CampaignSpendGuardrails::canEnable($campaign->fresh(), true));
        $activity = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'campaign_spend_safety_paused')->firstOrFail();
        $this->assertSame(201_000_000, $activity->details['pilot_spend_micros']);
        $this->assertStringContainsString('not a hard billing cap', $activity->details['reporting_note']);
    }

    public function test_invalid_policy_drift_expiry_and_unavailable_native_configuration_are_protective_stops(): void
    {
        foreach ([
            ['snapshot' => ['daily_budget_micros' => 51_000_000], 'reason' => 'ai_max_serving_limits_changed'],
            ['snapshot' => ['bidding_strategy' => BiddingStrategyType::TARGET_SPEND], 'reason' => 'ai_max_serving_limits_changed'],
            ['snapshot' => ['currency_code' => 'USD'], 'reason' => 'ai_max_spend_evidence_unverified'],
            ['snapshot' => ['cost_micros' => 196_799_999], 'reason' => 'ai_max_spend_evidence_unverified'],
            ['policy' => ['acknowledges_no_cpc_cap' => false], 'reason' => 'ai_max_approval_unverified'],
            ['policy' => ['approved_at' => now()->subDays(3)->toIso8601String(), 'starts_at' => now()->subDays(2)->toIso8601String(),
                'ends_at' => now()->subDay()->toIso8601String()], 'reason' => 'ai_max_approved_end_reached'],
            ['readiness' => false, 'reason' => 'ai_max_configuration_unverified'],
            ['throw' => true, 'reason' => 'ai_max_configuration_unverified'],
        ] as $scenario) {
            $campaign = $this->campaign($scenario['policy'] ?? []);
            $service = $this->safety($scenario['snapshot'] ?? []);
            $service->ready = $scenario['readiness'] ?? true;
            $service->throwReadiness = $scenario['throw'] ?? false;
            $this->assertSame($scenario['reason'], $service->check($campaign)['reason']);
            $this->assertSame(1, $service->pauses);
            $this->assertNotEmpty($campaign->fresh()->spend_safety_hold['verified_at']);
        }
    }

    public function test_pause_intent_survives_rejected_write_or_failed_readback_and_is_read_back_on_retry(): void
    {
        $campaign = $this->campaign();
        $service = $this->safety(['cost_micros' => 400_000_000]);
        $service->pauseSucceeded = false;
        try {
            $service->check($campaign);
            $this->fail('The failed pause must not be reported as success.');
        } catch (\RuntimeException) {
            $this->assertNotEmpty($campaign->fresh()->spend_safety_hold['requested_at']);
            $this->assertArrayNotHasKey('verified_at', $campaign->fresh()->spend_safety_hold);
        }
        $service->pauseSucceeded = true;
        $service->data['status'] = GoogleStatus::PAUSED; // readback resolves an ambiguous already-paused result
        $this->assertSame('paused', $service->check($campaign)['action']);
        $this->assertSame(1, $service->pauses, 'No repeated write when Google already confirms PAUSED.');
        $this->assertSame('held', $service->check($campaign)['action']);
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'campaign_spend_safety_paused')->count());
    }

    public function test_failed_attributable_setup_delivery_stops_the_pilot_but_organic_signups_do_not(): void
    {
        config(['conversions.google_ads_customer_id' => '1234567890']);
        $campaign = $this->campaign();
        SpectraConversionEvent::create(['event' => 'signup', 'mode' => 'server_google', 'value' => '0.00', 'currency' => 'USD']);
        $service = $this->safety();
        $this->assertSame('observed', $service->check($campaign)['action']);
        SpectraConversionEvent::create(['event' => 'paid_setup', 'mode' => 'server_google', 'value' => '999.00', 'currency' => 'USD',
            'google_conversion_resource' => 'customers/1234567890/conversionActions/444', 'ad_identifiers' => ['gclid' => 'test-click'],
            'upload_error' => 'Provider delivery failed']);
        $this->assertSame('ai_max_purchase_delivery_failed', $service->check($campaign)['reason']);
        $this->assertSame(1, $service->pauses);
    }

    public function test_paused_preparation_is_not_enabled_and_a_generic_restart_requires_fresh_readiness(): void
    {
        $campaign = $this->campaign();
        $service = $this->safety(['status' => GoogleStatus::PAUSED]);
        $service->ready = false;
        $this->assertSame('observed', $service->check($campaign)['action']);
        $this->assertSame(0, $service->pauses);
        $this->assertNull($campaign->fresh()->spend_safety_hold);
        $writer = new class($campaign->customer) extends UpdateCampaignStatus
        {
            protected function pilotRestartReady(Campaign $campaign, array $pilot): bool
            {
                return false;
            }
        };
        $result = $writer->enable('1234567890', 'customers/1234567890/campaigns/999', true);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('freshly verified', $result['error']);
    }
}

class AiMaxSpendSafetyProbe extends GoogleCampaignSpendSafety
{
    public array $data = [];

    public bool $ready = true;

    public bool $throwReadiness = false;

    public bool $pauseSucceeded = true;

    public int $pauses = 0;

    protected function snapshot(Campaign $campaign): array
    {
        return $this->data;
    }

    protected function pilotReadiness(Campaign $campaign, array $pilot): array
    {
        if ($this->throwReadiness) {
            throw new \RuntimeException('Configuration unavailable');
        }

        return ['ready' => $this->ready];
    }

    protected function pause(Campaign $campaign): bool
    {
        $this->pauses++;

        return $this->pauseSucceeded;
    }

    protected function isPaused(Campaign $campaign): bool
    {
        return true;
    }
}
