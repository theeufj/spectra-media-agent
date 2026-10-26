<?php

namespace Tests\Feature;

use App\Features\AutoHealing;
use App\Jobs\CheckGoogleSearchDelivery;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\Google\SearchKeywordBuilder;
use App\Services\Agents\GoogleSearchCostProtection;
use App\Services\Agents\GoogleSearchReachRecovery;
use App\Services\Agents\SearchTermMiningAgent;
use App\Services\GoogleAds\CommonServices\AddAdGroupCriterion;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SearchDeliveryStateConcurrencyTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(): Campaign
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890', 'service_type' => 'managed', 'is_sandbox' => false]);
        Feature::for($customer)->activate(AutoHealing::class);

        return Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'active',
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/61', 'end_date' => now()->addMonth(),
            'search_delivery_state' => ['ai_max_request' => ['enabled' => false, 'nonce' => 'old'],
                'ai_max_setup' => ['phase' => 'old'], 'ai_max_monitor' => ['state' => 'old'],
                'market_research' => ['revision' => 'old'], 'other_writer' => ['revision' => 'old']]])->load('customer');
    }

    public static function ownershipModes(): array
    {
        return [['pending'], ['pilot']];
    }

    private function currentNamespaces(): array
    {
        return ['ai_max_request' => ['enabled' => true, 'nonce' => 'new-owner-request', 'status' => 'goals_pending'],
            'ai_max_setup' => ['phase' => 'prepared', 'mutation_started_at' => 'durable'],
            'ai_max_monitor' => ['state' => 'verified'], 'market_research' => ['revision' => 'new'],
            'other_writer' => ['revision' => 'new']];
    }

    #[DataProvider('ownershipModes')]
    public function test_hourly_observation_cannot_restore_stale_namespaces_or_legacy_ownership(string $mode): void
    {
        $campaign = $this->campaign();
        $stale = $campaign->search_delivery_state;
        $current = $this->currentNamespaces();
        $cost = $this->createMock(GoogleSearchCostProtection::class);
        $cost->method('check')->willReturn(['status' => 'not_applicable', 'actions_taken' => 0]);
        $this->app->instance(GoogleSearchCostProtection::class, $cost);
        $recovery = $this->createMock(GoogleSearchReachRecovery::class);
        $recovery->method('check')->willReturnCallback(function () use ($campaign, $mode, $current, $stale): array {
            $campaign->fresh()->update(['search_delivery_state' => $current,
                'spend_guardrails' => $mode === 'pilot' ? ['ai_max_pilot' => ['enabled' => true]] : null]);

            return [...$stale, 'status' => 'delivering', 'mutation_allowed' => true];
        });

        (new CheckGoogleSearchDelivery($campaign->id))->handle($recovery);

        $saved = $campaign->fresh()->search_delivery_state;
        foreach ($current as $key => $value) {
            $this->assertEquals($value, $saved[$key], $key);
        }
        $this->assertSame($mode === 'pilot' ? 'native_automation' : 'pending_native_automation', $saved['status']);
        $this->assertFalse($saved['mutation_allowed']);
    }

    public function test_preflight_retry_merges_only_its_assessment_into_the_locked_current_state(): void
    {
        $campaign = $this->campaign();
        $current = $this->currentNamespaces();
        $current['ai_max_request']['enabled'] = false;
        $current['repair'] = ['errors' => [['code' => 'readback_unverified', 'message' => 'Needs review']]];
        $campaign->fresh()->update(['search_delivery_state' => $current]);
        $builder = new SearchKeywordBuilder($campaign->customer);
        (new \ReflectionMethod($builder, 'savePreflightState'))->invoke($builder, $campaign,
            ['status' => 'collecting_evidence', 'checked_at' => now()->toIso8601String()]);

        $saved = $campaign->fresh()->search_delivery_state;
        foreach ($current as $key => $value) {
            $this->assertEquals($value, $saved[$key], $key);
        }
        $this->assertSame('needs_review', $saved['status']);
        $this->assertNull($saved['measurement']);
    }

    #[DataProvider('ownershipModes')]
    public function test_preflight_retry_and_keyword_deployment_stop_when_an_ai_max_owner_takes_over(string $mode): void
    {
        $campaign = $this->campaign();
        $current = $this->currentNamespaces();
        $campaign->fresh()->update(['search_delivery_state' => $current,
            'spend_guardrails' => $mode === 'pilot' ? ['ai_max_pilot' => ['enabled' => true]] : null]);
        $builder = new SearchKeywordBuilder($campaign->customer);
        $this->assertFalse((new \ReflectionMethod($builder, 'savePreflightState'))->invoke($builder, $campaign,
            ['status' => 'collecting_evidence']));
        $writer = $this->createMock(AddAdGroupCriterion::class);
        $writer->expects($this->never())->method('__invoke');
        $this->app->bind(AddAdGroupCriterion::class, fn () => $writer);
        $result = ExecutionResult::success();
        $builder->addKeywords('1234567890', 'customers/1234567890/adGroups/7', [['text' => 'google ads setup']], $result, $campaign);

        $this->assertFalse($result->success);
        $this->assertSame('search_keyword_native_automation_owned', $result->errors[0]->code);
        $preflight = ExecutionResult::success();
        $this->assertFalse($builder->preflightReach($campaign, new Strategy, '1234567890', 'customers/1234567890/campaigns/61', $preflight));
        $activation = ExecutionResult::success();
        $this->assertFalse($builder->confirmActivationProof($campaign, '1234567890', 'customers/1234567890/campaigns/61', [], $activation));
        $negatives = ExecutionResult::success();
        $builder->addInitialNegativeKeywords('1234567890', 'customers/1234567890/campaigns/61', $campaign, new Strategy, $negatives);
        $this->assertSame('search_keyword_native_automation_owned', $negatives->errors[0]->code);
        $this->assertEquals($current, $campaign->fresh()->search_delivery_state);
    }

    public function test_new_ai_max_request_between_keywords_stops_the_remaining_remote_writes(): void
    {
        $campaign = $this->campaign();
        $current = $this->currentNamespaces();
        $writer = $this->createMock(AddAdGroupCriterion::class);
        $writer->expects($this->once())->method('__invoke')->willReturnCallback(function () use ($campaign, $current): string {
            $campaign->fresh()->update(['search_delivery_state' => $current]);

            return 'customers/1234567890/adGroupCriteria/7~1';
        });
        $this->app->bind(AddAdGroupCriterion::class, fn () => $writer);
        $result = ExecutionResult::success();
        (new SearchKeywordBuilder($campaign->customer))->addKeywords('1234567890', 'customers/1234567890/adGroups/7',
            [['text' => 'google ads setup'], ['text' => 'google ads campaign setup']], $result, $campaign);

        $this->assertFalse($result->success);
        $this->assertSame('search_keyword_native_automation_owned', $result->errors[0]->code);
        $this->assertEquals($current, $campaign->fresh()->search_delivery_state);
    }

    public function test_term_review_claim_reloads_namespaces_written_after_its_initial_read(): void
    {
        $campaign = $this->campaign();
        $current = $this->currentNamespaces();
        $current['ai_max_request']['enabled'] = false;
        $changed = false;
        DB::listen(function (QueryExecuted $query) use ($campaign, $current, &$changed): void {
            if (! $changed && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"campaigns"')
                && in_array($campaign->id, $query->bindings, true)) {
                $changed = true;
                $campaign->fresh()->update(['search_delivery_state' => $current]);
            }
        });
        $miner = $this->createMock(SearchTermMiningAgent::class);
        $miner->expects($this->once())->method('mine')->willReturnCallback(function (Campaign $claimed) use ($current): array {
            $saved = $claimed->fresh()->search_delivery_state;
            foreach ($current as $key => $value) {
                $this->assertEquals($value, $saved[$key], $key);
            }
            $this->assertSame('running', $saved['search_term_review']['status']);

            return ['keywords_added' => [], 'negatives_added' => []];
        });
        $this->app->instance(SearchTermMiningAgent::class, $miner);
        $job = new CheckGoogleSearchDelivery($campaign->id);
        $review = (new \ReflectionMethod($job, 'reviewSearchTerms'))->invoke($job, $campaign,
            ['status' => 'verified', 'limit_verified' => true, 'observed' => ['expensive_clicks' => true]]);

        $this->assertTrue($changed);
        $this->assertSame('checked', $review['status']);
    }
}
