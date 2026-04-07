<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\GoogleAds\AccountStructureService;
use App\Services\GoogleAds\NegativeKeywords\AddNegativeKeywordService;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest;
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
            $service = new AccountStructureService($campaign->customer);
            $googleAdsServiceClient = $service->getClient()->getGoogleAdsServiceClient();

            $query = "SELECT ad_group_criterion.keyword.text, metrics.clicks, metrics.impressions, metrics.conversions FROM keyword_view WHERE campaign.id = {$campaign->google_ads_campaign_id} AND metrics.conversions = 0 AND metrics.clicks > 100";

            $response = $googleAdsServiceClient->search(new SearchGoogleAdsRequest([
                'customer_id' => $campaign->customer->google_ads_customer_id,
                'query' => $query,
            ]));

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

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('FindUnderperformingKeywords failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
