<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\GoogleAds\GoogleAdsService;
use Google\Ads\GoogleAds\V15\Services\GoogleAdsServiceClient;
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
            $googleAdsService = new GoogleAdsService();
            $client = $googleAdsService->getClient();
            $googleAdsServiceClient = $client->getGoogleAdsServiceClient();

            $query = "SELECT ad_group_criterion.keyword.text, ad_group_criterion.quality_info.quality_score FROM keyword_view WHERE campaign.id = {$campaign->google_ads_campaign_id}";

            $response = $googleAdsServiceClient->search($campaign->customer->google_ads_customer_id, $query);

            foreach ($response->getIterator() as $googleAdsRow) {
                $keyword = $googleAdsRow->getAdGroupCriterion()->getKeyword()->getText();
                $qualityScore = $googleAdsRow->getAdGroupCriterion()->getQualityInfo()->getQualityScore();
                Log::info("Keyword '{$keyword}' has a Quality Score of {$qualityScore}.", [
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
