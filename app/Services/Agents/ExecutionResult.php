<?php

namespace App\Services\Agents;

/**
 * Represents the result of executing a deployment plan.
 *
 * `$success` is the single accessor — `failed()` and `isSuccessful()` are gone.
 *
 * This class used to carry two incompatible conventions at once: Google and
 * Facebook filled `errors`/`warnings`/`platformIds`/`metadata`, while Microsoft
 * and LinkedIn filled `message`/`data`. `toArray()` serialised only the first
 * set, and `DeploymentService` read only `$errors` — so a failed Microsoft or
 * LinkedIn deploy recorded a blank `strategies.deployment_error` and returned an
 * empty error string to its caller. `message` and `data` have been removed;
 * everything goes through `errors`, `warnings` and `metadata`.
 */
class ExecutionResult
{
    public bool $success;

    /** @var list<AgentIssue> */
    public array $errors;

    /** @var list<AgentIssue> */
    public array $warnings;

    public array $platformIds;

    public float $executionTime;

    public ?ExecutionPlan $plan;

    public array $metadata;

    public function __construct(
        bool $success,
        array $errors = [],
        array $warnings = [],
        array $platformIds = [],
        float $executionTime = 0.0,
        ?ExecutionPlan $plan = null,
        array $metadata = [],
    ) {
        $this->success = $success;
        $this->errors = AgentIssue::list($errors);
        $this->warnings = AgentIssue::list($warnings);
        $this->platformIds = $platformIds;
        $this->executionTime = $executionTime;
        $this->plan = $plan;
        $this->metadata = $metadata;
    }

    /**
     * Create a failed execution result.
     *
     * @param  iterable<mixed>|string  $errors  Error message(s) or AgentIssue(s)
     * @param  array  $metadata  Additional context (recovery plans, diagnostics)
     * @param  iterable<mixed>  $warnings  Warnings raised before the failure
     */
    public static function failure($errors, array $metadata = [], iterable $warnings = []): self
    {
        return new self(
            success: false,
            errors: is_iterable($errors) ? $errors : [$errors],
            warnings: $warnings,
            metadata: $metadata,
        );
    }

    /**
     * Create a successful execution result.
     *
     * @param  array  $platformIds  Created platform resource IDs
     * @param  float  $executionTime  Time taken to execute
     * @param  ExecutionPlan|null  $plan  The executed plan
     * @param  iterable<mixed>  $warnings  Any warnings during execution
     */
    public static function success(
        array $platformIds = [],
        float $executionTime = 0.0,
        ?ExecutionPlan $plan = null,
        iterable $warnings = []
    ): self {
        return new self(
            success: true,
            warnings: $warnings,
            platformIds: $platformIds,
            executionTime: $executionTime,
            plan: $plan,
        );
    }

    /**
     * Record an error. Fails the result.
     *
     * @param  string  $codeOrMessage  Machine-readable code, or the message itself
     * @param  string|null  $message  Human-readable explanation
     */
    public function addError(string $codeOrMessage, ?string $message = null): self
    {
        $this->errors[] = AgentIssue::make($codeOrMessage, $message);
        $this->success = false;

        return $this;
    }

    /**
     * Record a warning. Does not fail the result.
     *
     * Most callers pass (code, message); the Google executors raise incidental
     * free-text warnings with a single argument. Both are accepted. This method
     * once took one argument only, so PHP silently discarded the second and
     * customers were shown the slug ("no_conversion_tracking") instead of the
     * explanation.
     *
     * @param  string  $codeOrMessage  Machine-readable code, or the message itself
     * @param  string|null  $message  Human-readable explanation
     */
    public function addWarning(string $codeOrMessage, ?string $message = null): self
    {
        $this->warnings[] = AgentIssue::make($codeOrMessage, $message);

        return $this;
    }

    /**
     * Add a platform resource ID.
     *
     * @param  string  $type  Resource type (e.g., 'campaign_id', 'ad_group_id')
     * @param  string  $id  Resource ID from platform
     */
    public function addPlatformId(string $type, string $id): self
    {
        $this->platformIds[$type] = $id;

        return $this;
    }

    /**
     * Get a specific platform ID.
     *
     * @param  string  $type  Resource type
     * @return string|null Resource ID or null if not found
     */
    public function getPlatformId(string $type): ?string
    {
        return $this->platformIds[$type] ?? null;
    }

    /**
     * Add metadata to the result.
     *
     * @param  string  $key  Metadata key
     * @param  mixed  $value  Metadata value
     */
    public function addMetadata(string $key, $value): self
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    /**
     * All error messages joined into one sentence — what a customer should read.
     *
     * This is the single normaliser. `DeploymentService` used to carry two of
     * them, forty-five lines apart, that disagreed: one stored raw JSON in
     * `deployment_error`, the other returned the message text to the caller.
     */
    public function errorMessage(string $separator = '; '): string
    {
        return AgentIssue::toSentence($this->errors, $separator);
    }

    /**
     * All warning messages joined into one sentence.
     */
    public function warningMessage(string $separator = '; '): string
    {
        return AgentIssue::toSentence($this->warnings, $separator);
    }

    /**
     * Convert result to array for storage or logging.
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'errors' => array_map(fn (AgentIssue $i) => $i->toArray(), $this->errors),
            'warnings' => array_map(fn (AgentIssue $i) => $i->toArray(), $this->warnings),
            'platform_ids' => $this->platformIds,
            'execution_time' => $this->executionTime,
            'plan_summary' => $this->plan ? [
                'step_count' => $this->plan->getStepCount(),
                'reasoning' => $this->plan->reasoning,
            ] : null,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Get a summary message of the execution result.
     */
    public function getSummary(): string
    {
        if (! $this->success) {
            return sprintf('Execution failed with %d error(s): %s', count($this->errors), $this->errorMessage());
        }

        $message = 'Execution succeeded';

        if ($this->executionTime > 0) {
            $message .= sprintf(' in %.2f seconds', $this->executionTime);
        }

        if ($this->warnings !== []) {
            $message .= sprintf(' with %d warning(s)', count($this->warnings));
        }

        return $message;
    }
}
