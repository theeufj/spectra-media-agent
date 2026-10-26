<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Campaigns\CampaignSpendGuardrails;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CampaignAutomationLimitsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_an_ongoing_automation_limit_does_not_suspend_managed_repairs_or_create_a_fixed_trial(): void
    {
        $campaign = $this->campaign();

        $this->assertSame($this->limits(), CampaignSpendGuardrails::automationLimits($campaign));
        $this->assertNull(CampaignSpendGuardrails::activeTrial($campaign));
        $this->assertFalse(CampaignSpendGuardrails::automaticChangesSuspended($campaign));
        $this->assertTrue(CampaignSpendGuardrails::canEnable($campaign));
    }

    public function test_daily_and_keyword_mutations_respect_saved_integer_micro_limits(): void
    {
        $campaign = $this->campaign();

        $this->assertTrue(CampaignSpendGuardrails::permitsKeywordBid($campaign, 10_000_000));
        $this->assertFalse(CampaignSpendGuardrails::permitsKeywordBid($campaign, 10_000_001));
        $this->assertFalse(CampaignSpendGuardrails::permitsKeywordBid($campaign, 0));
        $this->assertTrue(CampaignSpendGuardrails::permitsDailyBudget($campaign, 50_000_000));
        $this->assertFalse(CampaignSpendGuardrails::permitsDailyBudget($campaign, 50_000_001));
        $this->assertFalse(CampaignSpendGuardrails::permitsDailyBudget($campaign, 0));
    }

    public function test_bids_cannot_be_amplified_or_changed_to_an_uncapped_strategy(): void
    {
        $campaign = $this->campaign();

        $this->assertTrue(CampaignSpendGuardrails::permitsBidModifier($campaign, 0.8));
        $this->assertTrue(CampaignSpendGuardrails::permitsBidModifier($campaign, 1.0));
        $this->assertFalse(CampaignSpendGuardrails::permitsBidModifier($campaign, 1.01));
        $this->assertFalse(CampaignSpendGuardrails::permitsBidModifier($campaign, INF));
        $this->assertTrue(CampaignSpendGuardrails::permitsBiddingStrategy($campaign, 'MANUAL_CPC', 10_000_000));
        $this->assertTrue(CampaignSpendGuardrails::permitsBiddingStrategy($campaign, 'MAXIMIZE_CLICKS', 10_000_000));
        foreach (['MANUAL_CPC', 'MAXIMIZE_CLICKS', 'MAXIMIZE_CONVERSIONS', 'TARGET_CPA'] as $strategy) {
            $this->assertFalse(CampaignSpendGuardrails::permitsBiddingStrategy($campaign, $strategy, null), $strategy);
            $this->assertFalse(CampaignSpendGuardrails::permitsBiddingStrategy($campaign, $strategy, 10_000_001), $strategy);
        }
        $this->assertFalse(CampaignSpendGuardrails::permitsBiddingStrategy($campaign, 'MAXIMIZE_CONVERSIONS', 1_000_000));
    }

    public function test_the_confirmed_approved_daily_budget_remains_a_ceiling_with_or_without_automation_limits(): void
    {
        foreach ([null, ['automation_limits' => $this->limits()]] as $profile) {
            $campaign = $this->campaign(['approved_daily_budget' => '25.00', 'spend_guardrails' => $profile]);
            $this->assertTrue(CampaignSpendGuardrails::permitsDailyBudget($campaign, 25_000_000));
            $this->assertFalse(CampaignSpendGuardrails::permitsDailyBudget($campaign, 25_000_001));
        }
    }

    public function test_malformed_enabled_limits_fail_closed_instead_of_disappearing(): void
    {
        foreach ([['max_cpc_bid_micros' => 0], ['max_daily_budget_micros' => -1],
            ['max_cpc_bid_micros' => '10000000'], ['currency_code' => 'USD'], ['approved_at' => 'invalid date'],
            ['approved_at' => now()->addDay()->toIso8601String()], ['source' => ''], ['currency_code' => '']] as $override) {
            $campaign = $this->campaign(['spend_guardrails' => ['automation_limits' => array_replace($this->limits(), $override)]]);
            $this->assertNotNull(CampaignSpendGuardrails::automationLimits($campaign));
            $this->assertFalse(CampaignSpendGuardrails::permitsKeywordBid($campaign, 1_000_000), json_encode($override));
            $this->assertFalse(CampaignSpendGuardrails::permitsDailyBudget($campaign, 1_000_000), json_encode($override));
            $this->assertFalse(CampaignSpendGuardrails::permitsBidModifier($campaign, 0.8), json_encode($override));
            $this->assertFalse(CampaignSpendGuardrails::permitsBiddingStrategy($campaign, 'MANUAL_CPC', 1_000_000), json_encode($override));
        }
    }

    public function test_automation_cannot_treat_an_unconfirmed_budget_as_approved(): void
    {
        foreach ([['budget_confirmed_at' => null], ['approved_daily_budget' => 0]] as $override) {
            $campaign = $this->campaign($override);
            $this->assertFalse(CampaignSpendGuardrails::permitsKeywordBid($campaign, 1_000_000));
            $this->assertFalse(CampaignSpendGuardrails::permitsDailyBudget($campaign, 1_000_000));
            $this->assertFalse(CampaignSpendGuardrails::permitsBiddingStrategy($campaign, 'MANUAL_CPC', 1_000_000));
        }
        // Legacy setup may stage budgets before confirmation; an absent profile
        // is deliberately different from an incomplete enabled profile.
        $legacy = $this->campaign(['budget_confirmed_at' => null, 'approved_daily_budget' => null, 'spend_guardrails' => null]);
        $this->assertTrue(CampaignSpendGuardrails::permitsDailyBudget($legacy, 1_000_000));
    }

    public function test_a_stale_model_cannot_restore_an_older_bid_or_daily_budget_limit(): void
    {
        $campaign = $this->campaign();
        $stale = $campaign->fresh();
        $campaign->update(['approved_daily_budget' => '20.00', 'spend_guardrails' => [
            'automation_limits' => $this->limits(['max_cpc_bid_micros' => 5_000_000, 'max_daily_budget_micros' => 20_000_000]),
        ]]);

        $this->assertFalse(CampaignSpendGuardrails::permitsKeywordBid($stale, 5_000_001));
        $this->assertFalse(CampaignSpendGuardrails::permitsDailyBudget($stale, 20_000_001));
        $this->assertFalse(CampaignSpendGuardrails::permitsBiddingStrategy($stale, 'MAXIMIZE_CLICKS', 5_000_001));
        $this->assertTrue(CampaignSpendGuardrails::permitsKeywordBid($stale, 5_000_000));
    }

    public function test_automation_limits_cannot_release_a_manual_hold_or_expand_a_fixed_trial(): void
    {
        $campaign = $this->campaign(['spend_safety_hold' => ['reason' => 'manual_pause']]);
        $this->assertFalse(CampaignSpendGuardrails::canEnable($campaign, true));
        $this->assertTrue(CampaignSpendGuardrails::automaticChangesSuspended($campaign));
        $this->assertFalse(CampaignSpendGuardrails::permitsBidModifier($campaign, 0.8));

        $campaign->update(['spend_safety_hold' => null, 'spend_guardrails' => [
            'enabled' => true, 'max_cpc_bid_micros' => 3_000_000, 'max_daily_budget_micros' => 10_000_000,
            'automation_limits' => $this->limits(),
        ]]);
        $this->assertTrue(CampaignSpendGuardrails::automaticChangesSuspended($campaign));
        $this->assertFalse(CampaignSpendGuardrails::permitsKeywordBid($campaign, 3_000_001));
        $this->assertFalse(CampaignSpendGuardrails::permitsDailyBudget($campaign, 10_000_001));
        $this->assertFalse(CampaignSpendGuardrails::permitsBiddingStrategy($campaign, 'MANUAL_CPC', 3_000_000));
        $this->assertTrue(CampaignSpendGuardrails::permitsBiddingStrategy($campaign, 'MAXIMIZE_CLICKS', 3_000_000));
    }

    private function campaign(array $attributes = []): Campaign
    {
        return Campaign::factory()->create($attributes + [
            'customer_id' => Customer::factory()->create(['service_type' => 'managed', 'currency_code' => 'AUD'])->id,
            'status' => 'active', 'end_date' => now()->addMonth(), 'daily_budget' => '50.00',
            'approved_daily_budget' => '50.00', 'budget_confirmed_at' => now(),
            'spend_guardrails' => ['automation_limits' => $this->limits()],
        ]);
    }

    private function limits(array $overrides = []): array
    {
        return $overrides + ['enabled' => true, 'max_daily_budget_micros' => 50_000_000,
            'max_cpc_bid_micros' => 10_000_000, 'currency_code' => 'AUD',
            'approved_at' => now()->startOfMinute()->toIso8601String(), 'source' => 'owner_approved_keyword_and_bid_repair'];
    }
}
