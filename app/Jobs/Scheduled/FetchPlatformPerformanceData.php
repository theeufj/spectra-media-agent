<?php

namespace App\Jobs\Scheduled;

use App\Jobs\FetchFacebookAdsPerformanceData;
use App\Jobs\FetchGoogleAdsPerformanceData;
use App\Jobs\FetchLinkedInAdsPerformanceData;
use App\Jobs\FetchMicrosoftAdsPerformanceData;
use App\Models\Campaign;
use App\Models\EnabledPlatform;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pull the latest performance figures for every deployed campaign, per platform.
 *
 * Was a Schedule::call() closure. Those run inside the scheduler tick: the
 * query, the fan-out and any HTTP all happen synchronously in that one process,
 * with no tries, no timeout, and no route to the admin exception dashboard —
 * and withoutOverlapping() then locks out the next run while it hangs. As a
 * queued job it retries, times out, and a failure reaches Queue::failing.
 */
class FetchPlatformPerformanceData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function handle(): void
    {
        Campaign::withDeployedPlatforms()->each(function ($campaign) {
            if ($campaign->google_ads_campaign_id && EnabledPlatform::isEnabled('google')) {
                FetchGoogleAdsPerformanceData::dispatch($campaign);
            }
            if ($campaign->facebook_ads_campaign_id && EnabledPlatform::isEnabled('facebook')) {
                FetchFacebookAdsPerformanceData::dispatch($campaign);
            }
            if ($campaign->microsoft_ads_campaign_id && EnabledPlatform::isEnabled('microsoft')) {
                FetchMicrosoftAdsPerformanceData::dispatch($campaign);
            }
            if ($campaign->linkedin_campaign_id && EnabledPlatform::isEnabled('linkedin')) {
                FetchLinkedInAdsPerformanceData::dispatch($campaign);
            }
        });
    }
}
