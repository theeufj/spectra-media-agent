<?php

namespace App\Http\Controllers;

use App\Jobs\MonitorAgentHealth;
use App\Models\AgentActivity;
use App\Models\AgentRun;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AgentActivityController extends Controller
{
    /** Admin automation-health page. */
    public function healthPage()
    {
        return Inertia::render('Admin/AgentHealth', [
            'jobs'    => $this->healthSummary(),
            'tracked' => array_keys(MonitorAgentHealth::EXPECTED_MAX_GAP_HOURS),
        ]);
    }

    /** JSON version of the same summary. Admin-only. */
    public function health()
    {
        return response()->json(['data' => $this->healthSummary()]);
    }

    /**
     * System-wide automation health: latest run + recent history per optimization job,
     * with a stale flag derived from each job's expected cadence.
     *
     * @return array<int, array<string, mixed>>
     */
    private function healthSummary(): array
    {
        $gaps = MonitorAgentHealth::EXPECTED_MAX_GAP_HOURS;
        $latestIds = AgentRun::query()->selectRaw('MAX(id) as id')->groupBy('job')->pluck('id');

        $seen = AgentRun::whereIn('id', $latestIds)
            ->orderBy('job')
            ->get()
            ->map(function (AgentRun $run) use ($gaps) {
                $recent = AgentRun::where('job', $run->job)->latest('id')->take(12)->get();
                $ageHours = round($run->created_at->diffInHours(now(), true), 1);
                $maxGap = $gaps[$run->job] ?? null;
                return [
                    'job'          => $run->job,
                    'last_status'  => $run->status,
                    'last_run_at'  => $run->created_at,
                    'age_hours'    => $ageHours,
                    'is_stale'     => $maxGap !== null && $ageHours > $maxGap,
                    'expected_gap' => $maxGap,
                    'actions'      => $run->actions_taken,
                    'errors'       => $run->errors,
                    'scope'        => $run->scope,
                    'note'         => $run->note,
                    'no_op_streak' => $recent->takeWhile(fn ($r) => $r->status === AgentRun::STATUS_NO_OP)->count(),
                    'recent'       => $recent->map(fn ($r) => [
                        'status'  => $r->status,
                        'actions' => $r->actions_taken,
                        'errors'  => $r->errors,
                        'at'      => $r->created_at,
                    ])->values(),
                ];
            })
            ->keyBy('job');

        // Include tracked jobs that have NEVER run (the most alarming case).
        $rows = collect($gaps)->keys()->map(function ($job) use ($seen) {
            return $seen[$job] ?? [
                'job' => $job, 'last_status' => 'never_run', 'last_run_at' => null,
                'age_hours' => null, 'is_stale' => true, 'expected_gap' => null,
                'actions' => 0, 'errors' => 0, 'scope' => null, 'note' => 'No run ever recorded',
                'no_op_streak' => 0, 'recent' => [],
            ];
        });

        // Append any instrumented-but-not-tracked jobs too.
        $extra = $seen->keys()->diff(array_keys($gaps));
        foreach ($extra as $job) {
            $rows->push($seen[$job]);
        }

        return $rows->values()->all();
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $customerId = session('active_customer_id');

        if (!$customerId || !$user->customers()->where('customers.id', $customerId)->exists()) {
            return response()->json(['data' => []]);
        }

        $activities = AgentActivity::where('customer_id', $customerId)
            ->when($request->input('campaign_id'), fn ($q, $id) => $q->where('campaign_id', $id))
            ->orderBy('created_at', 'desc')
            ->limit($request->input('limit', 20))
            ->get();

        return response()->json(['data' => $activities]);
    }
}
