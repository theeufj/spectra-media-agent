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
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use Google\Ads\GoogleAds\V26\Enums\KeywordMatchTypeEnum\KeywordMatchType;
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
        $this->existingKeywords([]);
    }

    private function campaign(array $changes = []): Campaign
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890',
            'conversion_tracking_verified_at' => now(), 'is_sandbox' => false]);

        return Campaign::factory()->create(array_replace(['customer_id' => $customer->id,
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/61', 'status' => 'active',
            'daily_budget' => '10.00', 'approved_daily_budget' => '10.00', 'budget_confirmed_at' => now()], $changes));
    }

    private function existingKeywords(array $keywords): void
    {
        $inspector = $this->createMock(InspectSearchDelivery::class);
        $inspector->method('inspect')->willReturn(['keywords' => $keywords]);
        $this->app->bind(InspectSearchDelivery::class, fn () => $inspector);
    }

    private function keyword(array $changes = []): array
    {
        return array_replace(['text' => ' GOOGLE ADS MANAGEMENT SOFTWARE ', 'match_type' => 'EXACT',
            'ad_group_resource' => 'customers/1234567890/adGroups/7',
            'resource_name' => 'customers/1234567890/adGroupCriteria/7~10',
            'status' => 'ENABLED', 'ad_group_status' => 'ENABLED'], $changes);
    }

    private function guard(Campaign $campaign, bool $ready, int $changes, string|array $action = 'add', bool $recheckNegatives = false): void
    {
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $call = 0;
        $method = $action === 'negative' && ! $recheckNegatives ? 'assessCostExclusions' : 'assess';
        $guard->expects($this->exactly($recheckNegatives ? 2 : 1))->method($method)->willReturnCallback(function ($actual, array $proposals) use ($campaign, $ready, $changes, $action, &$call): array {
            $this->assertSame($campaign->id, $actual->id);
            if (++$call > 1) {
                $this->assertSame(['negative'], array_column($proposals, 'action'), 'Exclusions must be checked against the actual installed replacement portfolio.');
            } elseif (is_array($action)) {
                $this->assertCount($changes, $proposals, 'The resulting portfolio must be assessed once as a batch.');
                $this->assertSame($action, array_column($proposals, 'action'));
            } else {
                $this->assertCount($changes, $proposals, 'The resulting portfolio must be assessed once as a batch.');
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
        return array_map(fn ($term) => $term + ['matured' => true, 'account_timezone' => 'Australia/Sydney',
            'matured_through' => now('Australia/Sydney')->startOfDay()->subDays(3)->toDateString()], [
                ['search_term' => 'google ads management software', 'impressions' => 1000, 'clicks' => 70,
                    'cost' => 10.0, 'conversions' => 12.0, 'ctr' => 0.07, 'ad_group_resource_name' => 'customers/1234567890/adGroups/7'],
                ['search_term' => 'affordable google ads management', 'impressions' => 1000, 'clicks' => 80,
                    'cost' => 10.0, 'conversions' => 6.0, 'ctr' => 0.08, 'ad_group_resource_name' => 'customers/1234567890/adGroups/7'],
                ['search_term' => 'free enterprise consulting jobs', 'impressions' => 1000, 'clicks' => 20,
                    'cost' => 100.0, 'conversions' => 0.0, 'ctr' => 0.02, 'ad_group_resource_name' => 'customers/1234567890/adGroups/7'],
            ]);
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

    public function test_sparse_matured_spend_uses_the_confirmed_budget_threshold_and_exact_exclusions_after_reach_verification(): void
    {
        $campaign = $this->campaign(['approved_daily_budget' => '50.00', 'daily_budget' => '50.00']);
        $term = array_replace($this->terms()[2], ['impressions' => 20, 'clicks' => 2, 'cost' => 30.0, 'ctr' => 0.1]);
        $this->guard($campaign, true, 1, 'negative');
        $factory = new SearchKeywordGuardFactory([$term]);

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertCount(1, $result['negatives_added']);
        $this->assertSame(KeywordMatchType::EXACT, $factory->mutator->writes[0]['match_type']);
    }

    public function test_zero_cost_low_ctr_query_does_not_block_a_proven_cost_exclusion_batch(): void
    {
        $campaign = $this->campaign(['approved_daily_budget' => '50.00', 'daily_budget' => '50.00']);
        $expensive = array_replace($this->terms()[2], ['impressions' => 20, 'clicks' => 2, 'cost' => 30.0, 'ctr' => 0.1]);
        $lowCtr = array_replace($this->terms()[2], ['search_term' => 'what are local ads',
            'impressions' => 600, 'clicks' => 0, 'cost' => 0.0, 'ctr' => 0.0]);
        $this->guard($campaign, true, 1, 'negative');
        $factory = new SearchKeywordGuardFactory([$expensive, $lowCtr]);

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertCount(1, $result['negatives_added']);
        $this->assertSame('free enterprise consulting jobs', $factory->mutator->writes[0]['text']);
        $this->assertSame('what are local ads', $result['review_candidates'][0]['search_term']);
        $this->assertArrayNotHasKey('requires_review', $result);
    }

    public function test_new_conversion_in_another_group_protects_the_same_search_text_from_campaign_wide_exclusion(): void
    {
        $campaign = $this->campaign();
        $waste = $this->terms()[2];
        $newConversion = array_replace($waste, ['impressions' => 1, 'clicks' => 1, 'cost' => 1,
            'conversions' => 0.5, 'ad_group_resource_name' => 'customers/1234567890/adGroups/8']);
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $guard->expects($this->never())->method('assess');
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $factory = new SearchKeywordGuardFactory([$waste, $newConversion], [$waste]);

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertSame([], $result['negatives_added']);
        $this->assertSame([], $factory->mutator->writes);
    }

    public function test_secondary_conversion_today_protects_older_zero_conversion_spend(): void
    {
        $campaign = $this->campaign();
        $matured = $this->terms()[2];
        $recent = array_replace($matured, ['all_conversions' => 1]);
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $guard->expects($this->never())->method('assess');
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $factory = new SearchKeywordGuardFactory([$recent], [$matured]);

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertSame([], $result['negatives_added']);
        $this->assertSame([], $factory->mutator->writes);
    }

    public function test_recent_expensive_traffic_without_matured_evidence_cannot_create_negative_proposals(): void
    {
        $campaign = $this->campaign();
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $guard->expects($this->never())->method('assess');
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $factory = new SearchKeywordGuardFactory([$this->terms()[2]], []);

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertSame([], $result['negatives_added']);
        $this->assertSame([], $factory->mutator->writes);
    }

    public function test_a_repeated_mining_run_does_not_resend_a_verified_campaign_exclusion(): void
    {
        $campaign = $this->campaign();
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $guard->expects($this->once())->method('assessCostExclusions')->willReturn(['ready' => true,
            'observed_snapshot' => ['negative_keywords' => [['scope' => 'campaign',
                'text' => 'FREE ENTERPRISE CONSULTING JOBS', 'match_type' => 'EXACT']]]]);
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $factory = new SearchKeywordGuardFactory([$this->terms()[2]]);

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertSame([], $result['negatives_added']);
        $this->assertSame([], $factory->mutator->writes);
        $this->assertArrayNotHasKey('requires_review', $result);
    }

    public function test_a_negative_in_a_different_group_does_not_hide_a_campaign_exclusion_proposal(): void
    {
        $campaign = $this->campaign();
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $guard->expects($this->once())->method('assessCostExclusions')->willReturn(['ready' => true,
            'observed_snapshot' => ['negative_keywords' => [['scope' => 'ad_group',
                'ad_group_resource' => 'customers/1234567890/adGroups/8',
                'text' => 'free enterprise consulting jobs', 'match_type' => 'EXACT']]]]);
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $factory = new SearchKeywordGuardFactory([$this->terms()[2]]);

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertCount(1, $result['negatives_added']);
        $this->assertCount(1, $factory->mutator->writes);
    }

    public function test_an_existing_campaign_phrase_exclusion_already_covers_its_observed_exact_query(): void
    {
        $campaign = $this->campaign();
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $guard->expects($this->once())->method('assessCostExclusions')->willReturn(['ready' => true,
            'observed_snapshot' => ['negative_keywords' => [['scope' => 'campaign',
                'text' => 'enterprise consulting', 'match_type' => 'PHRASE']]]]);
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $factory = new SearchKeywordGuardFactory([$this->terms()[2]]);

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertSame([], $result['negatives_added']);
        $this->assertSame([], $factory->mutator->writes);
        $this->assertArrayNotHasKey('requires_review', $result);
    }

    public function test_one_converted_query_is_promoted_as_exact_instead_of_broad(): void
    {
        $campaign = $this->campaign();
        $this->guard($campaign, true, 1);
        $factory = new SearchKeywordGuardFactory([array_replace($this->terms()[0], ['conversions' => 1])]);

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertCount(1, $result['keywords_added']);
        $this->assertSame(KeywordMatchType::EXACT, $factory->mutator->writes[0]['match_type']);
    }

    public function test_mining_safety_negatives_can_proceed_when_remaining_traffic_is_verified(): void
    {
        $campaign = $this->campaign();
        $this->guard($campaign, true, 3, ['add', 'add', 'negative'], true);
        $factory = new SearchKeywordGuardFactory($this->terms());

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertCount(2, $result['keywords_added']);
        $this->assertCount(1, $result['negatives_added']);
        $this->assertSame(['add', 'add', 'negative'], array_column($factory->mutator->writes, 'action'));
    }

    public function test_mining_skips_an_existing_converting_term_and_preserves_new_terms_and_waste_exclusions(): void
    {
        $campaign = $this->campaign();
        $this->existingKeywords([$this->keyword()]);
        $this->guard($campaign, true, 2, ['add', 'negative'], true);
        $factory = new SearchKeywordGuardFactory($this->terms());

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertSame(['affordable google ads management'], array_column($result['keywords_added'], 'keyword'));
        $this->assertSame(['free enterprise consulting jobs'], array_column($result['negatives_added'], 'keyword'));
        $this->assertSame(['add', 'negative'], array_column($factory->mutator->writes, 'action'));
    }

    public function test_mining_preserves_an_intentionally_paused_duplicate_without_enabling_it(): void
    {
        $campaign = $this->campaign();
        $this->existingKeywords([$this->keyword(['status' => 'PAUSED'])]);
        $this->guard($campaign, true, 2, ['add', 'negative'], true);
        $factory = new SearchKeywordGuardFactory($this->terms());

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertCount(1, $result['keywords_added']);
        $this->assertNotContains('google ads management software', array_column($factory->mutator->writes, 'text'));
    }

    public function test_mining_does_not_conflate_foreign_other_group_removed_or_other_match_keywords(): void
    {
        $campaign = $this->campaign();
        $this->existingKeywords([
            $this->keyword(['ad_group_resource' => 'customers/9999999999/adGroups/7', 'resource_name' => 'customers/9999999999/adGroupCriteria/7~10']),
            $this->keyword(['resource_name' => 'customers/9999999999/adGroupCriteria/7~10']),
            $this->keyword(['ad_group_resource' => 'customers/1234567890/adGroups/8', 'resource_name' => 'customers/1234567890/adGroupCriteria/8~10']),
            $this->keyword(['status' => 'REMOVED']), $this->keyword(['match_type' => 'PHRASE']),
        ]);
        $this->guard($campaign, true, 2);
        $factory = new SearchKeywordGuardFactory(array_slice($this->terms(), 0, 2));

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertCount(2, $result['keywords_added']);
    }

    public function test_mining_holds_all_writes_when_existing_keyword_inspection_is_unknown(): void
    {
        $campaign = $this->campaign();
        $inspector = $this->createMock(InspectSearchDelivery::class);
        $inspector->method('inspect')->willReturn(null);
        $this->app->bind(InspectSearchDelivery::class, fn () => $inspector);
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $guard->expects($this->never())->method('assess');
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $factory = new SearchKeywordGuardFactory($this->terms());

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertTrue($result['requires_review']);
        $this->assertSame([], $factory->mutator->writes);
    }

    public function test_a_failed_addition_holds_exclusions_and_records_the_actual_partial_portfolio(): void
    {
        $campaign = $this->campaign(['search_delivery_state' => ['other_agent' => ['preserved' => true]]]);
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $call = 0;
        $guard->expects($this->exactly(2))->method('assess')->willReturnCallback(function ($actual, array $changes) use (&$call): array {
            if (++$call === 1) {
                $this->assertSame(['negative', 'add', 'add'], array_column($changes, 'action'));

                return ['ready' => true];
            }
            $this->assertSame([], $changes, 'Read back the actual installed portfolio; do not forecast unapplied exclusions.');

            return ['ready' => false, 'message' => 'Only one addition was installed.'];
        });
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $terms = $this->terms();
        $factory = new SearchKeywordGuardFactory([$terms[2], $terms[0], $terms[1]]);
        $factory->mutator->failAt = 2;

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertTrue($result['requires_review']);
        $this->assertCount(1, $result['keywords_added']);
        $this->assertSame([], $result['negatives_added']);
        $this->assertSame(['add', 'add'], array_column($factory->mutator->writes, 'action'));
        $state = $campaign->fresh()->search_delivery_state;
        $this->assertSame(['preserved' => true], $state['other_agent']);
        $this->assertSame('needs_review', $state['status']);
        $this->assertFalse($state['mutation_allowed']);
        $this->assertSame('keyword_batch_partial_apply', $state['verification']['errors'][0]['code']);
        $this->assertCount(1, $state['verification']['errors'][0]['applied_keywords']);
        $this->assertFalse($state['verification']['errors'][0]['actual_portfolio_assessment']['ready']);
        $this->assertDatabaseHas('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'keyword_changes_need_review', 'status' => 'needs_review']);
    }

    public function test_exclusions_are_held_when_successful_additions_do_not_pass_actual_portfolio_recheck(): void
    {
        $campaign = $this->campaign();
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $call = 0;
        $guard->expects($this->exactly(2))->method('assess')->willReturnCallback(function ($actual, array $changes) use (&$call): array {
            if (++$call === 1) {
                return ['ready' => true];
            }
            $this->assertSame(['negative'], array_column($changes, 'action'));

            return ['ready' => false, 'message' => 'Live additions are not verified.'];
        });
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $factory = new SearchKeywordGuardFactory($this->terms());

        $result = (new SearchTermMiningAgent($factory))->mine($campaign);

        $this->assertTrue($result['requires_review']);
        $this->assertCount(2, $result['keywords_added']);
        $this->assertSame([], $result['negatives_added']);
        $this->assertCount(2, $factory->mutator->writes);
        $this->assertSame('keyword_batch_partial_apply', $campaign->fresh()->search_delivery_state['verification']['errors'][0]['code']);
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

        $this->app->instance(GeminiService::class, $gemini);

        return app(QualityScoreImprovementAgent::class);
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

    private function dsaFixture(Campaign $campaign, int $reads = 1, array $extraTerms = []): void
    {
        $campaign->forceFill(['primary_status' => 'ELIGIBLE'])->save();
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'google_ads_ad_group_id' => 'customers/1234567890/adGroups/7']);
        AgentActivity::record('dsa', 'dsa_campaign_setup', 'Existing DSA campaign', $campaign->customer_id, null,
            ['dsa_campaign_resource' => 'customers/1234567890/campaigns/74']);
        $source = $this->createMock(GetSearchTermsReport::class);
        $source->expects($this->exactly($reads))->method('__invoke')
            ->with('1234567890', 'customers/1234567890/campaigns/74', 'LAST_30_DAYS')
            ->willReturn([...array_map(fn ($term) => $term + ['campaign_resource_name' => 'customers/1234567890/campaigns/74'], $this->terms()), ...$extraTerms,
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

    public function test_dsa_skips_existing_paused_identity_without_blocking_a_new_converting_term(): void
    {
        $campaign = $this->campaign();
        $this->dsaFixture($campaign);
        $this->existingKeywords([$this->keyword(['match_type' => 'PHRASE', 'status' => 'PAUSED'])]);
        $this->guard($campaign, true, 1);
        $add = $this->createMock(AddKeyword::class);
        $add->expects($this->once())->method('__invoke')->with('1234567890', 'customers/1234567890/adGroups/7',
            'affordable google ads management', KeywordMatchType::PHRASE)->willReturn('customers/1234567890/adGroupCriteria/7~11');
        $this->app->bind(AddKeyword::class, fn () => $add);

        $result = (new DSAManagementAgent)->promoteHighPerformingTerms($campaign->customer);

        $this->assertSame(1, $result['promoted']);
        $this->assertFalse($result['requires_review']);
        $this->assertSame(['affordable google ads management'], array_column($result['terms'], 'keyword'));
    }

    public function test_dsa_does_not_dedupe_against_foreign_removed_other_group_or_other_match_identities(): void
    {
        $campaign = $this->campaign();
        $this->dsaFixture($campaign);
        $this->existingKeywords([
            $this->keyword(['match_type' => 'PHRASE', 'ad_group_resource' => 'customers/9999999999/adGroups/7',
                'resource_name' => 'customers/9999999999/adGroupCriteria/7~10']),
            $this->keyword(['match_type' => 'PHRASE', 'resource_name' => 'customers/9999999999/adGroupCriteria/7~10']),
            $this->keyword(['match_type' => 'PHRASE', 'ad_group_resource' => 'customers/1234567890/adGroups/8',
                'resource_name' => 'customers/1234567890/adGroupCriteria/8~10']),
            $this->keyword(['match_type' => 'PHRASE', 'status' => 'REMOVED']), $this->keyword(),
        ]);
        $this->guard($campaign, true, 2);
        $add = $this->createMock(AddKeyword::class);
        $add->expects($this->exactly(2))->method('__invoke')->willReturn('customers/1234567890/adGroupCriteria/7~11');
        $this->app->bind(AddKeyword::class, fn () => $add);

        $result = (new DSAManagementAgent)->promoteHighPerformingTerms($campaign->customer);

        $this->assertSame(2, $result['promoted']);
        $this->assertFalse($result['requires_review']);
    }

    public function test_dsa_partial_promotion_stops_later_additions_and_records_a_reviewable_actual_outcome(): void
    {
        $campaign = $this->campaign(['search_delivery_state' => ['other_agent' => ['preserved' => true]]]);
        $this->dsaFixture($campaign, 1, [['search_term' => 'ppc campaign management service', 'clicks' => 10,
            'conversions' => 2, 'campaign_resource_name' => 'customers/1234567890/campaigns/74']]);
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $call = 0;
        $guard->expects($this->exactly(2))->method('assess')->willReturnCallback(function ($actual, array $changes) use (&$call): array {
            if (++$call === 1) {
                $this->assertCount(3, $changes);

                return ['ready' => true];
            }
            $this->assertSame([], $changes);

            return ['ready' => false, 'message' => 'The partial live portfolio is not verified.'];
        });
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $add = $this->createMock(AddKeyword::class);
        $add->expects($this->exactly(2))->method('__invoke')->willReturnOnConsecutiveCalls('customers/1234567890/adGroupCriteria/7~11', null);
        $this->app->bind(AddKeyword::class, fn () => $add);

        $result = (new DSAManagementAgent)->promoteHighPerformingTerms($campaign->customer);

        $this->assertSame(1, $result['promoted']);
        $this->assertTrue($result['requires_review']);
        $this->assertCount(1, Cache::get('dsa_promoted:'.$campaign->customer_id));
        $state = $campaign->fresh()->search_delivery_state;
        $this->assertSame(['preserved' => true], $state['other_agent']);
        $this->assertSame('needs_review', $state['status']);
        $this->assertSame('keyword_batch_partial_apply', $state['verification']['errors'][0]['code']);
        $this->assertCount(1, $state['verification']['errors'][0]['applied_keywords']);
        $this->assertDatabaseHas('agent_activities', ['customer_id' => $campaign->customer_id, 'action' => 'dsa_terms_promoted', 'status' => 'needs_review']);
        $this->assertDatabaseMissing('agent_activities', ['customer_id' => $campaign->customer_id, 'action' => 'dsa_terms_promoted', 'status' => 'completed']);
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

    public function __construct(private array $terms, private ?array $maturedTerms = null)
    {
        $this->mutator = new SearchKeywordGuardMutator;
    }

    public function searchTerms(Customer $customer): SearchTermSource
    {
        return new class($this->terms, $this->maturedTerms) implements SearchTermSource
        {
            public function __construct(private array $terms, private ?array $maturedTerms) {}

            public function __invoke(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): array
            {
                return $dateRange === 'MATURED_LAST_30_DAYS' ? ($this->maturedTerms ?? $this->terms) : $this->terms;
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

    public ?int $failAt = null;

    public function addKeyword(string $customerId, string $adGroupResourceName, string $keyword, int $matchType): ?string
    {
        $this->writes[] = ['action' => 'add', 'text' => $keyword, 'match_type' => $matchType];
        if (count($this->writes) === $this->failAt) {
            return null;
        }

        return 'customers/1234567890/adGroupCriteria/7~'.(count($this->writes) + 100);
    }

    public function addNegativeKeyword(string $customerId, string $campaignResourceName, string $keyword, int $matchType): ?string
    {
        $this->writes[] = ['action' => 'negative', 'text' => $keyword, 'match_type' => $matchType];

        return 'customers/1234567890/campaignCriteria/61~101';
    }
}
