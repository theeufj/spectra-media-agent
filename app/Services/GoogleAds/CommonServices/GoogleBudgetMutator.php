<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Contracts\Ads\ReportsBudgetChanges;
use App\Models\Customer;

/**
 * Live implementation: sends budget changes to Google Ads.
 *
 * Thin adapter over UpdateCampaignBudget so agents depend on the contract
 * rather than constructing it. Behaviour unchanged.
 */
class GoogleBudgetMutator implements ReportsBudgetChanges
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

    public function updateDailyBudgetWithChanges(string $customerId, string $campaignResourceName, float $newDailyBudgetMicros): array
    {
        return (new UpdateCampaignBudget($this->customer))->updateWithChanges(
            $customerId,
            $campaignResourceName,
            $newDailyBudgetMicros
        );
    }
}
