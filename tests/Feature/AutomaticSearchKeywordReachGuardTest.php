<?php

namespace Tests\Feature;

use App\Contracts\Ads\KeywordMutator;
use App\Contracts\Ads\SearchTermSource;
use App\Jobs\ExpandBroadMatchKeywords;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\GoogleAdsPerformanceData;
use App\Models\KeywordQualityScore;
use App\Models\Strategy;
use App\Services\Ads\CustomerRoutedAdsServiceFactory;
use App\Services\Agents\DSAManagementAgent;
use App\Services\Agents\GoogleSearchMutationGuard;
use App\Services\Agents\QualityScoreImprovementAgent;
use App\Services\Agents\SearchTermMiningAgent;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\AddKeyword;
use App\Services\GoogleAds\CommonServices\GetCampaignKeywords;
use App\Services\GoogleAds\CommonServices\GetSearchTermsReport;
use App\Services\GoogleAds\CommonServices\UpdateKeywordStatus;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AutomaticSearchKeywordReachGuardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Cache::flush();
        Notification::fake();
        Http::fake();
    }

    private function campaign(array $changes = []): Campaign
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890',
            'conversion_tracking_verified_at' => now(), 'is_sandbox' => false]);

        return Campaign::factory()->create(array_replace(['customer_id' => $customer->id,
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/61', 'status' => 'active',
            'daily_budget' => '10.00', 'approved_daily_budget' => '10.00', 'budget_confirmed_at' => now()], $changes));
    }

    private function guard(Campaign $campaign, bool $ready, int $changes, string|array $action = 'add'): void
    {
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $guard->expects($this->once())->method('assess')->willReturnCallback(function ($actual, array $proposals) use ($campaign, $ready, $changes, $action): array {
            $this->assertSame($campaign->id, $actual->id);
            $this->assertCount($changes, $proposals, 'The resulting portfolio must be assessed once as a batch.');
            if (is_array($action)) {
                $this->assertSame($action, array_column($proposals, 'action'));
            } else {
                foreach ($proposals as $proposal) {
                    $this->assertSame($action, $proposal['action']);
                }
            }

            return ['ready' => $ready, 'status' => $ready ? 'ready' : 'unavailable',
                'message' => 'Demand or affordable traffic is not verified.'];
        });
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
    }

    private function terms(): array
    {
        return [
            ['search_term' => 'google ads management software', 'impressions' => 1000, 'clicks' => 70,
                'cost' => 10.0, 'conversions' => 12.0, 'ctr' => 0.07, 'ad_group_resource_name' => 'customers/1234567890/adGroups/7'],
            ['search_term' => 'affordable google ads management', 'impressions' => 1000, 'clicks' => 80,
                'cost' => 10.0, 'conversions' => 6.0, 'ctr' => 0.08, 'ad_group_resource_name' => 'customers/1234567890/adGroups/7'],
            ['search_term' => 'free enterprise consulting jobs', 'impressions' => 1000, 'clicks' => 20,
                'cost' => 100.0, 'conversions' => 0.0, 'ctr' => 0.02, 'ad_group_resource_name' => 'customers/1234567890/adGroups/7'],
        ];
    }

    public function test_mining_defers_additions_and_exclusions_without_verified_remaining_affordable_reach(): void
    {
        $campaign = $this->campaign();
        $this->guard($campaign, false, 3, ['add', 'add', 'negative']);
        $factory = new SearchKeywordGuardFactory($this->terms());
        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertSame([], $result['keywords_added']);
        $this->assertTrue($result['requires_review']);
        $this->assertCount(3, $result['keywords_deferred']);
        $this->assertSame([], $result['negatives_added']);
        $this->assertSame([], $factory->mutator->writes);
        $this->assertDatabaseHas('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'keyword_changes_need_review']);
        Http::assertNothingSent();
    }

    public function test_mining_applies_the_whole_verified_batch_once_and_preserves_match_types(): void
    {
        $campaign = $this->campaign();
        $this->guard($campaign, true, 2);
        $factory = new SearchKeywordGuardFactory(array_slice($this->terms(), 0, 2));
        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertCount(2, $result['keywords_added']);
        $this->assertSame([KeywordMatchType::EXACT, KeywordMatchType::PHRASE], array_column($factory->mutator->writes, 'match_type'));
    }

    public function test_mining_safety_negatives_can_proceed_when_remaining_traffic_is_verified(): void
    {
        $campaign = $this->campaign();
        $this->guard($campaign, true, 3, ['add', 'add', 'negative']);
        $factory = new SearchKeywordGuardFactory($this->terms());

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertCount(2, $result['keywords_added']);
        $this->assertCount(1, $result['negatives_added']);
        $this->assertSame(['add', 'add', 'negative'], array_column($factory->mutator->writes, 'action'));
    }

    public function test_keyword_forecast_exception_cannot_authorize_mined_additions(): void
    {
        $campaign = $this->campaign();
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $guard->expects($this->once())->method('assess')->willThrowException(new \RuntimeException('Forecast unavailable'));
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $factory = new SearchKeywordGuardFactory(array_slice($this->terms(), 0, 2));

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertSame([], $factory->mutator->writes);
        $this->assertTrue($result['requires_review']);
        $this->assertCount(1, $result['errors']);
        $this->assertDatabaseHas('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'keyword_changes_need_review']);
    }

    private function broadFixture(Campaign $campaign, bool $ready): void
    {
        GoogleAdsPerformanceData::create(['campaign_id' => $campaign->id, 'date' => now()->subDay()->toDateString(),
            'impressions' => 1000, 'clicks' => 50, 'cost' => 10, 'conversions' => 31, 'ctr' => 0.05, 'cpc' => 0.2, 'cpa' => 0.3]);
        $keywords = $this->createMock(GetCampaignKeywords::class);
        $keywords->expects($this->once())->method('__invoke')->willReturn([
            ['keyword_text' => 'google ads management software', 'match_type' => KeywordMatchType::EXACT,
                'ad_group_resource' => 'customers/1234567890/adGroups/7'],
            ['keyword_text' => 'affordable google ads management', 'match_type' => KeywordMatchType::PHRASE,
                'ad_group_resource' => 'customers/1234567890/adGroups/7'],
        ]);
        $this->app->bind(GetCampaignKeywords::class, fn () => $keywords);
        $add = $this->createMock(AddKeyword::class);
        $add->expects($ready ? $this->exactly(2) : $this->never())->method('__invoke')
            ->willReturn('customers/1234567890/adGroupCriteria/7~10');
        $this->app->bind(AddKeyword::class, fn () => $add);
        $this->guard($campaign, $ready, 2);
    }

    public function test_broad_expansion_cannot_use_account_conversions_to_bypass_unavailable_forecast(): void
    {
        $campaign = $this->campaign();
        $this->broadFixture($campaign, false);
        (new ExpandBroadMatchKeywords($campaign))->handle();

        $this->assertDatabaseHas('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'broad_match_needs_review']);
        $this->assertDatabaseMissing('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'broad_match_updated']);
        $this->assertFalse(Cache::has('broad_match_expanded:'.$campaign->id));
        Http::assertNothingSent();
    }

    public function test_broad_expansion_uses_one_verified_portfolio_assessment_before_adding(): void
    {
        $campaign = $this->campaign();
        $this->broadFixture($campaign, true);
        (new ExpandBroadMatchKeywords($campaign))->handle();

        $this->assertDatabaseHas('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'broad_match_updated']);
        $this->assertTrue(Cache::has('broad_match_expanded:'.$campaign->id));
    }

    private function qualityFixture(Campaign $campaign, bool $ready): QualityScoreImprovementAgent
    {
        foreach (['google ads management software', 'affordable google ads management'] as $index => $text) {
            foreach ([now()->subDays(21), now()] as $recordedAt) {
                KeywordQualityScore::create(['customer_id' => $campaign->customer_id,
                    'campaign_google_id' => $campaign->google_ads_campaign_id, 'keyword_text' => $text,
                    'ad_group_resource_name' => 'customers/1234567890/adGroups/7', 'match_type' => 'EXACT',
                    'criterion_resource_name' => 'customers/1234567890/adGroupCriteria/7~'.($index + 10),
                    'quality_score' => 2, 'recorded_at' => $recordedAt]);
            }
        }
        $status = $this->createMock(UpdateKeywordStatus::class);
        $status->expects($ready ? $this->exactly(2) : $this->never())->method('pause')->willReturn(true);
        $this->app->bind(UpdateKeywordStatus::class, fn () => $status);
        $this->guard($campaign, $ready, 2, 'pause');
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->never())->method('generateContent');

        return new QualityScoreImprovementAgent($gemini);
    }

    public function test_quality_score_pauses_require_viable_remaining_keywords(): void
    {
        $campaign = $this->campaign();
        $result = $this->qualityFixture($campaign, false)->improve($campaign);

        $this->assertSame([], $result['paused']);
        $this->assertSame('keyword_pause_reach_unverified', $result['flagged'][0]['issue']);
        $this->assertDatabaseHas('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'keyword_pauses_need_review']);
        Notification::assertNothingSent();
    }

    public function test_quality_score_pauses_can_proceed_after_resulting_portfolio_verification(): void
    {
        $campaign = $this->campaign();
        $result = $this->qualityFixture($campaign, true)->improve($campaign);

        $this->assertCount(2, $result['paused']);
        $this->assertSame([], $result['errors']);
    }

    public function test_dsa_does_not_create_unapproved_campaigns_or_promote_keywords_under_any_hold(): void
    {
        $agent = new DSAManagementAgent;
        $campaign = $this->campaign();
        foreach ([['approved_daily_budget' => null, 'budget_confirmed_at' => null],
            ['spend_guardrails' => ['enabled' => true, 'max_daily_budget_micros' => 10_000_000]],
            ['spend_safety_hold' => ['reason' => 'spend_cap']]] as $changes) {
            $campaign->forceFill(array_replace(['approved_daily_budget' => '10.00', 'budget_confirmed_at' => now(),
                'spend_guardrails' => null, 'spend_safety_hold' => null], $changes))->save();
            $before = $campaign->customer->campaigns()->count();
            $result = $agent->manage($campaign->customer);
            $promotion = $agent->promoteHighPerformingTerms($campaign->customer);
            $this->assertTrue($result['requires_review']);
            $this->assertTrue($promotion['requires_review']);
            $this->assertSame([], $result['created']);
            $this->assertSame(0, $promotion['promoted']);
            $this->assertSame($before, $campaign->customer->campaigns()->count());
            $this->assertDatabaseHas('agent_activities', ['customer_id' => $campaign->customer_id, 'action' => 'dsa_launch_requires_review']);
        }
        Http::assertNothingSent();
    }

    public function test_dsa_review_records_are_durable_and_deduplicated_per_customer(): void
    {
        $campaign = $this->campaign();
        $agent = new DSAManagementAgent;
        $agent->manage($campaign->customer);
        $agent->manage($campaign->customer);
        $agent->promoteHighPerformingTerms($campaign->customer);
        $agent->promoteHighPerformingTerms($campaign->customer);

        $this->assertSame(2, AgentActivity::where('customer_id', $campaign->customer_id)->where('agent_type', 'dsa')->count());
    }

    private function dsaFixture(Campaign $campaign, int $reads = 1): void
    {
        $campaign->forceFill(['primary_status' => 'ELIGIBLE'])->save();
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'google_ads_ad_group_id' => 'customers/1234567890/adGroups/7']);
        AgentActivity::record('dsa', 'dsa_campaign_setup', 'Existing DSA campaign', $campaign->customer_id, null,
            ['dsa_campaign_resource' => 'customers/1234567890/campaigns/74']);
        $source = $this->createMock(GetSearchTermsReport::class);
        $source->expects($this->exactly($reads))->method('__invoke')
            ->with('1234567890', 'customers/1234567890/campaigns/74', 'LAST_30_DAYS')
            ->willReturn([...array_map(fn ($term) => $term + ['campaign_resource_name' => 'customers/1234567890/campaigns/74'], $this->terms()),
                ['search_term' => 'one lucky click', 'clicks' => 1, 'conversions' => 1, 'campaign_resource_name' => 'customers/1234567890/campaigns/74'],
                ['search_term' => 'unrelated customer term', 'clicks' => 30, 'conversions' => 10, 'campaign_resource_name' => 'customers/9999999999/campaigns/74']]);
        $this->app->bind(GetSearchTermsReport::class, fn () => $source);
    }

    public function test_existing_dsa_promotions_keep_converting_terms_but_require_destination_portfolio_verification(): void
    {
        $campaign = $this->campaign();
        $this->dsaFixture($campaign);
        $this->guard($campaign, false, 2);
        $add = $this->createMock(AddKeyword::class);
        $add->expects($this->never())->method('__invoke');
        $this->app->bind(AddKeyword::class, fn () => $add);

        $result = (new DSAManagementAgent)->promoteHighPerformingTerms($campaign->customer);

        $this->assertSame(0, $result['promoted']);
        $this->assertTrue($result['requires_review']);
        $this->assertDatabaseHas('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'dsa_keyword_promotion_requires_review']);
        Http::assertNothingSent();
    }

    public function test_existing_dsa_promotions_apply_only_the_verified_converting_batch_and_record_success(): void
    {
        $campaign = $this->campaign();
        $this->dsaFixture($campaign);
        $this->guard($campaign, true, 2);
        $add = $this->createMock(AddKeyword::class);
        $add->expects($this->exactly(2))->method('__invoke')->with('1234567890', 'customers/1234567890/adGroups/7',
            $this->anything(), KeywordMatchType::PHRASE)->willReturn('customers/1234567890/adGroupCriteria/7~10');
        $this->app->bind(AddKeyword::class, fn () => $add);

        $result = (new DSAManagementAgent)->promoteHighPerformingTerms($campaign->customer);

        $this->assertSame(2, $result['promoted']);
        $this->assertFalse($result['requires_review']);
        $this->assertSame(array_column(array_slice($this->terms(), 0, 2), 'search_term'), array_column($result['terms'], 'keyword'));
        $this->assertCount(2, Cache::get('dsa_promoted:'.$campaign->customer_id));
        $this->assertDatabaseHas('agent_activities', ['customer_id' => $campaign->customer_id, 'action' => 'dsa_terms_promoted']);
    }

    public function test_existing_dsa_terms_cannot_bypass_trial_or_spend_holds(): void
    {
        $campaign = $this->campaign();
        $this->dsaFixture($campaign, 2);
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $guard->expects($this->never())->method('assess');
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $add = $this->createMock(AddKeyword::class);
        $add->expects($this->never())->method('__invoke');
        $this->app->bind(AddKeyword::class, fn () => $add);
        $agent = new DSAManagementAgent;
        foreach ([['spend_guardrails' => ['enabled' => true, 'max_daily_budget_micros' => 10_000_000]],
            ['spend_guardrails' => null, 'spend_safety_hold' => ['reason' => 'spend_cap']]] as $changes) {
            $campaign->forceFill($changes)->save();
            $result = $agent->promoteHighPerformingTerms($campaign->customer);
            $this->assertSame(0, $result['promoted']);
            $this->assertTrue($result['requires_review']);
        }
        $this->assertDatabaseHas('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'dsa_keyword_promotion_requires_review']);
    }

    public function test_existing_dsa_campaign_from_another_account_never_produces_promotions(): void
    {
        $campaign = $this->campaign();
        AgentActivity::record('dsa', 'dsa_campaign_setup', 'Wrong account resource', $campaign->customer_id, null,
            ['dsa_campaign_resource' => 'customers/9999999999/campaigns/74']);
        $source = $this->createMock(GetSearchTermsReport::class);
        $source->expects($this->never())->method('__invoke');
        $this->app->bind(GetSearchTermsReport::class, fn () => $source);
        $result = (new DSAManagementAgent)->promoteHighPerformingTerms($campaign->customer);

        $this->assertSame(0, $result['promoted']);
        $this->assertTrue($result['requires_review']);
    }

    public function test_dsa_promotions_do_not_modify_setup_only_or_inactive_destinations(): void
    {
        $campaign = $this->campaign(['status' => 'paused']);
        $this->dsaFixture($campaign);
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $guard->expects($this->never())->method('assess');
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $agent = new DSAManagementAgent;
        $result = $agent->promoteHighPerformingTerms($campaign->customer);
        $this->assertSame(0, $result['promoted']);

        $campaign->customer->forceFill(['service_type' => 'setup_only'])->save();
        $result = $agent->promoteHighPerformingTerms($campaign->customer);
        $this->assertSame(0, $result['promoted']);
        $this->assertTrue($result['requires_review']);
        Http::assertNothingSent();
    }
}

class SearchKeywordGuardFactory extends CustomerRoutedAdsServiceFactory
{
    public SearchKeywordGuardMutator $mutator;

    public function __construct(private array $terms)
    {
        $this->mutator = new SearchKeywordGuardMutator;
    }

    public function searchTerms(Customer $customer): SearchTermSource
    {
        return new class($this->terms) implements SearchTermSource
        {
            public function __construct(private array $terms) {}

            public function __invoke(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): array
            {
                return $this->terms;
            }
        };
    }

    public function keywords(Customer $customer): KeywordMutator
    {
        return $this->mutator;
    }
}

class SearchKeywordGuardMutator implements KeywordMutator
{
    public array $writes = [];

    public function addKeyword(string $customerId, string $adGroupResourceName, string $keyword, int $matchType): ?string
    {
        $this->writes[] = ['action' => 'add', 'text' => $keyword, 'match_type' => $matchType];

        return 'customers/1234567890/adGroupCriteria/7~'.(count($this->writes) + 100);
    }

    public function addNegativeKeyword(string $customerId, string $campaignResourceName, string $keyword, int $matchType): ?string
    {
        $this->writes[] = ['action' => 'negative', 'text' => $keyword, 'match_type' => $matchType];

        return 'customers/1234567890/campaignCriteria/61~101';
    }
}
