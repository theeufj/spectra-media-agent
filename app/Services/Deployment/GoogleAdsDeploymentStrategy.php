<?php

namespace App\Services\Deployment;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\GoogleAds\CommonServices\AddAdGroupCriterion;
use App\Services\GoogleAds\DisplayServices\CreateDisplayAdGroup;
use App\Services\GoogleAds\DisplayServices\CreateDisplayCampaign;
use App\Services\GoogleAds\DisplayServices\CreateResponsiveDisplayAd;
use App\Services\GoogleAds\DisplayServices\UploadImageAsset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GoogleAdsDeploymentStrategy implements DeploymentStrategy
{
    protected Customer $customer;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }

    public function deploy(Campaign $campaign, Strategy $strategy): bool
    {
        Log::info("Starting Google Ads deployment for Campaign ID: {$campaign->id}, Strategy ID: {$strategy->id}");

        try {
            $customerId = $this->customer->google_ads_customer_id;

            // Determine if it's a Display campaign
            $isDisplayCampaign = stripos($strategy->imagery_strategy, 'N/A') === false && !empty($strategy->imagery_strategy);

            if ($isDisplayCampaign) {
                $campaignResourceName = $this->deployDisplayCampaign($customerId, $campaign, $strategy);
            } else {
                // Placeholder for Search campaign deployment
                throw new \Exception("Search campaign deployment not yet implemented in this refactor.");
            }

            if (!$campaignResourceName) {
                throw new \Exception('Failed to create Google Ads campaign.');
            }

            Log::info("Successfully deployed to Google Ads for Strategy ID: {$strategy->id}");
            return true;

        } catch (\Exception $e) {
            Log::error("Google Ads deployment failed for Strategy ID {$strategy->id}: " . $e->getMessage());
            return false;
        }
    }

    private function deployDisplayCampaign(string $customerId, Campaign $campaign, Strategy $strategy): ?string
    {
        // 1. Create Campaign
        $createCampaignService = new CreateDisplayCampaign($this->customer);
        $campaignData = [
            'businessName' => $campaign->name,
            'budget' => $strategy->budget,
            'startDate' => now()->format('Y-m-d'),
            'endDate' => now()->addMonth()->format('Y-m-d'), // Placeholder
        ];
        $campaignResourceName = ($createCampaignService)($customerId, $campaignData);
        if (!$campaignResourceName) {
            throw new \Exception('Failed to create display campaign.');
        }

        $campaign->google_ads_campaign_id = $campaignResourceName;
        $campaign->save();

        // 2. Create Ad Group
        $createAdGroupService = new CreateDisplayAdGroup($this->customer);
        $adGroupResourceName = ($createAdGroupService)($customerId, $campaignResourceName, 'Default Ad Group');
        if (!$adGroupResourceName) {
            throw new \Exception('Failed to create display ad group.');
        }

        // 3. Upload Assets and Create Ad
        $imageCollaterals = $strategy->imageCollaterals()->where('is_active', true)->get();
        $adCopy = $strategy->adCopies()->where('platform', $strategy->platform)->first();
        $imageAssetResourceNames = [];

        if ($imageCollaterals->isNotEmpty()) {
            $uploadImageAssetService = new UploadImageAsset($this->customer);
            foreach ($imageCollaterals as $image) {
                $imageData = Storage::disk('s3')->get($image->s3_path);
                $imageAssetResourceNames[] = ($uploadImageAssetService)($customerId, $imageData, $image->s3_path);
            }
        }

        if ($adCopy && !empty($imageAssetResourceNames)) {
            $createAdService = new CreateResponsiveDisplayAd($this->customer);
            $adData = [
                'finalUrls' => [$campaign->landing_page_url],
                'headlines' => $adCopy->headlines,
                'longHeadlines' => [$adCopy->headlines[0]], // Placeholder
                'descriptions' => $adCopy->descriptions,
                'imageAssets' => $imageAssetResourceNames,
            ];
            ($createAdService)($customerId, $adGroupResourceName, $adData);
        }

        return $campaignResourceName;
    }
}
