<?php

namespace App\Services\Monitoring;

use App\Models\Campaign;
use App\Models\Conflict;
use App\Models\Customer;
use App\Models\PerformanceData;
use App\Models\Recommendation;
use Illuminate\Support\Facades\DB;

class DashboardDataService
{
    public function __invoke(Customer $customer): array
    {
        $campaigns = $customer->campaigns()->with('strategies.performanceData')->get();
        
        $performanceMetrics = $this->calculatePerformanceMetrics($campaigns);

        return [
            'portfolio_performance' => [
                'total_spend' => $performanceMetrics['total_spend'],
                'total_revenue' => $performanceMetrics['total_revenue'],
                'overall_roas' => $performanceMetrics['overall_roas'],
                'total_conversions' => $performanceMetrics['total_conversions'],
            ],
            'campaign_overview' => $this->getCampaignOverview($campaigns),
            'actionable_insights' => [
                'pending_recommendations' => $this->getPendingRecommendations($campaigns),
                'unresolved_conflicts' => $this->getUnresolvedConflicts($campaigns),
            ],
        ];
    }

    private function calculatePerformanceMetrics($campaigns): array
    {
        $totalSpend = 0;
        $totalRevenue = 0;
        $totalConversions = 0;

        foreach ($campaigns as $campaign) {
            foreach ($campaign->strategies as $strategy) {
                $performanceData = $strategy->performanceData->first();
                if ($performanceData) {
                    $totalSpend += $performanceData->spend;
                    $totalConversions += $performanceData->conversions;
                    
                    $revenueMultiple = $strategy->revenue_cpa_multiple ?? 1.0;
                    $cpaInDollars = ($strategy->cpa_target ?? 0) / 1000000;
                    $totalRevenue += $performanceData->conversions * $cpaInDollars * $revenueMultiple;
                }
            }
        }

        return [
            'total_spend' => $totalSpend,
            'total_revenue' => $totalRevenue,
            'total_conversions' => $totalConversions,
            'overall_roas' => $totalSpend > 0 ? $totalRevenue / $totalSpend : 0,
        ];
    }

    private function getCampaignOverview($campaigns): array
    {
        return $campaigns->map(function ($campaign) {
            $metrics = $this->calculatePerformanceMetrics(collect([$campaign]));
            return [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status,
                'spend' => $metrics['total_spend'],
                'revenue' => $metrics['total_revenue'],
                'roas' => $metrics['overall_roas'],
            ];
        })->toArray();
    }

    private function getPendingRecommendations($campaigns): int
    {
        return Recommendation::whereIn('campaign_id', $campaigns->pluck('id'))
            ->where('status', 'pending')
            ->count();
    }

    private function getUnresolvedConflicts($campaigns): int
    {
        return Conflict::whereIn('campaign_id', $campaigns->pluck('id'))
            ->where('status', 'unresolved')
            ->count();
    }
}
