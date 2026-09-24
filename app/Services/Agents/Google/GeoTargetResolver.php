<?php

namespace App\Services\Agents\Google;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\GoogleAds\CommonServices\AddCampaignCriterion;
use Illuminate\Support\Facades\Log;

/**
 * Resolves human-readable locations to Google geo target constants and applies
 * them as campaign criteria. Extracted from GoogleAdsExecutionAgent.
 */
class GeoTargetResolver
{
    public function __construct(protected Customer $customer) {}

    public function addLocationTargeting(
        string $customerId,
        string $campaignResourceName,
        Campaign $campaign,
        Strategy $strategy,
        ExecutionPlan $plan,
        ExecutionResult $result
    ): void {
        [$source, $configured] = $this->configuredLocations($campaign, $strategy, $plan);

        $locationIds = [];
        $unresolved = [];

        foreach ($configured as $location) {
            if ($id = $this->resolveGeoTargetId($location)) {
                $locationIds[] = $id;
            } else {
                $unresolved[] = $this->describeLocation($location);
            }
        }

        if ($configured !== [] && $locationIds === []) {
            // Everything the customer asked for failed to resolve. This used to
            // land on the same branch as "nothing configured" and substitute
            // US/CA/AU/GB, so a strategy targeting "Melbourne" — a name the
            // country-only map cannot resolve — served in four countries nobody
            // chose, and the deploy reported success. Fail instead: the campaign
            // exists but has no ad groups or ads yet, so nothing serves and
            // nothing is spent while the targeting is corrected.
            throw new \Exception(
                'Could not resolve any geo target from '.$source.' ('.implode(', ', $unresolved).'). '
                .'Refusing to deploy with default country targeting.'
            );
        }

        if ($unresolved !== []) {
            // Partial resolution still widens the campaign beyond what was asked
            // for, so say so rather than dropping the names silently.
            $result->addWarning(
                'geo_target_unresolved',
                'These locations could not be matched to a Google geo target and were not targeted: '
                .implode(', ', $unresolved).'.'
            );
        }

        if ($locationIds === []) {
            // Nothing configured anywhere — default to English-speaking markets
            // with meaningful SaaS ad spend.
            $locationIds = [2840, 2124, 2036, 2826]; // US, CA, AU, GB
            Log::info('GoogleAdsExecutionAgent: No geo configured — defaulting to US/CA/AU/GB');
        } else {
            Log::info('GoogleAdsExecutionAgent: Resolved geo targeting', [
                'source' => $source,
                'resolved' => count($locationIds),
                'unresolved' => count($unresolved),
            ]);
        }

        $result->addMetadata('expected_locations', array_values(array_unique($locationIds)));
        $this->applyLocationCriteria($customerId, $campaignResourceName, $locationIds, $result);
    }

    /**
     * Write the resolved geo target constants onto the campaign.
     *
     * Its own method so the decision above — which locations to target — can be
     * exercised without a Google Ads client, and so one location that Google
     * rejects warns rather than aborting the rest.
     *
     * @param  list<int>  $locationIds
     */
    protected function applyLocationCriteria(
        string $customerId,
        string $campaignResourceName,
        array $locationIds,
        ExecutionResult $result
    ): void {
        $addCriterionService = new AddCampaignCriterion($this->customer);

        foreach ($locationIds as $locationId) {
            try {
                $criterionResourceName = ($addCriterionService)($customerId, $campaignResourceName, [
                    'type' => 'LOCATION',
                    'locationId' => $locationId,
                ]);

                if ($criterionResourceName) {
                    $result->addPlatformId('location_criterion', $criterionResourceName);
                }
            } catch (\Throwable $e) {
                report($e);
                $result->addWarning("Failed to add location targeting for ID {$locationId}: ".$e->getMessage());
            }
        }
    }

    /**
     * The locations the customer actually asked for, and where they came from.
     *
     * Campaign-level targeting wins, then the strategy's targeting config, then
     * whatever the execution plan proposed. Every branch returns the raw values:
     * resolveGeoTargetId() already prefers google_criterion_id, so the config
     * branch no longer needs TargetingConfig::getGoogleGeoTargeting(), which
     * array_filter()s away any location that lacks one — a second silent drop
     * on top of the one this class was losing money to.
     *
     * An empty array means nothing was configured, which is the only case where
     * the default markets are a legitimate answer.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected function configuredLocations(Campaign $campaign, Strategy $strategy, ExecutionPlan $plan): array
    {
        if (! empty($campaign->geographic_targeting)) {
            return ['campaign geographic targeting', array_values((array) $campaign->geographic_targeting)];
        }

        if ($strategy->targetingConfig && ! empty($strategy->targetingConfig->geo_locations)) {
            return ['strategy targeting config', array_values((array) $strategy->targetingConfig->geo_locations)];
        }

        $campaignStructure = $plan->getCampaignStructure();
        if (! empty($campaignStructure['locations'])) {
            return ['execution plan', array_values((array) $campaignStructure['locations'])];
        }

        return ['nothing', []];
    }

    /**
     * A location as the customer would recognise it, for the error/warning text.
     */
    protected function describeLocation(mixed $location): string
    {
        if (is_string($location) || is_numeric($location)) {
            return (string) $location;
        }

        if (is_array($location)) {
            foreach (['name', 'city', 'region', 'country'] as $key) {
                if (! empty($location[$key]) && is_scalar($location[$key])) {
                    return (string) $location[$key];
                }
            }
        }

        return (string) json_encode($location);
    }

    /**
     * Resolve a location value (string name, numeric ID, or array) to a Google Ads geo target constant ID.
     */
    protected function resolveGeoTargetId(mixed $location): ?int
    {
        // Already a numeric ID
        if (is_numeric($location)) {
            return (int) $location;
        }

        // Array with criterion ID
        if (is_array($location)) {
            if (isset($location['google_criterion_id'])) {
                return (int) $location['google_criterion_id'];
            }
            if (isset($location['id'])) {
                return (int) $location['id'];
            }
            if (isset($location['location_id'])) {
                return (int) $location['location_id'];
            }
            // Try to resolve the country name from the array
            $name = $location['country'] ?? $location['name'] ?? null;
            if ($name && is_string($name)) {
                return $this->geoTargetNameToId($name);
            }

            return null;
        }

        // String — resolve country/region name to ID
        if (is_string($location)) {
            return $this->geoTargetNameToId($location);
        }

        return null;
    }

    /**
     * Map common country/region names to Google Ads geo target constant IDs.
     *
     * The table itself now lives in GeoTargets, because the forecast needs the
     * same answer and has no execution agent to ask. The logging stays here:
     * an unresolved location in a deployment is a targeting gap worth a
     * warning, whereas the forecast treats the same miss as a fallback.
     */
    protected function geoTargetNameToId(string $name): ?int
    {
        $id = \App\Services\GoogleAds\GeoTargets::idFor($name);

        if (! $id) {
            Log::warning('GoogleAdsExecutionAgent: Unknown geo target name, cannot resolve to ID', ['name' => $name]);
        }

        return $id;
    }
}
