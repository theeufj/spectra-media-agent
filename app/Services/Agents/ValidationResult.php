<?php

namespace App\Services\Agents;

/**
 * Represents the result of prerequisite validation.
 * 
 * Contains validation status, errors, and warnings about
 * platform prerequisites that must be met before deployment.
 */
class ValidationResult
{
    public bool $passed;
    public array $errors;
    public array $warnings;

    public function __construct(bool $passed, array $errors = [], array $warnings = [])
    {
        $this->passed = $passed;
        $this->errors = $errors;
        $this->warnings = $warnings;
    }

    /**
     * Check if validation passed.
     * 
     * @return bool True if validation passed
     */
    public function isValid(): bool
    {
        return $this->passed;
    }

    /**
     * Check if validation failed.
     * 
     * @return bool True if validation failed
     */
    public function failed(): bool
    {
        return !$this->passed;
    }

    /**
     * Add an error to the validation result.
     * 
     * @param string $error Error message
     * @return self
     */
    public function addError(string $error): self
    {
        $this->errors[] = $error;
        $this->passed = false;
        return $this;
    }

    /**
     * Add a warning to the validation result.
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
     * Get all error messages.
     * 
     * @return array Error messages
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get all warning messages.
     * 
     * @return array Warning messages
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Get error messages as a single string.
     * 
     * @param string $separator Separator between messages
     * @return string Combined error message
     */
    public function getErrorsAsString(string $separator = '; '): string
    {
        return implode($separator, $this->errors);
    }

    /**
     * Convert result to array for storage or logging.
     * 
     * @return array Result as array
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'error_count' => count($this->errors),
            'warning_count' => count($this->warnings),
        ];
    }
}
