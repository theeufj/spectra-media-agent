<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Models\GoogleAdsPerformanceData;
use App\Notifications\CriticalAgentAlert;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Proactive intra-day anomaly detection. Fires every 4 hours (scheduled via Kernel).
 *
 * Compares today's metrics to the same day last week. For each significant
 * deviation, calls Gemini to generate a one-sentence explanation with the most
 * likely cause, then sends a CriticalAgentAlert — enabling human intervention
 * before the day's budget is wasted.
 *
 * Anomalies detected:
 *   - CTR drop >25% vs same-weekday last week
 *   - CPC spike >50% vs same-weekday last week
 *   - Conversion rate drop >30% vs same-weekday last week
 *   - Zero delivery after 2pm (with sufficient historical impressions)
 *
 * Alerts are deduplicated per anomaly type per campaign for 6 hours.
 */
class PerformanceAnomalyAlertAgent
{
    public function __construct(private GeminiService $gemini) {}

    public function runForCustomer(Customer $customer): array
    {
        $campaigns = Campaign::where('customer_id', $customer->id)
            ->where('primary_status', 'ELIGIBLE')
            ->where(fn($q) => $q->whereNotNull('google_ads_campaign_id')
                                ->orWhereNotNull('facebook_ads_campaign_id'))
            ->get();

        $allAlerts = [];

        foreach ($campaigns as $campaign) {
            $alerts = $this->checkCampaign($campaign);
            if (!empty($alerts)) {
                $allAlerts[$campaign->id] = $alerts;
            }
        }

        return $allAlerts;
    }

    public function checkCampaign(Campaign $campaign): array
    {
        $today      = now()->toDateString();
        $lastWeek   = now()->subDays(7)->toDateString();
        $alerts     = [];

        $model = $campaign->google_ads_campaign_id
            ? GoogleAdsPerformanceData::class
            : ($campaign->facebook_ads_campaign_id ? FacebookAdsPerformanceData::class : null);

        if (!$model) {
            return [];
        }

        $todayData    = $model::where('campaign_id', $campaign->id)->where('date', $today)->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions')->first();
        $lastWeekData = $model::where('campaign_id', $campaign->id)->where('date', $lastWeek)->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions')->first();

        if (!$todayData || !$lastWeekData || ($lastWeekData->impressions ?? 0) < 100) {
            return [];
        }

        $todayImpressions   = (int) ($todayData->impressions   ?? 0);
        $todayClicks        = (int) ($todayData->clicks        ?? 0);
        $lastWeekImpressions = (int) ($lastWeekData->impressions ?? 0);
        $lastWeekClicks      = (int) ($lastWeekData->clicks     ?? 0);

        $todayCtr    = $todayImpressions   > 0 ? $todayClicks / $todayImpressions   : 0;
        $lastWeekCtr = $lastWeekImpressions > 0 ? $lastWeekClicks / $lastWeekImpressions : 0;

        $todayCpc    = $todayClicks    > 0 ? ($todayData->cost    ?? 0) / $todayClicks    : 0;
        $lastWeekCpc = $lastWeekClicks > 0 ? ($lastWeekData->cost ?? 0) / $lastWeekClicks : 0;

        $todayCvr    = $todayClicks    > 0 ? ($todayData->conversions    ?? 0) / $todayClicks    : 0;
        $lastWeekCvr = $lastWeekClicks > 0 ? ($lastWeekData->conversions ?? 0) / $lastWeekClicks : 0;

        // CTR drop >25%
        if ($lastWeekCtr > 0 && $todayCtr < $lastWeekCtr * 0.75 && $todayImpressions >= 100) {
            $dropPct = round((1 - $todayCtr / $lastWeekCtr) * 100);
            $alerts[] = $this->buildAlert($campaign, 'ctr_drop', "CTR dropped {$dropPct}% vs same day last week", [
                'today_ctr'     => round($todayCtr * 100, 2),
                'last_week_ctr' => round($lastWeekCtr * 100, 2),
            ]);
        }

        // CPC spike >50%
        if ($lastWeekCpc > 0 && $todayCpc > $lastWeekCpc * 1.5 && $todayClicks >= 10) {
            $spikePct = round(($todayCpc / $lastWeekCpc - 1) * 100);
            $alerts[] = $this->buildAlert($campaign, 'cpc_spike', "CPC spiked {$spikePct}% vs same day last week", [
                'today_cpc'     => round($todayCpc, 2),
                'last_week_cpc' => round($lastWeekCpc, 2),
            ]);
        }

        // Conversion rate drop >30%
        if ($lastWeekCvr > 0 && $todayCvr < $lastWeekCvr * 0.70 && $todayClicks >= 20) {
            $dropPct = round((1 - $todayCvr / $lastWeekCvr) * 100);
            $alerts[] = $this->buildAlert($campaign, 'cvr_drop', "Conversion rate dropped {$dropPct}% vs same day last week", [
                'today_cvr'     => round($todayCvr * 100, 2),
                'last_week_cvr' => round($lastWeekCvr * 100, 2),
            ]);
        }

        // Zero delivery after 2pm (local server time)
        if (now()->hour >= 14 && $todayImpressions === 0 && $lastWeekImpressions >= 100) {
            $alerts[] = $this->buildAlert($campaign, 'zero_delivery', "Zero impressions today despite normally serving at this time", [
                'last_week_impressions' => $lastWeekImpressions,
            ]);
        }

        return $alerts;
    }

