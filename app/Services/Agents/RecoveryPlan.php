<?php

namespace App\Services\Agents;

/**
 * Represents an AI-generated recovery plan for handling execution errors.
 *
 * Contains the error details and recovery actions suggested by AI to
 * resolve or work around the error.
 */
class RecoveryPlan
{
    // \Throwable, not \Exception: the failures worth recovering from
    // include \Error — a TypeError or a call to a method that does not
    // exist, which is how several deployment paths in this codebase have
    // actually broken.
    public ?\Throwable $error;

    public array $recoveryActions;

    public string $reasoning;

    public array $metadata;

    public bool $canAutoRecover;

    public function __construct(
        ?\Throwable $error = null,
        array $recoveryActions = [],
        string $reasoning = '',
        array $metadata = [],
        array $actions = [],
        bool $canAutoRecover = false,
    ) {
        $this->error = $error;
        $this->recoveryActions = ! empty($recoveryActions) ? $recoveryActions : $actions;
        $this->reasoning = $reasoning;
        $this->metadata = $metadata;
        $this->canAutoRecover = $canAutoRecover;
    }

    /**
     * Create a RecoveryPlan from AI-generated JSON response.
     *
     * Expected JSON structure:
     * {
     *   "error_analysis": "...",
     *   "recovery_actions": [
     *     {
     *       "action": "expand_targeting",
     *       "parameters": {...},
     *       "reasoning": "..."
     *     }
     *   ],
     *   "reasoning": "..."
     * }
     *
     * @param  \Throwable  $error  The original error
     * @param  string  $json  JSON response from AI
     *
     * @throws \Exception If JSON is invalid
     */
    public static function fromJson(\Throwable $error, string $json): self
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in recovery plan: '.json_last_error_msg());
        }

        $recoveryActions = $data['recovery_actions'] ?? [];
        $reasoning = $data['reasoning'] ?? $data['error_analysis'] ?? '';

        return new self(
            error: $error,
            recoveryActions: $recoveryActions,
            reasoning: $reasoning,
            metadata: $data
        );
    }

    /**
     * Create a simple recovery plan without AI.
     *
     * @param  \Throwable  $error  The error
     * @param  string  $action  Simple recovery action description
     * @param  string  $reasoning  Reasoning for the action
     */
    public static function simple(
        \Throwable $error,
        string $action,
        string $reasoning = ''
    ): self {
        return new self(
            error: $error,
            recoveryActions: [
                [
                    'action' => $action,
                    'reasoning' => $reasoning,
                ],
            ],
            reasoning: $reasoning
        );
    }

    /**
     * Check if recovery plan has any actions.
     *
     * @return bool True if actions exist
     */
    public function hasActions(): bool
    {
        return ! empty($this->recoveryActions);
    }

    /**
     * Get count of recovery actions.
     *
     * @return int Number of actions
     */
    public function getActionCount(): int
    {
        return count($this->recoveryActions);
    }

    /**
     * Get a specific recovery action by index.
     *
     * @param  int  $index  Action index
     * @return array|null Action data or null if not found
     */
    public function getAction(int $index): ?array
    {
        return $this->recoveryActions[$index] ?? null;
    }

    /**
     * Get error message.
     *
     * @return string Error message
     */
    public function getErrorMessage(): string
    {
        return $this->error->getMessage();
    }

    /**
     * Convert to array for storage or logging.
     *
     * @return array Recovery plan as array
     */
    public function toArray(): array
    {
        return [
            'error' => [
                'message' => $this->error->getMessage(),
                'code' => $this->error->getCode(),
                'type' => get_class($this->error),
            ],
            'recovery_actions' => $this->recoveryActions,
            'reasoning' => $this->reasoning,
            'action_count' => $this->getActionCount(),
            'metadata' => $this->metadata,
        ];
    }
}
