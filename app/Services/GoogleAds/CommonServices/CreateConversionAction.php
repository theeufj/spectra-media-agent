<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Enums\ConversionActionCategoryEnum\ConversionActionCategory;
use Google\Ads\GoogleAds\V22\Enums\ConversionActionTypeEnum\ConversionActionType;
use Google\Ads\GoogleAds\V22\Enums\ConversionActionStatusEnum\ConversionActionStatus;
use Google\Ads\GoogleAds\V22\Resources\ConversionAction;
use Google\Ads\GoogleAds\V22\Services\ConversionActionOperation;
use Google\Ads\GoogleAds\V22\Services\MutateConversionActionsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class CreateConversionAction extends BaseGoogleAdsService
{
    /**
     * Create a new Conversion Action.
     *
     * @param string $customerId
     * @param string $name Name of the conversion action
     * @param int $category ConversionActionCategory enum value
     * @return string|null Resource name of the created Conversion Action
     */
    public function __invoke(string $customerId, string $name, int $category = ConversionActionCategory::DEFAULT): ?string
    {
        $this->ensureClient();

        // Check if a conversion action with this name already exists
        $existing = $this->findExistingByName($customerId, $name);
        if ($existing) {
            $this->logInfo("Conversion Action '{$name}' already exists: {$existing}");
            return $existing;
        }
        
        $conversionAction = new ConversionAction([
            'name' => $name,
            'category' => $category,
            'type' => ConversionActionType::WEBPAGE,
            'status' => ConversionActionStatus::ENABLED,
            'view_through_lookback_window_days' => 1,
            'click_through_lookback_window_days' => 30,
            'value_settings' => new \Google\Ads\GoogleAds\V22\Resources\ConversionAction\ValueSettings([
                'default_value' => 1.0,
                'default_currency_code' => 'USD',
                'always_use_default_value' => true // Simplified for now
            ])
        ]);

        $operation = new ConversionActionOperation();
        $operation->setCreate($conversionAction);

        try {
            $conversionActionServiceClient = $this->client->getConversionActionServiceClient();
            $response = $conversionActionServiceClient->mutateConversionActions(new MutateConversionActionsRequest([
                'customer_id' => $customerId,
                'operations' => [$operation],
            ]));

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Created Conversion Action: $resourceName");

            return $resourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Failed to create Conversion Action: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if a conversion action with the given name already exists.
     */
    protected function findExistingByName(string $customerId, string $name): ?string
    {
        try {
            $query = "SELECT conversion_action.resource_name "
                . "FROM conversion_action "
                . "WHERE conversion_action.name = '" . addslashes($name) . "' "
                . "AND conversion_action.status = 'ENABLED' "
                . "LIMIT 1";

            $response = $this->searchQuery($customerId, $query);

            foreach ($response->iterateAllElements() as $row) {
                return $row->getConversionAction()->getResourceName();
            }
        } catch (\Exception $e) {
            $this->logError("Failed to check existing conversion action: " . $e->getMessage());
        }

        return null;
    }
}
