<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\GoogleAds\GoogleAdsService;
use App\Services\GoogleAds\NegativeKeywords\AddNegativeKeywordService;
use Google\Ads\GoogleAds\V15\Services\GoogleAdsServiceClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FindUnderperformingKeywords implements ShouldQueue
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
    public function handle(AddNegativeKeywordService $addNegativeKeywordService): void
    {
        try {
            $campaign = Campaign::findOrFail($this->campaignId);
            $googleAdsService = new GoogleAdsService($campaign->user->customer);
            $googleAdsServiceClient = $googleAdsService->getClient()->getGoogleAdsServiceClient();

            $query = "SELECT ad_group_criterion.keyword.text, metrics.clicks, metrics.impressions, metrics.conversions FROM keyword_view WHERE campaign.id = {$campaign->google_ads_campaign_id} AND metrics.conversions = 0 AND metrics.clicks > 100";

            $response = $googleAdsServiceClient->search($campaign->customer->google_ads_customer_id, $query);

            foreach ($response->getIterator() as $googleAdsRow) {
                $keyword = $googleAdsRow->getAdGroupCriterion()->getKeyword()->getText();
                ($addNegativeKeywordService)($this->campaignId, $keyword);
            }
        } catch (\Exception $e) {
            Log::error("Error finding underperforming keywords for campaign {$this->campaignId}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
