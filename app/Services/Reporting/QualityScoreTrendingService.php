<?php

namespace App\Services\Reporting;

use App\Models\Customer;
use App\Models\KeywordQualityScore;
use Illuminate\Support\Facades\DB;

class QualityScoreTrendingService
{
    /**
     * Get quality score trends for a customer over a period.
     *
     * @param Customer $customer
     * @param int $days Number of days to analyze
     * @return array
     */
    public function getTrends(Customer $customer, int $days = 30): array
    {
        $scores = KeywordQualityScore::where('customer_id', $customer->id)
            ->where('recorded_at', '>=', now()->subDays($days))
            ->whereNotNull('quality_score')
            ->orderBy('recorded_at')
            ->get();

        if ($scores->isEmpty()) {
            return [
                'average_qs' => null,
                'daily_averages' => [],
                'trending_up' => [],
                'trending_down' => [],
                'worst_keywords' => [],
                'best_keywords' => [],
            ];
        }

        $dailyAverages = $scores->groupBy(fn($s) => $s->recorded_at->toDateString())
            ->map(fn($group) => round($group->avg('quality_score'), 1))
            ->toArray();

        $keywordTrends = $this->calculateKeywordTrends($scores);

        $latestByKeyword = $scores->groupBy('keyword_text')
            ->map(fn($group) => $group->sortByDesc('recorded_at')->first());

        return [
            'average_qs' => round($scores->avg('quality_score'), 1),
            'daily_averages' => $dailyAverages,
            'trending_up' => collect($keywordTrends)->where('direction', 'up')->values()->toArray(),
            'trending_down' => collect($keywordTrends)->where('direction', 'down')->values()->toArray(),
            'worst_keywords' => $latestByKeyword->sortBy('quality_score')->take(10)->map(fn($k) => [
                'keyword' => $k->keyword_text,
                'quality_score' => $k->quality_score,
                'creative_quality' => $k->creative_quality_score,
                'landing_page' => $k->post_click_quality_score,
                'expected_ctr' => $k->search_predicted_ctr,
                'impressions' => $k->impressions,
            ])->values()->toArray(),
            'best_keywords' => $latestByKeyword->sortByDesc('quality_score')->take(10)->map(fn($k) => [
                'keyword' => $k->keyword_text,
                'quality_score' => $k->quality_score,
                'impressions' => $k->impressions,
            ])->values()->toArray(),
        ];
    }

    protected function calculateKeywordTrends($scores): array
    {
        $trends = [];
        $grouped = $scores->groupBy('keyword_text');

        foreach ($grouped as $keyword => $keywordScores) {
            if ($keywordScores->count() < 2) {
                continue;
            }

            $sorted = $keywordScores->sortBy('recorded_at');
            $first = $sorted->first()->quality_score;
            $last = $sorted->last()->quality_score;
            $diff = $last - $first;

            if ($diff === 0) {
                continue;
            }

            $trends[] = [
                'keyword' => $keyword,
                'direction' => $diff > 0 ? 'up' : 'down',
                'change' => $diff,
                'from' => $first,
                'to' => $last,
                'data_points' => $keywordScores->count(),
            ];
        }

        return $trends;
    }
}
