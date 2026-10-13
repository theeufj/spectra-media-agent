<?php

namespace Tests\Feature;

use App\Jobs\FindUnderperformingKeywords;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\NegativeKeywordList;
use App\Services\Agents\GoogleSearchMutationGuard;
use App\Services\GoogleAds\CommonServices\AddNegativeKeyword;
use App\Services\GoogleAds\NegativeKeywords\AddNegativeKeywordService;
use Google\Ads\GoogleAds\V22\Common\KeywordInfo;
use Google\Ads\GoogleAds\V22\Common\Metrics;
use Google\Ads\GoogleAds\V22\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutomaticNegativeKeywordReachGuardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function campaign(array $changes = []): Campaign
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890',
            'service_type' => 'managed', 'is_sandbox' => false]);

        return Campaign::factory()->create(array_replace(['customer_id' => $customer->id,
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/61', 'status' => 'active',
            'daily_budget' => '10.00', 'approved_daily_budget' => '10.00', 'budget_confirmed_at' => now()], $changes));
    }

    private function guard(bool $ready, int $count = 2): void
    {
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $guard->expects($this->once())->method('assess')->willReturnCallback(function ($campaign, array $changes) use ($ready, $count): array {
            $this->assertCount($count, $changes);
            foreach ($changes as $change) {
                $this->assertSame('negative', $change['action']);
                $this->assertSame('EXACT', $change['match_type']);
                $this->assertSame('campaign', $change['scope']);
            }

            return ['ready' => $ready, 'message' => 'Remaining affordable reach is unknown.'];
        });
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
    }

    private function noWriter(): void
    {
        $writer = $this->createMock(AddNegativeKeyword::class);
        $writer->expects($this->never())->method('__invoke');
        $this->app->bind(AddNegativeKeyword::class, fn () => $writer);
    }

    public function test_unknown_whole_portfolio_reach_holds_all_exclusions_and_local_records(): void
    {
        $campaign = $this->campaign();
        $this->guard(false);
        $this->noWriter();

        $result = (new AddNegativeKeywordService)->applyBatch($campaign->id, ['free jobs', 'enterprise consulting']);

        $this->assertTrue($result['requires_review']);
        $this->assertSame([], $result['applied']);
        $this->assertDatabaseMissing('negative_keyword_lists', ['customer_id' => $campaign->customer_id, 'name' => 'Auto-excluded']);
        $this->assertDatabaseHas('agent_activities', ['campaign_id' => $campaign->id,
            'action' => 'keyword_exclusions_need_review', 'status' => 'needs_review']);
        Http::assertNothingSent();
    }

    public function test_verified_exclusions_are_assessed_once_as_a_batch_and_only_confirmed_results_are_recorded(): void
    {
        $campaign = $this->campaign();
        $this->guard(true);
        $writer = $this->createMock(AddNegativeKeyword::class);
        $writer->expects($this->exactly(2))->method('__invoke')
            ->with('1234567890', 'customers/1234567890/campaigns/61', $this->anything(), 2)
            ->willReturnOnConsecutiveCalls('customers/1234567890/campaignCriteria/61~10', 'customers/1234567890/campaignCriteria/61~11');
        $this->app->bind(AddNegativeKeyword::class, fn () => $writer);

        $result = (new AddNegativeKeywordService)->applyBatch($campaign->id, ['free jobs', 'enterprise consulting', ' FREE JOBS ']);

        $this->assertFalse($result['requires_review']);
        $this->assertCount(2, $result['applied']);
        $list = NegativeKeywordList::where('customer_id', $campaign->customer_id)->where('name', 'Auto-excluded')->firstOrFail();
        $this->assertSame(['FREE JOBS', 'enterprise consulting'], $list->keywords);
        $this->assertSame([$campaign->id], $list->applied_to_campaigns);
    }

    public function test_a_failed_google_exclusion_holds_the_rest_and_does_not_persist_false_local_success(): void
    {
        $campaign = $this->campaign(['search_delivery_state' => ['other_agent' => ['preserved' => true]]]);
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $guard->expects($this->exactly(2))->method('assess')->willReturnOnConsecutiveCalls(
            ['ready' => true], ['ready' => false, 'message' => 'The actual portfolio is unknown.']);
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $writer = $this->createMock(AddNegativeKeyword::class);
        $writer->expects($this->once())->method('__invoke')->willReturn(null);
        $this->app->bind(AddNegativeKeyword::class, fn () => $writer);

        $result = (new AddNegativeKeywordService)->applyBatch($campaign->id, ['free jobs', 'enterprise consulting']);

        $this->assertTrue($result['requires_review']);
        $this->assertSame([], $result['applied']);
        $this->assertDatabaseMissing('negative_keyword_lists', ['customer_id' => $campaign->customer_id]);
        $state = $campaign->fresh()->search_delivery_state;
        $this->assertSame(['preserved' => true], $state['other_agent']);
        $this->assertSame('needs_review', $state['status']);
        $this->assertSame('negative_keyword_batch_unverified', $state['verification']['errors'][0]['code']);
    }

    public function test_partial_exclusions_preserve_only_confirmed_local_records_and_remain_under_review(): void
    {
        $campaign = $this->campaign();
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $guard->expects($this->exactly(2))->method('assess')->willReturnOnConsecutiveCalls(['ready' => true], ['ready' => true]);
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $writer = $this->createMock(AddNegativeKeyword::class);
        $writer->expects($this->exactly(2))->method('__invoke')
            ->willReturnOnConsecutiveCalls('customers/1234567890/campaignCriteria/61~10', null);
        $this->app->bind(AddNegativeKeyword::class, fn () => $writer);

        $result = (new AddNegativeKeywordService)->applyBatch($campaign->id, ['free jobs', 'enterprise consulting', 'training']);

        $this->assertTrue($result['requires_review']);
        $this->assertCount(1, $result['applied']);
        $this->assertSame(['free jobs'], NegativeKeywordList::where('customer_id', $campaign->customer_id)->firstOrFail()->keywords);
        $this->assertCount(1, $campaign->fresh()->search_delivery_state['verification']['errors'][0]['applied']);
    }

    public function test_direct_single_keyword_calls_cannot_bypass_the_portfolio_guard(): void
    {
        $campaign = $this->campaign();
        $this->guard(false, 1);
        $this->noWriter();

        (new AddNegativeKeywordService)($campaign->id, 'free jobs');

        $this->assertDatabaseMissing('negative_keyword_lists', ['customer_id' => $campaign->customer_id]);
    }

    public function test_owner_pause_during_research_blocks_api_and_local_writes(): void
    {
        $campaign = $this->campaign();
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $guard->expects($this->once())->method('assess')->willReturnCallback(function () use ($campaign): array {
            $campaign->fresh()->update(['status' => 'paused']);

            return ['ready' => true];
        });
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $this->noWriter();

        $result = (new AddNegativeKeywordService)->applyBatch($campaign->id, ['free jobs']);

        $this->assertTrue($result['requires_review']);
        $this->assertDatabaseMissing('negative_keyword_lists', ['customer_id' => $campaign->customer_id]);
    }

    public function test_trials_holds_pauses_and_unresolved_repairs_never_reach_forecasts_or_api_writes(): void
    {
        $campaign = $this->campaign();
        $guard = $this->createMock(GoogleSearchMutationGuard::class);
        $guard->expects($this->never())->method('assess');
        $this->app->instance(GoogleSearchMutationGuard::class, $guard);
        $this->noWriter();
        foreach ([['status' => 'paused'], ['spend_safety_hold' => ['reason' => 'owner review']],
            ['spend_guardrails' => ['enabled' => true]], ['search_delivery_state' => ['verification' => ['errors' => [['code' => 'partial']]]]]] as $change) {
            $campaign->forceFill(array_replace(['status' => 'active', 'spend_safety_hold' => null,
                'spend_guardrails' => null, 'search_delivery_state' => null], $change))->save();

            $result = (new AddNegativeKeywordService)->applyBatch($campaign->id, ['free jobs']);

            $this->assertTrue($result['requires_review']);
            $this->assertSame([], $result['applied']);
        }
        $this->assertDatabaseMissing('negative_keyword_lists', ['customer_id' => $campaign->customer_id]);
    }

    private function row(string $text, int $clicks, float $conversions): GoogleAdsRow
    {
        return new GoogleAdsRow(['ad_group_criterion' => new AdGroupCriterion(['keyword' => new KeywordInfo(['text' => $text])]),
            'metrics' => new Metrics(['clicks' => $clicks, 'conversions' => $conversions])]);
    }

    public function test_job_batches_candidates_and_preserves_any_converting_instance_of_the_same_text(): void
    {
        $campaign = $this->campaign();
        $rows = [$this->row('converting software', 150, 0), $this->row(' CONVERTING SOFTWARE ', 10, 0.5),
            $this->row('free jobs', 101, 0), $this->row('consulting', 60, 0), $this->row('consulting', 60, 0)];
        $job = new NegativeKeywordRowsJob($campaign->id, $rows);
        $service = $this->createMock(AddNegativeKeywordService::class);
        $service->expects($this->once())->method('applyBatch')->with($campaign->id, ['free jobs', 'consulting'])
            ->willReturn(['applied' => [], 'requires_review' => true]);

        $job->handle($service);

        $this->assertSame(1, $job->reads);
        $this->assertStringContainsString('segments.date DURING LAST_30_DAYS', $job->query);
        $this->assertStringNotContainsString('metrics.conversions = 0', $job->query);
        $this->assertStringContainsString('campaign.advertising_channel_type = SEARCH', $job->query);
    }

    public function test_job_with_unresolved_reach_outcome_does_not_read_or_write_google(): void
    {
        $campaign = $this->campaign(['search_delivery_state' => ['repair' => ['errors' => [['code' => 'partial']]]]]);
        $job = new NegativeKeywordRowsJob($campaign->id, []);
        $service = $this->createMock(AddNegativeKeywordService::class);
        $service->expects($this->never())->method('applyBatch');

        $job->handle($service);

        $this->assertSame(0, $job->reads);
        $this->assertTrue(AgentActivity::where('campaign_id', $campaign->id)->where('action', 'keyword_exclusions_need_review')->exists());
        Http::assertNothingSent();
    }
}

class NegativeKeywordRowsJob extends FindUnderperformingKeywords
{
    public int $reads = 0;

    public string $query = '';

    public function __construct(int $campaignId, private array $rows)
    {
        parent::__construct($campaignId);
    }

    protected function keywordRows(Campaign $campaign, string $query): iterable
    {
        $this->reads++;
        $this->query = $query;

        return $this->rows;
    }
}
