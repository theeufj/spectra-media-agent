<?php

namespace App\Services\GoogleAds;

use Google\Ads\GoogleAds\V22\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V22\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V22\Services\CampaignBudgetServiceClient;
use Illuminate\Support\Facades\Log;
use App\Models\Customer;

class CreateCampaignBudget extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    public function __invoke(string $customerId, string $budgetName, int $dailyBudgetMicros = 5000000): ?string
    {
        $campaignBudget = new CampaignBudget([
            'name' => $budgetName,
            'amount_micros' => $dailyBudgetMicros,
            'delivery_method' => \Google\Ads\GoogleAds\V22\Enums\BudgetDeliveryMethodEnum\BudgetDeliveryMethod::STANDARD,
            'explicitly_shared' => true
        ]);

        $campaignBudgetOperation = new CampaignBudgetOperation();
        $campaignBudgetOperation->setCreate($campaignBudget);

        /** @var CampaignBudgetServiceClient $campaignBudgetServiceClient */
        $campaignBudgetServiceClient = $this->googleAdsClient->getCampaignBudgetServiceClient();
        $response = $campaignBudgetServiceClient->mutateCampaignBudgets($customerId, [$campaignBudgetOperation]);

        return $response->getResults() ? $response->getResults()[0]->getResourceName() : null;
    }
}
