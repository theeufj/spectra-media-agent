<?php

namespace App\Services\Agents;

use App\Models\AgentActivity;
use App\Models\AdCopy;
use App\Models\Campaign;
use App\Notifications\CriticalAgentAlert;
use App\Services\FacebookAds\AdService;
use App\Services\FacebookAds\AdSetService;
use App\Services\GeminiService;
use App\Services\Agents\FacebookLearningPhaseAgent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Facebook's equivalent of Google's Quality Score improvement agent.
 *
 * Facebook Ad Relevance Diagnostics has three sub-scores:
 *   quality_ranking            — ad quality vs. competing ads targeting the same audience
 *   engagement_rate_ranking    — expected engagement rate vs. competing ads
 *   conversion_rate_ranking    — expected conversion rate vs. competing ads with the same optimisation goal
 *
 * Actions mirror QualityScoreImprovementAgent:
 *   quality_ranking BELOW_AVERAGE       → regenerate ad copy via Gemini
 *   engagement_rate_ranking BELOW_AVERAGE → flag creative for refresh (create CreativeBrief when model exists)
 *   conversion_rate_ranking BELOW_AVERAGE → dispatch CRO audit on landing page
 *   All three BELOW_AVERAGE + >2000 impressions → auto-pause ad
 */
class FacebookAdRelevanceDiagnosticsAgent
{
    public function __construct(private GeminiService $gemini) {}

    public function analyze(Campaign $campaign): array
    {
        $customer = $campaign->customer;

        if (!$campaign->facebook_ads_campaign_id || !$customer?->facebook_ads_account_id) {
            return ['skipped' => true];
        }

        // Don't touch campaigns in learning phase
        if (FacebookLearningPhaseAgent::isOnHold($campaign)) {
            return ['skipped' => true, 'reason' => 'learning_phase_hold'];
        }

        $adSetService = new AdSetService($customer);
        $adService    = new AdService($customer);

        $adSets = $adSetService->listAdSets($campaign->facebook_ads_campaign_id) ?? [];
        $actions  = [];
        $paused   = [];
        $flagged  = [];
        $errors   = [];

        foreach ($adSets as $adSet) {
            $ads = $adService->listAdsWithDiagnostics($adSet['id'] ?? '') ?? [];

            foreach ($ads as $ad) {
                try {
                    $this->diagnoseAd($campaign, $customer, $ad, $actions, $paused, $flagged, $errors);
                } catch (\Exception $e) {
                    $errors[] = "Error processing ad '{$ad['name']}': " . $e->getMessage();
                    Log::warning("FacebookAdRelevanceDiagnosticsAgent: " . $e->getMessage());
                }
            }
        }

        if (!empty($actions) || !empty($paused)) {
            AgentActivity::record(
                'facebook_relevance',
                'relevance_diagnostics_applied',
                "Applied " . (count($actions) + count($paused)) . " relevance action(s) for \"{$campaign->name}\"",
                $campaign->customer_id,
                $campaign->id,
                ['actions' => $actions, 'paused' => $paused, 'flagged' => $flagged, 'errors' => $errors]
            );
        }

        if (!empty($paused)) {
            $this->notifyPaused($campaign, $paused);
        }

        return [
            'actions' => $actions,
            'paused'  => $paused,
            'flagged' => $flagged,
            'errors'  => $errors,
        ];
    }

