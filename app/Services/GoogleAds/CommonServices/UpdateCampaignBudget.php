<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Services\CampaignOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\Protobuf\FieldMask;

class UpdateCampaignBudget extends BaseGoogleAdsService
{
    /**
     * Update a campaign's daily budget.
     *
     * @param string $customerId
     * @param string $campaignResourceName
     * @param float $newDailyBudgetMicros New daily budget in micros
     * @return bool Success status
     */
    public function __invoke(string $customerId, string $campaignResourceName, float $newDailyBudgetMicros): bool
    {
        $this->ensureClient();

        // First, we need to get the budget resource name from the campaign
        $query = "SELECT campaign.campaign_budget FROM campaign WHERE campaign.resource_name = '$campaignResourceName'";

        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $stream = $googleAdsServiceClient->search($customerId, $query);

            $budgetResourceName = null;
            foreach ($stream->iterateAllElements() as $googleAdsRow) {
                $budgetResourceName = $googleAdsRow->getCampaign()->getCampaignBudget();
                break;
            }

            if (!$budgetResourceName) {
                $this->logError("Could not find budget for campaign: $campaignResourceName");
                return false;
            }

            // Now update the budget
            $budget = new \Google\Ads\GoogleAds\V22\Resources\CampaignBudget([
                'resource_name' => $budgetResourceName,
                'amount_micros' => (int) $newDailyBudgetMicros,
            ]);

            $budgetOperation = new \Google\Ads\GoogleAds\V22\Services\CampaignBudgetOperation();
            $budgetOperation->setUpdate($budget);
            $budgetOperation->setUpdateMask(new FieldMask(['paths' => ['amount_micros']]));

            $campaignBudgetServiceClient = $this->client->getCampaignBudgetServiceClient();
            $response = $campaignBudgetServiceClient->mutateCampaignBudgets(
                new \Google\Ads\GoogleAds\V22\Services\MutateCampaignBudgetsRequest([
                    'customer_id' => $customerId,
                    'operations' => [$budgetOperation],
                ])
            );

            return count($response->getResults()) > 0;

        } catch (GoogleAdsException $e) {
            $this->logError("Failed to update campaign budget: " . $e->getMessage());
            return false;
        }
    }
}
