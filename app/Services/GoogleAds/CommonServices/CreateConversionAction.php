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

        // Check if it already exists (simple check by name could be added here, but for now we rely on unique names or API error)
        
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
}
