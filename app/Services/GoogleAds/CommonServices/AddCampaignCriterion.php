<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\CampaignCriterion;
use Google\Ads\GoogleAds\V22\Services\CampaignCriterionService;
use Google\Ads\GoogleAds\V22\Services\CampaignCriterionOperation;
use Google\Ads\GoogleAds\V22\Common\LocationInfo;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use App\Models\Customer;

class AddCampaignCriterion extends BaseGoogleAdsService
{
    public function __construct(Customer $customer, bool $useMccCredentials = false)
    {
        parent::__construct($customer, $useMccCredentials);
    }

    /**
     * Adds criteria (e.g., location, language) to a given campaign.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param string $campaignResourceName The resource name of the campaign.
     * @param array $criterionData Criterion details including type and specific fields.
     * @return string|null The resource name of the created campaign criterion, or null on failure.
     */
    public function __invoke(string $customerId, string $campaignResourceName, array $criterionData): ?string
    {
        $this->ensureClient();
        
        $campaignCriterion = new CampaignCriterion([
            'campaign' => $campaignResourceName,
        ]);

        // Set specific criterion based on type
        if (!isset($criterionData['type'])) {
            $this->logError("Criterion type is missing in criterionData.");
            return null;
        }

        switch ($criterionData['type']) {
            case 'LOCATION':
                if (isset($criterionData['locationId'])) {
                    $campaignCriterion->setLocation(new LocationInfo([
                        'geo_target_constant' => "geoTargetConstants/{$criterionData['locationId']}"
                    ]));
                }
                break;
                
            // Add other types as needed (LANGUAGE, etc.)
            
            default:
                $this->logError("Unsupported criterion type: " . $criterionData['type']);
                return null;
        }

        $campaignCriterionOperation = new CampaignCriterionOperation();
        $campaignCriterionOperation->setCreate($campaignCriterion);

        try {
            $campaignCriterionServiceClient = $this->client->getCampaignCriterionServiceClient();
            $request = new \Google\Ads\GoogleAds\V22\Services\MutateCampaignCriteriaRequest([
                'customer_id' => $customerId,
                'operations' => [$campaignCriterionOperation],
            ]);
            
            $response = $campaignCriterionServiceClient->mutateCampaignCriteria($request);
            $resourceName = $response->getResults()[0]->getResourceName();
            
            $this->logInfo("Successfully added campaign criterion: " . $resourceName);
            return $resourceName;
            
        } catch (GoogleAdsException $e) {
            $this->logError("Error adding campaign criterion for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }
}
