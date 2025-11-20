<?php

namespace App\Services\Agents;

/**
 * Represents the result of executing a deployment plan.
 * 
 * Contains success/failure status, errors, warnings, created platform IDs,
 * and execution timing information.
 */
class ExecutionResult
{
    public bool $success;
    public array $errors;
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
        array $metadata = []
    ) {
        $this->success = $success;
        $this->errors = $errors;
        $this->warnings = $warnings;
        $this->platformIds = $platformIds;
        $this->executionTime = $executionTime;
        $this->plan = $plan;
        $this->metadata = $metadata;
    }

    /**
     * Create a failed execution result.
     * 
     * @param array|string $errors Error message(s)
     * @param array $context Additional context
     * @return self
     */
    public static function failure($errors, array $context = []): self
    {
        $errorArray = is_array($errors) ? $errors : [$errors];
        
        return new self(
            success: false,
            errors: $errorArray,
            metadata: $context
        );
    }

    /**
     * Create a successful execution result.
     * 
     * @param array $platformIds Created platform resource IDs
     * @param float $executionTime Time taken to execute
     * @param ExecutionPlan|null $plan The executed plan
     * @param array $warnings Any warnings during execution
     * @return self
     */
    public static function success(
        array $platformIds = [],
        float $executionTime = 0.0,
        ?ExecutionPlan $plan = null,
        array $warnings = []
    ): self {
        return new self(
            success: true,
            errors: [],
            warnings: $warnings,
            platformIds: $platformIds,
            executionTime: $executionTime,
            plan: $plan
        );
    }

    /**
     * Check if the execution failed.
     * 
     * @return bool True if execution failed
     */
    public function failed(): bool
    {
        return !$this->success;
    }

    /**
     * Check if the execution succeeded.
     * 
     * @return bool True if execution succeeded
     */
    public function isSuccessful(): bool
    {
        return $this->success;
    }

    /**
     * Check if there are any errors.
     * 
     * @return bool True if errors exist
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Check if there are any warnings.
     * 
     * @return bool True if warnings exist
     */
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    /**
     * Add an error to the result.
     * 
     * @param string $error Error message
     * @return self
     */
    public function addError(string $error): self
    {
        $this->errors[] = $error;
        $this->success = false;
        return $this;
    }

    /**
     * Add a warning to the result.
     * 
     * @param string $warning Warning message
     * @return self
     */
    public function addWarning(string $warning): self
    {
        $this->warnings[] = $warning;
        return $this;
    }

    /**
     * Add a platform resource ID.
     * 
     * @param string $type Resource type (e.g., 'campaign_id', 'ad_group_id')
     * @param string $id Resource ID from platform
     * @return self
     */
    public function addPlatformId(string $type, string $id): self
    {
        $this->platformIds[$type] = $id;
        return $this;
    }

    /**
     * Get a specific platform ID.
     * 
     * @param string $type Resource type
     * @return string|null Resource ID or null if not found
     */
    public function getPlatformId(string $type): ?string
    {
        return $this->platformIds[$type] ?? null;
    }

    /**
     * Add metadata to the result.
     * 
     * @param string $key Metadata key
     * @param mixed $value Metadata value
     * @return self
     */
    public function addMetadata(string $key, $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * Convert result to array for storage or logging.
     * 
     * @return array Result as array
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
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
     * 
     * @return string Summary message
     */
    public function getSummary(): string
    {
        if ($this->success) {
            $message = "Execution succeeded";
            if ($this->executionTime > 0) {
                $message .= sprintf(" in %.2f seconds", $this->executionTime);
            }
            if ($this->hasWarnings()) {
                $message .= sprintf(" with %d warning(s)", count($this->warnings));
            }
            return $message;
        } else {
            return sprintf("Execution failed with %d error(s)", count($this->errors));
        }
    }
}
