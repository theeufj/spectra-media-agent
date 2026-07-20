<?php

namespace App\Jobs\Concerns;

use App\Models\AgentRun;
use Illuminate\Support\Facades\Log;

/**
 * Gives a scheduled optimization job a one-line way to leave a visible run trace.
 *
 * Usage in a job's handle():
 *   $t = $this->startRun();
 *   ... work, accumulating $healed / $errors ...
 *   $this->finishRun($t, actions: $healed, errors: $errors, scope: "{$n} campaigns");
 *
 * Status is inferred: any errors → partial (or failed if nothing succeeded);
 * zero actions and zero errors → no_op (ran but did nothing — the silent case we
 * most want to see); otherwise completed.
 */
trait RecordsAgentRun
{
    protected function startRun(): float
    {
        return microtime(true);
    }

    protected function finishRun(
        float $startedAt,
        int $actions = 0,
        int $errors = 0,
        int $warnings = 0,
        ?string $scope = null,
        ?string $note = null,
        array $details = []
    ): void {
        $status = match (true) {
            $errors > 0 && $actions === 0 => AgentRun::STATUS_FAILED,
            $errors > 0                   => AgentRun::STATUS_PARTIAL,
            $actions === 0                => AgentRun::STATUS_NO_OP,
            default                       => AgentRun::STATUS_COMPLETED,
        };

        $this->writeRun($status, $actions, $errors, $warnings, $scope, $note,
            $details, (int) round((microtime(true) - $startedAt) * 1000));
    }

    /** Record a hard failure (call from the job's failed() handler). */
    protected function recordRunFailure(\Throwable $e, ?float $startedAt = null): void
    {
        $this->writeRun(
            AgentRun::STATUS_FAILED, 0, 1, 0, null,
            substr($e->getMessage(), 0, 500), [],
            $startedAt ? (int) round((microtime(true) - $startedAt) * 1000) : null
        );
    }

    private function writeRun(
        string $status, int $actions, int $errors, int $warnings,
        ?string $scope, ?string $note, array $details, ?int $durationMs
    ): void {
        try {
            AgentRun::create([
                'job'           => class_basename(static::class),
                'status'        => $status,
                'actions_taken' => $actions,
                'errors'        => $errors,
                'warnings'      => $warnings,
                'scope'         => $scope,
                'duration_ms'   => $durationMs,
                'note'          => $note,
                'details'       => $details ?: null,
            ]);
        } catch (\Throwable $e) {
            // Observability must never break the job it observes.
            Log::warning('RecordsAgentRun: failed to write run trace: ' . $e->getMessage());
        }
    }
}
