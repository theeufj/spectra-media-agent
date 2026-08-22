<?php

namespace App\Jobs;

use App\Jobs\Concerns\RecordsAgentRun;
use App\Services\EmailSequences\SequenceDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends whatever follow-up emails are currently due.
 *
 * Hourly rather than daily: steps are configured in hours, and the first one
 * lands an hour after someone tries the landing page — a follow-up that
 * arrives the next morning has missed the moment it was written for.
 *
 * Leaves an AgentRun trace so it appears on /admin/automation-health beside
 * every other scheduled agent. A sequence quietly not sending is exactly the
 * kind of failure nobody notices.
 */
class DispatchEmailSequences implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RecordsAgentRun, SerializesModels;

    public function handle(SequenceDispatcher $dispatcher): void
    {
        $started = $this->startRun();

        $tally = $dispatcher->dispatchDue();

        $this->finishRun(
            $started,
            actions: $tally['sent'],
            errors: $tally['failed'],
            // Skips are the normal case — almost everyone due has already had
            // the step — so they are neither an action nor a problem.
            scope: sprintf('%d sent, %d already sent, %d failed', $tally['sent'], $tally['skipped'], $tally['failed']),
            note: config('email_sequences.enabled', false)
                ? null
                : 'Sequences are disabled; nothing was sent.',
            details: $tally,
        );
    }

    public function failed(\Throwable $e): void
    {
        $this->recordRunFailure($e);
    }
}
