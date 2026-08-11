<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\GoogleAdsPerformanceData;
use App\Services\Agents\GoogleAdsExecutionAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The executor silently converted Search and Display campaigns to Performance
 * Max whenever three correctly-sized images and A$10/day existed.
 *
 * That tested whether PMax was *possible*, never whether it was *wise*. PMax
 * picks its own inventory and needs conversion volume to pick well; with none it
 * chases the cheapest impressions available. On the one live campaign that meant
 * 89% of impressions inside mobile games — the X iOS app alone took 16,503 of
 * 38,486 — while Maximize Conversions bid on a history of zero.
 *
 * The account requested Display. Nobody chose PMax; an image count did.
 */
class PmaxUpgradeGateTest extends TestCase
{
    use RefreshDatabase;

    private function agentFor(Customer $customer): GoogleAdsExecutionAgent
    {
        return app()->makeWith(GoogleAdsExecutionAgent::class, ['customer' => $customer]);
    }

    private function hasSignal(Campaign $campaign): bool
    {
        $method = new \ReflectionMethod(GoogleAdsExecutionAgent::class, 'hasConversionSignalForPmax');

        return $method->invoke($this->agentFor($campaign->customer), $campaign);
    }

    private function seedConversions(Campaign $campaign, float $perDay, int $days = 10): void
    {
        foreach (range(1, $days) as $day) {
            GoogleAdsPerformanceData::create([
                'campaign_id' => $campaign->id,
                'date' => now()->subDays($day)->toDateString(),
                'impressions' => 1000,
                'clicks' => 50,
                'cost' => 40.00,
                'conversions' => $perDay,
                'ctr' => 0.05,
                'cpc' => 0.80,
                'cpa' => $perDay > 0 ? 40.0 / $perDay : 0,
            ]);
        }
    }

    private function campaign(): Campaign
    {
        return Campaign::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
        ]);
    }

    public function test_a_new_account_with_no_conversions_is_not_upgraded(): void
    {
        // The case that caused the damage: a fresh account, assets ready, zero
        // signal. Search with explicit keywords is the safer default.
        $this->assertFalse($this->hasSignal($this->campaign()));
    }

    public function test_an_account_below_the_threshold_is_not_upgraded(): void
    {
        $campaign = $this->campaign();
        $this->seedConversions($campaign, perDay: 1, days: 10); // 10 total, threshold 30

        $this->assertFalse($this->hasSignal($campaign));
    }

    public function test_an_account_with_enough_history_is_upgraded(): void
    {
        $campaign = $this->campaign();
        $this->seedConversions($campaign, perDay: 5, days: 10); // 50 total

        $this->assertTrue($this->hasSignal($campaign));
    }

    public function test_signal_is_measured_across_the_account_not_one_campaign(): void
    {
        // A campaign being deployed has no history of its own, and PMax bids on
        // account-level signal. Counting only this campaign would mean no
        // account ever qualified.
        $customer = Customer::factory()->create();
        $established = Campaign::factory()->create(['customer_id' => $customer->id]);
        $brandNew = Campaign::factory()->create(['customer_id' => $customer->id]);

        $this->seedConversions($established, perDay: 5, days: 10);

        $this->assertTrue($this->hasSignal($brandNew));
    }

    public function test_conversions_outside_the_window_do_not_count(): void
    {
        $campaign = $this->campaign();

        foreach (range(60, 70) as $day) {
            GoogleAdsPerformanceData::create([
                'campaign_id' => $campaign->id,
                'date' => now()->subDays($day)->toDateString(),
                'impressions' => 1000, 'clicks' => 50, 'cost' => 40.0,
                'conversions' => 10, 'ctr' => 0.05, 'cpc' => 0.8, 'cpa' => 4.0,
            ]);
        }

        $this->assertFalse($this->hasSignal($campaign), 'stale history should not qualify an account');
    }

    public function test_the_gate_can_be_disabled_to_restore_the_old_behaviour(): void
    {
        // Deliberately reversible: if this judgement turns out to be wrong for
        // some account, it should be one config value, not a code change.
        config(['optimization.pmax_upgrade.min_conversions' => 0]);

        $this->assertTrue($this->hasSignal($this->campaign()));
    }
}
