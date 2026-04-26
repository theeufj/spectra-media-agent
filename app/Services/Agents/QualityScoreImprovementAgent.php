<?php

namespace App\Services\Agents;

use App\Models\AdCopy;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\KeywordQualityScore;
use App\Notifications\CriticalAgentAlert;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\UpdateKeywordStatus;
use Illuminate\Support\Facades\Log;

/**
 * Diagnoses low Quality Score keywords and takes targeted corrective action.
 *
 * Three root causes (from Google's three sub-scores):
 *   search_predicted_ctr: BELOW_AVERAGE  → generate ad copy variations that feature the keyword
 *   creative_quality_score: BELOW_AVERAGE → flag for SKAG review (keyword in wrong ad group)
 *   post_click_quality_score: BELOW_AVERAGE → log landing page improvement recommendation
 *
 * Pauses keywords stuck at QS <5 for >14 consecutive days with no improvement.
 */
class QualityScoreImprovementAgent
{
    private const PAUSE_AFTER_DAYS = 14;
    private const QS_THRESHOLD    = 5;

    public function __construct(private GeminiService $gemini) {}

    public function improve(Campaign $campaign): array
    {
        $customer = $campaign->customer;

        if (!$customer?->google_ads_customer_id || !$campaign->google_ads_campaign_id) {
            return ['skipped' => true];
        }

        $customerId = str_replace('-', '', $customer->google_ads_customer_id);

        // Find all keywords with QS <5 in the last 14 days for this campaign
        $decliners = KeywordQualityScore::where('customer_id', $campaign->customer_id)
            ->where('campaign_google_id', $campaign->google_ads_campaign_id)
            ->declining(self::PAUSE_AFTER_DAYS)
            ->get()
            ->groupBy('keyword_text');

        $actions      = [];
        $flagged      = [];
        $paused       = [];
        $errors       = [];

        foreach ($decliners as $keywordText => $records) {
            $latest = $records->sortByDesc('recorded_at')->first();

            try {
                $this->diagnoseAndAct(
                    $campaign,
                    $customer,
                    $customerId,
                    $latest,
                    $records,
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

        return [
            'actions'  => $actions,
            'flagged'  => $flagged,
            'paused'   => $paused,
            'errors'   => $errors,
        ];
    }

    private function diagnoseAndAct(
        Campaign $campaign,
        object $customer,
        string $customerId,
        KeywordQualityScore $latest,
        $allRecords,
        array &$actions,
        array &$flagged,
        array &$paused,
        array &$errors
    ): void {
        $keyword   = $latest->keyword_text;
        $qs        = $latest->quality_score;
        $ctr       = $latest->search_predicted_ctr;        // ABOVE_AVERAGE | AVERAGE | BELOW_AVERAGE
        $creative  = $latest->creative_quality_score;      // ABOVE_AVERAGE | AVERAGE | BELOW_AVERAGE
        $postClick = $latest->post_click_quality_score;    // ABOVE_AVERAGE | AVERAGE | BELOW_AVERAGE

        // Check if stuck (all records in window have QS < threshold)
        $isStuck = $allRecords->count() >= 2
            && $allRecords->every(fn($r) => ($r->quality_score ?? 10) < self::QS_THRESHOLD)
            && $allRecords->min('recorded_at') <= now()->subDays(self::PAUSE_AFTER_DAYS);

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
                'keyword' => $keyword,
                'issue'   => 'creative_quality_score: BELOW_AVERAGE',
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
                'keyword' => $keyword,
                'issue'   => 'post_click_quality_score: BELOW_AVERAGE',
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
                $paused[] = ['keyword' => $keyword, 'qs' => $qs, 'days' => self::PAUSE_AFTER_DAYS];

                // Notify admins
                $admins = \App\Models\User::where('is_admin', true)->get();
                foreach ($admins as $admin) {
                    $admin->notify(new CriticalAgentAlert(
                        'quality_score',
                        "Keyword '{$keyword}' paused in campaign \"{$campaign->name}\" — stuck at QS {$qs} for 14+ days.",
                        ['customer_id' => $campaign->customer_id, 'campaign_id' => $campaign->id]
                    ));
                }
            } else {
                $errors[] = "Failed to pause keyword '{$keyword}'";
            }
        }
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
            $response = $this->gemini->generateContent('gemini-2.0-flash', $prompt);
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
}
