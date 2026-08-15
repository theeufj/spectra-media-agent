<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\Google\SearchKeywordBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bid a forecast is run at.
 *
 * GenerateKeywordForecast answers the question the platform never asked before
 * launching: at this budget and this bid, how many clicks is this? A budget too
 * small to win the auctions for its own keywords produces spend without
 * conversions, and Smart Bidding with nothing to learn from — which is what this
 * account actually saw.
 *
 * The forecast itself needs a live Google connection, but the bid it is run at
 * is pure arithmetic and decides whether the answer means anything. Forecasting
 * at an unrealistically low bid would return a reassuring "you cannot spend your
 * budget" for every campaign.
 */
class PreLaunchForecastTest extends TestCase
{
    use RefreshDatabase;

    private function builder(): SearchKeywordBuilder
    {
        return new SearchKeywordBuilder(Customer::factory()->create());
    }

    public function test_it_bids_at_googles_own_top_of_page_estimate(): void
    {
        // Google's estimate for these keywords is the best available guide to
        // what it costs to actually appear.
        $ideaMap = [
            'plumber sydney' => ['high_top_of_page_bid_micros' => 4_000_000],
            'emergency plumber' => ['high_top_of_page_bid_micros' => 6_000_000],
        ];

        $bid = $this->builder()->suggestedBid(['plumber sydney', 'emergency plumber'], $ideaMap, 50.0);

        $this->assertSame(5.0, $bid, 'should average the top-of-page estimates');
    }

    public function test_it_falls_back_to_a_share_of_the_daily_budget(): void
    {
        // Without Planner data, a tenth of the daily budget assumes a campaign
        // wants more than a handful of clicks a day.
        $bid = $this->builder()->suggestedBid(['some keyword'], [], 50.0);

        $this->assertSame(5.0, $bid);
    }

    public function test_the_fallback_bid_never_collapses_to_nothing(): void
    {
        // A tiny budget would otherwise produce a bid so low the forecast would
        // report no traffic for any keyword, which says nothing useful.
        $bid = $this->builder()->suggestedBid(['some keyword'], [], 1.0);

        $this->assertSame(0.5, $bid);
    }

    public function test_keywords_without_planner_data_do_not_drag_the_bid_down(): void
    {
        // Averaging a missing estimate as zero would understate the bid and make
        // every campaign look affordable.
        $ideaMap = ['known keyword' => ['high_top_of_page_bid_micros' => 8_000_000]];

        $bid = $this->builder()->suggestedBid(['known keyword', 'unknown keyword'], $ideaMap, 50.0);

        $this->assertSame(8.0, $bid);
    }

    public function test_forecasting_is_skipped_when_there_is_no_budget_to_assess(): void
    {
        // Nothing to say about viability without a budget, and no reason to
        // spend an API call finding that out.
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'daily_budget' => 0]);

        $builder = new class($customer) extends SearchKeywordBuilder
        {
            public function run(string $customerId, array $keywords, Campaign $campaign): void
            {
                $this->forecastViability($customerId, $keywords, $campaign);
            }
        };

        $builder->run('1234567890', ['a keyword'], $campaign);

        // No AgentActivity recorded means no forecast was attempted.
        $this->assertDatabaseMissing('agent_activities', ['campaign_id' => $campaign->id]);
    }
}
