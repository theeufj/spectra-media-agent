<?php

namespace Tests\Unit\Agents;

use App\Contracts\Ads\BudgetMutator;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Ads\CustomerRoutedAdsServiceFactory;
use App\Services\Agents\BudgetIntelligenceAgent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BudgetIntelligenceAgentTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(): Campaign
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890']);

        return Campaign::factory()->create([
            'customer_id' => $customer->id,
            'google_ads_campaign_id' => '111222333',
            'daily_budget' => 100,
            'approved_daily_budget' => 100,
        ]);
    }

    private function agent(float $multiplier, BudgetMutator $budgets): BudgetIntelligenceAgent
    {
        $factory = new class($budgets) extends CustomerRoutedAdsServiceFactory
        {
            public function __construct(private BudgetMutator $writer) {}

            public function budgets(Customer $customer): BudgetMutator
            {
                return $this->writer;
            }
        };

        return new class($factory, $multiplier) extends BudgetIntelligenceAgent
        {
            public function __construct(CustomerRoutedAdsServiceFactory $factory, private float $multiplier)
            {
                parent::__construct($factory);
            }

            protected function getTimeOfDayMultiplier(): float
            {
                return $this->multiplier;
            }

            protected function getDayOfWeekMultiplier(): float
            {
                return 1.0;
            }

            protected function getSeasonalMultiplier(): float
            {
                return 1.0;
            }
        };
    }

    public function test_time_of_day_multiplier_cannot_exceed_the_approved_budget(): void
    {
        $campaign = $this->campaign();
        $budgets = new RecordingBudgetMutator;

        $result = $this->agent(1.3, $budgets)->optimize($campaign);

        $this->assertEquals(1.3, $result['multiplier_applied']);
        $this->assertEmpty($result['errors']);
        $this->assertSame([['1234567890', 'customers/1234567890/campaigns/111222333', 100_000_000.0]], $budgets->writes);
        $this->assertSame(100.0, collect($result['adjustments'])->firstWhere('type', 'budget_updated')['adjusted_budget']);
    }

    public function test_a_neutral_multiplier_writes_the_base_budget_back_to_the_platform(): void
    {
        $budgets = new RecordingBudgetMutator;

        $result = $this->agent(1.0, $budgets)->optimize($this->campaign());

        $this->assertEquals(1.0, $result['multiplier_applied']);
        $this->assertEmpty($result['errors']);
        $this->assertSame([['1234567890', 'customers/1234567890/campaigns/111222333', 100_000_000.0]], $budgets->writes);
        $this->assertContains('budget_updated', array_column($result['adjustments'], 'type'));
    }

    public function test_handles_campaigns_with_no_google_ads_id(): void
    {
        $campaign = $this->campaign();
        $campaign->update(['google_ads_campaign_id' => null]);
        $budgets = new RecordingBudgetMutator;

        $result = $this->agent(1.0, $budgets)->optimize($campaign);

        $this->assertEquals($campaign->id, $result['campaign_id']);
        $this->assertEmpty($result['adjustments']);
        $this->assertSame([], $budgets->writes);
        $this->assertEquals(1.0, $result['multiplier_applied']);
    }

    public function test_a_new_learning_campaign_ignores_global_hour_weekday_and_seasonal_rules(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-12-24 02:00:00', 'UTC'));
        config(['budget_rules.time_of_day_multipliers' => ['00:00-06:00' => 0.5],
            'budget_rules.day_of_week_multipliers.thursday' => 1.2, 'budget_rules.seasonal_multipliers.12-24' => 1.5]);
        $campaign = $this->campaign();
        $campaign->update(['primary_status' => 'LEARNING']);
        $budgets = new RecordingBudgetMutator;
        $factory = new class($budgets) extends CustomerRoutedAdsServiceFactory
        {
            public function __construct(private BudgetMutator $writer) {}

            public function budgets(Customer $customer): BudgetMutator
            {
                return $this->writer;
            }
        };
        $result = (new BudgetIntelligenceAgent($factory))->optimize($campaign);
        $this->assertSame(1.0, $result['multiplier_applied']);
        $this->assertSame(100000000.0, $budgets->writes[0][2]);
        $this->assertSame('learning_hold', $result['adjustments'][0]['source']);
        $this->travelBack();
    }

    public function test_a_rejected_budget_is_reported_and_logged(): void
    {
        $campaign = $this->campaign();
        $budgets = new RecordingBudgetMutator(accepted: false);
        Exceptions::fake();
        Log::spy();

        $result = $this->agent(1.5, $budgets)->optimize($campaign);

        $this->assertEquals(1.5, $result['multiplier_applied']);
        $this->assertCount(1, $budgets->writes);
        $this->assertNotEmpty($result['errors']);
        $this->assertNotContains('budget_updated', array_column($result['adjustments'], 'type'));
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'Campaign '.$campaign->id.': platform budget reconciliation failed.');
        Log::shouldHaveReceived('error')->once()->with('Campaign budget reconciliation failed', ['campaign_id' => $campaign->id]);
    }

    public function test_an_adapter_error_is_reported_without_aborting_the_batch(): void
    {
        $budgets = new RecordingBudgetMutator(error: new \TypeError('Invalid adapter response'));
        Exceptions::fake();

        $result = $this->agent(1.0, $budgets)->optimize($this->campaign());

        $this->assertCount(1, $budgets->writes);
        $this->assertNotEmpty($result['errors']);
        Exceptions::assertReported(fn (\TypeError $e) => $e->getMessage() === 'Invalid adapter response');
    }
}

class RecordingBudgetMutator implements BudgetMutator
{
    public array $writes = [];

    public function __construct(private bool $accepted = true, private ?\Throwable $error = null) {}

    public function updateDailyBudget(string $customerId, string $campaignResourceName, float $newDailyBudgetMicros): bool
    {
        $this->writes[] = [$customerId, $campaignResourceName, $newDailyBudgetMicros];
        if ($this->error) {
            throw $this->error;
        }

        return $this->accepted;
    }
}
