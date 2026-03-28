<?php

namespace Tests\Unit\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\GoogleAdsExecutionAgent;
use App\Services\Agents\ValidationResult;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class GoogleAdsExecutionAgentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(GeminiService::class, Mockery::mock(GeminiService::class));
    }

    protected function createContext(?Customer $customer = null): array
    {
        $customer = $customer ?? new Customer([
            'name' => 'Test Company',
            'google_ads_customer_id' => '1234567890',
        ]);
        if (!$customer->id) {
            $customer->id = 1;
        }

        $campaign = new Campaign([
            'customer_id' => $customer->id,
            'google_ads_campaign_id' => '111',
        ]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        $strategy = new Strategy([
            'campaign_id' => 1,
            'platform' => 'Google Ads',
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

    public function test_execute_returns_success_with_google_ads_ids(): void
    {
        [$customer, $campaign, $strategy, $context] = $this->createContext();

        $agent = Mockery::mock(GoogleAdsExecutionAgent::class, [$customer])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agent->shouldReceive('validatePrerequisites')
            ->once()
            ->andReturn(new ValidationResult(true));

        $agent->shouldReceive('analyzeOptimizationOpportunities')
            ->once()
            ->andReturn(Mockery::mock('App\Services\Agents\OptimizationAnalysis'));

        $agent->shouldReceive('generateExecutionPlan')
            ->once()
            ->andReturn(Mockery::mock('App\Services\Agents\ExecutionPlan'));

        $agent->shouldReceive('executePlan')
            ->once()
            ->andReturn(ExecutionResult::success(
                platformIds: [
                    'campaign_id' => 'customers/1234567890/campaigns/999',
                    'ad_group_id' => 'customers/1234567890/adGroups/888',
                    'ad_id' => 'customers/1234567890/ads/777',
                ],
                executionTime: 3.2,
            ));

        Log::spy();

        $result = $agent->execute($context);

        $this->assertTrue($result->success);
        $this->assertEmpty($result->errors);
        $this->assertArrayHasKey('campaign_id', $result->platformIds);
        $this->assertArrayHasKey('ad_group_id', $result->platformIds);

        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    public function test_execute_fails_when_google_ads_customer_id_missing(): void
    {
        $customer = new Customer([
            'name' => 'Test Company',
            'google_ads_customer_id' => null,
        ]);
        $customer->id = 2;

        $campaign = new Campaign(['customer_id' => 2]);
        $campaign->id = 2;
        $campaign->setRelation('customer', $customer);

        $strategy = new Strategy(['campaign_id' => 2, 'platform' => 'Google Ads']);
        $strategy->id = 2;
        $strategy->setRelation('campaign', $campaign);

        $context = new ExecutionContext(
            strategy: $strategy,
            campaign: $campaign,
            customer: $customer,
            availableAssets: ['images' => 1, 'ad_copies' => 1, 'has_ad_copy' => true],
        );

        $agent = Mockery::mock(GoogleAdsExecutionAgent::class, [$customer])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agent->shouldReceive('initializeServices')->andReturnNull();
        $agent->shouldReceive('provisionGoogleAdsAccount')->andReturn(false);

        $validationResult = new ValidationResult(false);
        $validationResult->addError('google_ads_no_account', 'Failed to provision Google Ads account');

        $agent->shouldReceive('validatePrerequisites')
            ->once()
            ->andReturn($validationResult);

        $result = $agent->execute($context);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errors);
    }

    public function test_execute_handles_api_errors_gracefully(): void
    {
        [$customer, $campaign, $strategy, $context] = $this->createContext();

        $agent = Mockery::mock(GoogleAdsExecutionAgent::class, [$customer])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agent->shouldReceive('validatePrerequisites')
            ->once()
            ->andReturn(new ValidationResult(true));

        $agent->shouldReceive('analyzeOptimizationOpportunities')
            ->once()
            ->andReturn(Mockery::mock('App\Services\Agents\OptimizationAnalysis'));

        $agent->shouldReceive('generateExecutionPlan')
            ->once()
            ->andReturn(Mockery::mock('App\Services\Agents\ExecutionPlan'));

        $agent->shouldReceive('executePlan')
            ->once()
            ->andReturn(ExecutionResult::failure(
                'Google Ads API Error: Campaign creation failed - RESOURCE_EXHAUSTED'
            ));

        Log::spy();

        $result = $agent->execute($context);

        $this->assertTrue($result->failed());
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('Campaign creation failed', $result->errors[0]);
    }

    public function test_validation_fails_without_ad_copy(): void
    {
        [$customer, $campaign, $strategy, $context] = $this->createContext();

        $agent = Mockery::mock(GoogleAdsExecutionAgent::class, [$customer])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $validationResult = new ValidationResult(false);
        $validationResult->addError('no_ad_copy', 'No ad copy available for Google Ads');

        $agent->shouldReceive('validatePrerequisites')
            ->once()
            ->andReturn($validationResult);

        $result = $agent->execute($context);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errors);
    }
}
