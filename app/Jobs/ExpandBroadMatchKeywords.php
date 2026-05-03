<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\GoogleAds\CommonServices\AddKeyword;
use App\Services\GoogleAds\CommonServices\GetCampaignKeywords;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Adds BROAD match variants of EXACT and PHRASE keywords that have enough
 * click data but no BROAD version yet.
 *
 * Threshold: ≥ 3 clicks in last 30 days — low enough to capture opportunity
 * on new campaigns while avoiding noise from zero-traffic keywords.
 *
 * Brand protection: keywords shorter than 3 words are skipped (single-word
 * brand/product terms rarely benefit from broad match).
 *
 * Rate limit: once per 30 days per campaign (Cache key).
 */
class ExpandBroadMatchKeywords implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 2;
    public $timeout = 120;

    private const MIN_CLICKS = 3;

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

        $customerId      = $customer->cleanGoogleCustomerId();
        $campaignResource = $this->campaign->google_ads_campaign_id;

        $getKeywords = new GetCampaignKeywords($customer);
        $keywords    = ($getKeywords)($customerId, $campaignResource);

        if (empty($keywords)) {
            Log::info("ExpandBroadMatchKeywords: No keywords found for campaign {$this->campaign->id}");
            return;
        }

        // Build a set of texts that already have a BROAD variant
        $existingBroad = [];
        foreach ($keywords as $kw) {
            if ($kw['match_type'] === KeywordMatchType::BROAD) {
                $existingBroad[strtolower($kw['keyword_text'])] = true;
            }
        }

        $addKeyword = new AddKeyword($customer);
        $added      = [];

        foreach ($keywords as $kw) {
            // Only expand EXACT and PHRASE keywords
            if ($kw['match_type'] === KeywordMatchType::BROAD) {
                continue;
            }

            $text = $kw['keyword_text'];

            // Skip brand-style single/two-word terms — broad match on short
            // terms burns budget on irrelevant queries
            if (str_word_count($text) < 3) {
                continue;
            }

            // Skip if a broad version already exists
            if (isset($existingBroad[strtolower($text)])) {
                continue;
            }

            // Require minimum click signal before expanding
            if (($kw['clicks'] ?? 0) < self::MIN_CLICKS) {
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

        Cache::put($cacheKey, true, now()->addDays(30));

        if (!empty($added)) {
            AgentActivity::record(
                'keyword_expansion',
                'broad_match_expanded',
                "Added " . count($added) . " broad match keyword(s) to \"{$this->campaign->name}\"",
                $this->campaign->customer_id,
                $this->campaign->id,
                ['added' => $added]
            );
        }

        Log::info("ExpandBroadMatchKeywords: Complete for campaign {$this->campaign->id}", [
            'added' => count($added),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ExpandBroadMatchKeywords failed for campaign {$this->campaign->id}: " . $exception->getMessage());
    }
}
