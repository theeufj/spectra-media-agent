<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Strategy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ExecutionMetricsController extends Controller
{
    /**
     * Display the execution metrics dashboard.
     */
    public function index(Request $request)
    {
        // Get date range filter (default: last 30 days)
        $startDate = $request->input('start_date', now()->subDays(30));
        $endDate = $request->input('end_date', now());

        // Build base query for strategies with execution data
        $query = Strategy::whereNotNull('execution_result')
            ->whereBetween('updated_at', [$startDate, $endDate]);

        // Overall metrics
        $totalExecutions = $query->count();
        $successfulExecutions = (clone $query)
            ->where('execution_result->success', true)
            ->count();
        $failedExecutions = $totalExecutions - $successfulExecutions;
        $successRate = $totalExecutions > 0 
            ? round(($successfulExecutions / $totalExecutions) * 100, 2) 
            : 0;

        // Average execution time
        $avgExecutionTime = (clone $query)->avg('execution_time') ?? 0;

        // Error recovery statistics
        $errorRecoveryAttempts = (clone $query)
            ->whereNotNull('execution_errors')
            ->whereJsonLength('execution_errors', '>', 0)
            ->count();

        // Platform breakdown - PostgreSQL compatible JSON queries
        $platformStats = (clone $query)
            ->select('platform')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN (execution_result->>'success')::boolean = true THEN 1 ELSE 0 END) as successful")
            ->selectRaw('AVG(execution_time) as avg_time')
            ->groupBy('platform')
            ->get()
            ->map(function ($stat) {
                return [
                    'platform' => $stat->platform,
                    'total' => $stat->total,
                    'successful' => $stat->successful,
                    'failed' => $stat->total - $stat->successful,
                    'success_rate' => $stat->total > 0 
                        ? round(($stat->successful / $stat->total) * 100, 2) 
                        : 0,
                    'avg_execution_time' => round($stat->avg_time, 2),
                ];
            });

        // Budget accuracy metrics
        $budgetMetrics = (clone $query)
            ->where('execution_result->success', true)
            ->get()
            ->map(function ($strategy) {
                $campaign = $strategy->campaign;
                $executionResult = $strategy->execution_result;
                
                // Calculate budget accuracy if data available
                $plannedBudget = $campaign->total_budget ?? 0;
                $allocatedBudget = collect($executionResult['campaigns'] ?? [])->sum('budget') ?? 0;
                
                return [
                    'planned' => $plannedBudget,
                    'allocated' => $allocatedBudget,
                    'accuracy' => $plannedBudget > 0 
                        ? round((1 - abs($plannedBudget - $allocatedBudget) / $plannedBudget) * 100, 2)
                        : 0,
                ];
            })
            ->filter(fn($m) => $m['planned'] > 0);

        $avgBudgetAccuracy = $budgetMetrics->isNotEmpty() 
            ? round($budgetMetrics->avg('accuracy'), 2)
            : 0;

        // Time series data (daily aggregations) - PostgreSQL compatible
        $timeSeriesData = (clone $query)
            ->select(DB::raw('DATE(updated_at) as date'))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN (execution_result->>'success')::boolean = true THEN 1 ELSE 0 END) as successful")
            ->selectRaw('AVG(execution_time) as avg_time')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($day) {
                return [
                    'date' => $day->date,
                    'total' => $day->total,
                    'successful' => $day->successful,
                    'failed' => $day->total - $day->successful,
                    'success_rate' => $day->total > 0 
                        ? round(($day->successful / $day->total) * 100, 2) 
                        : 0,
                    'avg_execution_time' => round($day->avg_time, 2),
                ];
            });

        // Common errors analysis
        $commonErrors = (clone $query)
            ->whereNotNull('execution_errors')
            ->whereJsonLength('execution_errors', '>', 0)
            ->get()
            ->flatMap(function ($strategy) {
                return $strategy->execution_errors ?? [];
            })
            ->groupBy('type')
            ->map(function ($errors, $type) {
                return [
                    'type' => $type,
                    'count' => $errors->count(),
                    'sample_message' => $errors->first()['message'] ?? 'N/A',
                    'platforms' => $errors->pluck('context.platform')->unique()->values()->all(),
                ];
            })
            ->sortByDesc('count')
            ->take(10)
            ->values();

        // AI decision quality (based on optimization opportunities acted upon)
        $aiQualityMetrics = (clone $query)
            ->where('execution_result->success', true)
            ->get()
            ->map(function ($strategy) {
                $executionPlan = $strategy->execution_plan;
                $executionResult = $strategy->execution_result;
                
                // Count optimization recommendations vs implementations
                $recommendations = count($executionPlan['optimization_recommendations'] ?? []);
                $implemented = count($executionResult['optimizations_applied'] ?? []);
                
                return [
                    'recommendations' => $recommendations,
                    'implemented' => $implemented,
                    'implementation_rate' => $recommendations > 0 
                        ? round(($implemented / $recommendations) * 100, 2)
                        : 0,
                ];
            })
            ->filter(fn($m) => $m['recommendations'] > 0);

        $avgImplementationRate = $aiQualityMetrics->isNotEmpty()
            ? round($aiQualityMetrics->avg('implementation_rate'), 2)
            : 0;

        // Feature adoption metrics (agents vs legacy)
        $agentExecutions = (clone $query)->count();
        $legacyExecutions = Strategy::whereBetween('updated_at', [$startDate, $endDate])
            ->whereNull('execution_result')
            ->count();
        $totalDeployments = $agentExecutions + $legacyExecutions;
        $agentAdoptionRate = $totalDeployments > 0
            ? round(($agentExecutions / $totalDeployments) * 100, 2)
            : 0;

        return Inertia::render('Admin/ExecutionMetrics', [
            'metrics' => [
                'overview' => [
                    'total_executions' => $totalExecutions,
                    'successful_executions' => $successfulExecutions,
                    'failed_executions' => $failedExecutions,
                    'success_rate' => $successRate,
                    'avg_execution_time' => round($avgExecutionTime, 2),
                    'error_recovery_attempts' => $errorRecoveryAttempts,
                ],
                'platform_stats' => $platformStats,
                'budget' => [
                    'avg_accuracy' => $avgBudgetAccuracy,
                    'total_campaigns' => $budgetMetrics->count(),
                ],
                'ai_quality' => [
                    'avg_implementation_rate' => $avgImplementationRate,
                    'total_analyzed' => $aiQualityMetrics->count(),
                ],
                'feature_adoption' => [
                    'agent_executions' => $agentExecutions,
                    'legacy_executions' => $legacyExecutions,
                    'adoption_rate' => $agentAdoptionRate,
                ],
                'time_series' => $timeSeriesData,
                'common_errors' => $commonErrors,
            ],
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    /**
     * Get detailed execution data for a specific strategy.
     */
    public function show(Strategy $strategy)
    {
        // Load relationships
        $strategy->load('campaign.customer');

        return Inertia::render('Admin/ExecutionDetail', [
            'strategy' => [
                'id' => $strategy->id,
                'platform' => $strategy->platform,
                'campaign_name' => $strategy->campaign->name ?? 'N/A',
                'customer_name' => $strategy->campaign->customer->company_name ?? 'N/A',
                'execution_plan' => $strategy->execution_plan,
                'execution_result' => $strategy->execution_result,
                'execution_time' => $strategy->execution_time,
                'execution_errors' => $strategy->execution_errors,
                'created_at' => $strategy->created_at,
                'updated_at' => $strategy->updated_at,
            ],
        ]);
    }
}
