<?php

namespace Tests\Feature;

use App\Contracts\Ads\AdsServiceFactory;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\GoogleAdsPerformanceData;
use App\Services\Agents\CampaignOptimizationAgent;
use App\Services\Agents\HealthCheckAgent;
use App\Services\Agents\Optimization\MetricsFetcher;
use App\Services\Agents\Optimization\RecommendationApplier;
use App\Services\Agents\Optimization\RecommendationScorer;
use App\Services\Agents\SearchTermMiningAgent;
use App\Services\GoogleAds\CommonServices\GetAccountStatus;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\GoogleAds\CommonServices\GetSearchTermsReport;
use App\Services\GoogleAds\CommonServices\GoogleBudgetMutator;
use App\Services\GoogleAds\CommonServices\GoogleKeywordMutator;
use App\Services\Testing\Sandbox\SandboxAccountStatusSource;
use App\Services\Testing\Sandbox\SandboxCampaignPerformanceSource;
use App\Services\Testing\Sandbox\SandboxGeminiService;
use App\Services\Testing\Sandbox\SandboxKeywordMutator;
use App\Services\Testing\Sandbox\SandboxSearchTermSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sandbox used to reimplement the agents rather than run them.
 *
 * SandboxAgentRunner::runSearchTermMining derived its own recommendations from
 * KeywordQualityScore rows and recorded them as AgentActivity under the name
 * "SearchTermMiningAgent" — while the real agent was never invoked, in the
 * sandbox or in production, where it has zero recorded activity to this day.
 * A green sandbox run was therefore evidence of nothing, and actively
 * misleading: it manufactured records implying an agent had worked.
 *
 * The agent now resolves its platform services through AdsServiceFactory, which
 * routes on Customer::is_sandbox. These tests pin both halves of that: real
 * agent logic executes, and it can never reach a live account.
 */
class SandboxRunsRealAgentsTest extends TestCase
{
    use RefreshDatabase;

    private function sandboxCampaign(): Campaign
    {
        $customer = Customer::factory()->create([
            'is_sandbox' => true,
            'google_ads_customer_id' => '1234567890',
        ]);

        return Campaign::factory()->create([
            'customer_id' => $customer->id,
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/999',
        ]);
    }

    public function test_sandbox_customers_get_synthetic_services(): void
    {
        $customer = Customer::factory()->create(['is_sandbox' => true]);
        $factory = app(AdsServiceFactory::class);

        $this->assertInstanceOf(SandboxSearchTermSource::class, $factory->searchTerms($customer));
        $this->assertInstanceOf(SandboxKeywordMutator::class, $factory->keywords($customer));
    }

    public function test_real_customers_get_live_services(): void
    {
        // The half that matters most: a sandbox must never be able to serve a
        // real customer fake data, nor a real customer reach a sandbox recorder.
        $customer = Customer::factory()->create([
            'is_sandbox' => false,
            'google_ads_customer_id' => '1234567890',
        ]);
        $factory = app(AdsServiceFactory::class);

        $this->assertInstanceOf(GetSearchTermsReport::class, $factory->searchTerms($customer));
        $this->assertInstanceOf(GoogleKeywordMutator::class, $factory->keywords($customer));
    }

    public function test_the_real_agent_runs_end_to_end_against_synthetic_data(): void
    {
        $campaign = $this->sandboxCampaign();

        $result = app(SearchTermMiningAgent::class)->mine($campaign);

        // Proof the genuine agent executed: these keys come from
        // SearchTermMiningAgent::mine, not from any sandbox reimplementation.
        $this->assertSame($campaign->id, $result['campaign_id']);
        $this->assertGreaterThan(0, $result['terms_analyzed']);
        $this->assertEmpty($result['errors']);
    }

    public function test_it_promotes_converting_terms_and_negates_wasteful_ones(): void
    {
        $campaign = $this->sandboxCampaign();

        $result = app(SearchTermMiningAgent::class)->mine($campaign);

        $this->assertNotEmpty($result['keywords_added'], 'a converting, high-CTR term should be promoted');
        $this->assertNotEmpty($result['negatives_added'], 'an expensive non-converting term should be negated');
    }

