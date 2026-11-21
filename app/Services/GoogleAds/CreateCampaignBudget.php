<?php

namespace App\Services\GoogleAds;

use App\Models\Customer;
use Google\Ads\GoogleAds\V26\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V26\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignBudgetsRequest;

class CreateCampaignBudget extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    public function __invoke(string $customerId, string $budgetName, int $dailyBudgetMicros = 5_000_000, bool $explicitlyShared = true): ?string
    {
        $campaignBudget = new CampaignBudget([
            'name' => $budgetName,
            'amount_micros' => $dailyBudgetMicros,
            'delivery_method' => \Google\Ads\GoogleAds\V26\Enums\BudgetDeliveryMethodEnum\BudgetDeliveryMethod::STANDARD,
            'explicitly_shared' => $explicitlyShared,
        ]);

        $campaignBudgetOperation = new CampaignBudgetOperation;
        $campaignBudgetOperation->setCreate($campaignBudget);

        $this->ensureClient();
        $campaignBudgetServiceClient = $this->client->getCampaignBudgetServiceClient();
        $request = new MutateCampaignBudgetsRequest([
            'validate_only' => $this->dryRun,
            'customer_id' => $customerId,
            'operations' => [$campaignBudgetOperation],
        ]);
        $response = $campaignBudgetServiceClient->mutateCampaignBudgets($request);

        return count($response->getResults()) > 0 ? $response->getResults()[0]->getResourceName() : null;
    }
}
