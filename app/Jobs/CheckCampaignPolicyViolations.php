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

class CheckCampaignPolicyViolations implements ShouldQueue
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

            $query = "SELECT ad_group_ad.policy_summary FROM ad_group_ad WHERE campaign.id = {$campaign->google_ads_campaign_id}";

            $response = $googleAdsServiceClient->search($campaign->customer->google_ads_customer_id, $query);

            foreach ($response->getIterator() as $googleAdsRow) {
                $policySummary = $googleAdsRow->getAdGroupAd()->getPolicySummary();
                if (count($policySummary->getPolicyTopicEntries()) > 0) {
                    Log::warning("Policy violation found for campaign {$this->campaignId}. Pausing campaign.", [
                        'policy_summary' => $policySummary->serializeToJsonString(),
                    ]);
                    $campaign->update(['status' => 'PAUSED']);
                    break;
                }
            }
        } catch (\Exception $e) {
            Log::error("Error checking for policy violations for campaign {$this->campaignId}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
