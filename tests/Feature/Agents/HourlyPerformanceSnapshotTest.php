<?php

namespace Tests\Feature\Agents;

use App\Jobs\HourlyBudgetOptimization;
use App\Models\Campaign;
use App\Models\CampaignHourlyPerformance;
use App\Models\Customer;
use App\Models\MicrosoftAdsPerformanceData;
use App\Services\Agents\AdaptiveThresholds;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * campaign_hourly_performance rows are *hourly* figures. Every platform reports
 * day-to-date totals, so the snapshot has to subtract what today already has on
 * record — otherwise the series accumulates and every threshold derived from it
 * (min_impressions_for_decision, auto_pause_min_impressions) is an order of
 * magnitude too high, and no creative is ever judged.
 */
class HourlyPerformanceSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_snapshot_records_the_hour_not_the_running_day_total(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 10:30:00'));

        $customer = Customer::factory()->create(['microsoft_ads_customer_id' => '1234567']);
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'microsoft_ads_campaign_id' => '55667788',
        ]);

        // Today's stored platform row is cumulative for the day.
        MicrosoftAdsPerformanceData::create([
            'campaign_id' => $campaign->id,
            'date' => now()->toDateString(),
            'impressions' => 1000,
            'clicks' => 100,
            'cost' => 50,
            'conversions' => 5,
            'conversion_value' => 250,
        ]);

        // …of which earlier hours have already been recorded.
        foreach ([8 => 400, 9 => 300] as $hour => $impressions) {
            CampaignHourlyPerformance::create([
                'campaign_id' => $campaign->id,
                'customer_id' => $customer->id,
                'date' => now()->toDateString(),
                'hour' => $hour,
                'day_of_week' => (int) now()->format('w'),
                'platform' => 'microsoft_ads',
                'impressions' => $impressions,
                'clicks' => 40,
                'conversions' => 2,
                'spend' => 15,
                'conversion_value' => 100,
            ]);
        }

        // recordHourlySnapshot is protected, and the job's only public entry
        // point also drives the budget agent and the alert service.
        $job = new class extends HourlyBudgetOptimization
        {
            public function snapshot(Campaign $campaign): void
            {
                $this->recordHourlySnapshot($campaign);
            }
        };
        $job->snapshot($campaign);

        $row = CampaignHourlyPerformance::where('campaign_id', $campaign->id)
            ->where('hour', 10)
            ->firstOrFail();

        $this->assertEquals(300, $row->impressions);   // 1000 - (400 + 300)
        $this->assertEquals(20, $row->clicks);         // 100 - (40 + 40)
        $this->assertEquals(20, $row->spend);          // 50 - (15 + 15)
        $this->assertEquals(50, $row->conversion_value); // 250 - (100 + 100)
        $this->assertEquals(1, $row->conversions);     // 5 - (2 + 2)
    }

    public function test_a_restated_platform_total_never_writes_a_negative_hour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 11:15:00'));

        $customer = Customer::factory()->create(['microsoft_ads_customer_id' => '1234567']);
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'microsoft_ads_campaign_id' => '55667788',
        ]);

        MicrosoftAdsPerformanceData::create([
            'campaign_id' => $campaign->id,
            'date' => now()->toDateString(),
            'impressions' => 100,
            'clicks' => 10,
            'cost' => 5,
        ]);

        CampaignHourlyPerformance::create([
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'hour' => 9,
            'day_of_week' => (int) now()->format('w'),
            'platform' => 'microsoft_ads',
            'impressions' => 500,
            'clicks' => 50,
            'spend' => 25,
        ]);

        $job = new class extends HourlyBudgetOptimization
        {
            public function snapshot(Campaign $campaign): void
            {
                $this->recordHourlySnapshot($campaign);
            }
        };
        $job->snapshot($campaign);

        $row = CampaignHourlyPerformance::where('campaign_id', $campaign->id)
            ->where('hour', 11)
            ->firstOrFail();

        $this->assertEquals(0, $row->impressions);
        $this->assertEquals(0, $row->spend);
    }

    public function test_daily_volume_thresholds_come_from_real_days_not_hourly_average_times_24(): void
    {
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        // 13 days × 8 serving hours = 104 rows (computeBaselines needs 100+).
        // 8 × 2,000 impressions = 16,000/day, so the decision threshold is 1,600.
        // The old avg × 24 form read 48,000/day and returned 4,800.
        $rows = [];
        for ($day = 1; $day <= 13; $day++) {
            for ($hour = 9; $hour <= 16; $hour++) {
                $rows[] = [
                    'campaign_id' => $campaign->id,
                    'customer_id' => $customer->id,
                    'date' => now()->subDays($day)->toDateString(),
                    'hour' => $hour,
                    'day_of_week' => (int) now()->subDays($day)->format('w'),
                    'platform' => 'google_ads',
                    'impressions' => 2000,
                    'clicks' => 40,
                    'conversions' => 2,
                    'spend' => 100,
                    'conversion_value' => 400,
                    'ctr' => 0.02,
                    'roas' => 4,
                ];
            }
        }
        CampaignHourlyPerformance::insert($rows);

        $thresholds = AdaptiveThresholds::forCustomer($customer->fresh());

        $this->assertEquals(1600, $thresholds['min_impressions_for_decision']);
        $this->assertEquals(3200, $thresholds['auto_pause_min_impressions']);
        // 8 × $100 = $800/day, 10% of it. The old form read $2,400/day.
        $this->assertEquals(80, $thresholds['max_spend_no_conversion']);
    }
}
