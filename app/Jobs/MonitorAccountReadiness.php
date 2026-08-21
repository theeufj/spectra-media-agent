<?php

namespace App\Jobs;

use App\Jobs\Concerns\RecordsAgentRun;
use App\Services\Analytics\AccountReadiness;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reports how many accounts cannot yet run ads, and why.
 *
 * An audit found 14 of 15 accounts had never created a campaign and only one
 * had an ad-spend credit account — a state nothing measured, so nothing
 * surfaced it. The activation funnel says how many accounts reached each step;
 * this says which ones are stuck and on what, nightly, with an AgentRun trace
 * so it sits on /admin/automation-health beside every other agent.
 *
 * Blocked accounts count as ERRORS rather than warnings. They are not a
 * degraded experience — the customer is paying and structurally cannot
 * advertise. Stalled work counts as warnings: something was started and did not
 * finish, which needs attention but is not the same as being unable to begin.
 */
class MonitorAccountReadiness implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RecordsAgentRun, SerializesModels;

    public function handle(AccountReadiness $readiness): void
    {
        $started = $this->startRun();

        $report = $readiness->report();
        $summary = $report['summary'];

        $this->finishRun(
            $started,
            // Accounts reviewed, so an empty platform records no_op rather than
            // looking like a clean bill of health.
            actions: $summary['total'],
            errors: $summary['blocked'],
            warnings: $summary['stalled'],
            scope: "{$summary['total']} accounts",
            note: sprintf(
                '%d of %d accounts blocked, %d stalled, %d never launched a campaign, %d serving ads.',
                $summary['blocked'], $summary['total'], $summary['stalled'],
                $summary['never_launched'], $summary['serving'],
            ),
            details: [
                'summary' => $summary,
                // The "fix this once, unblock N accounts" view — the most
                // actionable thing in the report.
                'blocker_counts' => $report['blocker_counts'],
                'blocked_accounts' => array_map(
                    fn ($a) => [
                        'id' => $a['id'],
                        'name' => $a['name'],
                        'age_days' => $a['age_days'],
                        'blockers' => array_column($a['blockers'], 'key'),
                    ],
                    array_values(array_filter(
                        $report['accounts'],
                        fn ($a) => $a['severity'] === AccountReadiness::SEVERITY_BLOCKED,
                    )),
                ),
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        $this->recordRunFailure($e);
    }
}
