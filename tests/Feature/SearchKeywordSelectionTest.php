<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\TargetingConfig;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\Google\SearchKeywordBuilder;
use App\Services\Campaigns\CampaignKeywordResearch;
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
                return ($this->response)($customerId, $seedKeywords, $url, $language, $geoTargets, $pageSize);
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

    public function test_sparse_demand_discovery_recovers_ordinary_buying_terms_without_admitting_unrelated_volume(): void
    {
        $ai = Mockery::mock(GeminiService::class);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'specific commercial-intent')
            && str_contains($prompt, 'ordinary commercial product/service category terms'))->once()
            ->andReturn(['text' => '["ai paid search management"]']);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'Recover commercial product/service category')
            && str_contains($prompt, 'Managed paid search advertising'))->once()
            ->andReturn(['text' => '["paid search management","ppc management services"]']);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'Review keyword candidates'))->once()
            ->andReturn(['text' => '{"reviews":[{"id":2,"relevant":false,"commercial_intent":true,"reason":"ERP is not sold"}]}']);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'negative keywords'))->once()
            ->andReturn(['text' => '[]']);
        $this->app->instance(GeminiService::class, $ai);
        $service = new class($this->customer()) extends KeywordResearchService
        {
            public int $passes = 0;

            protected function expandWithKeywordPlanner(string $customerId, array $seedKeywords, ?string $url, ?string $language, array $geoTargets, int $maxResults): array
            {
                $this->passes++;

                return $this->passes === 1
                    ? [['keyword' => 'ai paid search management', 'avg_monthly_searches' => 20]]
                    : [['keyword' => 'paid search management', 'avg_monthly_searches' => 1900],
                        ['keyword' => 'ppc management services', 'avg_monthly_searches' => 1900],
                        ['keyword' => 'enterprise resource planning', 'avg_monthly_searches' => 100000]];
            }
        };
        $result = $service->research('123', 'SiteToSpend');
        $this->assertSame(2, $service->passes);
        $this->assertCount(3, $result['keywords']);
        $this->assertContains('paid search management', array_column($result['keywords'], 'text'));
        $this->assertNotContains('enterprise resource planning', array_column($result['keywords'], 'text'));
        $this->assertNotContains('BROAD', array_column($result['keywords'], 'match_type'));
        $this->assertTrue($result['discovery']['category_recovery_attempted']);
    }

    public function test_unavailable_planner_does_not_trigger_additional_discovery_api_retries(): void
    {
        $ai = Mockery::mock(GeminiService::class);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'specific commercial-intent'))->once()
            ->andReturn(['text' => '["paid search management"]']);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'negative keywords'))->once()
            ->andReturn(['text' => '[]']);
        $this->app->instance(GeminiService::class, $ai);
        $result = $this->researchService($this->customer(), [])->research('123', 'SiteToSpend');
        $this->assertFalse($result['discovery']['category_recovery_attempted']);
        $this->assertSame('paid search management', $result['keywords'][0]['text']);
    }

    public function test_campaign_research_checks_category_and_specific_google_ideas_and_retains_lower_volume_buying_terms(): void
    {
        $category = ['google ads agency', 'ppc management services', 'paid search agency', 'google ads setup service',
            'ppc consultants', 'search advertising agency', 'google ads account setup', 'ppc campaign setup',
            'google advertising services', 'paid advertising service', 'search marketing agency', 'google ads specialist'];
        $specific = ['small business google ads setup melbourne', 'one time ppc setup melbourne'];
        $ai = Mockery::mock(GeminiService::class);
        foreach (['category and buyer-synonym' => array_slice($category, 0, 2), 'specific buying-intent' => $specific] as $profile => $seeds) {
            $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => $model === config('ai.models.default')
                && str_contains($prompt, $profile) && str_contains($prompt, 'one-time Google Ads setup')
                && str_contains($prompt, 'small businesses') && str_contains($prompt, 'Melbourne')
                && str_contains($prompt, 'example.com') && str_contains($prompt, 'google-ads-setup'))->once()
                ->andReturn(['text' => json_encode($seeds)]);
        }
        $reviews = array_map(fn ($id) => ['id' => $id, 'relevant' => true, 'commercial_intent' => true,
            'reason' => 'A buyer looking for the Google Ads setup service actually sold.'], range(2, 11));
        $reviews[] = ['id' => 12, 'relevant' => false, 'commercial_intent' => true, 'reason' => 'ERP is not sold.'];
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'Review keyword candidates')
            && str_contains($prompt, 'one-time Google Ads setup'))->once()->andReturn(['text' => json_encode(['reviews' => $reviews])]);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'negative keywords')
            && str_contains($prompt, $specific[0]) && str_contains($prompt, $category[11]))->once()->andReturn(['text' => '["jobs"]']);
        $this->app->instance(GeminiService::class, $ai);
        $passes = [];
        $this->fakePlanner(function ($id, $seeds, $url, $language, $geos, $pageSize) use (&$passes, $category, $specific) {
            $passes[] = $seeds;
            $this->assertSame('123', $id);
            $this->assertSame('https://example.com/google-ads-setup', $url);
            $this->assertSame('languageConstants/1000', $language);
            $this->assertSame(['geoTargetConstants/1000567'], $geos);
            $this->assertSame(36, $pageSize);
            if ($seeds === array_slice($category, 0, 2)) {
                return [...array_map(fn ($keyword) => ['keyword' => $keyword, 'avg_monthly_searches' => 10000,
                    'competition_index' => 20], $category),
                    ['keyword' => 'enterprise resource planning software', 'avg_monthly_searches' => 1000000]];
            }
            $this->assertSame($specific, $seeds);

            return array_map(fn ($keyword) => ['keyword' => $keyword, 'avg_monthly_searches' => 20,
                'competition_index' => 80], $specific);
        });
        [$campaign, $strategy] = $this->profileCampaign();
        $result = (new CampaignKeywordResearch($this->customer()))->discover($campaign, $strategy, $this->profileContext(),
            [['text' => 'old selected term', 'match_type' => 'EXACT']]);

        $this->assertCount(2, $passes);
        $this->assertSame(['category', 'specific'], array_column($result['keyword_sets'], 'kind'));
        $this->assertSame(['ready', 'ready'], array_column($result['keyword_sets'], 'status'));
        $this->assertSame([true, true], array_column($result['keyword_sets'], 'demand_verified'));
        $this->assertCount(12, $result['keyword_sets'][0]['keywords']);
        $this->assertSame($specific, array_column($result['keyword_sets'][1]['keywords'], 'text'));
        $this->assertCount(12, $result['keywords']);
        foreach ($specific as $keyword) {
            $this->assertContains($keyword, array_column($result['keywords'], 'text'));
        }
        $this->assertNotContains('enterprise resource planning software', array_column($result['keywords'], 'text'));
        $this->assertSame(['jobs'], $result['negative_keywords']);
        $this->assertNotContains('BROAD', array_column($result['keywords'], 'match_type'));
        foreach ($result['keyword_sets'] as $set) {
            foreach ($set['keywords'] as $keyword) {
                $this->assertNotEmpty($keyword['selection_reason']);
            }
        }
    }

    public function test_explicit_sparse_profiles_do_not_trigger_a_third_google_discovery_pass(): void
    {
        $ai = Mockery::mock(GeminiService::class);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'category and buyer-synonym'))
            ->once()->andReturn(['text' => '["google ads setup service"]']);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'specific buying-intent'))
            ->once()->andReturn(['text' => '["small business google ads setup melbourne"]']);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'negative keywords'))
            ->once()->andReturn(['text' => '[]']);
        $this->app->instance(GeminiService::class, $ai);
        $passes = 0;
        $this->fakePlanner(function ($id, $seeds) use (&$passes) {
            $passes++;

            return [['keyword' => $seeds[0], 'avg_monthly_searches' => 20]];
        });
        [$campaign, $strategy] = $this->profileCampaign();
        $result = (new CampaignKeywordResearch($this->customer()))->discover($campaign, $strategy, $this->profileContext(), []);

        $this->assertSame(2, $passes);
        $this->assertCount(2, $result['keywords']);
        $this->assertFalse($result['discovery']['category_recovery_attempted']);
        $this->assertSame(['category', 'specific'], $result['discovery']['profiles_attempted']);
    }

    public function test_one_unavailable_profile_does_not_erase_other_profile_or_invent_google_demand(): void
    {
        $ai = Mockery::mock(GeminiService::class);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'category and buyer-synonym'))
            ->once()->andReturn(['text' => '["google ads setup service"]']);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'specific buying-intent'))
            ->once()->andReturn(['text' => '["small business google ads setup melbourne"]']);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'negative keywords'))
            ->once()->andReturn(['text' => '[]']);
        $this->app->instance(GeminiService::class, $ai);
        $passes = 0;
        $this->fakePlanner(function ($id, $seeds) use (&$passes) {
            $passes++;
            if ($passes === 1) {
                throw new \RuntimeException('Keyword Planner unavailable for category profile');
            }

            return [['keyword' => $seeds[0], 'avg_monthly_searches' => 20]];
        });
        [$campaign, $strategy] = $this->profileCampaign();
        $result = (new CampaignKeywordResearch($this->customer()))->discover($campaign, $strategy, $this->profileContext(), []);

        $this->assertSame(2, $passes);
        $this->assertSame(['needs_review', 'ready'], array_column($result['keyword_sets'], 'status'));
        $this->assertSame([false, true], array_column($result['keyword_sets'], 'demand_verified'));
        $this->assertNull($result['keyword_sets'][0]['keywords'][0]['avg_monthly_searches']);
        $this->assertStringContainsString('could not be verified', $result['keyword_sets'][0]['reason']);
        $this->assertSame('small business google ads setup melbourne', $result['keyword_sets'][1]['keywords'][0]['text']);
        $this->assertCount(2, $result['keywords']);
    }

    public function test_missing_platform_account_produces_both_semantic_profiles_without_claiming_measured_demand(): void
    {
        $ai = Mockery::mock(GeminiService::class);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'category and buyer-synonym'))
            ->once()->andReturn(['text' => '["google ads setup service"]']);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'specific buying-intent'))
            ->once()->andReturn(['text' => '["small business google ads setup melbourne"]']);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'negative keywords'))
            ->once()->andReturn(['text' => '[]']);
        $this->app->instance(GeminiService::class, $ai);
        $this->fakePlanner(function () {
            $this->fail('Missing account must not call Keyword Planner.');
        });
        [$campaign, $strategy] = $this->profileCampaign();
        $result = (new CampaignKeywordResearch($this->customer()))->discover($campaign, $strategy, [], []);

        $this->assertSame('business_context_only', $result['source']);
        $this->assertSame(['category', 'specific'], array_column($result['keyword_sets'], 'kind'));
        $this->assertSame([false, false], array_column($result['keyword_sets'], 'demand_verified'));
        $this->assertSame(['needs_review', 'needs_review'], array_column($result['keyword_sets'], 'status'));
        $this->assertSame(['PHRASE', 'PHRASE'], array_column($result['keywords'], 'match_type'));
        $this->assertSame([null, null], array_column($result['keywords'], 'avg_monthly_searches'));
    }

    public function test_specific_offer_seeds_with_zero_or_unknown_demand_are_not_ranked_away_by_generic_expansions(): void
    {
        $specific = ['small business google ads setup melbourne', 'one time ppc setup melbourne'];
        $ai = Mockery::mock(GeminiService::class);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'category and buyer-synonym'))
            ->once()->andReturn(['text' => '["google ads setup service"]']);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'specific buying-intent'))
            ->once()->andReturn(['text' => json_encode($specific)]);
        $reviews = array_map(fn ($id) => ['id' => $id, 'relevant' => true, 'commercial_intent' => true,
            'reason' => 'A commercial Google Ads service relevant to the offer.'], range(1, 14));
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'Review keyword candidates'))
            ->once()->andReturn(['text' => json_encode(['reviews' => $reviews])]);
        $ai->shouldReceive('generateContent')->withArgs(fn ($model, $prompt) => str_contains($prompt, 'negative keywords'))
            ->once()->andReturn(['text' => '[]']);
        $this->app->instance(GeminiService::class, $ai);
        $passes = 0;
        $this->fakePlanner(function ($id, $seeds) use (&$passes, $specific) {
            $passes++;
            if ($passes === 1) {
                return [['keyword' => $seeds[0], 'avg_monthly_searches' => 2000]];
            }

            return [['keyword' => $specific[0], 'avg_monthly_searches' => 0, 'competition_index' => 80],
                ...array_map(fn ($index) => ['keyword' => 'commercial google ads service '.$index,
                    'avg_monthly_searches' => 100000, 'competition_index' => 10], range(1, 14))];
        });
        [$campaign, $strategy] = $this->profileCampaign();
        $result = (new CampaignKeywordResearch($this->customer()))->discover($campaign, $strategy, $this->profileContext(), []);

        $this->assertSame(2, $passes);
        $profile = $result['keyword_sets'][1];
        $this->assertSame('specific', $profile['kind']);
        $this->assertSame('needs_review', $profile['status']);
        $this->assertFalse($profile['demand_verified']);
        $this->assertCount(12, $profile['keywords']);
        $this->assertSame($specific, array_column(array_slice($profile['keywords'], 0, 2), 'text'));
        $this->assertSame(0, $profile['keywords'][0]['avg_monthly_searches']);
        $this->assertNull($profile['keywords'][1]['avg_monthly_searches']);
        $this->assertStringContainsString('unverified', $profile['keywords'][1]['selection_reason']);
        $this->assertSame(['EXACT', 'PHRASE'], array_column(array_slice($profile['keywords'], 0, 2), 'match_type'));
        foreach ($specific as $keyword) {
            $this->assertContains($keyword, array_column($result['keywords'], 'text'));
        }
        $this->assertNotContains('BROAD', array_column($profile['keywords'], 'match_type'));
    }

    private function profileCampaign(): array
    {
        $campaign = new Campaign(['product_focus' => 'one-time Google Ads setup', 'target_market' => 'small businesses in Melbourne',
            'landing_page_url' => 'https://example.com/google-ads-setup', 'geographic_targeting' => ['Melbourne']]);
        $strategy = new Strategy(['platform' => 'Google Ads', 'campaign_type' => 'search']);
        $strategy->setRelation('targetingConfig', null);

        return [$campaign, $strategy];
    }

    private function profileContext(): array
    {
        return ['customer_id' => '123', 'language_constants' => ['languageConstants/1000'],
            'geo_target_constants' => ['geoTargetConstants/1000567']];
    }
}
