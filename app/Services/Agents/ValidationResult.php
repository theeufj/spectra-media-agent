<?php

namespace App\Services\Agents;

/**
 * Represents the result of prerequisite validation.
 *
 * `$passed` is the single accessor. This class used to expose `isValid()`,
 * `passes()`, `failed()` and the public property — four ways to ask one
 * question — and the four platform agents each picked a different one, which
 * is how their `execute()` implementations drifted apart without anything
 * failing. Errors and warnings are always {@see AgentIssue}, never the
 * `string[]|array[]` union they used to be.
 */
class ValidationResult
{
    public bool $passed;

    /** @var list<AgentIssue> */
    public array $errors;

    /** @var list<AgentIssue> */
    public array $warnings;

    public function __construct(bool $passed, array $errors = [], array $warnings = [])
    {
        $this->passed = $passed;
        $this->errors = AgentIssue::list($errors);
        $this->warnings = AgentIssue::list($warnings);
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
        $this->passed = false;

        return $this;
    }

    /**
     * Record a warning. Does not fail the result.
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
     * All error messages joined into one sentence.
     *
     * Safe to call on any result: this used to `implode()` straight over
     * `$errors`, which emitted "Array; Array" for anything built through
     * `addError()`.
     */
    public function errorMessage(string $separator = '; '): string
    {
        return AgentIssue::toSentence($this->errors, $separator);
    }

    /**
     * Convert result to array for storage or logging.
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'errors' => array_map(fn (AgentIssue $i) => $i->toArray(), $this->errors),
            'warnings' => array_map(fn (AgentIssue $i) => $i->toArray(), $this->warnings),
            'error_count' => count($this->errors),
            'warning_count' => count($this->warnings),
        ];
    }
}
