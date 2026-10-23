<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\Campaigns\GoogleBudgetPacingHistory;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Protobuf\FieldMask;

class UpdateCampaignBudget extends BaseGoogleAdsService
{
    /**
     * Update a campaign's daily budget.
     *
     * @param  float  $newDailyBudgetMicros  New daily budget in micros
     * @return bool Success status
     */
    public function __invoke(string $customerId, string $campaignResourceName, float $newDailyBudgetMicros): bool
    {
        $local = CampaignSpendGuardrails::forGoogleResource($customerId, $campaignResourceName);
        if ($local && ! CampaignSpendGuardrails::permitsDailyBudget($local, (int) $newDailyBudgetMicros)) {
            $this->logError('Budget mutation refused: exceeds the approved bounded-trial daily limit.');

            return false;
        }
        $this->ensureClient();

        try {
            $before = $this->readBudget($customerId, $campaignResourceName);
            if (! $before) {
                $this->logError("Could not find budget for campaign: $campaignResourceName");

                return false;
            }
            if (! $this->writeBudget($customerId, $before['budget_resource'], (int) $newDailyBudgetMicros)) {
                return false;
            }
            if ($this->dryRun) {
                return true;
            }
            $readback = $this->readBudget($customerId, $campaignResourceName);
            if (! $readback || $readback['budget_resource'] !== $before['budget_resource']
                || $readback['daily_budget_micros'] !== (int) $newDailyBudgetMicros) {
                $this->logError('Google budget mutation was not confirmed by readback.');

                return false;
            }
            if ($local) {
                app(GoogleBudgetPacingHistory::class)->record($local, $readback);
            }

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to update or verify campaign budget: '.$e->getMessage(), $e);

            return false;
        }
    }

    protected function readBudget(string $customerId, string $campaignResourceName): ?array
    {
        $query = 'SELECT campaign.resource_name, campaign.campaign_budget, campaign_budget.amount_micros, '
            ."customer.currency_code, customer.time_zone FROM campaign WHERE campaign.resource_name = '$campaignResourceName'";
        foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
            $snapshot = ['campaign_resource' => $row->getCampaign()->getResourceName(),
                'budget_resource' => $row->getCampaign()->getCampaignBudget(),
                'daily_budget_micros' => (int) $row->getCampaignBudget()->getAmountMicros(),
                'currency_code' => $row->getCustomer()->getCurrencyCode(), 'account_timezone' => $row->getCustomer()->getTimeZone()];
            if ($snapshot['campaign_resource'] === $campaignResourceName
                && str_starts_with($snapshot['budget_resource'], "customers/{$customerId}/campaignBudgets/")
                && preg_match('/^[A-Z]{3}$/', $snapshot['currency_code'])
                && in_array($snapshot['account_timezone'], timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
                return $snapshot;
            }
        }

        return null;
    }

    protected function writeBudget(string $customerId, string $budgetResourceName, int $micros): bool
    {
        $budget = new \Google\Ads\GoogleAds\V26\Resources\CampaignBudget(['resource_name' => $budgetResourceName, 'amount_micros' => $micros]);
        $operation = new \Google\Ads\GoogleAds\V26\Services\CampaignBudgetOperation;
        $operation->setUpdate($budget);
        $operation->setUpdateMask(new FieldMask(['paths' => ['amount_micros']]));
        $response = $this->client->getCampaignBudgetServiceClient()->mutateCampaignBudgets(
            new \Google\Ads\GoogleAds\V26\Services\MutateCampaignBudgetsRequest([
                'validate_only' => $this->dryRun, 'customer_id' => $customerId, 'operations' => [$operation],
            ])
        );

        return count($response->getResults()) > 0;
    }
}
