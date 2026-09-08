<?php

namespace Tests\Feature\Agents;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Services\Agents\PerformanceAnomalyAlertAgent;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

/**
 * Anomaly response cuts budget from whatever is currently stored, once per
 * anomaly type per day, against a baseline that takes a week to catch up — and
 * nothing anywhere puts the budget back. Without a floor measured against the
 * pre-anomaly value, a run of alerts ratchets a campaign down to nothing.
 */
class AnomalyBudgetCutFloorTest extends TestCase
{
    use DatabaseTransactions;

    public function test_repeated_cuts_never_take_more_than_half_the_pre_anomaly_budget(): void
    {
        Notification::fake();
        config(['services.facebook.system_user_token' => null]);

        $campaign = $this->campaignWithCpcSpike(100);
        $agent = $this->agent();

        // Six alerts in the same week; the rate limit is per anomaly type per
        // day, so a week of CPC spikes is six chances to cut.
        for ($i = 0; $i < 6; $i++) {
            Cache::flush();
            $agent->checkCampaign($campaign->fresh());
        }

        $this->assertEquals(50, $campaign->fresh()->daily_budget);
    }

    public function test_a_single_cut_records_the_budget_it_started_from(): void
    {
        Notification::fake();
        config(['services.facebook.system_user_token' => null]);

        $campaign = $this->campaignWithCpcSpike(100);

        $this->agent()->checkCampaign($campaign);

        $this->assertEquals(80, $campaign->fresh()->daily_budget);

        $activity = AgentActivity::where('campaign_id', $campaign->id)
            ->where('agent_type', 'anomaly_response')
            ->firstOrFail();

        $this->assertEquals(100, $activity->details['budget_before']);
        $this->assertEquals(80, $activity->details['budget_after']);
    }

    private function agent(): PerformanceAnomalyAlertAgent
    {
        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldReceive('generateContent')->andReturn(['text' => 'Competition increased.']);
        $this->instance(GeminiService::class, $gemini);

        return app(PerformanceAnomalyAlertAgent::class);
    }

    /**
     * A Facebook campaign whose CPC is 5x the same weekday last week, with CTR
     * and CVR unchanged so cpc_spike is the only anomaly that fires.
     */
    private function campaignWithCpcSpike(float $dailyBudget): Campaign
    {
        $customer = Customer::factory()->create(['facebook_ads_account_id' => 'act_1234567890']);
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'facebook_ads_campaign_id' => '9876543210',
            'primary_status' => 'ELIGIBLE',
            'daily_budget' => $dailyBudget,
        ]);

        foreach ([[now(), 500], [now()->subDays(7), 100]] as [$date, $cost]) {
            FacebookAdsPerformanceData::create([
                'campaign_id' => $campaign->id,
                'facebook_campaign_id' => '9876543210',
                'date' => $date->toDateString(),
                'impressions' => 1000,
                'clicks' => 100,
                'cost' => $cost,
                'conversions' => 0,
            ]);
        }

        return $campaign;
    }
}
