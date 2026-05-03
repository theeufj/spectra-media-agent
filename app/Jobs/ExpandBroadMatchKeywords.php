<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\GoogleAds\CommonServices\AddKeyword;
use App\Services\GoogleAds\CommonServices\GetCampaignKeywords;
use App\Services\GoogleAds\CommonServices\RemoveKeyword;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1 — EXPAND: adds a BROAD match variant for every EXACT/PHRASE keyword
 * that doesn't already have one. No click threshold — broad match is how the
 * campaign discovers what converts; waiting for clicks before adding it is
 * circular (the keyword can't get clicks if it doesn't exist).
 *
 * Phase 2 — PRUNE: removes BROAD keywords that are older than PRUNE_AFTER_DAYS
 * and have accumulated zero clicks (all time). These are dead weight.
 *
 * Rate limit: once per 30 days per campaign (Cache key). Both phases run
 * together on each tick so pruning happens on the same cadence as expansion.
 */
class ExpandBroadMatchKeywords implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 2;
    public $timeout = 120;

    private const PRUNE_AFTER_DAYS = 45;

    public function __construct(protected Campaign $campaign) {}

    public function handle(): void
    {
        $customer = $this->campaign->customer;

        if (!$customer?->google_ads_customer_id || !$this->campaign->google_ads_campaign_id) {
            return;
        }

        $cacheKey = "broad_match_expanded:{$this->campaign->id}";
        if (Cache::has($cacheKey)) {
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

        // ── Phase 1: EXPAND ──────────────────────────────────────────────────

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

        // ── Phase 2: PRUNE ───────────────────────────────────────────────────

        $removeKeyword = new RemoveKeyword($customer);
        $pruned        = [];
        $cutoff        = Carbon::now()->subDays(self::PRUNE_AFTER_DAYS);

        foreach ($keywords as $kw) {
            if ($kw['match_type'] !== KeywordMatchType::BROAD) {
                continue;
            }

            if (($kw['clicks'] ?? 0) > 0) {
                continue;
            }

            if (empty($kw['creation_time'])) {
                continue;
            }

            $createdAt = Carbon::parse($kw['creation_time']);
            if ($createdAt->isAfter($cutoff)) {
                continue;
            }

            $ok = ($removeKeyword)($customerId, $kw['criterion_resource']);
            if ($ok) {
                $pruned[] = $kw['keyword_text'];
                Log::info("ExpandBroadMatchKeywords: Pruned zero-click broad match \"{$kw['keyword_text']}\" (created {$createdAt->toDateString()}) from campaign {$this->campaign->id}");
            }
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
