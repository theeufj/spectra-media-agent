<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Contracts\Ads\BudgetMutator;
use App\Models\Customer;

/**
 * Live implementation: sends budget changes to Google Ads.
 *
 * Thin adapter over UpdateCampaignBudget so agents depend on the contract
 * rather than constructing it. Behaviour unchanged.
 */
class GoogleBudgetMutator implements BudgetMutator
{
    public function __construct(private Customer $customer) {}

    public function updateDailyBudget(string $customerId, string $campaignResourceName, float $newDailyBudgetMicros): bool
    {
        return (new UpdateCampaignBudget($this->customer))(
            $customerId,
            $campaignResourceName,
            $newDailyBudgetMicros
        );
    }
}
