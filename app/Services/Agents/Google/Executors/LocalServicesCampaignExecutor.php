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
use App\Services\GoogleAds\LocalServicesServices\CreateLocalServicesCampaign;
use Illuminate\Support\Facades\Log;

/**
 * Deploys a Google Ads Local Services campaign.
 * Extracted verbatim from GoogleAdsExecutionAgent.
 */
class LocalServicesCampaignExecutor implements CampaignTypeExecutor
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
        if ($this->customer->google_ads_customer_id && $customerId !== $this->customer->google_ads_customer_id) {
            $customerId = $this->customer->google_ads_customer_id;
        }

        Log::info('GoogleAdsExecutionAgent: Creating Local Services Campaign', ['customer_id' => $customerId]);

        // 1. Create Campaign (Local Services campaigns don't need ad groups or ads)
        $campaignStructure = $plan->getCampaignStructure();
        $timestamp = now()->format('Ymd_His');

        if (! empty($campaign->google_ads_campaign_id)) {
            $campaignResourceName = $campaign->google_ads_campaign_id;
            Log::info('GoogleAdsExecutionAgent: Reusing existing Local Services campaign from prior attempt', [
                'campaign_id' => $campaign->id,
                'google_ads_campaign_id' => $campaignResourceName,
            ]);
            $result->addPlatformId('campaign', $campaignResourceName);
        } else {
            $createCampaignService = new CreateLocalServicesCampaign($this->customer);
            $campaignName = $campaign->name.' - Local Services - '.$timestamp;

            $campaignData = [
                'businessName' => $campaignName,
                'budget' => $strategy->daily_budget ?: ($campaign->daily_budget ?: $campaign->total_budget / 30),
                'startDate' => now()->addDay()->format('Y-m-d'),
                'endDate' => now()->addYear()->format('Y-m-d'),
                'categoryBids' => $campaignStructure['category_bids'] ?? [],
            ];

            $campaignResourceName = ($createCampaignService)($customerId, $campaignData);
            if (! $campaignResourceName) {
                throw new \Exception('Failed to create Local Services campaign');
            }

            $result->addPlatformId('campaign', $campaignResourceName);
            $campaign->google_ads_campaign_id = $campaignResourceName;
            $campaign->save();
        }

        // 1.5 Add Location Targeting (critical for local services)
        $this->geo->addLocationTargeting($customerId, $campaignResourceName, $campaign, $strategy, $plan, $result);
    }

    /**
     * Get keywords from campaign, targeting config, or execution plan
     */
}
