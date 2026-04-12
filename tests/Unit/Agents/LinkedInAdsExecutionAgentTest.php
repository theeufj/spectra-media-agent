<?php

namespace Tests\Unit\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\LinkedInAdsExecutionAgent;
use App\Services\Agents\ValidationResult;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class LinkedInAdsExecutionAgentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(GeminiService::class, Mockery::mock(GeminiService::class));
    }

    protected function createContext(?Customer $customer = null, array $overrides = []): array
    {
        $customer = $customer ?? new Customer(array_merge([
            'name' => 'B2B Corp',
            'linkedin_ads_account_id' => '508123456',
            'linkedin_oauth_access_token' => 'test-access-token',
            'linkedin_token_expires_at' => now()->addDays(30),
        ], $overrides));
        if (!$customer->id) {
            $customer->id = 1;
        }

        $campaign = new Campaign([
            'customer_id' => $customer->id,
            'name' => 'B2B Lead Gen Campaign',
        ]);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        $strategy = new Strategy([
            'campaign_id' => 1,
            'platform' => 'LinkedIn Ads',
            'daily_budget' => 50,
            'campaign_type' => 'SPONSORED_UPDATES',
            'target_audience' => 'Marketing directors at mid-size companies',
        ]);
        $strategy->id = 1;
        $strategy->setRelation('campaign', $campaign);

        $context = new ExecutionContext(
            strategy: $strategy,
            campaign: $campaign,
            customer: $customer,
            availableAssets: ['images' => 2, 'videos' => 0, 'ad_copies' => 3],
        );

        return [$customer, $campaign, $strategy, $context];
    }

    // ──────────────────────────────────────────────
    // Validation tests
    // ──────────────────────────────────────────────

    public function test_validation_fails_without_linkedin_account_id(): void
    {
        $customer = new Customer([
            'name' => 'No LinkedIn Corp',
            'linkedin_ads_account_id' => null,
            'linkedin_oauth_access_token' => 'tok',
        ]);
        $customer->id = 2;

        [, , , $context] = $this->createContext($customer);

        $agent = new LinkedInAdsExecutionAgent($customer);

        Log::spy();
        $result = $agent->execute($context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('account ID not configured', $result->message);
    }

    public function test_validation_fails_without_api_credentials(): void
    {
        config(['linkedinads.client_id' => null, 'linkedinads.client_secret' => null, 'linkedinads.refresh_token' => null]);

        [, , , $context] = $this->createContext();
        $customer = $context->customer;

        $agent = new LinkedInAdsExecutionAgent($customer);

        Log::spy();
        $result = $agent->execute($context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('credentials not configured', $result->message);
    }

    public function test_validation_fails_without_management_credential(): void
    {
        config([
            'linkedinads.client_id' => 'test-id',
            'linkedinads.client_secret' => 'test-secret',
            'linkedinads.refresh_token' => null,
        ]);

        $customer = new Customer([
            'name' => 'No Token Corp',
            'linkedin_ads_account_id' => '508123456',
        ]);
        $customer->id = 3;

        [, , , $context] = $this->createContext($customer);

        $agent = new LinkedInAdsExecutionAgent($customer);

        Log::spy();
        $result = $agent->execute($context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('management credential configured', $result->message);
    }

    public function test_validation_fails_without_ad_copies(): void
    {
        config([
            'linkedinads.client_id' => 'test-id',
            'linkedinads.client_secret' => 'test-secret',
            'linkedinads.refresh_token' => 'test-refresh',
        ]);

        [$customer] = $this->createContext();

        $campaign = new Campaign(['customer_id' => $customer->id, 'name' => 'Test']);
        $campaign->id = 1;
        $campaign->setRelation('customer', $customer);

        $strategy = new Strategy(['campaign_id' => 1, 'platform' => 'LinkedIn Ads']);
        $strategy->id = 1;
        $strategy->setRelation('campaign', $campaign);

        $context = new ExecutionContext(
            strategy: $strategy,
            campaign: $campaign,
            customer: $customer,
            availableAssets: ['ad_copies' => 0, 'images' => 1],
        );

        $agent = new LinkedInAdsExecutionAgent($customer);

        Log::spy();
        $result = $agent->execute($context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('ad copy is required', $result->message);
    }

    // ──────────────────────────────────────────────
    // Execution tests (mocked to avoid real API calls)
    // ──────────────────────────────────────────────

    public function test_execute_succeeds_with_mocked_plan(): void
    {
        config([
            'linkedinads.client_id' => 'test-id',
            'linkedinads.client_secret' => 'test-secret',
            'linkedinads.refresh_token' => 'test-refresh',
        ]);

        [$customer, $campaign, $strategy, $context] = $this->createContext();

        $agent = Mockery::mock(LinkedInAdsExecutionAgent::class, [$customer])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agent->shouldReceive('validatePrerequisites')
            ->once()
            ->andReturn(new ValidationResult(true));

        $agent->shouldReceive('generateExecutionPlan')
            ->once()
            ->andReturn(Mockery::mock('App\Services\Agents\ExecutionPlan'));

        $agent->shouldReceive('executePlan')
            ->once()
            ->andReturn(new ExecutionResult(
                success: true,
                message: 'LinkedIn campaign deployed successfully',
                data: ['steps' => [['step' => 'create_campaign', 'status' => 'success']]],
            ));

        Log::spy();
        $result = $agent->execute($context);

        $this->assertTrue($result->success);
        $this->assertEquals('LinkedIn campaign deployed successfully', $result->message);
        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    public function test_execute_handles_exception_with_recovery_plan(): void
    {
        config([
            'linkedinads.client_id' => 'test-id',
            'linkedinads.client_secret' => 'test-secret',
            'linkedinads.refresh_token' => 'test-refresh',
        ]);

        [$customer, $campaign, $strategy, $context] = $this->createContext();

        $agent = Mockery::mock(LinkedInAdsExecutionAgent::class, [$customer])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agent->shouldReceive('validatePrerequisites')
            ->once()
            ->andReturn(new ValidationResult(true));

        $agent->shouldReceive('generateExecutionPlan')
            ->once()
            ->andThrow(new \Exception('LinkedIn API rate limit exceeded'));

        Log::spy();
        $result = $agent->execute($context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('rate limit', $result->message);
        $this->assertArrayHasKey('recovery_plan', $result->data ?? []);
    }

    // ──────────────────────────────────────────────
    // DeploymentService wiring test
    // ──────────────────────────────────────────────

    public function test_deployment_service_resolves_linkedin_agent(): void
    {
        $customer = new Customer([
            'name' => 'Test',
            'linkedin_ads_account_id' => '508123456',
        ]);
        $customer->id = 1;

        // Use reflection to test the private normalizePlatform and getAgent
        $reflection = new \ReflectionClass(\App\Services\DeploymentService::class);

        $normalize = $reflection->getMethod('normalizePlatform');
        $normalize->setAccessible(true);

        $this->assertEquals('linkedin', $normalize->invoke(null, 'LinkedIn Ads'));
        $this->assertEquals('linkedin', $normalize->invoke(null, 'linkedin'));
        $this->assertEquals('linkedin', $normalize->invoke(null, 'LinkedIn'));

        $getAgent = $reflection->getMethod('getAgent');
        $getAgent->setAccessible(true);

        $agent = $getAgent->invoke(null, 'linkedin', $customer);
        $this->assertInstanceOf(LinkedInAdsExecutionAgent::class, $agent);
    }

    // ──────────────────────────────────────────────
    // Campaign service targeting builder test
    // ──────────────────────────────────────────────

    public function test_targeting_criteria_builder_creates_valid_structure(): void
    {
        $customer = new Customer([
            'name' => 'Test',
            'linkedin_ads_account_id' => '508123456',
            'linkedin_oauth_access_token' => 'tok',
        ]);
        $customer->id = 1;

        $service = new \App\Services\LinkedInAds\CampaignService($customer);

        // Use reflection to test the protected buildTargetingCriteria method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildTargetingCriteria');
        $method->setAccessible(true);

        $targeting = [
            'job_titles' => ['urn:li:title:100', 'urn:li:title:200'],
            'industries' => ['urn:li:industry:4'],
            'company_sizes' => ['urn:li:staffCountRange:D'],
            'seniorities' => ['urn:li:seniority:7'],
            'locations' => ['urn:li:geo:103644278'],
        ];

        $result = $method->invoke($service, $targeting);

        $this->assertArrayHasKey('include', $result);
        $this->assertArrayHasKey('exclude', $result);
        $this->assertArrayHasKey('and', $result['include']);

        // Should have 5 criteria (titles, industries, sizes, seniorities, locations)
        $this->assertCount(5, $result['include']['and']);
    }

    // ──────────────────────────────────────────────
    // Performance model test
    // ──────────────────────────────────────────────

    public function test_performance_data_model_has_correct_casts(): void
    {
        $model = new \App\Models\LinkedInAdsPerformanceData();

        $this->assertTrue(in_array('campaign_id', $model->getFillable()));
        $this->assertTrue(in_array('impressions', $model->getFillable()));
        $this->assertTrue(in_array('cost', $model->getFillable()));
        $this->assertTrue(in_array('conversions', $model->getFillable()));
        $this->assertEquals('linkedin_ads_performance_data', $model->getTable());
    }
}
