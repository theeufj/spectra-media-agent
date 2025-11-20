<?php

namespace App\Services\Agents;

/**
 * Represents budget validation results for a platform deployment.
 * 
 * Validates that allocated budget meets platform minimums and
 * supports the planned campaign features.
 */
class BudgetValidation
{
    public bool $isValid;
    public float $allocatedBudget;
    public array $platformMinimums;
    public array $errors;
    public array $warnings;
    public array $metadata;

    public function __construct(
        bool $isValid,
        float $allocatedBudget,
        array $platformMinimums = [],
        array $errors = [],
        array $warnings = [],
        array $metadata = []
    ) {
        $this->isValid = $isValid;
        $this->allocatedBudget = $allocatedBudget;
        $this->platformMinimums = $platformMinimums;
        $this->errors = $errors;
        $this->warnings = $warnings;
        $this->metadata = $metadata;
    }

    /**
     * Create a valid budget validation result.
     * 
     * @param float $allocatedBudget The allocated budget
     * @param array $warnings Any warnings
     * @return self
     */
    public static function valid(float $allocatedBudget, array $warnings = []): self
    {
        return new self(
            isValid: true,
            allocatedBudget: $allocatedBudget,
            warnings: $warnings
        );
    }

    /**
     * Create an invalid budget validation result.
     * 
     * @param float $allocatedBudget The allocated budget
     * @param array $errors Validation errors
     * @param array $platformMinimums Platform minimum requirements
     * @return self
     */
    public static function invalid(
        float $allocatedBudget,
        array $errors,
        array $platformMinimums = []
    ): self {
        return new self(
            isValid: false,
            allocatedBudget: $allocatedBudget,
            platformMinimums: $platformMinimums,
            errors: $errors
        );
    }

    /**
     * Check if validation passed.
     * 
     * @return bool True if validation passed
     */
    public function passes(): bool
    {
        return $this->isValid;
    }

    /**
     * Check if budget meets a specific minimum requirement.
     * 
     * @param string $requirement Requirement name
     * @return bool True if requirement is met
     */
    public function meetsMinimum(string $requirement): bool
    {
        if (!isset($this->platformMinimums[$requirement])) {
            return true; // No requirement set, consider it met
        }

        return $this->allocatedBudget >= $this->platformMinimums[$requirement];
    }

    /**
     * Add an error to the validation.
     * 
     * @param string $error Error message
     * @return self
     */
    public function addError(string $error): self
    {
        $this->errors[] = $error;
        $this->isValid = false;
        return $this;
    }

    /**
     * Add a warning to the validation.
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
     * Get all errors.
     * 
     * @return array Error messages
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get all warnings.
     * 
     * @return array Warning messages
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Convert to array for storage or logging.
     * 
     * @return array Validation as array
     */
    public function toArray(): array
    {
        return [
            'is_valid' => $this->isValid,
            'allocated_budget' => $this->allocatedBudget,
            'platform_minimums' => $this->platformMinimums,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'metadata' => $this->metadata,
        ];
    }
}