    private function diagnoseAd(Campaign $campaign, object $customer, array $ad, array &$actions, array &$paused, array &$flagged, array &$errors): void
    {
        $adId         = $ad['id'] ?? null;
        $adName       = $ad['name'] ?? $adId;
        $impressions  = (int) ($ad['impressions'] ?? 0);
        $qualityRank  = $ad['quality_ranking']          ?? 'UNKNOWN';
        $engageRank   = $ad['engagement_rate_ranking']  ?? 'UNKNOWN';
        $cvRank       = $ad['conversion_rate_ranking']  ?? 'UNKNOWN';

        $belowAvgCount = (int) ($qualityRank === 'BELOW_AVERAGE')
                       + (int) ($engageRank  === 'BELOW_AVERAGE')
                       + (int) ($cvRank      === 'BELOW_AVERAGE');

        // Auto-pause: all three below average with sufficient data
        if ($belowAvgCount >= 3 && $impressions >= 2000 && $adId) {
            $adService = new AdService($customer);
            $paused_ok = $adService->pauseAd($adId);

            if ($paused_ok) {
                $paused[] = ['ad_id' => $adId, 'ad_name' => $adName, 'impressions' => $impressions];
            } else {
                $errors[] = "Failed to pause ad '{$adName}'";
            }
            return;
        }

        // Root cause 1: Low quality ranking → regenerate ad copy
        if ($qualityRank === 'BELOW_AVERAGE') {
            $strategy = $campaign->strategies()->latest()->first();
            if ($strategy) {
                $variations = $this->generateAdCopyVariations($campaign, $customer);
                foreach ($variations as $variant) {
                    AdCopy::create([
                        'strategy_id'  => $strategy->id,
                        'platform'     => 'Facebook',
                        'headlines'    => $variant['headlines'] ?? [],
                        'descriptions' => $variant['descriptions'] ?? [],
                    ]);
                }
                if ($variations) {
                    $actions[] = ['ad' => $adName, 'action' => 'generated_copy', 'reason' => 'quality_ranking: BELOW_AVERAGE', 'variants' => count($variations)];
                }
            }
        }

        // Root cause 2: Low engagement ranking → flag for creative refresh
        if ($engageRank === 'BELOW_AVERAGE') {
            $flagged[] = [
                'ad'             => $adName,
                'issue'          => 'engagement_rate_ranking: BELOW_AVERAGE',
                'recommendation' => "Refresh visual creative for '{$adName}' — try video over static image, stronger hook in first 3 seconds.",
            ];
        }

        // Root cause 3: Low conversion ranking → trigger CRO audit
        if ($cvRank === 'BELOW_AVERAGE') {
            $landingPage = $customer->website ?? null;
            if ($landingPage) {
                \App\Jobs\RunCroAudit::dispatch($landingPage, $customer->id)
                    ->delay(now()->addMinutes(5));
            }
            $flagged[] = [
                'ad'             => $adName,
                'issue'          => 'conversion_rate_ranking: BELOW_AVERAGE',
                'recommendation' => "CRO audit dispatched for landing page. Check form length, page speed, and message match with ad.",
            ];
        }
    }

    private function notifyPaused(Campaign $campaign, array $paused): void
    {
        $cacheKey = "notif:fb_relevance_paused:{$campaign->id}";
        if (Cache::has($cacheKey)) {
            return;
        }

        $adList  = implode(', ', array_column($paused, 'ad_name'));
        $count   = count($paused);
        $message = "{$count} Facebook ad(s) were paused in \"{$campaign->name}\" — all three Ad Relevance Diagnostics ranked BELOW_AVERAGE with 2000+ impressions: {$adList}.";

        $admins = \App\Models\User::where('is_admin', true)->get();
        foreach ($admins as $admin) {
            $admin->notify(new CriticalAgentAlert(
                'facebook_relevance',
                'Low-Relevance Facebook Ads Paused',
                $message,
                ['campaign_id' => $campaign->id, 'paused' => $paused]
            ));
        }

        Cache::put($cacheKey, true, now()->addHours(24));
    }

    private function generateAdCopyVariations(Campaign $campaign, object $customer): array
    {
        $prompt = <<<PROMPT
You are an expert Facebook Ads copywriter. Generate 2 ad copy variations to improve Ad Relevance Diagnostics scores.

Business: {$customer->name}
Campaign: {$campaign->name}
Website: {$customer->website}

Requirements:
- Primary Text: max 125 characters, include a strong hook in the first sentence
- Headline: max 40 characters, benefit-focused
- Description: max 30 characters, supporting detail
- Include a clear call to action
- Write for Facebook/Instagram feed placement

Return ONLY valid JSON:
[
  {
    "headlines": ["Headline 1"],
    "descriptions": ["Primary text that hooks immediately. CTA here.", "Description line"]
  },
  {
    "headlines": ["Headline 2"],
    "descriptions": ["Different angle primary text. CTA.", "Description line"]
  }
]
PROMPT;

        try {
            $response = $this->gemini->generateContent('gemini-2.5-flash', $prompt);
            $text = preg_replace('/```json\s*|\s*```/', '', $response['text'] ?? '');
            $data = json_decode(trim($text), true);
            return (json_last_error() === JSON_ERROR_NONE && is_array($data)) ? $data : [];
        } catch (\Exception $e) {
            Log::error('FacebookAdRelevanceDiagnosticsAgent: Copy generation failed: ' . $e->getMessage());
            return [];
        }
    }
}
