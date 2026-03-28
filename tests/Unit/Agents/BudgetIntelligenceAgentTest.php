<?php

namespace Tests\Unit\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\BudgetIntelligenceAgent;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBudget;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class BudgetIntelligenceAgentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Configure budget rules for predictable testing
        config(['budget_rules' => [
            'time_of_day_multipliers' => [
                '00:00-06:00' => 0.5,
                '06:00-09:00' => 1.2,
                '09:00-17:00' => 1.0,
                '17:00-21:00' => 1.3,
                '21:00-00:00' => 0.8,
            ],
            'day_of_week_multipliers' => [
                'monday' => 1.0,
                'tuesday' => 1.1,
                'wednesday' => 1.1,
                'thursday' => 1.2,
                'friday' => 1.3,
                'saturday' => 0.9,
                'sunday' => 0.8,
            ],
            'seasonal_multipliers' => [
                'black_friday' => 2.0,
                'cyber_monday' => 1.8,
                '12-24' => 1.5,
            ],
            'reallocation_rules' => [
                'min_roas_threshold' => 1.5,
                'max_shift_percentage' => 20,
                'min_data_days' => 7,
                'min_conversions' => 5,
            ],
        ]]);
    }

    public function test_applies_time_of_day_multiplier_correctly(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 1;

        $campaign = new Campaign([
            'customer_id' => 1,
            'google_ads_campaign_id' => '111222333',
            'daily_budget' => 100.00,
        ]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        // Use a partial mock to control time-based methods and the Google Ads call
        $agent = Mockery::mock(BudgetIntelligenceAgent::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        // Force specific multipliers for deterministic testing
        $agent->shouldReceive('getTimeOfDayMultiplier')->once()->andReturn(1.3);
        $agent->shouldReceive('getDayOfWeekMultiplier')->once()->andReturn(1.0);
        $agent->shouldReceive('getSeasonalMultiplier')->once()->andReturn(1.0);

        Log::spy();

        $result = $agent->optimize($campaign);

        $this->assertEquals($campaign->id, $result['campaign_id']);
        $this->assertEquals(1.3, $result['multiplier_applied']);
        $this->assertNotEmpty($result['adjustments']);
        $this->assertContains('multiplier_calculation', array_column($result['adjustments'], 'type'));
        // Budget update via new UpdateCampaignBudget fails in test env — verify error handling
        $this->assertNotEmpty($result['errors']);
    }

    public function test_no_adjustment_when_multiplier_is_one(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 1;

        $campaign = new Campaign([
            'customer_id' => 1,
            'google_ads_campaign_id' => '111222333',
            'daily_budget' => 100.00,
        ]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        $agent = Mockery::mock(BudgetIntelligenceAgent::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agent->shouldReceive('getTimeOfDayMultiplier')->once()->andReturn(1.0);
        $agent->shouldReceive('getDayOfWeekMultiplier')->once()->andReturn(1.0);
        $agent->shouldReceive('getSeasonalMultiplier')->once()->andReturn(1.0);

        $result = $agent->optimize($campaign);

        $this->assertEquals(1.0, $result['multiplier_applied']);
        // Only the multiplier_calculation adjustment, no budget_updated
        $types = array_column($result['adjustments'], 'type');
        $this->assertContains('multiplier_calculation', $types);
        $this->assertNotContains('budget_updated', $types);
    }

    public function test_handles_campaigns_with_no_google_ads_id(): void
    {
        $customer = new Customer(['name' => 'Test Company']);
        $customer->id = 1;

        $campaign = new Campaign([
            'customer_id' => 1,
            'google_ads_campaign_id' => null,
            'daily_budget' => 50.00,
        ]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        $agent = new BudgetIntelligenceAgent();

        $result = $agent->optimize($campaign);

        $this->assertEquals($campaign->id, $result['campaign_id']);
        $this->assertEmpty($result['adjustments']);
        $this->assertEquals(1.0, $result['multiplier_applied']);
    }

    public function test_handles_update_budget_failure(): void
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 1;

        $campaign = new Campaign([
            'customer_id' => 1,
            'google_ads_campaign_id' => '111222333',
            'daily_budget' => 100.00,
        ]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        $agent = Mockery::mock(BudgetIntelligenceAgent::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agent->shouldReceive('getTimeOfDayMultiplier')->once()->andReturn(1.5);
        $agent->shouldReceive('getDayOfWeekMultiplier')->once()->andReturn(1.0);
        $agent->shouldReceive('getSeasonalMultiplier')->once()->andReturn(1.0);

        // The real optimize() will try `new UpdateCampaignBudget(...)` which will fail
        // in testing since there's no real Google Ads client. The catch block handles it.
        Log::spy();

        $result = $agent->optimize($campaign);

        $this->assertEquals(1.5, $result['multiplier_applied']);
        // Should have an error from the failed UpdateCampaignBudget instantiation
        $this->assertNotEmpty($result['errors']);

        Log::shouldHaveReceived('error')->atLeast()->once();
    }
}
