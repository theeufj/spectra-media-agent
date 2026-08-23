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
use App\Services\GoogleAds\ShoppingServices\CreateShoppingAdGroup;
use App\Services\GoogleAds\ShoppingServices\CreateShoppingCampaign;
use App\Services\GoogleAds\ShoppingServices\CreateShoppingProductAd;
use Illuminate\Support\Facades\Log;

/**
 * Deploys a Google Ads Shopping campaign.
 * Extracted verbatim from GoogleAdsExecutionAgent.
 */
class ShoppingCampaignExecutor implements CampaignTypeExecutor
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

        Log::info('GoogleAdsExecutionAgent: Creating Shopping Campaign', ['customer_id' => $customerId]);

        // Validate Merchant Center ID
        $merchantId = $this->customer->google_merchant_id
            ?? $plan->getCampaignStructure()['merchant_id']
            ?? null;

        if (! $merchantId) {
            throw new \Exception('Shopping campaigns require a linked Google Merchant Center account. No merchant_id found.');
        }

        // 1. Create Campaign — idempotency guard prevents duplicates on retry
        $timestamp = now()->format('Ymd_His');
        if ($reusableGoogleCampaign = $strategy->reusableGoogleCampaignId()) {
            $campaignResourceName = $reusableGoogleCampaign;
            Log::info('GoogleAdsExecutionAgent: Reusing existing Shopping campaign from prior attempt', [
                'campaign_id' => $campaign->id,
                'google_ads_campaign_id' => $campaignResourceName,
            ]);
            $result->addPlatformId('campaign', $campaignResourceName);
        } else {
            $createCampaignService = new CreateShoppingCampaign($this->customer);
            $campaignStructure = $plan->getCampaignStructure();
            $campaignName = $campaign->name.' - Shopping - '.$timestamp;

            $campaignData = [
                'businessName' => $campaignName,
                'budget' => $strategy->daily_budget ?: ($campaign->daily_budget ?: $campaign->total_budget / 30),
                'startDate' => now()->addDay()->format('Y-m-d'),
                'endDate' => now()->addYear()->format('Y-m-d'),
                'merchantId' => $merchantId,
                'feedLabel' => $plan->getCampaignStructure()['feed_label'] ?? null,
                'campaignPriority' => $plan->getCampaignStructure()['campaign_priority'] ?? 0,
                'enableLocal' => $plan->getCampaignStructure()['enable_local'] ?? false,
                'targetCpaMicros' => $strategy->cpa_target ?? null,
            ];

            $campaignResourceName = ($createCampaignService)($customerId, $campaignData);
            if (! $campaignResourceName) {
                throw new \Exception('Failed to create Shopping campaign');
            }

            $result->addPlatformId('campaign', $campaignResourceName);
            $strategy->recordGoogleCampaignId($campaignResourceName);
        }

        // 1.5 Add Location Targeting
        $this->geo->addLocationTargeting($customerId, $campaignResourceName, $campaign, $strategy, $plan, $result);

        // 2. Create Ad Group — reuse if already created in a prior attempt
        if (! empty($strategy->google_ads_ad_group_id)) {
            $adGroupResourceName = $strategy->google_ads_ad_group_id;
            $result->addPlatformId('ad_group', $adGroupResourceName);
        } else {
            $createAdGroupService = new CreateShoppingAdGroup($this->customer);
            $adGroupName = 'All Products - '.$timestamp;
            $adGroupResourceName = ($createAdGroupService)($customerId, $campaignResourceName, $adGroupName);
            if (! $adGroupResourceName) {
                throw new \Exception('Failed to create Shopping ad group');
            }

            $result->addPlatformId('ad_group', $adGroupResourceName);
            $strategy->google_ads_ad_group_id = $adGroupResourceName;
            $strategy->save();
        }

        // 3. Create Shopping Product Ad (auto-generated from feed)
        $createAdService = new CreateShoppingProductAd($this->customer);
        $adResourceName = ($createAdService)($customerId, $adGroupResourceName);
        if ($adResourceName) {
            $result->addPlatformId('ad', $adResourceName);
        }
    }

    /**
     * Execute Local Services campaign deployment
     *
     * Local Services Ads are auto-generated from the business profile.
     * No ad groups or ad creatives are needed — just the campaign.
     */
}
