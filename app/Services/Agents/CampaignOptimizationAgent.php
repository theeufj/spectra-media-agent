<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Prompts\OptimizationPrompt;
use App\Services\Agents\Optimization\MetricsFetcher;
use App\Services\Agents\Optimization\RecommendationApplier;
use App\Services\Agents\Optimization\RecommendationScorer;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates AI-powered campaign optimisation: fetches metrics, scores
 * AI recommendations for confidence, and applies high-confidence ones.
 *
 * Heavy lifting is delegated to:
 *   MetricsFetcher       — cross-platform metric retrieval
 *   RecommendationScorer — data quality + confidence scoring
 *   RecommendationApplier — applies individual recommendations to APIs
 */
class CampaignOptimizationAgent
{
    public function __construct(
        private GeminiService $gemini,
        private MetricsFetcher $fetcher,
        private RecommendationScorer $scorer,
        private RecommendationApplier $applier,
    ) {}

    public function analyze(Campaign $campaign): ?array
    {
        $metrics = $this->fetcher->fetchCurrent($campaign);
        $platform = $this->fetcher->platform($campaign);
        $historical = $this->fetcher->fetchHistorical($campaign);

        if (! $metrics) {
            Log::info("CampaignOptimizationAgent: No performance data for campaign {$campaign->id} ({$platform}).");

            return null;
        }

        $dataQuality = $this->scorer->assessDataQuality($metrics);
        $campaignData = [
            'name' => $campaign->name,
            'platform' => $platform,
            'goals' => $campaign->goals,
            'total_budget' => $campaign->total_budget,
            'daily_budget' => $campaign->daily_budget,
            'primary_kpi' => $campaign->primary_kpi,
            'product_focus' => $campaign->product_focus,
            'data_quality_score' => $dataQuality['score'],
            'data_quality_notes' => $dataQuality['notes'],
        ];

        $competitiveContext = app(\App\Services\Competition\CompetitorCampaignContext::class);
        $sources = $competitiveContext->forCampaign($campaign);
        $campaignData['approved_daily_budget'] = $campaign->approved_daily_budget ?? $campaign->daily_budget;
        $campaignData['landing_page_url'] = $campaign->landing_page_url;
        if ($sources !== [] && $campaign->google_ads_campaign_id) {
            try {
                $state = app(\App\Services\Competition\CompetitivePlatformGateway::class)->state($campaign);
                $state['ads'] = array_slice($state['ads'], 0, 20);
                $state['keywords'] = array_slice($state['keywords'], 0, 100);
                $campaignData['current_google_configuration'] = $state;
            } catch (\Throwable $e) {
                report($e);
                $campaignData['current_google_configuration'] = 'Unavailable. Do not invent existing ad groups, keywords or resource identifiers.';
            }
        }
        $prompt = OptimizationPrompt::generate($campaignData, $metrics, $historical, $sources);

        try {
            $response = $this->gemini->generateContent(
                model: config('ai.models.pro'),
                prompt: $prompt,
                config: ['temperature' => 0.7, 'maxOutputTokens' => 4096],
                enableThinking: true
            );

            if (! $response || ! isset($response['text'])) {
                Log::error("CampaignOptimizationAgent: Empty AI response for campaign {$campaign->id}");

                return null;
            }

            if (preg_match('/\{.*\}/s', $response['text'], $matches)) {
                $recommendations = json_decode($matches[0], true);

                if ($recommendations) {
                    $recommendations = $competitiveContext->attribute($recommendations, $sources, $metrics);
                    $recommendations = $this->scorer->enhance($recommendations, $metrics, $historical, $dataQuality);
                    $recommendations['categorized'] = $this->scorer->categorize($recommendations);

                    Cache::put("optimization:campaign:{$campaign->id}", $recommendations, now()->addHours($this->cacheTtlHours()));

                    return $recommendations;
                }
            }

            Log::error("CampaignOptimizationAgent: Failed to parse AI response for campaign {$campaign->id}");

            return null;

        } catch (\Throwable $e) {
            report($e);
            Log::error("CampaignOptimizationAgent: Failed for campaign {$campaign->id}: ".$e->getMessage());

            return null;
        }
    }

    public function getCachedRecommendations(int $campaignId): ?array
    {
        return Cache::get("optimization:campaign:{$campaignId}");
    }

    /**
     * Returns cache TTL in hours — shortened during seasonal peak periods
     * (budget_multiplier >= 1.5) so recommendations stay fresh when it matters most.
     */
    private function cacheTtlHours(): int
    {
        $seasonals = config('budget_rules.seasonal_multipliers', []);
        $today = now();

        foreach ($seasonals as $key => $multiplier) {
            if ($multiplier < 1.5) {
                continue;
            }

            // Date-keyed entries (MM-DD format)
            if (str_contains($key, '-') && strlen($key) === 5) {
                if ($today->format('m-d') === $key) {
                    return 4;
                }

                continue;
            }

            // Named entries: black_friday, cyber_monday
            if ($key === 'black_friday') {
                // Last Friday of November
                $lastFriday = (clone $today)->setDate((int) $today->format('Y'), 11, 1)
                    ->endOfMonth()
                    ->startOfDay();
                while ($lastFriday->dayOfWeek !== 5) {
                    $lastFriday->subDay();
                }
                if ($today->isSameDay($lastFriday)) {
                    return 4;
                }
            } elseif ($key === 'cyber_monday') {
                // Monday after Black Friday (4th Monday of November or 1st Monday of December)
                $cyberMonday = (clone $today)->setDate((int) $today->format('Y'), 11, 1)
                    ->endOfMonth()
                    ->startOfDay();
                while ($cyberMonday->dayOfWeek !== 5) {
                    $cyberMonday->subDay();
                }
                $cyberMonday->addDays(3); // Friday + 3 = Monday
                if ($today->isSameDay($cyberMonday)) {
                    return 4;
                }
            }
        }

        return 12;
    }

    public function applyRecommendation(Campaign $campaign, array $recommendation, bool $approvedByUser = false): array
    {
        $type = RecommendationScorer::canonicalType($recommendation['type'] ?? '');
        $field = $type === 'BUDGET' ? 'last_budget_changed_at' : 'last_bidding_changed_at';
        if (! $approvedByUser && in_array($type, ['BUDGET', 'BIDDING'], true) && $campaign->$field?->greaterThan(now()->subDays(7))) {
            return [
                'applied' => false,
                'requires_review' => true,
                'message' => 'Budget and bidding changes require seven days between automatic adjustments.',
                'recommendation' => $recommendation,
            ];
        }
        $result = $this->applier->apply($campaign, $recommendation, $approvedByUser);
        if (($result['applied'] ?? false) && in_array($type, ['BUDGET', 'BIDDING'], true)) {
            $campaign->forceFill([$field => now()])->save();
        }

        return $result;
    }
}
