<?php

namespace App\Services\Agents;

use App\Models\AdCopy;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\KeywordQualityScore;
use App\Notifications\CriticalAgentAlert;
use App\Services\GeminiService;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\CommonServices\UpdateKeywordStatus;
use App\Services\GoogleAds\CommonServices\UpdateResponsiveSearchAd;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Diagnoses low Quality Score keywords and takes targeted corrective action.
 *
 * Three root causes (from Google's three sub-scores):
 *   search_predicted_ctr: BELOW_AVERAGE  → generate ad copy variations that feature the keyword
 *   creative_quality_score: BELOW_AVERAGE → flag for SKAG review (keyword in wrong ad group)
 *   post_click_quality_score: BELOW_AVERAGE → log landing page improvement recommendation
 *
 * Pauses keywords stuck at QS < threshold for > pause_min_days consecutive days.
 * Thresholds are configured in config/optimization.php.
 */
class QualityScoreImprovementAgent
{
    public function __construct(private GeminiService $gemini) {}

    public function improve(Campaign $campaign): array
    {
        $customer = $campaign->customer;

        if (!$customer?->google_ads_customer_id || !$campaign->google_ads_campaign_id) {
            return ['skipped' => true];
        }

        $customerId  = $customer->cleanGoogleCustomerId();
        $pauseDays   = config('optimization.quality_score.pause_min_days', 21);
        $qsThreshold = config('optimization.quality_score.pause_qs_threshold', 5);

        $decliners = KeywordQualityScore::where('customer_id', $campaign->customer_id)
            ->where('campaign_google_id', $campaign->google_ads_campaign_id)
            ->declining($pauseDays)
            ->get()
            ->groupBy('keyword_text');

        $actions = [];
        $flagged  = [];
        $paused   = [];
        $errors   = [];

        foreach ($decliners as $keywordText => $records) {
            $latest = $records->sortByDesc('recorded_at')->first();

            try {
                $this->diagnoseAndAct(
                    $campaign,
                    $customer,
                    $customerId,
                    $latest,
                    $records,
                    $pauseDays,
                    $qsThreshold,
                    $actions,
                    $flagged,
                    $paused,
                    $errors
                );
            } catch (\Exception $e) {
                $errors[] = "Error processing keyword '{$keywordText}': " . $e->getMessage();
                Log::error('QualityScoreImprovementAgent: ' . $e->getMessage());
            }
        }

        if (!empty($actions) || !empty($paused)) {
            $total = count($actions) + count($paused);
            AgentActivity::record(
                'quality_score',
                'qs_improvements_applied',
                "Applied {$total} QS action(s) for \"{$campaign->name}\"",
                $campaign->customer_id,
                $campaign->id,
                ['actions' => $actions, 'paused' => $paused, 'flagged' => $flagged, 'errors' => $errors]
            );
        }

        // Send ONE aggregated alert if any keywords were paused this run
        if (!empty($paused)) {
            $this->notifyPaused($campaign, $paused, $qsThreshold, $pauseDays);
        }

        return [
            'actions' => $actions,
            'flagged' => $flagged,
            'paused'  => $paused,
            'errors'  => $errors,
        ];
    }

    private function diagnoseAndAct(
        Campaign $campaign,
        object $customer,
        string $customerId,
        KeywordQualityScore $latest,
        $allRecords,
        int $pauseDays,
        int $qsThreshold,
        array &$actions,
        array &$flagged,
        array &$paused,
        array &$errors
    ): void {
        $keyword   = $latest->keyword_text;
        $qs        = $latest->quality_score;
        $ctr       = $latest->search_predicted_ctr;
        $creative  = $latest->creative_quality_score;
        $postClick = $latest->post_click_quality_score;

        $isStuck = $allRecords->count() >= 2
            && $allRecords->every(fn($r) => ($r->quality_score ?? 10) < $qsThreshold)
            && $allRecords->min('recorded_at') <= now()->subDays($pauseDays);

        // Root cause 1: Low expected CTR → generate tighter ad copy
        if ($ctr === 'BELOW_AVERAGE' && !$isStuck) {
            $strategy = $campaign->strategies()
                ->whereNotNull('google_ads_ad_group_id')
                ->latest()
                ->first()
                ?? $campaign->strategies()->latest()->first();

            if ($strategy) {
                $variations = $this->generateAdCopyVariations($campaign, $customer, $keyword);

                if (!empty($variations)) {
                    foreach ($variations as $variant) {
                        AdCopy::create([
                            'strategy_id'  => $strategy->id,
                            'platform'     => 'Google Ads',
                            'headlines'    => $variant['headlines'],
                            'descriptions' => $variant['descriptions'],
                        ]);
                    }

                    AgentActivity::record(
                        'quality_score',
                        'ad_copy_generated',
                        "Generated " . count($variations) . " ad variation(s) for keyword '{$keyword}' (QS={$qs}, CTR=Below Average) in \"{$campaign->name}\"",
                        $campaign->customer_id,
                        $campaign->id
                    );

                    $actions[] = [
                        'keyword'  => $keyword,
                        'action'   => 'generated_ad_copy',
                        'variants' => count($variations),
                        'reason'   => 'search_predicted_ctr: BELOW_AVERAGE',
                    ];
                }
            }
        }

        // Root cause 2: Poor ad relevance → flag for SKAG review
        if ($creative === 'BELOW_AVERAGE') {
            $flagged[] = [
                'keyword'        => $keyword,
                'issue'          => 'creative_quality_score: BELOW_AVERAGE',
                'recommendation' => "Consider moving '{$keyword}' to a dedicated single-keyword ad group (SKAG) for tighter ad relevance.",
            ];

            AgentActivity::record(
                'quality_score',
                'skag_recommended',
                "Keyword '{$keyword}' flagged for SKAG in \"{$campaign->name}\" (creative QS below average)",
                $campaign->customer_id,
                $campaign->id
            );
        }

        // Root cause 3: Poor landing page → log improvement recommendation
        if ($postClick === 'BELOW_AVERAGE') {
            $flagged[] = [
                'keyword'        => $keyword,
                'issue'          => 'post_click_quality_score: BELOW_AVERAGE',
                'recommendation' => "Landing page for '{$keyword}' needs: keyword in H1/title, faster load time, and content that directly addresses search intent.",
            ];

            AgentActivity::record(
                'quality_score',
                'landing_page_flagged',
                "Landing page flagged for keyword '{$keyword}' in \"{$campaign->name}\" (post-click QS below average)",
                $campaign->customer_id,
                $campaign->id
            );
        }

        // Auto-pause if stuck at low QS for extended period
        if ($isStuck && $latest->criterion_resource_name) {
            $service = new UpdateKeywordStatus($customer);
            $success = $service->pause($customerId, $latest->criterion_resource_name);

            if ($success) {
                $paused[] = ['keyword' => $keyword, 'qs' => $qs, 'days' => $pauseDays];
            } else {
                $errors[] = "Failed to pause keyword '{$keyword}'";
            }
        }
    }

    private function notifyPaused(Campaign $campaign, array $paused, int $qsThreshold, int $pauseDays): void
    {
        $cacheKey = "notif:qs_paused:{$campaign->id}";
        if (Cache::has($cacheKey)) {
            return;
        }

        $keywordList = implode(', ', array_column($paused, 'keyword'));
        $count       = count($paused);
        $message     = "{$count} keyword(s) were paused in \"{$campaign->name}\" after remaining below QS {$qsThreshold} for {$pauseDays}+ days: {$keywordList}.";

        $admins = \App\Models\User::where('is_admin', true)->get();
        foreach ($admins as $admin) {
            $admin->notify(new CriticalAgentAlert(
                'quality_score',
                'Low-QS Keywords Paused',
                $message,
                [
                    'campaign_name' => $campaign->name,
                    'campaign_id'   => $campaign->id,
                    'paused'        => $paused,
                ]
            ));
        }

        Cache::put($cacheKey, true, now()->addHours(24));
    }

    private function generateAdCopyVariations(Campaign $campaign, object $customer, string $keyword): array
    {
        $businessName = $customer->name;
        $landingPage  = $customer->website ?? '';

        $prompt = <<<PROMPT
You are an expert Google Ads copywriter. Generate 2 Responsive Search Ad variations specifically designed to improve Quality Score for the keyword: "{$keyword}"

Business: {$businessName}
Campaign: {$campaign->name}
Landing page: {$landingPage}

Requirements:
- Include the exact keyword in at least 2 headlines
- Headlines: max 30 characters each, provide 5 headlines
- Descriptions: max 90 characters each, provide 2 descriptions
- Focus on the specific intent behind "{$keyword}"
- Strong call to action

Return ONLY valid JSON array:
[
  {
    "headlines": ["...", "...", "...", "...", "..."],
    "descriptions": ["...", "..."]
  },
  {
    "headlines": ["...", "...", "...", "...", "..."],
    "descriptions": ["...", "..."]
  }
]
PROMPT;

        try {
            $response = $this->gemini->generateContent(config('ai.models.default'), $prompt);
            $text = $response['text'] ?? '';
            $text = preg_replace('/```json\s*|\s*```/', '', $text);
            $data = json_decode(trim($text), true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            }
        } catch (\Exception $e) {
            Log::error('QualityScoreImprovementAgent: Ad copy generation failed: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Fetch RSA ad strength for all ads in the campaign and improve POOR/AVERAGE ones
     * by generating additional headline/description assets via Gemini.
     *
     * Ad strength enum: POOR=1, AVERAGE=2, GOOD=3, EXCELLENT=4
     */
    public function checkAdStrength(Campaign $campaign): array
    {
        $customer = $campaign->customer;

        if (!$customer?->google_ads_customer_id || !$campaign->google_ads_campaign_id) {
            return ['skipped' => true];
        }

        $cacheKey = "ad_strength_check:{$campaign->id}";
        if (Cache::has($cacheKey)) {
            return ['skipped' => 'recently_checked'];
        }
        Cache::put($cacheKey, true, now()->addHours(23));

        $customerId = $customer->cleanGoogleCustomerId();
        $actions    = [];
        $errors     = [];

        try {
            // Fetch ad strength + existing copy via GAQL
            $service = new class($customer) extends BaseGoogleAdsService {
                public function fetchAdStrength(string $customerId, string $campaignResource): array
                {
                    $this->ensureClient();
                    $query = "SELECT ad_group_ad.resource_name, ad_group_ad.ad.id, ad_group_ad.ad_strength, "
                           . "ad_group_ad.ad.responsive_search_ad.headlines, "
                           . "ad_group_ad.ad.responsive_search_ad.descriptions, "
                           . "ad_group.resource_name "
                           . "FROM ad_group_ad "
                           . "WHERE campaign.resource_name = '{$campaignResource}' "
                           . "AND ad_group_ad.status = 'ENABLED' "
                           . "AND ad_group_ad.ad.type = 'RESPONSIVE_SEARCH_AD'";

                    $results = [];
                    foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
                        $rsa = $row->getAdGroupAd()->getAd()->getResponsiveSearchAd();
                        $headlines    = [];
                        $descriptions = [];
                        foreach ($rsa->getHeadlines() as $asset) {
                            $headlines[] = $asset->getText();
                        }
                        foreach ($rsa->getDescriptions() as $asset) {
                            $descriptions[] = $asset->getText();
                        }
                        $results[] = [
                            'resource_name' => $row->getAdGroupAd()->getResourceName(),
                            'ad_id'         => $row->getAdGroupAd()->getAd()->getId(),
                            'ad_strength'   => $row->getAdGroupAd()->getAdStrength(),
                            'ad_group'      => $row->getAdGroup()->getResourceName(),
                            'headlines'     => $headlines,
                            'descriptions'  => $descriptions,
                        ];
                    }
                    return $results;
                }
            };

            $resourceName = $campaign->google_ads_campaign_id;
            if (!str_starts_with($resourceName, 'customers/')) {
                $resourceName = "customers/{$customerId}/campaigns/{$resourceName}";
            }

            $ads = $service->fetchAdStrength($customerId, $resourceName);

            // Ad strength enum: 0=UNSPECIFIED, 1=UNKNOWN, 2=PENDING, 3=NO_ADS, 4=POOR, 5=AVERAGE, 6=GOOD, 7=EXCELLENT
            $weakAds = array_filter($ads, fn($ad) => in_array($ad['ad_strength'], [4, 5], true));

            $updater = new UpdateResponsiveSearchAd($customer);

            foreach ($weakAds as $ad) {
                $strengthLabel = $ad['ad_strength'] === 4 ? 'POOR' : 'AVERAGE';
                Log::info("QualityScoreImprovementAgent: RSA ad {$ad['ad_id']} has {$strengthLabel} strength", [
                    'campaign_id' => $campaign->id,
                ]);

                $newCopy = $this->generateAdStrengthCopy($campaign, $customer);
                if (empty($newCopy)) {
                    continue;
                }

                $newHeadlines    = array_merge(...array_column($newCopy, 'headlines'));
                $newDescriptions = array_merge(...array_column($newCopy, 'descriptions'));

                $updated = ($updater)(
                    $customerId,
                    $ad['resource_name'],
                    $ad['headlines'],
                    $ad['descriptions'],
                    $newHeadlines,
                    $newDescriptions
                );

                if ($updated) {
                    $actions[] = [
                        'ad_id'           => $ad['ad_id'],
                        'strength_before' => $strengthLabel,
                        'headlines_added' => count($newHeadlines),
                    ];
                } else {
                    $errors[] = "Could not update RSA {$ad['ad_id']} (no new assets or API error)";
                }
            }
        } catch (\Exception $e) {
            $errors[] = $e->getMessage();
            Log::warning("QualityScoreImprovementAgent: Ad strength check failed for campaign {$campaign->id}: " . $e->getMessage());
        }

        if (!empty($actions)) {
            AgentActivity::record(
                'quality_score',
                'ad_strength_improved',
                "Pushed " . count($actions) . " RSA ad strength improvement(s) for \"{$campaign->name}\"",
                $campaign->customer_id,
                $campaign->id,
                ['actions' => $actions, 'errors' => $errors]
            );
        }

        return ['actions' => $actions, 'errors' => $errors];
    }

    private function generateAdStrengthCopy(Campaign $campaign, object $customer): array
    {
        $prompt = <<<PROMPT
You are a Google Ads copywriter. Generate 3 additional RSA headlines and 2 additional descriptions for this campaign to improve its Ad Strength rating.

Business: {$customer->name}
Campaign: {$campaign->name}
Goal: {$campaign->goals}

Requirements:
- Headlines: max 30 characters each
- Descriptions: max 90 characters each
- Be specific, use numbers and CTAs
- Vary messaging angles (price, quality, urgency, benefit)

Return ONLY valid JSON:
[{"headlines": ["...", "...", "..."], "descriptions": ["...", "..."]}]
PROMPT;

        try {
            $response = $this->gemini->generateContent(config('ai.models.default'), $prompt);
            $text = preg_replace('/```json\s*|\s*```/', '', $response['text'] ?? '');
            $data = json_decode(trim($text), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            }
        } catch (\Exception $e) {
            Log::error('QualityScoreImprovementAgent: Ad strength copy generation failed: ' . $e->getMessage());
        }

        return [];
    }
}
