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
        return $this->updateWithChanges($customerId, $campaignResourceName, $newDailyBudgetMicros)['success'];
    }

    /**
     * @return array{success: bool, changes: list<array{platform: string, resource_name: string, old_budget_micros: int, new_budget_micros: int, currency: string}>}
     */
    public function updateWithChanges(string $customerId, string $campaignResourceName, float $newDailyBudgetMicros): array
    {
        $result = ['success' => false, 'changes' => []];
        $local = CampaignSpendGuardrails::forGoogleResource($customerId, $campaignResourceName);
        if ($local && ! CampaignSpendGuardrails::permitsDailyBudget($local, (int) $newDailyBudgetMicros)) {
            $this->logError('Budget mutation refused: exceeds the approved bounded-trial daily limit.');

            return $result;
        }
        $this->ensureClient();

        try {
            $before = $this->readBudget($customerId, $campaignResourceName);
            if (! $before) {
                $this->logError("Could not find budget for campaign: $campaignResourceName");

                return $result;
            }
            if (! $this->writeBudget($customerId, $before['budget_resource'], (int) $newDailyBudgetMicros)) {
                return $result;
            }
            if ($this->dryRun) {
                return ['success' => true, 'changes' => []];
            }
            $readback = $this->readBudget($customerId, $campaignResourceName);
            if (! $readback || $readback['budget_resource'] !== $before['budget_resource']
                || $readback['currency_code'] !== $before['currency_code']
                || $readback['daily_budget_micros'] !== (int) $newDailyBudgetMicros) {
                $this->logError('Google budget mutation was not confirmed by readback.');

                return $result;
            }
            $result['success'] = true;
            if ($before['daily_budget_micros'] !== $readback['daily_budget_micros']) {
                $result['changes'][] = ['platform' => 'google', 'resource_name' => $campaignResourceName,
                    'old_budget_micros' => $before['daily_budget_micros'], 'new_budget_micros' => $readback['daily_budget_micros'],
                    'currency' => $readback['currency_code']];
            }
            if ($local) {
                app(GoogleBudgetPacingHistory::class)->record($local, $readback);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logError('Failed to update or verify campaign budget: '.$e->getMessage(), $e);

            // A later local recording failure must not erase a confirmed platform change.
            $result['success'] = false;

            return $result;
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
