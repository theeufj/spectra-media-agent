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
use App\Services\GoogleAds\DisplayServices\CreateDisplayAdGroup;
use App\Services\GoogleAds\DisplayServices\CreateDisplayCampaign;
use App\Services\GoogleAds\DisplayServices\CreateResponsiveDisplayAd;
use App\Services\GoogleAds\DisplayServices\UploadImageAsset;
use App\Services\StorageHelper;
use Illuminate\Support\Facades\Log;

/**
 * Deploys a Google Ads Display campaign.
 * Extracted verbatim from GoogleAdsExecutionAgent.
 */
class DisplayCampaignExecutor implements CampaignTypeExecutor
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
            Log::warning('GoogleAdsExecutionAgent: Customer ID mismatch, switching to stored account ID', [
                'provided_id' => $customerId,
                'stored_id' => $this->customer->google_ads_customer_id,
            ]);
            $customerId = $this->customer->google_ads_customer_id;
        }

        Log::info('GoogleAdsExecutionAgent: Creating Display Campaign in account', [
            'customer_id' => $customerId,
        ]);

        // 1. Create Campaign — idempotency guard prevents duplicates on retry
        $timestamp = now()->format('Ymd_His');
        if (! empty($campaign->google_ads_campaign_id)) {
            $campaignResourceName = $campaign->google_ads_campaign_id;
            Log::info('GoogleAdsExecutionAgent: Reusing existing Display campaign from prior attempt', [
                'campaign_id' => $campaign->id,
                'google_ads_campaign_id' => $campaignResourceName,
            ]);
            $result->addPlatformId('campaign', $campaignResourceName);
        } else {
            $createCampaignService = new CreateDisplayCampaign($this->customer);
            $campaignName = $campaign->name.' - '.$timestamp;

            $campaignData = [
                'businessName' => $campaignName,
                'budget' => $strategy->daily_budget ?: ($campaign->daily_budget ?: $campaign->total_budget / 30),
                'startDate' => now()->addDay()->format('Y-m-d'),
                'endDate' => now()->addYear()->format('Y-m-d'),
            ];

            $campaignResourceName = ($createCampaignService)($customerId, $campaignData);
            if (! $campaignResourceName) {
                throw new \Exception('Failed to create display campaign');
            }

            $result->addPlatformId('campaign', $campaignResourceName);
            $campaign->google_ads_campaign_id = $campaignResourceName;
            $campaign->save();
        }

        // 1.5 Add Location Targeting
        $this->geo->addLocationTargeting($customerId, $campaignResourceName, $campaign, $strategy, $plan, $result);

        // 2. Create Ad Group — reuse if already created in a prior attempt
        if (! empty($strategy->google_ads_ad_group_id)) {
            $adGroupResourceName = $strategy->google_ads_ad_group_id;
            Log::info('GoogleAdsExecutionAgent: Reusing existing Display ad group from prior attempt', [
                'strategy_id' => $strategy->id,
                'google_ads_ad_group_id' => $adGroupResourceName,
            ]);
            $result->addPlatformId('ad_group', $adGroupResourceName);
        } else {
            $createAdGroupService = new CreateDisplayAdGroup($this->customer);
            $adGroupName = 'Default Ad Group - '.$timestamp;
            $adGroupResourceName = ($createAdGroupService)($customerId, $campaignResourceName, $adGroupName);
            if (! $adGroupResourceName) {
                throw new \Exception('Failed to create display ad group');
            }

            $result->addPlatformId('ad_group', $adGroupResourceName);
            $strategy->google_ads_ad_group_id = $adGroupResourceName;
            $strategy->save();
        }

        // 3. Upload Image Assets
        $imageCollaterals = $strategy->imageCollaterals()->where('is_active', true)->where('should_deploy', true)->get();
        $imageAssetResourceNames = [];

        if ($imageCollaterals->isNotEmpty()) {
            $uploadImageAssetService = new UploadImageAsset($this->customer);
            foreach ($imageCollaterals as $image) {
                try {
                    $imageData = StorageHelper::get($image->s3_path);
                    if (! $imageData) {
                        Log::warning('GoogleAdsExecutionAgent: Image data is null, skipping upload', [
                            's3_path' => $image->s3_path,
                        ]);

                        continue;
                    }

                    $assetResourceName = ($uploadImageAssetService)($customerId, $imageData, $image->s3_path);
                    if ($assetResourceName) {
                        $imageAssetResourceNames[] = $assetResourceName;
                        $result->addPlatformId('image_asset', $assetResourceName);
                    }
                } catch (\Exception $e) {
                    $result->addWarning("Failed to upload image asset {$image->s3_path}: ".$e->getMessage());
                }
            }
        }

        // 4. Create Responsive Display Ad
        $adCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%google%'])->first();
        if ($adCopy && ! empty($imageAssetResourceNames)) {
            $finalUrl = $this->urls->getFinalUrl($campaign, $strategy, $plan);

            if (! $finalUrl) {
                $result->addWarning('No landing page URL found for display ad creation. Skipping ad creation.');
            } else {
                $createAdService = new CreateResponsiveDisplayAd($this->customer);
                $adData = [
                    'finalUrls' => [$finalUrl],
                    'headlines' => $adCopy->headlines ?? [],
                    'longHeadlines' => [$adCopy->headlines[0] ?? 'Get Started Today'],
                    'descriptions' => $adCopy->descriptions ?? [],
                    'imageAssets' => $imageAssetResourceNames,
                ];

                $adResourceName = ($createAdService)($customerId, $adGroupResourceName, $adData);
                if ($adResourceName) {
                    $result->addPlatformId('ad', $adResourceName);
                }
            }
        }

        // 5. Add Ad Extensions
        // Note: Display campaigns support fewer extensions, but Sitelinks/Callouts are often compatible.
        $this->extensions->createAndLinkAdExtensions($customerId, $campaignResourceName, $strategy, $result);

        // 6. Apply conversion value rules (device + audience modifiers)
        try {
            $applyValueRules = new \App\Services\GoogleAds\CommonServices\ApplyConversionValueRules($this->customer);
            $applyValueRules($customerId, $campaignResourceName, $this->customer);
        } catch (\Exception $e) {
            Log::warning('GoogleAdsExecutionAgent: Conversion value rules not applied: '.$e->getMessage());
        }
    }

    /**
     * Execute Performance Max campaign deployment
     */
}
