<?php

namespace App\Services;

use App\Models\Customer;
use App\Services\Deployment\DeploymentStrategy;
use App\Services\Deployment\GoogleAdsDeploymentStrategy;
use Illuminate\Support\Facades\Log;

class DeploymentService
{
    /**
     * Factory method to get the correct deployment strategy for a given platform.
     *
     * @param string $platform The name of the platform (e.g., 'Google Ads (SEM)').
     * @param Customer $customer The customer object containing the necessary credentials.
     * @return DeploymentStrategy|null
     */
    public static function getStrategy(string $platform, Customer $customer): ?DeploymentStrategy
    {
        switch ($platform) {
            case 'Google Ads (SEM)':
                return new GoogleAdsDeploymentStrategy($customer);

            // case 'Facebook Ads':
            //     $facebookService = new FacebookAdsService($credentials['access_token']);
            //     return new FacebookAdsDeploymentStrategy($facebookService);

            default:
                Log::warning("No deployment strategy found for platform: {$platform}");
                return null;
        }
    }
}
