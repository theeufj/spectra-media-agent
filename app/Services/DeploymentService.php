<?php

namespace App\Services;

use App\Services\Deployment\DeploymentStrategy;
use App\Services\Deployment\GoogleAdsDeploymentStrategy;
use Illuminate\Support\Facades\Log;

class DeploymentService
{
    /**
     * Factory method to get the correct deployment strategy for a given platform.
     *
     * @param string $platform The name of the platform (e.g., 'Google Ads (SEM)').
     * @param array $credentials The necessary credentials for the platform's service.
     * @return DeploymentStrategy|null
     */
    public static function getStrategy(string $platform, array $credentials): ?DeploymentStrategy
    {
        switch ($platform) {
            case 'Google Ads (SEM)':
                // Here, you would fetch the real, user-specific credentials
                $googleAdsService = new GoogleAdsService(
                    $credentials['access_token'],
                    $credentials['developer_token'],
                    $credentials['customer_id'],
                    $credentials['login_customer_id']
                );
                return new GoogleAdsDeploymentStrategy($googleAdsService);

            // case 'Facebook Ads':
            //     $facebookService = new FacebookAdsService($credentials['access_token']);
            //     return new FacebookAdsDeploymentStrategy($facebookService);

            default:
                Log::warning("No deployment strategy found for platform: {$platform}");
                return null;
        }
    }
}