    private function buildAlert(Campaign $campaign, string $type, string $summary, array $metrics): array
    {
        $cacheKey = "anomaly:{$type}:{$campaign->id}";
        if (Cache::has($cacheKey)) {
            return []; // already alerted within 6-hour window
        }

        $explanation = $this->explainAnomaly($campaign, $type, $summary, $metrics);

        $message = "{$summary} for \"{$campaign->name}\". " . ($explanation ?? 'No AI explanation available.');

        $customer = $campaign->customer;
        if ($customer) {
            $admins = \App\Models\User::where('is_admin', true)->get();
            foreach ($admins as $admin) {
                $admin->notify(new CriticalAgentAlert(
                    'performance_anomaly',
                    'Performance Anomaly Detected',
                    $message,
                    array_merge($metrics, ['campaign_id' => $campaign->id, 'anomaly_type' => $type])
                ));
            }
        }

        Cache::put($cacheKey, true, now()->addHours(6));

        Log::info("PerformanceAnomalyAlertAgent: Alert sent for campaign {$campaign->id}", [
            'type'    => $type,
            'summary' => $summary,
        ]);

        return ['type' => $type, 'summary' => $summary, 'explanation' => $explanation, 'metrics' => $metrics];
    }

    private function explainAnomaly(Campaign $campaign, string $type, string $summary, array $metrics): ?string
    {
        $platform = $campaign->google_ads_campaign_id ? 'Google Ads' : 'Facebook Ads';
        $metricsJson = json_encode($metrics);

        $prompts = [
            'ctr_drop'     => "A Google/Facebook Ads campaign called \"{$campaign->name}\" ({$platform}) has experienced this today: {$summary}. Metrics: {$metricsJson}. In one sentence, what is the most likely cause and what should be checked first?",
            'cpc_spike'    => "A {$platform} campaign called \"{$campaign->name}\" has experienced: {$summary}. Metrics: {$metricsJson}. In one sentence, what is the most likely cause (increased competition, bid changes, auction dynamics) and the first action to take?",
            'cvr_drop'     => "A {$platform} campaign called \"{$campaign->name}\" has experienced: {$summary}. Metrics: {$metricsJson}. In one sentence, what is the most likely cause (landing page issue, audience mismatch, seasonal effect) and what to check first?",
            'zero_delivery' => "A {$platform} campaign called \"{$campaign->name}\" has zero impressions today despite serving well last week. In one sentence, what are the three most likely causes to check (billing, policy, audience)?",
        ];

        $prompt = $prompts[$type] ?? "Explain in one sentence: {$summary} for campaign \"{$campaign->name}\" on {$platform}.";

        try {
            $response = $this->gemini->generateContent(
                'gemini-2.5-flash',
                $prompt,
                ['temperature' => 0.3, 'maxOutputTokens' => 100]
            );
            return trim($response['text'] ?? '');
        } catch (\Exception $e) {
            Log::debug("PerformanceAnomalyAlertAgent: Gemini explanation failed: " . $e->getMessage());
            return null;
        }
    }
}
