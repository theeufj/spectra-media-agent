<?php

namespace Tests\Unit\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\FacebookAdsExecutionAgent;
use App\Services\Agents\ValidationResult;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class FacebookAdsExecutionAgentTest extends TestCase
{
    // GeminiService::recordCost() writes an ai_costs row on every call, so
    // these tests were committing cost rows that leaked into the suite and
    // broke AiCostControllerTest's totals.
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Bind a mock GeminiService so PlatformExecutionAgent can resolve it
        $this->app->instance(GeminiService::class, Mockery::mock(GeminiService::class));
    }

    protected function createContext(?Customer $customer = null): array
    {
        $customer = $customer ?? new Customer([
            'name' => 'Test Company',
            'google_ads_customer_id' => '1234567890',
            'facebook_bm_owned' => true,
            'facebook_ads_account_id' => 'act_123456789',
            'facebook_page_id' => '999888777',
        ]);
        if (! $customer->id) {
            $customer->id = 1;
        }

        $campaign = new Campaign([
            'customer_id' => $customer->id,
        ]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        $strategy = new Strategy([
            'campaign_id' => 1,
            'platform' => 'Facebook Ads',
        ]);
        $strategy->id = 1;
        $strategy->setRelation('campaign', $campaign);

        $context = new ExecutionContext(
            strategy: $strategy,
            campaign: $campaign,
            customer: $customer,
            availableAssets: ['images' => 3, 'videos' => 1, 'ad_copies' => 2, 'has_ad_copy' => true],
            platformStatus: ['connected' => true],
        );

        return [$customer, $campaign, $strategy, $context];
    }

    public function test_execute_creates_campaign_and_returns_success(): void
    {
        [$customer, $campaign, $strategy, $context] = $this->createContext();

        $agent = Mockery::mock(FacebookAdsExecutionAgent::class, [$customer])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agent->shouldReceive('validatePrerequisites')
            ->once()
            ->andReturn(new ValidationResult(true));

        // The template method does not call this. Facebook's own execute() used
        // to, and threw the result away; Google stopped calling it because the
        // discarded analysis cost a conversions query plus several collateral
        // counts on every deploy. Asserting never() keeps that cost from
        // creeping back in.
        $agent->shouldNotReceive('analyzeOptimizationOpportunities');

        $agent->shouldReceive('generateExecutionPlan')
            ->once()
            ->andReturn(Mockery::mock('App\Services\Agents\ExecutionPlan'));

        $agent->shouldReceive('executePlan')
            ->once()
            ->andReturn(ExecutionResult::success(
                platformIds: ['campaign_id' => 'fb_camp_123', 'adset_id' => 'fb_adset_456'],
                executionTime: 2.5,
            ));

        Log::spy();

        $result = $agent->execute($context);

        $this->assertTrue($result->success);
        $this->assertEmpty($result->errors);
        $this->assertArrayHasKey('campaign_id', $result->platformIds);

        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    public function test_execute_fails_when_facebook_account_not_configured(): void
    {
        $customer = new Customer([
            'name' => 'Test Company',
            'facebook_bm_owned' => false,
            'facebook_ads_account_id' => null,
            'facebook_page_id' => null,
        ]);
        $customer->id = 2;

        $campaign = new Campaign(['customer_id' => 2]);
        $campaign->id = 2;
        $campaign->setRelation('customer', $customer);

        $strategy = new Strategy(['campaign_id' => 2, 'platform' => 'Facebook Ads']);
        $strategy->id = 2;
        $strategy->setRelation('campaign', $campaign);

        $context = new ExecutionContext(
            strategy: $strategy,
            campaign: $campaign,
            customer: $customer,
            availableAssets: ['images' => 1, 'ad_copies' => 1, 'has_ad_copy' => true],
        );

        $agent = Mockery::mock(FacebookAdsExecutionAgent::class, [$customer])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        // Let validatePrerequisites run the real code for BM check
        $agent->shouldReceive('initializeServices')->andReturnNull();

        $validationResult = new ValidationResult(false);
        $validationResult->addError(
            'facebook_bm_not_configured',
            'Facebook ad account not set up.'
        );

        $agent->shouldReceive('validatePrerequisites')
            ->once()
            ->andReturn($validationResult);

        $result = $agent->execute($context);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errors);
    }

    public function test_execute_handles_api_errors_during_execution(): void
    {
        [$customer, $campaign, $strategy, $context] = $this->createContext();

        $agent = Mockery::mock(FacebookAdsExecutionAgent::class, [$customer])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agent->shouldReceive('validatePrerequisites')
            ->once()
            ->andReturn(new ValidationResult(true));

        // The template method does not call this. Facebook's own execute() used
        // to, and threw the result away; Google stopped calling it because the
        // discarded analysis cost a conversions query plus several collateral
        // counts on every deploy. Asserting never() keeps that cost from
        // creeping back in.
        $agent->shouldNotReceive('analyzeOptimizationOpportunities');

        $agent->shouldReceive('generateExecutionPlan')
            ->once()
            ->andReturn(Mockery::mock('App\Services\Agents\ExecutionPlan'));

        $agent->shouldReceive('executePlan')
            ->once()
            ->andReturn(ExecutionResult::failure(
                'Facebook API Error: Campaign creation failed - insufficient permissions'
            ));

        Log::spy();

        $result = $agent->execute($context);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('Campaign creation failed', $result->errorMessage());
    }

    public function test_validation_fails_without_facebook_page(): void
    {
        $customer = new Customer([
            'name' => 'Test Company',
            'facebook_bm_owned' => true,
            'facebook_ads_account_id' => 'act_123',
            'facebook_page_id' => null,
        ]);
        $customer->id = 3;

        $campaign = new Campaign(['customer_id' => 3]);
        $campaign->id = 3;
        $campaign->setRelation('customer', $customer);

        $strategy = new Strategy(['campaign_id' => 3, 'platform' => 'Facebook Ads']);
        $strategy->id = 3;
        $strategy->setRelation('campaign', $campaign);

        $context = new ExecutionContext(
            strategy: $strategy,
            campaign: $campaign,
            customer: $customer,
        );

        $agent = Mockery::mock(FacebookAdsExecutionAgent::class, [$customer])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agent->shouldReceive('initializeServices')->andReturnNull();

        // Simulate what validatePrerequisites would return for missing page
        $validationResult = new ValidationResult(false);
        $validationResult->addError('facebook_page_not_connected', 'Facebook Page not connected');

        $agent->shouldReceive('validatePrerequisites')
            ->once()
            ->andReturn($validationResult);

        $result = $agent->execute($context);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errors);
    }
}
