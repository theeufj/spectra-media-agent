<?php

namespace App\Services\GoogleAds\Exclusions;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\MobileApplicationInfo;
use Google\Ads\GoogleAds\V22\Common\PlacementInfo;
use Google\Ads\GoogleAds\V22\Resources\CustomerNegativeCriterion;
use Google\Ads\GoogleAds\V22\Services\CustomerNegativeCriterionOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCustomerNegativeCriteriaRequest;

/**
 * Applies placement exclusions to a Google Ads account.
 *
 * Deliberately account-level (CustomerNegativeCriterion) rather than
 * campaign-level: Performance Max does not accept campaign placement
 * exclusions, and account-level negative criteria are the only mechanism that
 * reaches PMax inventory. They apply to every campaign in the account, which is
 * what we want — mobile game inventory is not wanted by any campaign type here.
 *
 * The platform had no exclusion capability at all before this: TargetingConfig
 * carried an `excluded_placements` column that no code ever read, and
 * CreatePerformanceMaxCampaign set none. Every PMax campaign it deployed was
 * free to spend its budget in whatever app inventory Google chose.
 *
 * Idempotent: existing exclusions are read first and skipped, so it is safe to
 * run repeatedly from a scheduled remediation pass.
 */
class ExcludePlacements extends BaseGoogleAdsService
{
    /**
     * Exclude a set of placements at account level.
     *
     * Entries are validated rather than trusted: this is called with both
     * API-derived placements and customer-configured strings.
     *
     * @param  list<array<string, mixed>>  $placements  Each with kind + identifier
     * @return array{excluded:int, skipped:int, failed:int, names:list<string>}
     */
    public function apply(string $customerId, array $placements): array
    {
        $this->ensureClient();

        $result = ['excluded' => 0, 'skipped' => 0, 'failed' => 0, 'names' => []];

        if ($placements === []) {
            return $result;
        }

        $existing = $this->existingExclusions($customerId);

        $operations = [];
        $queuedNames = [];

        foreach ($placements as $placement) {
            $identifier = $placement['identifier'] ?? null;
            $kind = $placement['kind'] ?? null;

            if (! $identifier || ! in_array($kind, ['mobile_application', 'website'], true)) {
                continue;
            }

            if (in_array($identifier, $existing, true)) {
                $result['skipped']++;

                continue;
            }

            $criterion = new CustomerNegativeCriterion;

            if ($kind === 'mobile_application') {
                // performance_max_placement_view.placement already returns the
                // exact appId form Google expects here ("1-<iOS store id>",
                // "2-<android package>"), so it round-trips without parsing.
                $criterion->setMobileApplication(new MobileApplicationInfo(['app_id' => $identifier]));
            } else {
                $criterion->setPlacement(new PlacementInfo(['url' => $identifier]));
            }

            $operation = new CustomerNegativeCriterionOperation;
            $operation->setCreate($criterion);

            $operations[] = $operation;
            $queuedNames[] = $placement['name'] ?? $identifier;

            // Guard against duplicates inside a single batch.
            $existing[] = $identifier;
        }

        if ($operations === []) {
            return $result;
        }

        // Submit individually rather than as one batch: a single invalid
        // identifier would otherwise reject every exclusion alongside it, and
        // partial progress is more useful than none.
        foreach ($operations as $i => $operation) {
            try {
                $this->getClient()->getCustomerNegativeCriterionServiceClient()
                    ->mutateCustomerNegativeCriteria(new MutateCustomerNegativeCriteriaRequest([
                        'validate_only' => $this->dryRun,
                        'customer_id' => $customerId,
                        'operations' => [$operation],
                    ]));

                $result['excluded']++;
                $result['names'][] = $queuedNames[$i];
            } catch (\Throwable $e) {
                $result['failed']++;
                $this->logError('ExcludePlacements: failed to exclude '.$queuedNames[$i], $e);
            }
        }

        $this->logInfo('ExcludePlacements: applied account-level exclusions', $result);

        return $result;
    }

    /**
     * Identifiers already excluded on the account, so we never re-submit them.
     *
     * @return list<string>
     */
    public function existingExclusions(string $customerId): array
    {
        $identifiers = [];

        try {
            $rows = $this->searchQuery($customerId,
                'SELECT customer_negative_criterion.mobile_application.app_id,
                        customer_negative_criterion.placement.url
                 FROM customer_negative_criterion'
            );

            foreach ($rows->iterateAllElements() as $row) {
                $criterion = $row->getCustomerNegativeCriterion();

                if ($appId = $criterion->getMobileApplication()?->getAppId()) {
                    $identifiers[] = $appId;
                }
                if ($url = $criterion->getPlacement()?->getUrl()) {
                    $identifiers[] = $url;
                }
            }
        } catch (\Throwable $e) {
            // Returning empty would risk re-submitting duplicates, which Google
            // rejects harmlessly — but log it so a silent read failure is visible.
            $this->logError('ExcludePlacements: could not read existing exclusions', $e);
        }

        return $identifiers;
    }
}
