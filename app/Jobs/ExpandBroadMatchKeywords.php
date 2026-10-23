<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\Agents\GoogleSearchMutationGuard;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\CommonServices\AddKeyword;
use App\Services\GoogleAds\CommonServices\GetCampaignKeywords;
use Google\Ads\GoogleAds\V26\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Broad variants require verified demand and a faithful, affordable portfolio forecast. */
class ExpandBroadMatchKeywords implements ShouldQueue
{
    public $deleteWhenMissingModels = true;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 120;

    public function __construct(protected Campaign $campaign) {}

    public function handle(): void
    {
        if (CampaignSpendGuardrails::automaticChangesSuspended($this->campaign)) {
            Log::info('ExpandBroadMatchKeywords: Automatic keyword changes suspended by trial or spending hold', ['campaign_id' => $this->campaign->id]);

            return;
        }
        $customer = $this->campaign->customer;
        if (! $customer?->google_ads_customer_id || ! $this->campaign->google_ads_campaign_id) {
            return;
        }
        if (! $customer->conversion_tracking_verified_at || $this->campaign->googleAdsPerformanceData()
            ->whereDate('date', '>=', now()->subDays(30)->toDateString())
            ->whereDate('date', '<=', now()->toDateString())->sum('conversions') < 30) {
            Log::info('ExpandBroadMatchKeywords: Waiting for verified tracking and conversion evidence', ['campaign_id' => $this->campaign->id]);

            return;
        }
        $cacheKey = "broad_match_expanded:{$this->campaign->id}";
        if (Cache::has($cacheKey)) {
            return;
        }
        $customerId = $customer->cleanGoogleCustomerId();
        $keywords = app(GetCampaignKeywords::class, ['customer' => $customer])($customerId, $this->campaign->googleAdsResourceName(), 'LAST_30_DAYS', false);
        $existing = [];
        foreach ($keywords as $keyword) {
            if ($keyword['match_type'] === KeywordMatchType::BROAD) {
                $existing[$keyword['ad_group_resource'].'|'.mb_strtolower($keyword['keyword_text'])] = true;
            }
        }
        $changes = [];
        foreach ($keywords as $keyword) {
            $text = $keyword['keyword_text'];
            $key = $keyword['ad_group_resource'].'|'.mb_strtolower($text);
            if ($keyword['match_type'] === KeywordMatchType::BROAD || str_word_count($text) < 3 || isset($existing[$key])) {
                continue;
            }
            $changes[$key] = ['action' => 'add', 'text' => $text, 'match_type' => 'BROAD',
                'ad_group_resource' => $keyword['ad_group_resource'],
                'selection_reason' => 'Broader variant of an existing reviewed campaign keyword, subject to a fresh demand and affordable traffic assessment.'];
        }
        if ($changes === []) {
            return;
        }
        $changes = array_values($changes);
        try {
            $assessment = app(GoogleSearchMutationGuard::class)->assess($this->campaign, $changes);
        } catch (\Throwable $e) {
            report($e);
            $assessment = ['ready' => false, 'message' => 'Broad keyword demand and affordable traffic could not be verified.'];
        }
        if (! ($assessment['ready'] ?? false)) {
            $this->recordReview($changes, $assessment);

            return;
        }
        // Existing approved negatives are part of the forecast. Do not add speculative
        // AI exclusions after that assessment or silently remove existing keywords.
        $added = [];
        foreach ($changes as $change) {
            if (CampaignSpendGuardrails::automaticChangesSuspended($this->campaign)) {
                $this->recordReview($changes, ['ready' => false, 'message' => 'A trial or spending hold started before keyword expansion.']);

                break;
            }
            $resource = app(AddKeyword::class, ['customer' => $customer])($customerId,
                $change['ad_group_resource'], $change['text'], KeywordMatchType::BROAD);
            if ($resource) {
                $added[] = $change['text'];
            }
        }
        if ($added !== []) {
            AgentActivity::record('keyword_expansion', 'broad_match_updated',
                'Added '.count($added).' broad match keyword(s) after verifying the projected campaign reach.',
                $this->campaign->customer_id, $this->campaign->id, ['added' => $added, 'assessment' => $assessment]);
        }
        if (count($added) === count($changes)) {
            Cache::put($cacheKey, true, now()->addDays(30));
        }
    }

    private function recordReview(array $changes, array $assessment): void
    {
        AgentActivity::record('keyword_expansion', 'broad_match_needs_review',
            $assessment['message'] ?? 'Broad keyword expansion requires verified demand and affordable traffic at the approved settings.',
            $this->campaign->customer_id, $this->campaign->id, ['changes' => $changes, 'assessment' => $assessment]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ExpandBroadMatchKeywords failed for campaign {$this->campaign->id}: ".$exception->getMessage());
    }
}
