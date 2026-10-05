<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\Agents\AgentIssue;
use App\Services\GoogleAds\BaseGoogleAdsService;

/**
 * Read-only account conversion inventory. A name or an account-wide primary flag
 * cannot establish a campaign's intent. Repairs belong to ReconcileCampaignConversionGoals.
 */
class VerifyConversionGoals extends BaseGoogleAdsService
{
    /**
     * @deprecated Use audit() for inventory, or campaign-specific reconciliation for repairs.
     *
     * @return array{status:string,scope:string,actions:array,warnings:list<AgentIssue>,primary_actions:array}
     */
    public function verifyAndHeal(): array
    {
        return $this->audit();
    }

    /** @return array{status:string,scope:string,actions:array,warnings:list<AgentIssue>,primary_actions:array} */
    public function audit(): array
    {
        $customerId = $this->customer?->cleanGoogleCustomerId();
        if (! $customerId) {
            return ['status' => 'unknown', 'scope' => 'account', 'actions' => [], 'primary_actions' => [],
                'warnings' => [new AgentIssue('conversion_account_missing', 'A Google Ads account is required to inspect account conversion actions.')]];
        }
        try {
            $primaries = array_values(array_filter($this->readActions($customerId),
                fn ($action) => ($action['status'] ?? '') === 'ENABLED' && ($action['primaryForGoal'] ?? false)));

            return ['status' => 'observed', 'scope' => 'account', 'actions' => [], 'primary_actions' => $primaries,
                'warnings' => $primaries === [] ? [new AgentIssue('conversion_account_primary_missing', 'No enabled account primary action was observed. Campaign custom goals can select secondary actions; verify each campaign against its reviewed intent.')] : []];
        } catch (\Throwable $e) {
            $this->logError('VerifyConversionGoals: account conversion inventory is unavailable.', $e);
            throw $e;
        }
    }

    protected function readActions(string $customerId): array
    {
        $this->ensureClient();
        $actions = [];
        $query = 'SELECT conversion_action.resource_name, conversion_action.name, conversion_action.status, '
            .'conversion_action.category, conversion_action.origin, conversion_action.primary_for_goal '
            ."FROM conversion_action WHERE conversion_action.status = 'ENABLED'";
        foreach ($this->searchQuery($customerId, $query)->iterateAllElements() as $row) {
            $actions[] = json_decode($row->getConversionAction()->serializeToJsonString(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $actions;
    }
}
