<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Strategy;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\ExecutionStep;
use App\Services\Agents\FacebookAdsExecutionAgent;
use App\Services\Agents\GoogleAdsExecutionAgent;
use App\Services\Agents\LinkedInAdsExecutionAgent;
use App\Services\Agents\MicrosoftAdsExecutionAgent;
use App\Services\Agents\PlatformExecutionAgent;
use App\Services\Agents\ValidationResult;
use App\Services\Campaigns\CampaignMarketResearchWorkflow;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExecutionBudgetAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(GeminiService::class, Mockery::mock(GeminiService::class));
        // Budget authorization remains a separate gate after market consent.
        $this->mock(CampaignMarketResearchWorkflow::class, fn ($mock) => $mock->shouldReceive('assertApproved')->andReturnNull());
        Http::fake([]);
    }

    private function context(mixed $allocation = 10, mixed $approval = 10): ExecutionContext
    {
        return new ExecutionContext(
            new Strategy(['daily_budget' => $allocation]),
            new Campaign(['approved_daily_budget' => $approval]),
            new Customer,
        );
    }

    /** @param class-string<PlatformExecutionAgent> $class */
    private function execute(string $class, ExecutionContext $context, ExecutionPlan $plan, bool $allowed = false): ExecutionResult
    {
        $agent = Mockery::mock($class, [$context->customer])->makePartial()->shouldAllowMockingProtectedMethods();
        $this->assertInstanceOf(PlatformExecutionAgent::class, $agent);
        $this->expectation($agent, 'bootPlatformServices')->once();
        $this->expectation($agent, 'validatePrerequisites')->once()->andReturn(new ValidationResult(true));
        $this->expectation($agent, 'generateExecutionPlan')->once()->andReturn($plan);
        $agent->shouldNotReceive('handleExecutionError');
        if ($allowed) {
            $this->expectation($agent, 'executePlan')->once()->with($plan, $context)
                ->andReturn(ExecutionResult::success());
        } else {
            $agent->shouldNotReceive('executePlan');
        }

        return $agent->execute($context);
    }

    private function expectation(Mockery\LegacyMockInterface $agent, string $method): Mockery\Expectation
    {
        $agent->shouldReceive($method);
        $director = $agent->mockery_getExpectationsFor($method);
        $this->assertInstanceOf(Mockery\ExpectationDirector::class, $director);
        $expectations = $director->getExpectations();
        $expectation = end($expectations);
        $this->assertInstanceOf(Mockery\Expectation::class, $expectation);

        return $expectation;
    }

    public static function budgetedPlatforms(): array
    {
        return [
            'LinkedIn' => [LinkedInAdsExecutionAgent::class, 'parameters', 'daily_budget'],
            'Microsoft daily budget' => [MicrosoftAdsExecutionAgent::class, 'params', 'daily_budget'],
            'Microsoft budget alias' => [MicrosoftAdsExecutionAgent::class, 'parameters', 'budget'],
        ];
    }

    #[DataProvider('budgetedPlatforms')]
    public function test_ai_cannot_raise_a_ten_dollar_allocation_to_five_hundred(string $class, string $paramsKey, string $budgetKey): void
    {
        $result = $this->execute($class, $this->context(), new ExecutionPlan([
            ['action' => 'create_campaign', $paramsKey => [$budgetKey => 500]],
        ]));

        $this->assertFalse($result->success);
        $this->assertSame('execution_budget_not_authorized', $result->errors[0]->code);
        $this->assertArrayNotHasKey('recovery_plan', $result->metadata);
        Http::assertNothingSent();
    }

    #[DataProvider('budgetedPlatforms')]
    public function test_separately_valid_creates_cannot_exceed_the_allocation_in_total(string $class, string $paramsKey, string $budgetKey): void
    {
        $result = $this->execute($class, $this->context(), new ExecutionPlan([
            ['action' => 'create_campaign', $paramsKey => [$budgetKey => '6.00']],
            ['action' => 'create_campaign', $paramsKey => [$budgetKey => '5.00']],
        ]));

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    #[DataProvider('budgetedPlatforms')]
    public function test_valid_allocations_and_omitted_budgets_use_the_strategy_allocation(string $class, string $paramsKey, string $budgetKey): void
    {
        foreach ([[], [$budgetKey => '9.99']] as $params) {
            $result = $this->execute($class, $this->context(), new ExecutionPlan([
                ['action' => 'create_campaign', $paramsKey => $params],
            ]), allowed: true);
            $this->assertTrue($result->success, $result->errorMessage());
        }
    }

    public static function invalidBudgets(): array
    {
        return array_map(fn ($amount) => [$amount], [0, -1, INF, NAN, 'invalid', true, [], '99999999999999999999999', '10.000001']);
    }

    #[DataProvider('invalidBudgets')]
    public function test_invalid_or_over_budget_amounts_never_reach_campaign_creation(mixed $amount): void
    {
        $result = $this->execute(LinkedInAdsExecutionAgent::class, $this->context(), new ExecutionPlan([
            ['action' => 'create_campaign', 'parameters' => ['daily_budget' => $amount]],
        ]));

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public static function platforms(): array
    {
        return array_map(fn ($class) => [$class], [GoogleAdsExecutionAgent::class, FacebookAdsExecutionAgent::class, MicrosoftAdsExecutionAgent::class, LinkedInAdsExecutionAgent::class]);
    }

    #[DataProvider('platforms')]
    public function test_campaign_budget_or_defaults_cannot_replace_a_missing_strategy_allocation(string $class): void
    {
        $context = $this->context(null);
        $context->campaign->daily_budget = 100;
        $context->campaign->total_budget = 3000;

        $result = $this->execute($class, $context, new ExecutionPlan([]));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('strategy allocation', $result->errorMessage());
        Http::assertNothingSent();
    }

    public function test_current_desired_budget_is_not_an_approval(): void
    {
        $context = $this->context(10, null);
        $context->campaign->daily_budget = 500;
        $result = $this->execute(LinkedInAdsExecutionAgent::class, $context, new ExecutionPlan([]));

        $this->assertFalse($result->success);
    }

    #[DataProvider('platforms')]
    public function test_strategy_allocations_cannot_exceed_the_campaign_approval(string $class): void
    {
        $result = $this->execute($class, $this->context(20, 10), new ExecutionPlan([]));

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public function test_manual_campaign_approval_does_not_require_generated_budget_confirmation(): void
    {
        $result = $this->execute(LinkedInAdsExecutionAgent::class, $this->context(), new ExecutionPlan([]), allowed: true);

        $this->assertTrue($result->success);
    }

    public function test_generated_campaigns_still_require_budget_confirmation(): void
    {
        $context = $this->context();
        $context->campaign->auto_generated_at = now();
        $result = $this->execute(LinkedInAdsExecutionAgent::class, $context, new ExecutionPlan([]));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Confirm', $result->errorMessage());
    }

    public function test_account_wide_microsoft_import_cannot_bypass_the_strategy_limit(): void
    {
        $result = $this->execute(MicrosoftAdsExecutionAgent::class, $this->context(), new ExecutionPlan([
            new ExecutionStep('import_from_google', params: ['daily_budget' => 10]),
        ]));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('imports cannot verify', $result->errorMessage());
        Http::assertNothingSent();
    }

    public function test_object_step_allocations_use_exact_decimal_totals(): void
    {
        $result = $this->execute(MicrosoftAdsExecutionAgent::class, $this->context('0.30', '0.30'), new ExecutionPlan([
            new ExecutionStep('create_campaign', params: ['daily_budget' => '0.10']),
            new ExecutionStep('create_campaign', params: ['daily_budget' => '0.20']),
        ]), allowed: true);

        $this->assertTrue($result->success);
    }

    public function test_linkedin_object_parameters_cannot_bypass_the_budget_guard(): void
    {
        $result = $this->execute(LinkedInAdsExecutionAgent::class, $this->context(), new ExecutionPlan([
            (object) ['action' => 'create_campaign', 'parameters' => ['daily_budget' => 500], 'params' => ['daily_budget' => 10]],
        ]));

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public function test_signed_off_sibling_allocations_must_fit_the_approved_campaign_envelope(): void
    {
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'approved_daily_budget' => 10]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'daily_budget' => 6, 'signed_off_at' => now()]);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'daily_budget' => 5, 'signed_off_at' => now()]);
        $context = new ExecutionContext($strategy, $campaign, $customer);

        $result = $this->execute(LinkedInAdsExecutionAgent::class, $context, new ExecutionPlan([]));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('combined strategy allocations', $result->errorMessage());
    }

    public function test_plan_filter_status_cannot_hide_prior_platform_spending_from_the_guard(): void
    {
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'approved_daily_budget' => 50]);
        $prior = Strategy::factory()->create(['campaign_id' => $campaign->id, 'daily_budget' => 50, 'deployment_status' => 'skipped_plan', 'signed_off_at' => null]);
        $prior->forceFill(['google_ads_campaign_id' => '123'])->save();
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'daily_budget' => 10]);

        $result = $this->execute(MicrosoftAdsExecutionAgent::class, new ExecutionContext($strategy, $campaign, $customer), new ExecutionPlan([]));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('combined strategy allocations', $result->errorMessage());
        Http::assertNothingSent();
    }

    public function test_requeued_unsigned_prior_deployment_with_unknown_budget_blocks_even_a_funded_selection(): void
    {
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'approved_daily_budget' => 50]);
        Strategy::factory()->create([
            'campaign_id' => $campaign->id, 'daily_budget' => null,
            'deployment_status' => 'queued', 'signed_off_at' => null, 'deployed_at' => now(),
        ]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'daily_budget' => 10]);

        $result = $this->execute(MicrosoftAdsExecutionAgent::class, new ExecutionContext($strategy, $campaign, $customer), new ExecutionPlan([]));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('combined strategy allocations', $result->errorMessage());
        Http::assertNothingSent();
    }

    public function test_budget_reductions_during_planning_are_checked_and_the_executor_uses_fresh_models(): void
    {
        $customer = Customer::factory()->create(['linkedin_ads_account_id' => '508123456']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'approved_daily_budget' => 20]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'daily_budget' => 20]);
        $context = new ExecutionContext($strategy, $campaign, $customer);
        Campaign::whereKey($campaign->id)->update(['approved_daily_budget' => 10]);
        Strategy::whereKey($strategy->id)->update(['daily_budget' => 10]);

        config(['linkedinads.client_id' => 'test', 'linkedinads.client_secret' => 'test', 'linkedinads.refresh_token' => 'test']);
        EnabledPlatform::updateOrCreate(['slug' => 'linkedin'], ['name' => 'LinkedIn', 'is_enabled' => true]);
        Http::fake([
            'www.linkedin.com/oauth/*' => Http::response(['access_token' => 'test-token']),
            'api.linkedin.com/rest/adCampaigns' => Http::response(['id' => '99'], 201),
        ]);
        $agent = Mockery::mock(LinkedInAdsExecutionAgent::class, [$customer])->makePartial()->shouldAllowMockingProtectedMethods();
        $this->assertInstanceOf(LinkedInAdsExecutionAgent::class, $agent);
        $this->expectation($agent, 'validatePrerequisites')->once()->andReturn(new ValidationResult(true));
        $this->expectation($agent, 'generateExecutionPlan')->once()->andReturn(new ExecutionPlan([
            ['action' => 'create_campaign', 'parameters' => []],
        ]));

        $result = $agent->execute($context);

        $this->assertTrue($result->success, $result->errorMessage());
        $this->assertEquals(10, $context->campaign->approved_daily_budget);
        $this->assertEquals(10, $context->strategy->daily_budget);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/rest/adCampaigns')
            && $request['dailyBudget']['amount'] === '10.00');
    }

    public function test_microsoft_fallback_uses_the_strategy_allocation_even_when_a_google_campaign_exists(): void
    {
        $context = $this->context(10, 100);
        $context->campaign->daily_budget = 100;
        $context->campaign->google_ads_campaign_id = '123';
        $context->customer->microsoft_ads_account_id = '456';
        $agent = new MicrosoftAdsExecutionAgent($context->customer);
        $plan = (new \ReflectionMethod($agent, 'generateFallbackPlan'))->invoke($agent, $context);

        $this->assertSame('create_campaign', $plan->steps[0]->action);
        $this->assertEquals(10, $plan->steps[0]->params['daily_budget']);
        $this->assertNotContains('import_from_google', array_map(fn ($step) => $step->action, $plan->steps));
    }
}
