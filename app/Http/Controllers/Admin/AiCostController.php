<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiCost;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AiCostController extends Controller
{
    public function index(Request $request)
    {
        $period = $request->get('period', '30'); // days

        $since = now()->subDays((int) $period)->startOfDay();

        // ── Summary cards ──────────────────────────────────────────────────
        $totalCost       = AiCost::where('created_at', '>=', $since)->sum('cost');
        $totalCalls      = AiCost::where('created_at', '>=', $since)->count();
        $totalInputTokens  = AiCost::where('created_at', '>=', $since)->sum('input_tokens');
        $totalOutputTokens = AiCost::where('created_at', '>=', $since)->sum('output_tokens');
        $totalCachedTokens = AiCost::where('created_at', '>=', $since)->sum('cached_tokens');
        $avgCostPerCall  = $totalCalls > 0 ? $totalCost / $totalCalls : 0;
        $avgDurationMs   = AiCost::where('created_at', '>=', $since)->avg('duration_ms');

        // Previous period for trend
        $prevSince      = now()->subDays((int) $period * 2)->startOfDay();
        $prevUntil      = now()->subDays((int) $period)->startOfDay();
        $prevCost       = AiCost::whereBetween('created_at', [$prevSince, $prevUntil])->sum('cost');
        $costTrend      = $prevCost > 0 ? round((($totalCost - $prevCost) / $prevCost) * 100, 1) : null;

        // ── By model ───────────────────────────────────────────────────────
        $byModel = AiCost::where('created_at', '>=', $since)
            ->select('model',
                DB::raw('COUNT(*) as calls'),
                DB::raw('SUM(cost) as total_cost'),
                DB::raw('SUM(input_tokens) as input_tokens'),
                DB::raw('SUM(output_tokens) as output_tokens'),
                DB::raw('SUM(cached_tokens) as cached_tokens'),
                DB::raw('AVG(duration_ms) as avg_duration_ms')
            )
            ->groupBy('model')
            ->orderByDesc('total_cost')
            ->get()
            ->map(fn ($r) => [
                'model'          => $r->model,
                'calls'          => (int) $r->calls,
                'total_cost'     => round((float) $r->total_cost, 4),
                'input_tokens'   => (int) $r->input_tokens,
                'output_tokens'  => (int) $r->output_tokens,
                'cached_tokens'  => (int) $r->cached_tokens,
                'avg_duration_ms'=> (int) $r->avg_duration_ms,
                'pct'            => $totalCost > 0 ? round(($r->total_cost / $totalCost) * 100, 1) : 0,
            ]);

        // ── By operation / task type ───────────────────────────────────────
        $byOperation = AiCost::where('created_at', '>=', $since)
            ->select('operation', 'task_type',
                DB::raw('COUNT(*) as calls'),
                DB::raw('SUM(cost) as total_cost'),
                DB::raw('SUM(input_tokens) as input_tokens'),
                DB::raw('SUM(output_tokens) as output_tokens'),
                DB::raw('AVG(duration_ms) as avg_duration_ms')
            )
            ->groupBy('operation', 'task_type')
            ->orderByDesc('total_cost')
            ->get()
            ->map(fn ($r) => [
                'operation'       => $r->operation ?? 'unknown',
                'task_type'       => $r->task_type ?? '—',
                'calls'           => (int) $r->calls,
                'total_cost'      => round((float) $r->total_cost, 4),
                'input_tokens'    => (int) $r->input_tokens,
                'output_tokens'   => (int) $r->output_tokens,
                'avg_duration_ms' => (int) $r->avg_duration_ms,
                'pct'             => $totalCost > 0 ? round(($r->total_cost / $totalCost) * 100, 1) : 0,
            ]);

        // ── By customer ────────────────────────────────────────────────────
        $byCustomer = AiCost::where('created_at', '>=', $since)
            ->whereNotNull('customer_id')
            ->select('customer_id',
                DB::raw('COUNT(*) as calls'),
                DB::raw('SUM(cost) as total_cost'),
                DB::raw('SUM(input_tokens) as input_tokens'),
                DB::raw('SUM(output_tokens) as output_tokens')
            )
            ->groupBy('customer_id')
            ->orderByDesc('total_cost')
            ->get()
            ->map(function ($r) use ($totalCost) {
                $customer = Customer::find($r->customer_id);
                return [
                    'customer_id'   => $r->customer_id,
                    'customer_name' => $customer?->name ?? "Customer #{$r->customer_id}",
                    'calls'         => (int) $r->calls,
                    'total_cost'    => round((float) $r->total_cost, 4),
                    'input_tokens'  => (int) $r->input_tokens,
                    'output_tokens' => (int) $r->output_tokens,
                    'pct'           => $totalCost > 0 ? round(($r->total_cost / $totalCost) * 100, 1) : 0,
                ];
            });

        // Unattributed (no customer_id)
        $unattributed = AiCost::where('created_at', '>=', $since)
            ->whereNull('customer_id')
            ->select(
                DB::raw('COUNT(*) as calls'),
                DB::raw('SUM(cost) as total_cost')
            )
            ->first();

        // ── Daily cost trend ───────────────────────────────────────────────
        $daily = AiCost::where('created_at', '>=', $since)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(cost) as total_cost'),
                DB::raw('COUNT(*) as calls')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date'       => $r->date,
                'total_cost' => round((float) $r->total_cost, 4),
                'calls'      => (int) $r->calls,
            ]);

        // ── Fallback events ────────────────────────────────────────────────
        $fallbacks = AiCost::where('created_at', '>=', $since)
            ->whereNotNull('metadata')
            ->get()
            ->filter(fn ($r) => !empty($r->metadata['fallback_from']))
            ->groupBy(fn ($r) => $r->metadata['fallback_from'] . ' → ' . $r->model)
            ->map(fn ($group, $key) => [
                'chain' => $key,
                'count' => $group->count(),
                'cost'  => round($group->sum('cost'), 4),
            ])
            ->values();

        return Inertia::render('Admin/AiCosts', [
            'period'       => $period,
            'summary' => [
                'total_cost'          => round((float) $totalCost, 4),
                'total_calls'         => (int) $totalCalls,
                'total_input_tokens'  => (int) $totalInputTokens,
                'total_output_tokens' => (int) $totalOutputTokens,
                'total_cached_tokens' => (int) $totalCachedTokens,
                'avg_cost_per_call'   => round((float) $avgCostPerCall, 6),
                'avg_duration_ms'     => (int) $avgDurationMs,
                'cost_trend'          => $costTrend,
            ],
            'byModel'      => $byModel,
            'byOperation'  => $byOperation,
            'byCustomer'   => $byCustomer,
            'unattributed' => [
                'calls' => (int) ($unattributed?->calls ?? 0),
                'cost'  => round((float) ($unattributed?->total_cost ?? 0), 4),
            ],
            'daily'        => $daily,
            'fallbacks'    => $fallbacks,
        ]);
    }
}
