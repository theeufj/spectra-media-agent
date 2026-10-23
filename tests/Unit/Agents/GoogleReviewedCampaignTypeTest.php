<?php

namespace Tests\Unit\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\Google\Executors\CampaignTypeExecutor;
use App\Services\Agents\GoogleAdsExecutionAgent;
use App\Services\Deployment\AdDestinationCheck;
use App\Services\GeminiService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleReviewedCampaignTypeTest extends TestCase
{
    private function fixture(?string $reviewed = 'search', bool $existing = false): array
    {
        $this->app->instance(GeminiService::class, $this->createMock(GeminiService::class));
        $destination = $this->createMock(AdDestinationCheck::class);
        $destination->method('check')->willReturn(['status' => 'reachable']);
        $this->app->instance(AdDestinationCheck::class, $destination);
        $customer = new Customer(['google_ads_customer_id' => '123', 'name' => 'Reviewed Business']);
        $customer->id = 101;
        $campaign = new Campaign(['customer_id' => 101, 'landing_page_url' => 'https://example.test/offer', 'daily_budget' => '10.00']);
        $campaign->id = 102;
        $campaign->setRelation('customer', $customer);
        $strategy = new Strategy(['campaign_id' => 102, 'platform' => 'Google Ads', 'campaign_type' => $reviewed,
            'daily_budget' => '10.00', ...($existing ? ['deployed_at' => now()] : [])]);
        $strategy->id = 103;
        $strategy->setRelation('campaign', $campaign);
        $agent = new ReviewedTypeRecordingGoogleAgent($customer);

        return [$agent, new ExecutionContext($strategy, $campaign, $customer), $strategy];
    }

    public static function differentChannels(): array
    {
        return [['search', 'performance_max', false], ['search', 'display', false], ['display', 'performance_max', false],
            ['search', 'performance_max', true], ['display', 'search', true]];
    }

    #[DataProvider('differentChannels')]
    public function test_a_different_llm_channel_is_rejected_before_tracking_or_platform_mutations(string $reviewed, string $planned, bool $existing): void
    {
        [$agent, $context, $strategy] = $this->fixture($reviewed, $existing);
        $result = $agent->run(new ExecutionPlan([], rawPlan: ['campaign_structure' => ['type' => $planned]]), $context);

        $this->assertFalse($result->success);
        $this->assertSame('google_reviewed_campaign_type_mismatch', $result->errors[0]->code);
        $this->assertSame([], $agent->selected);
        $this->assertSame(0, $agent->trackingCalls);
        $this->assertSame($reviewed, $strategy->campaign_type);
    }

    public static function reviewedChannels(): array
    {
        return [['search'], ['display'], ['performance_max'], ['shopping'], ['demand_gen'], ['local_services'], ['video']];
    }

    #[DataProvider('reviewedChannels')]
    public function test_explicit_reviewed_channel_is_preserved_without_an_automatic_pmax_upgrade(string $reviewed): void
    {
        [$agent, $context, $strategy] = $this->fixture($reviewed);
        $result = $agent->run(new ExecutionPlan([], rawPlan: ['campaign_structure' => ['type' => $reviewed]]), $context);

        $this->assertTrue($result->success, $result->errorMessage());
        $this->assertSame([$reviewed], $agent->selected);
        $this->assertSame(0, $agent->pmaxUpgradeChecks);
        $this->assertSame($reviewed, $strategy->campaign_type);
        $this->assertSame($reviewed, $result->metadata['reviewed_campaign_type']);
    }

    public function test_an_omitted_plan_channel_uses_the_reviewed_type_and_missing_reviewed_type_is_held(): void
    {
        [$agent, $context] = $this->fixture('display');
        $this->assertTrue($agent->run(new ExecutionPlan([]), $context)->success);
        $this->assertSame(['display'], $agent->selected);
        [$agent, $context] = $this->fixture(null);
        $result = $agent->run(new ExecutionPlan([]), $context);
        $this->assertFalse($result->success);
        $this->assertSame('google_reviewed_campaign_type_unverified', $result->errors[0]->code);
        $this->assertSame(0, $agent->trackingCalls);
    }

    public function test_placement_changes_during_slow_setup_stop_before_campaign_or_ad_writes(): void
    {
        [$agent, $context, $strategy] = $this->fixture('search');
        $agent->duringTracking = function () use ($strategy): void {
            $strategy->campaign_type = 'performance_max';
        };
        $result = $agent->run(new ExecutionPlan([], rawPlan: ['campaign_structure' => ['type' => 'search']]), $context);
        $this->assertFalse($result->success);
        $this->assertSame('google_reviewed_campaign_type_changed', $result->errors[0]->code);
        $this->assertSame([], $agent->selected);
    }
}

class ReviewedTypeRecordingGoogleAgent extends GoogleAdsExecutionAgent
{
    public array $selected = [];

    public int $trackingCalls = 0;

    public int $pmaxUpgradeChecks = 0;

    public ?\Closure $duringTracking = null;

    public function run(ExecutionPlan $plan, ExecutionContext $context): ExecutionResult
    {
        return $this->executePlan($plan, $context);
    }

    protected function setupConversionTracking(string $customerId, ExecutionResult $result, ?Campaign $campaign = null): void
    {
        $this->trackingCalls++;
        if ($this->duringTracking) {
            ($this->duringTracking)();
        }
    }

    protected function hasConversionSignalForPmax(Campaign $campaign): bool
    {
        $this->pmaxUpgradeChecks++;

        return true;
    }

    protected function executorFor(string $campaignType): CampaignTypeExecutor
    {
        $this->selected[] = $campaignType;

        return new class implements CampaignTypeExecutor
        {
            public function execute(string $customerId, Campaign $campaign, Strategy $strategy, ExecutionPlan $plan, ExecutionResult $result): void
            {
                $result->addPlatformId('campaign', 'recorded-without-platform-calls');
            }
        };
    }
}
