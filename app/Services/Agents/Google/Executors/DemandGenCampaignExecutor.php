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
use App\Services\GoogleAds\DemandGenServices\CreateDemandGenAdGroup;
use App\Services\GoogleAds\DemandGenServices\CreateDemandGenCampaign;
use App\Services\GoogleAds\DemandGenServices\CreateDemandGenMultiAssetAd;
use App\Services\GoogleAds\DisplayServices\UploadImageAsset;
use App\Services\StorageHelper;
use Illuminate\Support\Facades\Log;

/**
 * Deploys a Google Ads Demand Gen campaign.
 * Extracted verbatim from GoogleAdsExecutionAgent.
 */
class DemandGenCampaignExecutor implements CampaignTypeExecutor
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

        Log::info('GoogleAdsExecutionAgent: Creating Demand Gen Campaign', ['customer_id' => $customerId]);

        // 1. Create Campaign — idempotency guard prevents duplicates on retry
        $timestamp = now()->format('Ymd_His');
        if (! empty($campaign->google_ads_campaign_id)) {
            $campaignResourceName = $campaign->google_ads_campaign_id;
            Log::info('GoogleAdsExecutionAgent: Reusing existing DemandGen campaign from prior attempt', [
                'campaign_id' => $campaign->id,
                'google_ads_campaign_id' => $campaignResourceName,
            ]);
            $result->addPlatformId('campaign', $campaignResourceName);
        } else {
            $createCampaignService = new CreateDemandGenCampaign($this->customer);
            $campaignName = $campaign->name.' - DemandGen - '.$timestamp;

            $campaignData = [
                'businessName' => $campaignName,
                'budget' => $strategy->daily_budget ?: ($campaign->daily_budget ?: $campaign->total_budget / 30),
                'startDate' => now()->addDay()->format('Y-m-d'),
                'endDate' => now()->addYear()->format('Y-m-d'),
                'targetCpaMicros' => $strategy->cpa_target ?? null,
            ];

            $campaignResourceName = ($createCampaignService)($customerId, $campaignData);
            if (! $campaignResourceName) {
                throw new \Exception('Failed to create Demand Gen campaign');
            }

            $result->addPlatformId('campaign', $campaignResourceName);
            $campaign->google_ads_campaign_id = $campaignResourceName;
            $campaign->save();
        }

        // 1.5 Add Location Targeting
        $this->geo->addLocationTargeting($customerId, $campaignResourceName, $campaign, $strategy, $plan, $result);

        // 2. Create Ad Group
        $createAdGroupService = new CreateDemandGenAdGroup($this->customer);
        $adGroupName = 'Demand Gen Ad Group - '.$timestamp;
        $adGroupResourceName = ($createAdGroupService)($customerId, $campaignResourceName, $adGroupName);
        if (! $adGroupResourceName) {
            throw new \Exception('Failed to create Demand Gen ad group');
        }

        $result->addPlatformId('ad_group', $adGroupResourceName);
        $strategy->google_ads_ad_group_id = $adGroupResourceName;
        $strategy->save();

        // 3. Upload Image Assets
        $imageCollaterals = $strategy->imageCollaterals()->where('is_active', true)->where('should_deploy', true)->get();
        $imageAssetResourceNames = [];
        $logoAssetResourceNames = [];

        if ($imageCollaterals->isNotEmpty()) {
            $uploadImageAssetService = new UploadImageAsset($this->customer);
            foreach ($imageCollaterals as $image) {
                try {
                    $imageData = StorageHelper::get($image->s3_path);
                    if (! $imageData) {
                        continue;
                    }

                    $assetResourceName = ($uploadImageAssetService)($customerId, $imageData, $image->s3_path);
                    if ($assetResourceName) {
                        if (str_contains(strtolower($image->s3_path), 'logo')) {
                            $logoAssetResourceNames[] = $assetResourceName;
                        } else {
                            $imageAssetResourceNames[] = $assetResourceName;
                        }
                        $result->addPlatformId('image_asset', $assetResourceName);
                    }
                } catch (\Exception $e) {
                    $result->addWarning("Failed to upload image asset {$image->s3_path}: ".$e->getMessage());
                }
            }
        }

        // 4. Create Demand Gen Multi-Asset Ad
        $adCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%google%'])->first();
        if ($adCopy && ! empty($imageAssetResourceNames)) {
            $finalUrl = $this->urls->getFinalUrl($campaign, $strategy, $plan);

            if (! $finalUrl) {
                $result->addWarning('No landing page URL found for Demand Gen ad. Skipping ad creation.');
            } else {
                $createAdService = new CreateDemandGenMultiAssetAd($this->customer);
                $adData = [
                    'finalUrls' => [$finalUrl],
                    'headlines' => array_slice($adCopy->headlines ?? [], 0, 5),
                    'descriptions' => array_slice($adCopy->descriptions ?? [], 0, 5),
                    'businessName' => $this->customer->business_name ?? $campaign->name,
                    'imageAssets' => $imageAssetResourceNames,
                    'logoAssets' => $logoAssetResourceNames,
                    'callToActionText' => 'Learn more',
                ];

                $adResourceName = ($createAdService)($customerId, $adGroupResourceName, $adData);
                if ($adResourceName) {
                    $result->addPlatformId('ad', $adResourceName);
                }
            }
        }

        // 5. Add Ad Extensions
        $this->extensions->createAndLinkAdExtensions($customerId, $campaignResourceName, $strategy, $result);
    }

    /**
     * Execute Shopping campaign deployment
     *
     * Shopping campaigns require a linked Google Merchant Center account.
     * Product data comes from the feed — ads are auto-generated.
     */
}
