<?php

namespace App\Services\GoogleAds;

use Google\Ads\GoogleAds\V22\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V22\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V22\Services\CampaignBudgetServiceClient;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignBudgetsRequest;
use Illuminate\Support\Facades\Log;
use App\Models\Customer;

class CreateCampaignBudget extends BaseGoogleAdsService
{
    public function __construct(Customer $customer, bool $useMccCredentials = false)
    {
        parent::__construct($customer, $useMccCredentials);
    }

    public function __invoke(string $customerId, string $budgetName, int $dailyBudgetMicros = 5000000, bool $explicitlyShared = true): ?string
    {
        $campaignBudget = new CampaignBudget([
            'name' => $budgetName,
            'amount_micros' => $dailyBudgetMicros,
            'delivery_method' => \Google\Ads\GoogleAds\V22\Enums\BudgetDeliveryMethodEnum\BudgetDeliveryMethod::STANDARD,
            'explicitly_shared' => $explicitlyShared
        ]);

        $campaignBudgetOperation = new CampaignBudgetOperation();
        $campaignBudgetOperation->setCreate($campaignBudget);

        /** @var CampaignBudgetServiceClient $campaignBudgetServiceClient */
        $this->ensureClient();
        $campaignBudgetServiceClient = $this->client->getCampaignBudgetServiceClient();
        $request = new MutateCampaignBudgetsRequest([
            'customer_id' => $customerId,
            'operations' => [$campaignBudgetOperation],
        ]);
        $response = $campaignBudgetServiceClient->mutateCampaignBudgets($request);

        return $response->getResults() ? $response->getResults()[0]->getResourceName() : null;
    }
}
