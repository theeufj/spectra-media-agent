<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\AgentIssue;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\Google\AdExtensionBuilder;
use App\Services\Agents\Google\AudienceTargeter;
use App\Services\Agents\Google\BiddingStrategyApplier;
use App\Services\Agents\Google\Executors\SearchCampaignExecutor;
use App\Services\Agents\Google\GeoTargetResolver;
use App\Services\Agents\Google\LandingUrlBuilder;
use App\Services\Agents\Google\SearchKeywordBuilder;
use App\Services\DeploymentService;
use App\Services\GeminiService;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GoogleSearchGoalReadinessTest extends TestCase
{
    use DatabaseTransactions;

    private function executor(Customer $customer): SearchCampaignExecutor
    {
        return new SearchCampaignExecutor($customer,
            new GeoTargetResolver($customer), new AdExtensionBuilder($customer, app(GeminiService::class)),
            new LandingUrlBuilder($customer), new BiddingStrategyApplier($customer),
            new SearchKeywordBuilder($customer), new AudienceTargeter($customer));
    }

    public function test_unready_goal_blocks_campaign_creation_and_ad_or_bidding_writes(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'conversion_goals' => ['primary_goal' => 'Sign-up']]);
        $goals = new SearchLaunchGoalsFixture;
        $goals->prepared = ['ready' => false,
            'issues' => [new AgentIssue('conversion_action_unready', 'The signup action cannot accept conversions.')]];
        $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $goals);
        $result = new ExecutionResult(true);

        $this->executor($customer)->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);

        $this->assertFalse($result->success);
        $this->assertSame('conversion_action_unready', $result->errors[0]->code);
        $this->assertSame([], $result->platformIds);
        $this->assertNull($strategy->fresh()->google_ads_campaign_id);
        $this->assertSame(1, $goals->prepareCalls);
        $this->assertSame([], $goals->reconcileCalls);
    }

    public function test_reused_campaign_must_confirm_goal_before_ads_or_smart_bidding(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $resource = 'customers/123/campaigns/456';
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'google_ads_campaign_id' => $resource]);
        $goals = new SearchLaunchGoalsFixture;
        $goals->reconciled = ['ready' => false,
            'issues' => [new AgentIssue('conversion_goal_unverified', 'Google has not confirmed the selected goal.')]];
        $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $goals);
        $result = new ExecutionResult(true);

        $this->executor($customer)->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);

        $this->assertFalse($result->success);
        $this->assertSame('conversion_goal_unverified', $result->errors[0]->code);
        $this->assertSame($resource, $strategy->fresh()->google_ads_campaign_id);
        $this->assertFalse($result->metadata['conversion_goal_readiness']['ready']);
        $this->assertSame([[$strategy->id, $resource, true]], $goals->reconcileCalls);
    }

    public function test_unverified_goal_keeps_custom_ownership_in_the_execution_result(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $resource = 'customers/123/campaigns/456';
        $custom = 'customers/123/customConversionGoals/789';
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'google_ads_campaign_id' => $resource]);
        $goals = new SearchLaunchGoalsFixture;
        $goals->onReconcile = function () use ($strategy, $custom) {
            $strategy->fresh()->update(['execution_result' => ['metadata' => ['conversion_goal_readiness' => [
                'managed_custom_goal' => $custom, 'checked_at' => now()->toIso8601String(),
            ]]]]);

            return ['ready' => false, 'issues' => [new AgentIssue('conversion_goal_unverified', 'Goal confirmation is pending.')]];
        };
        $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $goals);
        $result = new ExecutionResult(true);

        $this->executor($customer)->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);

        $this->assertSame($custom, $result->metadata['conversion_goal_readiness']['managed_custom_goal']);
        $this->assertFalse($result->success);
    }

    public function test_deployment_persistence_preserves_concurrent_readiness_evidence(): void
    {
        $strategy = Strategy::factory()->create();
        $custom = 'customers/123/customConversionGoals/789';
        $strategy->fresh()->update(['execution_result' => ['metadata' => [
            'google_readiness' => ['status' => 'needs_review', 'issues' => [['code' => 'ad_strength_pending', 'message' => 'Google is reviewing the updated ad.']]],
            'conversion_goal_readiness' => ['managed_custom_goal' => $custom, 'last_ready' => ['intent' => ['category' => 'SIGNUP']]],
        ]]]);
        $result = new ExecutionResult(false);
        $result->addError('conversion_goal_unverified', 'Google has not confirmed the goal.');
        $result->addMetadata('conversion_goal_readiness', ['ready' => false, 'status' => 'needs_review']);
        $writer = new class extends DeploymentService
        {
            public function store(Strategy $strategy, ExecutionResult $result): void
            {
                parent::persistAgentResult($strategy, $result);
            }
        };

        $writer->store($strategy, $result);

        $saved = $strategy->fresh();
        $this->assertSame('failed', $saved->deployment_status);
        $this->assertSame('Google has not confirmed the goal.', $saved->deployment_error);
        $this->assertSame('needs_review', $saved->execution_result['metadata']['google_readiness']['status']);
        $this->assertSame($custom, $saved->execution_result['metadata']['conversion_goal_readiness']['managed_custom_goal']);
        $this->assertSame('SIGNUP', $saved->execution_result['metadata']['conversion_goal_readiness']['last_ready']['intent']['category']);
        $this->assertFalse($saved->execution_result['metadata']['conversion_goal_readiness']['ready']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('goalCheckOrder')]
    public function test_deployment_keeps_the_latest_goal_check(?string $currentTime, ?string $incomingTime, bool $expectedReady): void
    {
        $strategy = Strategy::factory()->create();
        $custom = 'customers/123/customConversionGoals/789';
        $strategy->fresh()->update(['execution_result' => ['metadata' => ['conversion_goal_readiness' => [
            'ready' => false, 'status' => 'needs_review', 'checked_at' => $currentTime,
            'managed_custom_goal' => $custom,
            'issues' => [['code' => 'conversion_import_unready', 'message' => 'Google rejected conversion import validation.']],
        ]]]]);
        $result = new ExecutionResult(true);
        $result->addMetadata('conversion_goal_readiness', [
            'ready' => true, 'status' => 'ready', 'checked_at' => $incomingTime, 'issues' => [],
        ]);
        $writer = new class extends DeploymentService
        {
            public function store(Strategy $strategy, ExecutionResult $result): void
            {
                parent::persistAgentResult($strategy, $result);
            }
        };

        $writer->store($strategy, $result);

        $saved = $strategy->fresh()->execution_result['metadata']['conversion_goal_readiness'];
        $this->assertSame($expectedReady, $saved['ready']);
        $this->assertSame($expectedReady ? 'ready' : 'needs_review', $saved['status']);
        $this->assertSame($custom, $saved['managed_custom_goal']);
        $this->assertCount($expectedReady ? 0 : 1, $saved['issues']);
    }

    public static function goalCheckOrder(): array
    {
        return [
            'older success cannot hide a failure' => ['2026-10-05T02:00:00Z', '2026-10-05T01:59:00Z', false],
            'locked writer wins equal timestamps' => ['2026-10-05T02:00:00Z', '2026-10-05T02:00:00Z', false],
            'undated success cannot hide a dated failure' => ['2026-10-05T02:00:00Z', null, false],
            'newer confirmed success replaces failure' => ['2026-10-05T02:00:00Z', '2026-10-05T02:01:00Z', true],
            'legacy undated metadata preserves ownership' => [null, null, true],
        ];
    }
}

class SearchLaunchGoalsFixture extends ReconcileCampaignConversionGoals
{
    public array $prepared = ['ready' => true, 'issues' => []];

    public array $reconciled = ['ready' => true, 'issues' => []];

    public int $prepareCalls = 0;

    public array $reconcileCalls = [];

    public ?\Closure $onReconcile = null;

    public function __construct() {}

    public function prepare(Strategy $strategy): array
    {
        $this->prepareCalls++;

        return $this->prepared;
    }

    public function reconcile(Strategy $strategy, ?string $campaignResource = null, bool $allowInactive = false): array
    {
        $this->reconcileCalls[] = [$strategy->id, $campaignResource, $allowInactive];

        return $this->onReconcile ? ($this->onReconcile)() : $this->reconciled;
    }
}
