<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\GoogleAds\CommonServices\AddKeyword;
use App\Services\GoogleAds\CommonServices\DismissRecommendation;
use App\Services\GoogleAds\CommonServices\GetCampaignKeywords;
use App\Services\GoogleAds\CommonServices\GetGoogleAdsRecommendations;
use App\Services\GoogleAds\CommonServices\RemoveKeyword;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V22\Enums\RecommendationTypeEnum\RecommendationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1 — PRUNE (only on re-runs): removes BROAD keywords that have accumulated
 * zero all-time clicks. Pruning is skipped on the first run because keywords need
 * time to accumulate data. The 30-day cache key doubles as the "has run before"
 * signal — if it exists, every broad keyword has had ≥30 days to get a click.
 *
 * Phase 2 — EXPAND: adds a BROAD match variant for every EXACT/PHRASE keyword
 * that doesn't already have one. No click threshold — broad match is how the
 * campaign discovers what converts; waiting for clicks before adding is circular
 * (the keyword can't get clicks if it doesn't exist yet).
 *
 * Rate limit: once per 30 days per campaign (Cache key).
 */
class ExpandBroadMatchKeywords implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 2;
    public $timeout = 120;

    public function __construct(protected Campaign $campaign) {}

    public function handle(): void
    {
        $customer = $this->campaign->customer;

        if (!$customer?->google_ads_customer_id || !$this->campaign->google_ads_campaign_id) {
            return;
        }

        $cacheKey    = "broad_match_expanded:{$this->campaign->id}";
        $hasRunBefore = Cache::has($cacheKey);

        if ($hasRunBefore) {
            Log::info("ExpandBroadMatchKeywords: Rate-limited, skipping campaign {$this->campaign->id}");
            return;
        }

        $customerId       = $customer->cleanGoogleCustomerId();
        $campaignResource = $this->campaign->google_ads_campaign_id;

        // Fetch all keywords without date segmentation so zero-activity keywords appear
        $getKeywords = new GetCampaignKeywords($customer);
        $keywords    = ($getKeywords)($customerId, $campaignResource, 'LAST_30_DAYS', false);

        if (empty($keywords)) {
            Log::info("ExpandBroadMatchKeywords: No keywords found for campaign {$this->campaign->id}");
            return;
        }

        // ── Phase 1: PRUNE (only on re-runs — keywords need ≥30 days to get clicks) ──
        // $hasRunBefore being true means the cache expired, so it's been ≥30 days.
        // On this first pass it's always false, so pruning is skipped intentionally.

        $removeKeyword = new RemoveKeyword($customer);
        $pruned        = [];

        if ($hasRunBefore) {
            // Fetch performance data separately (segmented query) to find which broad
            // keywords have had any clicks in the last 365 days
            $perfKeywords     = ($getKeywords)($customerId, $campaignResource, 'LAST_365_DAYS', true);
            $criteriaWithClicks = [];
            foreach ($perfKeywords as $pk) {
                if ($pk['match_type'] === KeywordMatchType::BROAD && ($pk['clicks'] ?? 0) > 0) {
                    $criteriaWithClicks[$pk['criterion_resource']] = true;
                }
            }

            foreach ($keywords as $kw) {
                if ($kw['match_type'] !== KeywordMatchType::BROAD) {
                    continue;
                }

                if (isset($criteriaWithClicks[$kw['criterion_resource']])) {
                    continue;
                }

                $ok = ($removeKeyword)($customerId, $kw['criterion_resource']);
                if ($ok) {
                    $pruned[] = $kw['keyword_text'];
                    Log::info("ExpandBroadMatchKeywords: Pruned zero-click broad match \"{$kw['keyword_text']}\" from campaign {$this->campaign->id}");
                }
            }
        }

        // ── Phase 2: EXPAND ──────────────────────────────────────────────────

        // Index which keyword texts already have a BROAD variant
        $existingBroad = [];
        foreach ($keywords as $kw) {
            if ($kw['match_type'] === KeywordMatchType::BROAD) {
                $existingBroad[strtolower($kw['keyword_text'])] = true;
            }
        }

        $addKeyword = new AddKeyword($customer);
        $added      = [];

        foreach ($keywords as $kw) {
            if ($kw['match_type'] === KeywordMatchType::BROAD) {
                continue;
            }

            $text = $kw['keyword_text'];

            // Skip single/two-word terms — broad match on short terms tends to
            // attract irrelevant queries and burn budget with no targeting signal
            if (str_word_count($text) < 3) {
                continue;
            }

            if (isset($existingBroad[strtolower($text)])) {
                continue;
            }

            $result = ($addKeyword)(
                $customerId,
                $kw['ad_group_resource'],
                $text,
                KeywordMatchType::BROAD
            );

            if ($result) {
                $existingBroad[strtolower($text)] = true;
                $added[] = $text;
                Log::info("ExpandBroadMatchKeywords: Added broad match for \"{$text}\" in campaign {$this->campaign->id}");
            }
        }

        // ── Phase 3: DISMISS the Google Ads recommendation ───────────────────
        // Always dismiss any open KEYWORD_MATCH_TYPE / USE_BROAD_MATCH_KEYWORD
        // recommendations for this campaign, regardless of whether we added new
        // keywords this run. The recommendation may predate our first expansion.

        $getRecommendations = new GetGoogleAdsRecommendations($customer);
        $allRecs            = ($getRecommendations)($customerId, $campaignResource);
        $toBeDismissed      = array_column(
            array_filter($allRecs, fn($r) => in_array($r['type'], [
                RecommendationType::KEYWORD_MATCH_TYPE,     // 14
                RecommendationType::USE_BROAD_MATCH_KEYWORD, // 20
            ])),
            'resource_name'
        );

        if (!empty($toBeDismissed)) {
            (new DismissRecommendation($customer))($customerId, $toBeDismissed);
            Log::info("ExpandBroadMatchKeywords: Dismissed " . count($toBeDismissed) . " broad match recommendation(s) for campaign {$this->campaign->id}");
        }

        // ── Record & rate-limit ──────────────────────────────────────────────

        Cache::put($cacheKey, true, now()->addDays(30));

        if (!empty($added) || !empty($pruned)) {
            $summary = [];
            if (!empty($added)) {
                $summary[] = 'Added ' . count($added) . ' broad match keyword(s): ' . implode(', ', $added);
            }
            if (!empty($pruned)) {
                $summary[] = 'Pruned ' . count($pruned) . ' zero-click broad match keyword(s): ' . implode(', ', $pruned);
            }

            AgentActivity::record(
                'keyword_expansion',
                'broad_match_updated',
                implode('. ', $summary) . " — campaign \"{$this->campaign->name}\"",
                $this->campaign->customer_id,
                $this->campaign->id,
                ['added' => $added, 'pruned' => $pruned]
            );
        }

        Log::info("ExpandBroadMatchKeywords: Complete for campaign {$this->campaign->id}", [
            'added'  => count($added),
            'pruned' => count($pruned),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ExpandBroadMatchKeywords failed for campaign {$this->campaign->id}: " . $exception->getMessage());
    }
}
