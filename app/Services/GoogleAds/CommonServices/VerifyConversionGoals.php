<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\ConversionAction;
use Google\Ads\GoogleAds\V22\Services\ConversionActionOperation;
use Google\Ads\GoogleAds\V22\Services\MutateConversionActionsRequest;
use Google\Protobuf\FieldMask;

/**
 * Conversion-goal hygiene check for the self-healing loop.
 *
 * Google auto-creates catch-all conversion actions ("Default Purchase Conversion",
 * etc.) and marks them primary. On a lead-gen account those never fire, so PMax
 * optimizes toward a goal that gets no data — which surfaces as "Conversions:
 * detected issues" in the UI. This demotes those stray defaults from primary,
 * with a guard so a real primary goal always remains.
 */
class VerifyConversionGoals extends BaseGoogleAdsService
{
    /**
     * @return array{actions: string[], warnings: string[]}
     */
    public function verifyAndHeal(): array
    {
        $actions = [];
        $warnings = [];
        $customerId = $this->customer->google_ads_customer_id;
        if (!$customerId) {
            return ['actions' => $actions, 'warnings' => $warnings];
        }

        try {
            $this->ensureClient();

            $defaults = [];       // stray Google default primaries
            $realPrimaries = [];  // legitimate primaries

            $q = "SELECT conversion_action.resource_name, conversion_action.name, conversion_action.primary_for_goal "
                . "FROM conversion_action WHERE conversion_action.status = 'ENABLED'";
            foreach ($this->searchQuery($customerId, $q)->iterateAllElements() as $row) {
                $ca = $row->getConversionAction();
                if (!$ca->getPrimaryForGoal()) {
                    continue;
                }
                if (preg_match('/^Default\b/i', (string) $ca->getName())) {
                    $defaults[] = ['res' => $ca->getResourceName(), 'name' => $ca->getName()];
                } else {
                    $realPrimaries[] = $ca->getName();
                }
            }

            // Only demote stray defaults if a real primary goal remains — never
            // leave the account with zero primary conversion goals.
            if (!empty($defaults) && !empty($realPrimaries)) {
                foreach ($defaults as $d) {
                    if ($this->demote($customerId, $d['res'])) {
                        $actions[] = "Demoted stray default conversion goal '{$d['name']}' from primary (it never fires on a lead-gen account)";
                    }
                }
            }

            if (empty($realPrimaries)) {
                $warnings[] = 'No non-default primary conversion action is set — bidding has no real goal to optimize toward.';
            } elseif (count($realPrimaries) > 5) {
                $warnings[] = count($realPrimaries) . ' primary conversion goals are set — consider consolidating to the few that matter for cleaner optimization.';
            }
        } catch (\Throwable $e) {
            $this->logError('VerifyConversionGoals: failed: ' . $e->getMessage());
        }

        return ['actions' => $actions, 'warnings' => $warnings];
    }

    private function demote(string $customerId, string $resourceName): bool
    {
        try {
            $ca = new ConversionAction(['resource_name' => $resourceName, 'primary_for_goal' => false]);
            $op = new ConversionActionOperation();
            $op->setUpdate($ca);
            $op->setUpdateMask(new FieldMask(['paths' => ['primary_for_goal']]));
            $this->client->getConversionActionServiceClient()->mutateConversionActions(
                new MutateConversionActionsRequest(['customer_id' => $customerId, 'operations' => [$op]])
            );
            return true;
        } catch (\Throwable $e) {
            $this->logError('VerifyConversionGoals: failed to demote ' . $resourceName . ': ' . $e->getMessage());
            return false;
        }
    }
}
