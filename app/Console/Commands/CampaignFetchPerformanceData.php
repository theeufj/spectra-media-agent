<?php

namespace App\Console\Commands;

use App\Jobs\FetchGoogleAdsPerformanceData;
use App\Jobs\FetchFacebookAdsPerformanceData;
use App\Jobs\FetchLinkedInAdsPerformanceData;
use App\Jobs\FetchMicrosoftAdsPerformanceData;
use App\Models\Campaign;
use Illuminate\Console\Command;

class CampaignFetchPerformanceData extends Command
{
    protected $signature = 'campaign:fetch-performance-data';

    protected $description = 'Fetch performance data for all active campaigns across all platforms';

    public function handle()
    {
        $campaigns = Campaign::where('status', 'active')->get();

        $counts = ['google' => 0, 'facebook' => 0, 'linkedin' => 0, 'microsoft' => 0];

        foreach ($campaigns as $campaign) {
            if ($campaign->google_ads_campaign_id) {
                FetchGoogleAdsPerformanceData::dispatch($campaign);
                $counts['google']++;
            }

            if ($campaign->facebook_ads_campaign_id) {
                FetchFacebookAdsPerformanceData::dispatch($campaign);
                $counts['facebook']++;
            }

            if ($campaign->linkedin_campaign_id) {
                FetchLinkedInAdsPerformanceData::dispatch($campaign);
                $counts['linkedin']++;
            }

            if ($campaign->microsoft_ads_campaign_id) {
                FetchMicrosoftAdsPerformanceData::dispatch($campaign);
                $counts['microsoft']++;
            }
        }

        $this->info(
            "Dispatched performance jobs — " .
            "Google: {$counts['google']}, Facebook: {$counts['facebook']}, " .
            "LinkedIn: {$counts['linkedin']}, Microsoft: {$counts['microsoft']}."
        );
    }
}
