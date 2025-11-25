<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;

/**
 * Abstract base class for platform-specific execution agents.
 * 
 * Execution agents transform high-level marketing strategies into platform-specific
 * campaign deployments using AI-powered decision making. They replace hardcoded
 * deployment logic with intelligent, context-aware execution planning.
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
     * This is the main entry point that orchestrates the entire execution flow:
     * 1. Validates prerequisites
     * 2. Analyzes optimization opportunities
     * 3. Generates AI-powered execution plan
     * 4. Executes the plan
     * 5. Handles errors and recovery
     * 
     * @param ExecutionContext $context The execution context containing strategy, campaign, and customer
     * @return ExecutionResult Result of the execution with success/failure details
     */
    abstract public function execute(ExecutionContext $context): ExecutionResult;

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
     * @param ExecutionContext $context The execution context containing all necessary data
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
     * @param ExecutionContext $context The execution context containing strategy, campaign, and customer
     * @return ValidationResult Result indicating if prerequisites are met
     */
    abstract protected function validatePrerequisites(
        ExecutionContext $context
    ): ValidationResult;

    /**
     * Analyze available optimization opportunities for this platform.
     * 
     * Identifies platform-specific features and optimizations that can be
     * leveraged based on available assets, budget, and account status:
     * - Advanced campaign types (Performance Max, Advantage+)
     * - Smart bidding strategies
     * - Creative optimizations (Dynamic Creative, Responsive Ads)
     * - Audience targeting enhancements
     * 
     * @param ExecutionContext $context The execution context containing strategy, campaign, and customer
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
     * @param \Exception $error The error that occurred
     * @param ExecutionContext $context Context about the failed execution
     * @return RecoveryPlan AI-generated recovery actions
     */
    abstract protected function handleExecutionError(
        \Exception $error,
        ExecutionContext $context
    ): RecoveryPlan;

    /**
     * Execute the generated plan step by step.
     * 
     * @param ExecutionPlan $plan The plan to execute
     * @param ExecutionContext $context The execution context containing strategy, campaign, and customer
     * @return ExecutionResult Result of the execution
     */
    abstract protected function executePlan(
        ExecutionPlan $plan,
        ExecutionContext $context
    ): ExecutionResult;

    /**
     * Log execution progress for monitoring and debugging.
     * 
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
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
     * @param string $message Error message
     * @param array $context Additional context
     * @return void
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
