<?php

namespace App\Services\Deployment;

use App\Models\Campaign;
use App\Models\Connection;
use App\Models\Strategy;
use App\Services\GoogleAdsService;
use App\Services\GoogleAdsSettings\BiddingStrategy;
use Illuminate\Support\Facades\Log;

class GoogleAdsDeploymentStrategy implements DeploymentStrategy
{
    protected GoogleAdsService $googleAdsService;

    public function __construct(GoogleAdsService $googleAdsService)
    {
        $this->googleAdsService = $googleAdsService;
    }

    public function deploy(Campaign $campaign, Strategy $strategy, Connection $connection): bool
    {
        Log::info("Starting Google Ads deployment for Campaign ID: {$campaign->id}, Strategy ID: {$strategy->id}");

        try {
            $customerId = $connection->account_id;

            // 1. Create Campaign Budget
            $budgetResourceName = $this->googleAdsService->createCampaignBudget($customerId, $campaign->name . ' Budget');
            if (!$budgetResourceName) {
                throw new \Exception('Failed to create campaign budget.');
            }

            // 2. Instantiate the Bidding Strategy
            $biddingStrategyConfig = $strategy->bidding_strategy;
            $strategyClassName = "App\\Services\\GoogleAdsSettings\\" . $biddingStrategyConfig['name'];
            $strategyParams = $biddingStrategyConfig['parameters'];
            
            if (!class_exists($strategyClassName)) {
                throw new \Exception("Bidding strategy class not found: {$strategyClassName}");
            }
            
            $biddingStrategy = new $strategyClassName(...array_values($strategyParams));

            // 3. Determine Campaign Type and Create Campaign
            $isDisplayCampaign = stripos($strategy->imagery_strategy, 'N/A') === false && !empty($strategy->imagery_strategy);
            
            if ($isDisplayCampaign) {
                $campaignResourceName = $this->googleAdsService->createDisplayCampaign($customerId, $campaign, $budgetResourceName, $biddingStrategy);
            } else {
                $campaignResourceName = $this->googleAdsService->createSearchCampaign($customerId, $campaign, $budgetResourceName, $biddingStrategy);
            }

            if (!$campaignResourceName) {
                throw new \Exception('Failed to create Google Ads campaign.');
            }

            // 4. Create Ad Group
            $adGroupResourceName = $this->googleAdsService->createAdGroup($customerId, $campaignResourceName, 'Default Ad Group');
            if (!$adGroupResourceName) {
                throw new \Exception('Failed to create ad group.');
            }

            // 5. Create Ads and Upload Assets
            if ($isDisplayCampaign) {
                // Logic for creating Display Ads
                $imageCollaterals = $strategy->imageCollaterals()->where('is_active', true)->get();
                foreach ($imageCollaterals as $image) {
                    $imageData = Storage::disk('s3')->get($image->s3_path);
                    $assetResourceName = $this->googleAdsService->uploadImageAsset($customerId, base64_encode($imageData), $image->s3_path);
                    // You would then use this assetResourceName to create a ResponsiveDisplayAd
                }
            } else {
                // Logic for creating Search Ads
                $adCopy = $strategy->adCopies()->where('platform', $strategy->platform)->first();
                if ($adCopy) {
                    $this->googleAdsService->createResponsiveSearchAd(
                        $customerId,
                        $adGroupResourceName,
                        $adCopy->headlines,
                        $adCopy->descriptions,
                        $campaign->landing_page_url
                    );
                }
            }

            Log::info("Successfully deployed to Google Ads for Strategy ID: {$strategy->id}");
            return true;

        } catch (\Exception $e) {
            Log::error("Google Ads deployment failed for Strategy ID {$strategy->id}: " . $e->getMessage());
            return false;
        }
    }
}
