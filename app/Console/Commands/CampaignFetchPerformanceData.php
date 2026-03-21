<?php

namespace App\Console\Commands;

use App\Jobs\FetchGoogleAdsPerformanceData;
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
    protected $description = 'Fetch performance data for all active campaigns from Google Ads';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $campaigns = Campaign::where('status', 'active')->get();

        $googleCount = 0;

        foreach ($campaigns as $campaign) {
            if ($campaign->google_ads_campaign_id) {
                FetchGoogleAdsPerformanceData::dispatch($campaign);
                $googleCount++;
            }
        }

        $this->info("Successfully dispatched {$googleCount} Google Ads performance data jobs.");
    }
}
