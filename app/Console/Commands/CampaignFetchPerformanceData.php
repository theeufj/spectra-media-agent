<?php

namespace App\Console\Commands;

use App\Jobs\FetchGoogleAdsPerformanceData;
use App\Jobs\FetchFacebookAdsPerformanceData;
use App\Models\Campaign;
use Illuminate\Console\Command;

class CampaignFetchPerformanceData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'campaign:fetch-performance-data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch performance data for all active campaigns from Google Ads and Facebook Ads';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $campaigns = Campaign::where('status', 'active')->get();

        $googleCount = 0;
        $facebookCount = 0;

        foreach ($campaigns as $campaign) {
            // Dispatch Google Ads performance fetch if campaign has Google Ads ID
            if ($campaign->google_ads_campaign_id) {
                FetchGoogleAdsPerformanceData::dispatch($campaign);
                $googleCount++;
            }

            // Dispatch Facebook Ads performance fetch if campaign has Facebook Ads ID
            if ($campaign->facebook_ads_campaign_id) {
                FetchFacebookAdsPerformanceData::dispatch($campaign);
                $facebookCount++;
            }
        }

        $this->info("Successfully dispatched {$googleCount} Google Ads and {$facebookCount} Facebook Ads performance data jobs.");
    }
}
