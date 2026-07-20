<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Models\User;
use App\Notifications\CriticalAgentAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Watches the agent_runs trace and alerts admins when a scheduled optimization job
 * goes silent (hasn't run within its expected window) or starts failing repeatedly.
 * This is the backstop that makes silent failures visible — the exact class of bug
 * that hid all day: jobs "running" while doing nothing or crashing internally.
 */
class MonitorAgentHealth implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Max hours between runs before a job is considered stale (≈ 2× its cadence). */
    private const EXPECTED_MAX_GAP_HOURS = [
        'RunSelfHealingChecks'       => 9,   // scheduled every 4h
        'RunStrategicDiagnosis'      => 30,  // daily
        'RunPerformanceAnomalyCheck' => 9,   // every 4h
    ];

    public function handle(): void
    {
        $problems = [];

        foreach (self::EXPECTED_MAX_GAP_HOURS as $job => $maxGapHours) {
            $last = AgentRun::where('job', $job)->latest('id')->first();

            if (!$last) {
                $problems[] = "{$job}: no run has ever been recorded (expected roughly every {$maxGapHours}h).";
                continue;
            }

            $ageHours = (int) $last->created_at->diffInHours(now());
            if ($ageHours > $maxGapHours) {
                $problems[] = "{$job}: last ran {$ageHours}h ago (expected within {$maxGapHours}h) — it appears to have stopped running.";
            }

            // Failure streak: the last 3 runs all failed = the job is crashing.
            $recent = AgentRun::where('job', $job)->latest('id')->take(3)->get();
            if ($recent->count() >= 3 && $recent->every(fn ($r) => $r->status === AgentRun::STATUS_FAILED)) {
                $problems[] = "{$job}: last 3 runs all FAILED — the job is crashing (latest error: " . ($last->note ?: 'n/a') . ').';
            }
        }

        if (empty($problems)) {
            Log::info('MonitorAgentHealth: all tracked optimization jobs healthy');
            return;
        }

        Log::warning('MonitorAgentHealth: detected automation health issues', ['problems' => $problems]);

        $admins = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->get();
        foreach ($admins as $admin) {
            $admin->notify(new CriticalAgentAlert(
                'agent_health',
                'Automation health: ' . count($problems) . ' issue(s) detected',
                "One or more optimization jobs are stale or failing:\n\n• " . implode("\n• ", $problems),
                ['problems' => $problems]
            ));
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('MonitorAgentHealth failed: ' . $e->getMessage());
    }
}