    public function test_it_never_negates_a_term_that_converted(): void
    {
        // The safety rule worth pinning: the fixture includes a term with poor
        // CTR and high cost that DID convert. Negating it would throw away
        // working spend, and no threshold should ever override that.
        $campaign = $this->sandboxCampaign();

        $result = app(SearchTermMiningAgent::class)->mine($campaign);

        $negated = array_column($result['negatives_added'], 'keyword');
        foreach ($negated as $keyword) {
            $this->assertStringNotContainsString('review', $keyword, 'a converting term was negated');
        }
    }

    public function test_nothing_is_sent_to_a_real_account_and_decisions_are_recorded(): void
    {
        $campaign = $this->sandboxCampaign();

        app(SearchTermMiningAgent::class)->mine($campaign);

        $decisions = app(AdsServiceFactory::class)->recordedDecisions($campaign->customer);

        $this->assertNotEmpty($decisions, 'the agent made decisions but none were recorded');

        foreach ($decisions as $decision) {
            $this->assertContains($decision['action'], ['add_keyword', 'add_negative']);
            $this->assertNotEmpty($decision['keyword']);
        }
    }

    public function test_budget_agent_reads_seeded_performance_and_records_changes(): void
    {
        $campaign = $this->sandboxCampaign();

        // Seed the performance the sandbox source aggregates. The agent sees
        // real rows, not invented numbers, so a scenario configured to look
        // like heavy spend with no return actually presents that way.
        foreach (range(1, 14) as $day) {
            GoogleAdsPerformanceData::create([
                'campaign_id' => $campaign->id,
                'date' => now()->subDays($day)->toDateString(),
                'impressions' => 1000,
                'clicks' => 40,
                'cost' => 50.00,
                'conversions' => 0,
                'ctr' => 0.04,
                'cpc' => 1.25,
                'cpa' => 0,
            ]);
        }

        $metrics = app(AdsServiceFactory::class)
            ->campaignPerformance($campaign->customer)
            ->__invoke('1234567890', $campaign->google_ads_campaign_id, 'LAST_30_DAYS');

        $this->assertSame(14000, $metrics['impressions']);
        $this->assertSame(560, $metrics['clicks']);
        // Derived, not summed — averaging daily averages would be wrong, and
        // these are the exact fields agents threshold on.
        $this->assertEqualsWithDelta(0.04, $metrics['ctr'], 0.0001);
        $this->assertEqualsWithDelta(1.25, $metrics['average_cpc'], 0.0001);
    }

    public function test_a_sandbox_budget_change_never_reaches_the_platform(): void
    {
        $campaign = $this->sandboxCampaign();
        $factory = app(AdsServiceFactory::class);

        $applied = $factory->budgets($campaign->customer)
            ->updateDailyBudget('1234567890', $campaign->google_ads_campaign_id, 25_000_000);

        // Reports success so the agent's success path runs exactly as it would
        // live, while nothing was sent and no real money can move.
        $this->assertTrue($applied);

        $recorded = $factory->recordedBudgetChanges($campaign->customer);
        $this->assertCount(1, $recorded);
        $this->assertSame(25_000_000.0, (float) $recorded[0]['daily_budget_micros']);
    }

    public function test_live_customers_get_live_budget_and_performance_services(): void
    {
        $customer = Customer::factory()->create([
            'is_sandbox' => false,
            'google_ads_customer_id' => '1234567890',
        ]);
        $factory = app(AdsServiceFactory::class);

        $this->assertInstanceOf(GetCampaignPerformance::class, $factory->campaignPerformance($customer));
        $this->assertInstanceOf(GoogleBudgetMutator::class, $factory->budgets($customer));
        $this->assertSame([], $factory->recordedBudgetChanges($customer));
    }

