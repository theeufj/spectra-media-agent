<?php

namespace App\Http\Controllers;

use App\Models\AgentActivity;
use App\Models\AgentRun;
use Illuminate\Http\Request;

class AgentActivityController extends Controller
{
    /**
     * System-wide automation health: latest run + recent history per optimization job.
     * Surfaces stale / failing / doing-nothing jobs. Admin-only.
     */
    public function health()
    {
        $latestIds = AgentRun::query()->selectRaw('MAX(id) as id')->groupBy('job')->pluck('id');

        $rows = AgentRun::whereIn('id', $latestIds)
            ->orderBy('job')
            ->get()
            ->map(function (AgentRun $run) {
                $recent = AgentRun::where('job', $run->job)->latest('id')->take(10)->get();
                return [
                    'job'          => $run->job,
                    'last_status'  => $run->status,
                    'last_run_at'  => $run->created_at,
                    'age_hours'    => round($run->created_at->diffInHours(now(), true), 1),
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
            ->values();

        return response()->json(['data' => $rows]);
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
