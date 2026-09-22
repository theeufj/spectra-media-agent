<?php

use App\Contracts\Ads\BudgetMutator;
use App\Models\AdSpendCredit;
use App\Models\AdSpendTransaction;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\AdSpendBillingService;
use App\Services\Agents\BudgetIntelligenceAgent;
use App\Services\Agents\CampaignOptimizationAgent;
use App\Services\Agents\Optimization\MetricsFetcher;
use App\Services\Agents\Optimization\RecommendationApplier;
use App\Services\Agents\Optimization\RecommendationScorer;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;

// Regressions reproduced during the project audit.
class AuditMoneyRegressionTest extends Tests\TestCase
{
    use DatabaseTransactions;

    public function test_budget_updates_respect_allocation_and_restore_after_a_reduction(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890']);
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'google_ads_campaign_id' => '111222333',
            'daily_budget' => 100,
        ]);
        Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads',
            'daily_budget' => 50,
        ]);
        Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'Facebook Ads',
            'daily_budget' => 50,
        ]);
        $factory = new class extends \App\Services\Ads\CustomerRoutedAdsServiceFactory
        {
            public array $writes = [];

            public function budgets(Customer $customer): BudgetMutator
            {
                return new class($this) implements BudgetMutator
                {
                    public function __construct(private object $factory) {}

                    public function updateDailyBudget(string $customerId, string $campaignResourceName, float $newDailyBudgetMicros): bool
                    {
                        $this->factory->writes[] = $newDailyBudgetMicros / 1000000;

                        return true;
                    }
                };
            }
        };
        $agent = new class($factory) extends BudgetIntelligenceAgent
        {
            private int $run = 0;

            protected function getTimeOfDayMultiplier(): float
            {
                return $this->run++ === 0 ? 0.5 : 1.0;
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
        $agent->optimize($campaign);
        $agent->optimize($campaign);
        $this->assertEquals([25.0, 50.0], $factory->writes);
    }

    public function test_canonical_budget_recommendations_observe_the_change_cooldown(): void
    {
        $campaign = new Campaign(['daily_budget' => 100, 'last_budget_changed_at' => now()]);
        $recommendation = ['type' => 'BUDGET_ADJUSTMENT', 'suggested_value' => 150];
        $applier = new class extends RecommendationApplier
        {
            public function apply(Campaign $campaign, array $recommendation, bool $approvedByUser = false): array
            {
                throw new RuntimeException('Cooling-off must prevent the call.');
            }
        };
        $agent = new CampaignOptimizationAgent(
            app(GeminiService::class), app(MetricsFetcher::class), new RecommendationScorer, $applier,
        );
        $this->assertFalse($agent->applyRecommendation($campaign, $recommendation)['applied']);
    }

    public function test_initial_credit_and_ledger_roll_back_together(): void
    {
        $customer = Customer::factory()->create();
        $billing = new class extends AdSpendBillingService
        {
            public int $chargeCalls = 0;

            protected function chargeCustomer(Customer $customer, float $amount, string $description, string $idempotencyKey): array
            {
                $this->chargeCalls++;

                return ['success' => true, 'charge_id' => 'audit_fake_charge'];
            }
        };
        Event::listen('eloquent.creating: '.AdSpendTransaction::class, function () {
            throw new RuntimeException('Audit: simulated ledger write failure');
        });
        try {
            try {
                $billing->initializeCreditAccount($customer, 100);
                $this->fail('The injected ledger failure should throw.');
            } catch (RuntimeException $e) {
                $this->assertSame('Audit: simulated ledger write failure', $e->getMessage());
            }
        } finally {
            Event::forget('eloquent.creating: '.AdSpendTransaction::class);
        }
        $this->assertFalse(AdSpendCredit::where('customer_id', $customer->id)->exists());
        $credit = $billing->initializeCreditAccount($customer, 100);
        $this->assertEquals(700, $credit->current_balance);
        $this->assertSame(1, $credit->transactions()->count());
        $billing->initializeCreditAccount($customer, 100);
        $this->assertSame(2, $billing->chargeCalls);
        $this->assertSame(1, $credit->transactions()->count());
    }
}