    public function test_the_gemini_stub_is_deterministic_and_free(): void
    {
        // Determinism is the point, not cost: a sandbox that makes real model
        // calls returns something different every run, so you cannot tell a
        // behaviour change from a different sampling.
        $stub = new SandboxGeminiService;

        $a = $stub->generateContent('gemini-x', 'Recommend optimisations for this campaign');
        $b = $stub->generateContent('gemini-x', 'Recommend optimisations for this campaign');

        $this->assertSame($a['text'], $b['text']);
        $this->assertSame(0.0, $a['cost']);
        $this->assertTrue($a['sandbox']);
        $this->assertCount(2, $stub->calls());
    }

    public function test_the_stub_returns_json_the_agents_can_actually_parse(): void
    {
        // A stub returning something simpler than the real shape would let an
        // agent's parsing bug pass unnoticed — the exact failure this exercise
        // exists to remove.
        $response = (new SandboxGeminiService)->generateContent('gemini-x', 'Recommend optimisations');

        $this->assertArrayHasKey('text', $response);

        $decoded = json_decode($response['text'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('recommendations', $decoded);
        $this->assertNotEmpty($decoded['recommendations']);
        $this->assertArrayHasKey('confidence', $decoded['recommendations'][0]);
    }

    public function test_the_real_optimisation_agent_runs_on_synthetic_metrics(): void
    {
        $campaign = $this->sandboxCampaign();

        foreach (range(1, 40) as $day) {
            GoogleAdsPerformanceData::create([
                'campaign_id' => $campaign->id,
                'date' => now()->subDays($day)->toDateString(),
                'impressions' => 900,
                'clicks' => 45,
                'cost' => 60.00,
                'conversions' => 3,
                'ctr' => 0.05,
                'cpc' => 1.33,
                'cpa' => 20.00,
            ]);
        }

        $agent = new CampaignOptimizationAgent(
            new SandboxGeminiService,
            new MetricsFetcher(new SandboxCampaignPerformanceSource($campaign->customer)),
            app(RecommendationScorer::class),
            app(RecommendationApplier::class),
        );

        $result = $agent->analyze($campaign);

        // The real agent fetched synthetic metrics, called the stub, and parsed
        // the response through its own scoring pipeline.
        $this->assertIsArray($result);
        $this->assertArrayHasKey('recommendations', $result);
    }

    public function test_account_status_is_routed_per_customer(): void
    {
        // Distinct IDs — google_ads_customer_id is unique on customers.
        $sandbox = Customer::factory()->create(['is_sandbox' => true, 'google_ads_customer_id' => '1111111111']);
        $live = Customer::factory()->create(['is_sandbox' => false, 'google_ads_customer_id' => '2222222222']);
        $factory = app(AdsServiceFactory::class);

        $this->assertInstanceOf(SandboxAccountStatusSource::class, $factory->accountStatus($sandbox));
        $this->assertInstanceOf(GetAccountStatus::class, $factory->accountStatus($live));
    }

    public function test_the_sandbox_account_always_reports_healthy(): void
    {
        // Deliberately not randomised: a sandbox that intermittently claimed
        // suspension would train people to ignore a genuinely critical alert.
        $customer = Customer::factory()->create(['is_sandbox' => true]);

        $status = app(AdsServiceFactory::class)->accountStatus($customer)->__invoke('1234567890');

        $this->assertSame(2, $status['status']); // 2 = ENABLED
        $this->assertFalse($status['is_manager']);
    }

    public function test_the_real_health_agent_runs_without_touching_the_api(): void
    {
        $campaign = $this->sandboxCampaign();

        $agent = app()->makeWith(HealthCheckAgent::class, ['gemini' => new SandboxGeminiService]);
        $result = $agent->checkCustomerHealth($campaign->customer);

        // Keys come from HealthCheckAgent::checkCustomerHealth itself — proof
        // the real agent ran rather than a sandbox stand-in.
        $this->assertArrayHasKey('overall_health', $result);
        $this->assertArrayHasKey('issues', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertSame($campaign->customer->id, $result['customer_id']);
    }

    public function test_live_customers_record_no_decisions(): void
    {
        // recordedDecisions is a sandbox-inspection affordance; for a live
        // customer the changes were really sent, so there is nothing to report.
        $customer = Customer::factory()->create(['is_sandbox' => false]);

        $this->assertSame([], app(AdsServiceFactory::class)->recordedDecisions($customer));
    }
}
