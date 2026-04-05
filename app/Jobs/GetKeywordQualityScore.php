<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\KeywordQualityScore;
use App\Services\GoogleAds\AccountStructureService;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GetKeywordQualityScore implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $campaignId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $campaignId)
    {
        $this->campaignId = $campaignId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $campaign = Campaign::findOrFail($this->campaignId);
            $service = new AccountStructureService($campaign->customer);
            $client = $service->getClient();

            if (!$client) {
                Log::error("Google Ads client not available for campaign {$this->campaignId}");
                return;
            }

            $googleAdsServiceClient = $client->getGoogleAdsServiceClient();

            $query = "SELECT "
                . "ad_group_criterion.keyword.text, "
                . "ad_group_criterion.keyword.match_type, "
                . "ad_group_criterion.quality_info.quality_score, "
                . "ad_group_criterion.quality_info.creative_quality_score, "
                . "ad_group_criterion.quality_info.post_click_quality_score, "
                . "ad_group_criterion.quality_info.search_predicted_ctr, "
                . "ad_group_criterion.cpc_bid_micros, "
                . "ad_group_criterion.resource_name, "
                . "ad_group.resource_name, "
                . "metrics.impressions, "
                . "metrics.clicks, "
                . "metrics.conversions, "
                . "metrics.cost_micros "
                . "FROM keyword_view "
                . "WHERE campaign.id = {$campaign->google_ads_campaign_id}";

            $response = $googleAdsServiceClient->search(new SearchGoogleAdsRequest([
                'customer_id' => $campaign->customer->google_ads_customer_id,
                'query' => $query,
            ]));

            $customerId = $campaign->customer->id;

            foreach ($response->getIterator() as $googleAdsRow) {
                $criterion = $googleAdsRow->getAdGroupCriterion();
                $keyword = $criterion->getKeyword()->getText();
                $qualityInfo = $criterion->getQualityInfo();
                $qualityScore = $qualityInfo?->getQualityScore();
                $metrics = $googleAdsRow->getMetrics();

                KeywordQualityScore::create([
                    'customer_id' => $customerId,
                    'campaign_google_id' => $campaign->google_ads_campaign_id,
                    'ad_group_resource_name' => $googleAdsRow->getAdGroup()->getResourceName(),
                    'criterion_resource_name' => $criterion->getResourceName(),
                    'keyword_text' => $keyword,
                    'match_type' => $criterion->getKeyword()->getMatchType(),
                    'quality_score' => $qualityScore ?: null,
                    'creative_quality_score' => $qualityInfo?->getCreativeQualityScore(),
                    'post_click_quality_score' => $qualityInfo?->getPostClickQualityScore(),
                    'search_predicted_ctr' => $qualityInfo?->getSearchPredictedCtr(),
                    'impressions' => $metrics->getImpressions(),
                    'clicks' => $metrics->getClicks(),
                    'conversions' => $metrics->getConversions(),
                    'cost_micros' => $metrics->getCostMicros(),
                    'cpc_bid_micros' => $criterion->getCpcBidMicros(),
                    'recorded_at' => now(),
                ]);

                Log::info("Keyword '{$keyword}' QS: {$qualityScore}", [
                    'campaign_id' => $this->campaignId,
                    'keyword' => $keyword,
                    'quality_score' => $qualityScore,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Error getting keyword Quality Score for campaign {$this->campaignId}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
