<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\TargetingConfig;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\Google\SearchKeywordBuilder;
use App\Services\GeminiService;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordIdeas;
use App\Services\GoogleAds\KeywordResearch\KeywordResearchService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

class SearchKeywordSelectionTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): Customer
    {
        return Customer::factory()->create(['name' => 'SiteToSpend', 'business_type' => 'Digital SEM agency',
            'description' => 'Managed paid search advertising for small businesses.', 'website' => 'https://example.com']);
    }

    public function test_reviewed_strategy_keywords_are_used_before_execution_plan_research(): void
    {
        $customer = $this->customer();
        $campaign = new Campaign;
        $strategy = new Strategy(['bidding_strategy' => ['keywords' => [
            ['text' => 'managed ppc services', 'match_type' => 'EXACT'],
            ['text' => 'hire ppc manager', 'match_type' => 'BROAD'],
        ]]]);
        $strategy->setRelation('targetingConfig', null);
        $plan = new ExecutionPlan([], rawPlan: ['creative_strategy' => ['keywords' => ['management consulting']]]);
        $selected = (new SearchKeywordBuilder($customer))->getKeywords($campaign, $strategy, $plan);
        $this->assertSame(['managed ppc services', 'hire ppc manager'], array_column($selected, 'text'));
        $this->assertSame(['EXACT', 'PHRASE'], array_column($selected, 'match_type'));
    }

    public function test_explicit_campaign_keywords_and_match_types_take_priority(): void
    {
        $customer = $this->customer();
        $campaign = new Campaign(['keywords' => [['text' => 'custom keyword', 'match_type' => 'BROAD']]]);
        $strategy = new Strategy(['bidding_strategy' => ['keywords' => ['another keyword']]]);
        $actual = (new SearchKeywordBuilder($customer))->getKeywords($campaign, $strategy, new ExecutionPlan([]));
        $this->assertSame($campaign->keywords, $actual);
    }

    public function test_targeting_and_execution_step_keywords_are_supported(): void
    {
        $builder = new SearchKeywordBuilder($this->customer());
        $strategy = new Strategy;
        $strategy->setRelation('targetingConfig', new TargetingConfig(['google_options' => ['keywords' => ['targeted phrase']]]));
        $this->assertSame('targeted phrase', $builder->getKeywords(new Campaign, $strategy, new ExecutionPlan([]))[0]['text']);
        $strategy->setRelation('targetingConfig', null);
        $plan = new ExecutionPlan([['action' => 'add_keywords', 'parameters' => ['keywords' => ['hire ppc manager']]]]);
        $this->assertSame('hire ppc manager', $builder->getKeywords(new Campaign, $strategy, $plan)[0]['text']);
    }

    public function test_planner_cannot_replace_six_selected_terms_with_twenty_unrelated_high_volume_ideas(): void
    {
        $customer = $this->customer();
        $campaign = new Campaign(['daily_budget' => 0]);
        $strategy = new Strategy(['bidding_strategy' => ['landing_page_url' => 'https://example.com/paid-search']]);
        $selected = array_map(fn ($text) => ['text' => $text, 'match_type' => 'EXACT'], [
            'hire digital marketing agency', 'outsource google ads', 'managed ppc services',
            'b2b ad agency', 'affordable google ads management', 'hire ppc manager',
        ]);
        $this->fakePlanner(function ($id, $seeds, $url) use ($selected) {
            $this->assertSame('123', $id);
            $this->assertSame(array_column($selected, 'text'), $seeds);
            $this->assertSame('https://example.com/paid-search', $url);

            return [
                ['keyword' => 'managed ppc services', 'avg_monthly_searches' => 500, 'competition_index' => 20],
                ['keyword' => 'affordable google ads management', 'avg_monthly_searches' => 0],
                ['keyword' => 'management consulting', 'avg_monthly_searches' => 100000],
                ['keyword' => 'enterprise resource planning systems', 'avg_monthly_searches' => 100000],
            ];
        });
        $actual = (new SearchKeywordBuilder($customer))->validateAndEnrichKeywords('123', $selected, $campaign, $strategy);
        $this->assertSame(array_column($selected, 'text'), array_column($actual, 'text'));
        $this->assertSame(array_column($selected, 'match_type'), array_column($actual, 'match_type'));
        $this->assertSame(500, $actual[2]['avg_monthly_searches']);
        $this->assertNull($actual[0]['avg_monthly_searches']);
        $this->assertSame(0, $actual[4]['avg_monthly_searches']);
    }

    public function test_planner_outage_preserves_selected_terms(): void
    {
        $this->fakePlanner(fn () => throw new \RuntimeException('Planner unavailable'));
        $selected = [['text' => 'managed ppc services', 'match_type' => 'EXACT']];
        $actual = (new SearchKeywordBuilder($this->customer()))->validateAndEnrichKeywords('123', $selected, new Campaign(['daily_budget' => 0]), new Strategy);
        $this->assertSame($selected, $actual);
    }

    public function test_new_campaigns_are_not_automatically_expanded_back_to_broad_match(): void
    {
        $customer = $this->customer();
        $customer->update(['google_ads_customer_id' => '123', 'conversion_tracking_verified_at' => now()]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => 'customers/123/campaigns/456']);
        // No conversion history: this must return before any Google API call.
        (new \App\Jobs\ExpandBroadMatchKeywords($campaign))->handle();
        $this->assertDatabaseMissing('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'broad_match_updated']);
    }

    private function fakePlanner(\Closure $response): void
    {
        $this->app->bind(GenerateKeywordIdeas::class, fn () => new class($response) extends GenerateKeywordIdeas
        {
            public function __construct(private \Closure $response) {}

            public function __invoke(string $customerId, array $seedKeywords = [], ?string $url = null, ?string $language = null, array $geoTargets = [], int $pageSize = 50): array
            {
                return ($this->response)($customerId, $seedKeywords, $url);
            }
        });
    }

    private function researchService(Customer $customer, array $ideas): KeywordResearchService
    {
        return new class($customer, $ideas) extends KeywordResearchService
        {
            public function __construct(Customer $customer, private array $ideas)
            {
                parent::__construct($customer);
            }

            protected function expandWithKeywordPlanner(string $customerId, array $seedKeywords, ?string $url, ?string $language, array $geoTargets, int $maxResults): array
            {
                return $this->ideas;
            }
        };
    }

    public function test_new_research_requires_offer_relevance_before_volume_ranking(): void
    {
        $ai = Mockery::mock(GeminiService::class);
        $ai->shouldReceive('generateContent')->withArgs(function ($model, $prompt) {
            return str_contains($prompt, 'Managed paid search advertising') && str_contains($prompt, 'Digital SEM agency')
                && str_contains($prompt, 'Our paid search package') && str_contains($prompt, 'Review keyword candidates');
        })->andReturn(['text' => json_encode(['reviews' => [
            ['id' => 1, 'relevant' => false, 'commercial_intent' => true, 'reason' => 'ERP is not sold'],
            ['id' => 2, 'relevant' => true, 'commercial_intent' => true, 'reason' => 'PPC campaign management matches the offer'],
            ['id' => 99, 'relevant' => true, 'commercial_intent' => true, 'reason' => 'Invented ID'],
        ]])]);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'negative keywords'))->andReturn(['text' => '[]']);
        $this->app->instance(GeminiService::class, $ai);
        $service = $this->researchService($this->customer(), [
            ['keyword' => 'managed ppc services', 'avg_monthly_searches' => 50, 'competition_index' => 75],
            ['keyword' => 'enterprise resource planning systems', 'avg_monthly_searches' => 100000, 'competition_index' => 5],
            ['keyword' => 'google ads management service', 'avg_monthly_searches' => 2000, 'competition_index' => 5],
        ]);
        $actual = $service->research('123', 'SiteToSpend', userSeedKeywords: ['managed ppc services'], businessContext: ['offer' => 'Our paid search package']);
        $this->assertSame(['google ads management service', 'managed ppc services'], array_column($actual['keywords'], 'text'));
        $this->assertNotContains('BROAD', array_column($actual['keywords'], 'match_type'));
        $this->assertSame('PPC campaign management matches the offer', $actual['keywords'][0]['selection_reason']);
    }

    public function test_unavailable_or_malformed_relevance_review_never_admits_unreviewed_suggestions(): void
    {
        foreach ([null, ['text' => '{broken'], ['text' => '{"reviews":[{"id":1,"relevant":"true","commercial_intent":true}]}']] as $response) {
            $ai = Mockery::mock(GeminiService::class);
            $ai->shouldReceive('generateContent')->andReturn($response);
            $this->app->instance(GeminiService::class, $ai);
            $service = $this->researchService($this->customer(), [
                ['keyword' => 'managed ppc services', 'avg_monthly_searches' => 50, 'competition_index' => 75],
                ['keyword' => 'management consulting', 'avg_monthly_searches' => 100000, 'competition_index' => 5],
            ]);
            $actual = $service->research('123', 'SiteToSpend', userSeedKeywords: ['managed ppc services']);
            $this->assertSame(['managed ppc services'], array_column($actual['keywords'], 'text'));
        }
    }

    public function test_seed_generation_uses_real_business_and_offer_and_fallback_does_not_use_broad_match(): void
    {
        $ai = Mockery::mock(GeminiService::class);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'specific commercial-intent')
            && str_contains($prompt, 'Managed paid search advertising') && str_contains($prompt, 'Monthly ad management'))
            ->andReturn(['text' => '["managed ppc services","hire ppc manager"]']);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'negative keywords')
            && str_contains($prompt, 'positive_keywords'))->andReturn(null);
        $this->app->instance(GeminiService::class, $ai);
        $actual = $this->researchService($this->customer(), [])->research('123', 'SiteToSpend', businessContext: ['offer' => 'Monthly ad management']);
        $this->assertSame(['PHRASE', 'PHRASE'], array_column($actual['keywords'], 'match_type'));
        $this->assertSame([], $actual['negative_keywords']);
    }
}
