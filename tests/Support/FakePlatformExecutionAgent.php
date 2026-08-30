<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\OptimizationAnalysis;
use App\Services\Agents\PlatformExecutionAgent;
use App\Services\Agents\RecoveryPlan;
use App\Services\Agents\ValidationResult;
use App\Services\GeminiService;

/**
 * A concrete execution agent whose steps are supplied per-test.
 *
 * A named class rather than an anonymous one so static analysis can see
 * `$calls` and `getGemini()` — the anonymous version resolved as the abstract
 * parent, and every access to its own members read as an error.
 *
 * Each step defaults to succeeding; pass a closure to make one throw, fail
 * validation, or return a specific result.
 */
class FakePlatformExecutionAgent extends PlatformExecutionAgent
{
    /** @var list<string> Steps the template invoked, in order. */
    public array $calls = [];

    /** @param array<string, callable> $steps */
    public function __construct(Customer $customer, private array $steps = [])
    {
        parent::__construct($customer);
    }

    public function getGemini(): GeminiService
    {
        return $this->gemini;
    }

    protected function bootPlatformServices(ExecutionContext $context): void
    {
        $this->calls[] = 'boot';
        ($this->steps['boot'] ?? fn () => null)();
    }

    protected function validatePrerequisites(ExecutionContext $context): ValidationResult
    {
        $this->calls[] = 'validate';

        return ($this->steps['validate'] ?? fn () => new ValidationResult(true))();
    }

    protected function generateExecutionPlan(ExecutionContext $context): ExecutionPlan
    {
        $this->calls[] = 'plan';

        return ($this->steps['plan'] ?? fn () => new ExecutionPlan([]))();
    }

    protected function executePlan(ExecutionPlan $plan, ExecutionContext $context): ExecutionResult
    {
        $this->calls[] = 'execute';

        return ($this->steps['execute'] ?? fn () => ExecutionResult::success())();
    }

    protected function analyzeOptimizationOpportunities(ExecutionContext $context): OptimizationAnalysis
    {
        $this->calls[] = 'analyze';

        return new OptimizationAnalysis;
    }

    protected function handleExecutionError(\Throwable $error, ExecutionContext $context): RecoveryPlan
    {
        $this->calls[] = 'recover';

        return new RecoveryPlan($error, [['action' => 'retry']], 'Try again');
    }

    protected function getPlatformName(): string
    {
        return 'Test Platform';
    }
}
