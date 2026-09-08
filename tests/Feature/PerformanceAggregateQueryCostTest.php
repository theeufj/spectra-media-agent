<?php

namespace Tests\Feature;

use App\Http\Controllers\RoiDashboardController;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Models\GoogleAdsPerformanceData;
use App\Models\User;
use App\Services\CrossChannelBudgetAllocator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Per-campaign performance aggregates cost one query per campaign per platform.
 *
 * Three places built the same figures the same way — a SUM per platform inside
 * a loop over campaigns — so a forty-campaign account spent 160 round trips
 * drawing one table. SUM() is additive, so a single query grouped by
 * campaign_id answers all of them at once.
 *
 * These tests assert the *shape* of the cost rather than a magic number: the
 * query count must not move when the campaign count does.
 */
class PerformanceAggregateQueryCostTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * A customer with $count campaigns, each with one day of Google and
     * Facebook spend: $40 + $10 cost, $200 + $50 revenue, 4 + 1 conversions.
     *
     * @return array{0: User, 1: Customer, 2: Collection<int, Campaign>}
     */
    private function accountWithCampaigns(int $count): array
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        $campaigns = Campaign::factory()->count($count)->create([
            'customer_id' => $customer->id,
            'daily_budget' => 100,
            'platform_status' => 'ENABLED',
            'google_ads_campaign_id' => 'customers/111/campaigns/222',
            'facebook_ads_campaign_id' => '333',
        ]);

        foreach ($campaigns as $campaign) {
            GoogleAdsPerformanceData::create([
                'campaign_id' => $campaign->id,
                'date' => now()->subDay()->toDateString(),
                'impressions' => 1000,
                'clicks' => 100,
                'cost' => 40,
                'conversions' => 4,
                'conversion_value' => 200,
            ]);

            FacebookAdsPerformanceData::create([
                'campaign_id' => $campaign->id,
                'facebook_campaign_id' => 'fb_'.$campaign->id,
                'date' => now()->subDay()->toDateString(),
                'impressions' => 500,
                'clicks' => 50,
                'cost' => 10,
                'conversions' => 1,
                'conversion_value' => 50,
            ]);
        }

        return [$user, $customer, $campaigns];
    }

    /**
     * @return list<string>
     */
    private function record(callable $callback): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $callback();

        return $queries;
    }

    private function countReads(array $queries, string $table): int
    {
        return count(array_filter($queries, fn ($sql) => str_contains($sql, $table)));
    }

    public function test_the_dashboard_breakdown_does_not_cost_a_query_per_campaign(): void
    {
        $reads = [];

        foreach ([2, 6] as $campaignCount) {
            [$user] = $this->accountWithCampaigns($campaignCount);

            $queries = $this->record(function () use ($user) {
                $this->actingAs($user)->get(route('dashboard'))->assertOk();
            });

            $reads[$campaignCount] = $this->countReads($queries, 'google_ads_performance_data');
        }

        $this->assertSame(
            $reads[2],
            $reads[6],
            'the dashboard must read each platform table a fixed number of times, not once per campaign'
        );
    }

    public function test_the_dashboard_breakdown_still_sums_every_platform(): void
    {
        [$user, , $campaigns] = $this->accountWithCampaigns(2);

        $response = $this->actingAs($user)->get(route('dashboard'));
        $response->assertOk();

        $breakdown = collect($response->viewData('page')['props']['campaignBreakdown'])
            ->keyBy('id');

        $this->assertCount(2, $breakdown);

        foreach ($campaigns as $campaign) {
            $row = $breakdown[$campaign->id];

            // Google $40 + Facebook $10, revenue $200 + $50, 4 + 1 conversions.
            $this->assertEquals(50, $row['cost']);
            $this->assertEquals(250, $row['revenue']);
            $this->assertEquals(5, $row['conversions']);
            $this->assertEquals(5, $row['roas']);
        }
    }

    public function test_the_roi_breakdown_matches_the_dashboard_and_is_grouped(): void
    {
        [, , $campaigns] = $this->accountWithCampaigns(4);

        $controller = new class extends RoiDashboardController
        {
            /**
             * @param  Collection<int, Campaign>  $campaigns
             */
            public function exposeBreakdown($campaigns, Carbon $since): array
            {
                return $this->buildCampaignBreakdown($campaigns, $since);
            }
        };

        $breakdown = [];

        $queries = $this->record(function () use ($controller, $campaigns, &$breakdown) {
            $breakdown = $controller->exposeBreakdown($campaigns, Carbon::now()->subDays(30));
        });

        $this->assertCount(4, $breakdown);
        $this->assertEquals(50, $breakdown[0]['cost']);
        $this->assertEquals(250, $breakdown[0]['revenue']);

        // One grouped read per platform table for the whole list.
        $this->assertSame(1, $this->countReads($queries, 'google_ads_performance_data'));
        $this->assertSame(1, $this->countReads($queries, 'facebook_ads_performance_data'));
    }

    public function test_the_rebalance_snapshot_is_one_aggregate_per_platform(): void
    {
        [, $customer] = $this->accountWithCampaigns(5);

        $allocator = new class extends CrossChannelBudgetAllocator
        {
            public function exposeSnapshot(Customer $customer): array
            {
                return $this->getPerformanceSnapshot($customer);
            }
        };

        $snapshot = [];

        $queries = $this->record(function () use ($allocator, $customer, &$snapshot) {
            $snapshot = $allocator->exposeSnapshot($customer);
        });

        $this->assertEquals(200, $snapshot['google_ads']['spend']);
        $this->assertEquals(1000, $snapshot['google_ads']['conversion_value']);
        $this->assertSame(5, $snapshot['google_ads']['campaigns']);

        // Facebook's arm of this block selected every column except
        // conversion_value, so its ROAS was structurally 0 and the performance
        // strategy scored the platform on CPA alone.
        $this->assertEquals(250, $snapshot['facebook_ads']['conversion_value']);
        $this->assertEquals(5, $snapshot['facebook_ads']['roas']);

        $this->assertSame(1, $this->countReads($queries, 'google_ads_performance_data'));
        $this->assertSame(1, $this->countReads($queries, 'facebook_ads_performance_data'));

        // No campaign carries a Microsoft or LinkedIn id, so neither table is
        // read at all.
        $this->assertSame(0, $this->countReads($queries, 'microsoft_ads_performance_data'));
        $this->assertSame(0, $this->countReads($queries, 'linkedin_ads_performance_data'));
    }

    public function test_the_cross_platform_summary_is_computed_once_per_window(): void
    {
        [$user] = $this->accountWithCampaigns(3);

        $queries = $this->record(function () use ($user) {
            $this->actingAs($user)->get(route('dashboard'))->assertOk();
        });

        // getPlatformComparison() and getFunnelAnalysis() are both derived from
        // getSummary(), and the dashboard renders both. Unmemoised, that ran the
        // four platform aggregates twice for one page.
        //
        // Matched on the service's own alias. Two other aggregates read the same
        // table on this render and neither is what this pins: the dashboard's
        // per-campaign breakdown (grouped by campaign_id) and the dashboard's own
        // platform totals, which select the identical columns under `as revenue`.
        // That last one is real remaining duplication — the dashboard computes
        // what the service already hands it — but collapsing the two changes the
        // prop names the page renders, so it is a separate change from this one.
        $summaryReads = count(array_filter(
            $queries,
            fn ($sql) => str_contains($sql, 'google_ads_performance_data')
                && str_contains($sql, 'SUM(conversion_value) as conversion_value')
                && str_contains($sql, 'SUM(impressions) as impressions')
                && ! str_contains($sql, 'group by'),
        ));

        $this->assertSame(1, $summaryReads, 'the cross-platform summary must be computed once per render');
    }
}
