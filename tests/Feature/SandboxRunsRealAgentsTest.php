<?php

namespace Tests\Feature;

use App\Contracts\Ads\AdsServiceFactory;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\SearchTermMiningAgent;
use App\Services\GoogleAds\CommonServices\GetSearchTermsReport;
use App\Services\GoogleAds\CommonServices\GoogleKeywordMutator;
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

    public function test_live_customers_record_no_decisions(): void
    {
        // recordedDecisions is a sandbox-inspection affordance; for a live
        // customer the changes were really sent, so there is nothing to report.
        $customer = Customer::factory()->create(['is_sandbox' => false]);

        $this->assertSame([], app(AdsServiceFactory::class)->recordedDecisions($customer));
    }
}
