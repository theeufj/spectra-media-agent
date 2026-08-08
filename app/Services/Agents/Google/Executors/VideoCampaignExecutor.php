<?php

namespace App\Services\Agents\Google\Executors;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\Google\AdExtensionBuilder;
use App\Services\Agents\Google\BiddingStrategyApplier;
use App\Services\Agents\Google\GeoTargetResolver;
use App\Services\Agents\Google\LandingUrlBuilder;
use App\Services\GoogleAds\VideoServices\CreateResponsiveVideoAd;
use App\Services\GoogleAds\VideoServices\CreateVideoAdGroup;
use App\Services\GoogleAds\VideoServices\CreateVideoCampaign;
use Illuminate\Support\Facades\Log;

/**
 * Deploys a Google Ads Video campaign.
 * Extracted verbatim from GoogleAdsExecutionAgent.
 */
class VideoCampaignExecutor implements CampaignTypeExecutor
{
    public function __construct(
        protected Customer $customer,
        protected GeoTargetResolver $geo,
        protected AdExtensionBuilder $extensions,
        protected LandingUrlBuilder $urls,
        protected BiddingStrategyApplier $bidding,
    ) {}

    public function execute(
        string $customerId,
        Campaign $campaign,
        Strategy $strategy,
        ExecutionPlan $plan,
        ExecutionResult $result
    ): void {
        // Verify we are targeting the correct account
        if ($this->customer->google_ads_customer_id && $customerId !== $this->customer->google_ads_customer_id) {
            $customerId = $this->customer->google_ads_customer_id;
        }

        Log::info('GoogleAdsExecutionAgent: Creating Video Campaign in account', [
            'customer_id' => $customerId,
        ]);

        // 1. Create Campaign — idempotency guard prevents duplicates on retry
        $timestamp = now()->format('Ymd_His');
        if (! empty($campaign->google_ads_campaign_id)) {
            $campaignResourceName = $campaign->google_ads_campaign_id;
            Log::info('GoogleAdsExecutionAgent: Reusing existing Video campaign from prior attempt', [
                'campaign_id' => $campaign->id,
                'google_ads_campaign_id' => $campaignResourceName,
            ]);
            $result->addPlatformId('campaign', $campaignResourceName);
        } else {
            $createCampaignService = new CreateVideoCampaign($this->customer);
            $campaignName = $campaign->name.' - Video - '.$timestamp;

            $campaignData = [
                'businessName' => $campaignName,
                'budget' => $strategy->daily_budget ?: ($campaign->daily_budget ?: $campaign->total_budget / 30),
                'startDate' => now()->addDay()->format('Y-m-d'),
                'endDate' => now()->addYear()->format('Y-m-d'),
            ];

            $campaignResourceName = ($createCampaignService)($customerId, $campaignData);
            if (! $campaignResourceName) {
                throw new \Exception('Failed to create video campaign');
            }

            $result->addPlatformId('campaign', $campaignResourceName);
            $campaign->google_ads_campaign_id = $campaignResourceName;
            $campaign->save();
        }

        // 1.5 Add Location Targeting
        $this->geo->addLocationTargeting($customerId, $campaignResourceName, $campaign, $strategy, $plan, $result);

        // 2. Create Ad Group
        $createAdGroupService = new CreateVideoAdGroup($this->customer);
        $adGroupName = 'Video Ad Group - '.$timestamp;
        $adGroupResourceName = ($createAdGroupService)($customerId, $campaignResourceName, $adGroupName);
        if (! $adGroupResourceName) {
            throw new \Exception('Failed to create video ad group');
        }

        $result->addPlatformId('ad_group', $adGroupResourceName);

        // 3. Create Video Ad
        // Note: Video ads require a YouTube Video ID.
        // We assume the strategy or ad copy provides this, or we use a placeholder.
        $adCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%youtube%'])->first();

        if ($adCopy && ! empty($adCopy->video_id)) {
            $createAdService = new CreateResponsiveVideoAd($this->customer);

            $adData = [
                'videoId' => $adCopy->video_id,
                'headline' => $adCopy->headlines[0] ?? 'Watch Now',
                'longHeadline' => $adCopy->headlines[1] ?? 'Discover More',
                'description' => $adCopy->descriptions[0] ?? 'Click to learn more',
                'callToAction' => 'WATCH_NOW',
            ];

            $adResourceName = ($createAdService)($customerId, $adGroupResourceName, $adData);
            if ($adResourceName) {
                $result->addPlatformId('ad', $adResourceName);
            }
        } else {
            $result->addWarning('No YouTube Video ID found in Ad Copy. Skipping Video Ad creation.');
        }

        // 4. Add Ad Extensions
        $this->extensions->createAndLinkAdExtensions($customerId, $campaignResourceName, $strategy, $result);
    }

    /**
     * Execute Demand Gen campaign deployment
     *
     * Demand Gen campaigns run across YouTube, Gmail, and Discover using multi-asset ads.
     */
}
