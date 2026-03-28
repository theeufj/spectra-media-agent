<?php

namespace Tests\Unit\Agents;

use App\Models\Customer;
use App\Services\Agents\PlatformExecutionAgent;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\OptimizationAnalysis;
use App\Services\Agents\RecoveryPlan;
use App\Services\Agents\ValidationResult;
use App\Services\GeminiService;
use Mockery;
use Tests\TestCase;

class PlatformExecutionAgentTest extends TestCase
{
    public function test_can_be_instantiated_with_customer(): void
    {
        $geminiMock = Mockery::mock(GeminiService::class);
        $this->app->instance(GeminiService::class, $geminiMock);

        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 1;

        $agent = new class($customer) extends PlatformExecutionAgent {
            public function execute(ExecutionContext $context): ExecutionResult
            {
                return ExecutionResult::success();
            }

            protected function generateExecutionPlan(ExecutionContext $context): ExecutionPlan
            {
                return Mockery::mock(ExecutionPlan::class);
            }

            protected function validatePrerequisites(ExecutionContext $context): ValidationResult
            {
                return new ValidationResult(true);
            }

            protected function analyzeOptimizationOpportunities(ExecutionContext $context): OptimizationAnalysis
            {
                return Mockery::mock(OptimizationAnalysis::class);
            }

            protected function handleExecutionError(\Exception $error, ExecutionContext $context): RecoveryPlan
            {
                return Mockery::mock(RecoveryPlan::class);
            }

            protected function executePlan(ExecutionPlan $plan, ExecutionContext $context): ExecutionResult
            {
                return ExecutionResult::success();
            }

            protected function getPlatformName(): string
            {
                return 'Test Platform';
            }

            // Expose gemini for testing
            public function getGemini(): GeminiService
            {
                return $this->gemini;
            }
        };

        $this->assertInstanceOf(PlatformExecutionAgent::class, $agent);
        $this->assertSame($geminiMock, $agent->getGemini());
    }

    public function test_gemini_service_resolved_from_container(): void
    {
        $geminiMock = Mockery::mock(GeminiService::class);
        $this->app->instance(GeminiService::class, $geminiMock);

        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 1;

        $agent = new class($customer) extends PlatformExecutionAgent {
            public function execute(ExecutionContext $context): ExecutionResult
            {
                return ExecutionResult::success();
            }
            protected function generateExecutionPlan(ExecutionContext $context): ExecutionPlan
            {
                return Mockery::mock(ExecutionPlan::class);
            }
            protected function validatePrerequisites(ExecutionContext $context): ValidationResult
            {
                return new ValidationResult(true);
            }
            protected function analyzeOptimizationOpportunities(ExecutionContext $context): OptimizationAnalysis
            {
                return Mockery::mock(OptimizationAnalysis::class);
            }
            protected function handleExecutionError(\Exception $error, ExecutionContext $context): RecoveryPlan
            {
                return Mockery::mock(RecoveryPlan::class);
            }
            protected function executePlan(ExecutionPlan $plan, ExecutionContext $context): ExecutionResult
            {
                return ExecutionResult::success();
            }
            protected function getPlatformName(): string
            {
                return 'Test';
            }
            public function getGemini(): GeminiService
            {
                return $this->gemini;
            }
        };

        // Verify the GeminiService resolved is the mock we bound
        $this->assertInstanceOf(GeminiService::class, $agent->getGemini());
        $this->assertSame($geminiMock, $agent->getGemini());
    }
}
