<?php

namespace App\Services\Agents;

use App\Models\Customer;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;

/**
 * Abstract base class for platform-specific execution agents.
 *
 * Execution agents transform high-level marketing strategies into platform-specific
 * campaign deployments using AI-powered decision making. They replace hardcoded
 * deployment logic with intelligent, context-aware execution planning.
 *
 * `execute()` is the template method and is final. It used to be abstract while
 * this docblock described the orchestration it was supposed to perform, so all
 * four platform agents wrote their own copy — and the copies drifted. Microsoft
 * and LinkedIn caught `\Exception` instead of `\Throwable` (letting a `TypeError`
 * straight through a handler meant to contain it) and never called `report()`,
 * so their deploy failures never reached the admin exception dashboard. Google
 * and Facebook read validation through `passes()` and `isValid()` respectively;
 * Microsoft and LinkedIn read the `$passed` property. Google and Facebook
 * serialised the recovery plan, the other two attached the raw object.
 *
 * There is now one copy of that flow, here. Subclasses supply the platform
 * steps; they do not own the order, the error contract, or the reporting.
 */
abstract class PlatformExecutionAgent
{
    protected Customer $customer;

    protected GeminiService $gemini;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->gemini = app(GeminiService::class);
    }

    /**
     * Execute the deployment for the given execution context.
     *
     * The flow is fixed for every platform:
     *   1. Boot platform services
     *   2. Validate prerequisites — bail with the validation errors if unmet
     *   3. Generate an AI-powered execution plan
     *   4. Execute the plan
     *   5. On any throwable: report it, ask the agent for a recovery plan, and
     *      return a failure carrying both
     *
     * @param  ExecutionContext  $context  Strategy, campaign and customer for this deploy
     */
    final public function execute(ExecutionContext $context): ExecutionResult
    {
        $startedAt = hrtime(true);

        $this->logExecution('Starting execution', [
            'campaign_id' => $context->campaign->id,
            'strategy_id' => $context->strategy->id,
        ]);

        try {
            $this->bootPlatformServices($context);

            $validation = $this->validatePrerequisites($context);

            if (! $validation->passed) {
                $this->logError('Prerequisites not met', [
                    'campaign_id' => $context->campaign->id,
                    'errors' => $validation->errorMessage(),
                ]);

                return ExecutionResult::failure(
                    $validation->errors,
                    ['validation' => $validation->toArray()],
                    $validation->warnings,
                );
            }

            $plan = $this->generateExecutionPlan($context);
            $result = $this->executePlan($plan, $context);

            // Executors that never stamp their own timing would otherwise report
            // 0.0 seconds for every deploy.
            if ($result->executionTime <= 0.0) {
                $result->executionTime = $this->elapsedSeconds($startedAt);
            }

            // Prerequisite warnings are raised before the plan runs, so they are
            // not on the executor's result — carry them through rather than
            // dropping them on the floor.
            foreach ($validation->warnings as $warning) {
                $result->addWarning($warning->code, $warning->message);
            }

            $this->logExecution('Execution completed', [
                'campaign_id' => $context->campaign->id,
                'success' => $result->success,
                'execution_time' => $result->executionTime,
                'platform_ids' => count($result->platformIds),
            ]);

            return $result;
        } catch (\Throwable $e) {
            // report() as well as Log::error(): a per-item catch that only logs
            // is invisible on the admin exception dashboard, which is fed by
            // Queue::failing and the report handler.
            report($e);

            $this->logError('Execution failed', [
                'campaign_id' => $context->campaign->id,
                'error' => $e->getMessage(),
            ]);

            $recovery = $this->handleExecutionError($e, $context);

            return ExecutionResult::failure(
                [new AgentIssue(
                    'execution_failed',
                    $this->getPlatformName().' deployment failed: '.$e->getMessage(),
                )],
                [
                    'recovery_plan' => $recovery->toArray(),
                    'execution_time' => $this->elapsedSeconds($startedAt),
                ],
            );
        }
    }

    /**
     * Construct any platform SDK services this agent needs before validation.
     *
     * Optional: only Facebook builds per-customer service objects up front. It
     * runs inside the template's try/catch, so a constructor that throws is
     * reported and recovered like any other failure.
     */
    protected function bootPlatformServices(ExecutionContext $context): void
    {
        // No-op by default.
    }

    /**
     * Generate an AI-powered execution plan based on execution context.
     *
     * The execution plan includes:
     * - Step-by-step deployment actions
     * - Budget allocation across campaign elements
     * - Platform-specific optimizations
     * - Fallback strategies for common errors
     * - Reasoning for all decisions
     *
     * @param  ExecutionContext  $context  The execution context containing all necessary data
     * @return ExecutionPlan Structured plan for deployment
     */
    abstract protected function generateExecutionPlan(ExecutionContext $context): ExecutionPlan;

    /**
     * Validate all prerequisites before attempting deployment.
     *
     * Checks platform-specific requirements such as:
     * - Account connections and credentials
     * - Required assets (images, videos, ad copy)
     * - Platform features (pixel, conversion tracking)
     * - Budget minimums
     * - Payment method validity
     *
     * @param  ExecutionContext  $context  The execution context containing strategy, campaign, and customer
     * @return ValidationResult Result indicating if prerequisites are met
     */
    abstract protected function validatePrerequisites(
        ExecutionContext $context
    ): ValidationResult;

    /**
     * Analyze available optimization opportunities for this platform.
     *
     * NOT invoked by execute(). Google's agent stopped calling it because the
     * result was discarded while the call cost a conversions query plus several
     * collateral counts on every deploy, and `executePlan()` independently
     * re-derives Performance Max eligibility with a different rule set (>=3
     * valid-ratio images, versus images + video + conversion tracking + budget).
     * Facebook called it and threw the result away. Microsoft and LinkedIn never
     * called it at all.
     *
     * It stays on the interface because those two eligibility rule sets should
     * be unified and fed into generateExecutionPlan(); the implementations are
     * where that work will start. Until then nothing pays for the analysis.
     *
     * @param  ExecutionContext  $context  The execution context containing strategy, campaign, and customer
     * @return OptimizationAnalysis Analysis of available opportunities
     */
    abstract protected function analyzeOptimizationOpportunities(
        ExecutionContext $context
    ): OptimizationAnalysis;

    /**
     * Handle execution errors with intelligent recovery.
     *
     * Uses AI to generate recovery plans for common platform errors:
     * - Budget/targeting constraint violations
     * - Asset approval failures
     * - API quota issues
     * - Platform policy violations
     *
     * @param  \Throwable  $error  The error that occurred
     * @param  ExecutionContext  $context  Context about the failed execution
     * @return RecoveryPlan AI-generated recovery actions
     */
    abstract protected function handleExecutionError(
        \Throwable $error,
        ExecutionContext $context
    ): RecoveryPlan;

    /**
     * Execute the generated plan step by step.
     *
     * @param  ExecutionPlan  $plan  The plan to execute
     * @param  ExecutionContext  $context  The execution context containing strategy, campaign, and customer
     * @return ExecutionResult Result of the execution
     */
    abstract protected function executePlan(
        ExecutionPlan $plan,
        ExecutionContext $context
    ): ExecutionResult;

    /**
     * Seconds elapsed since an hrtime(true) reading.
     *
     * Deliberately unrounded: hrtime is nanosecond-resolution, and rounding to
     * milliseconds here turns any fast path into a literal 0.0, which reads
     * downstream as "never timed" rather than "timed, and quick". Format at
     * the point of display.
     */
    protected function elapsedSeconds(int|float $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1e9;
    }

    /**
     * Log execution progress for monitoring and debugging.
     *
     * @param  string  $message  Log message
     * @param  array  $context  Additional context
     */
    protected function logExecution(string $message, array $context = []): void
    {
        Log::info("[{$this->getPlatformName()}] {$message}", array_merge([
            'customer_id' => $this->customer->id,
            'agent' => static::class,
        ], $context));
    }

    /**
     * Log execution errors for monitoring and debugging.
     *
     * @param  string  $message  Error message
     * @param  array  $context  Additional context
     */
    protected function logError(string $message, array $context = []): void
    {
        Log::error("[{$this->getPlatformName()}] {$message}", array_merge([
            'customer_id' => $this->customer->id,
            'agent' => static::class,
        ], $context));
    }

    /**
     * Get the platform name for this agent.
     *
     * @return string Platform name (e.g., "Google Ads", "Facebook Ads")
     */
    abstract protected function getPlatformName(): string;
}
