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
        $locationIds = [];

        // 1. Check campaign-level geographic targeting (highest priority)
        if (! empty($campaign->geographic_targeting)) {
            $locationIds = array_map(fn ($loc) => $this->resolveGeoTargetId($loc), $campaign->geographic_targeting);
            Log::info('GoogleAdsExecutionAgent: Using campaign geographic targeting', ['count' => count($locationIds)]);
        }
        // 2. Check targeting config — use the proper getter that extracts criterion IDs
        elseif ($strategy->targetingConfig && ! empty($strategy->targetingConfig->geo_locations)) {
            $googleTargeting = $strategy->targetingConfig->getGoogleGeoTargeting();
            if (! empty($googleTargeting)) {
                $locationIds = $googleTargeting;
                Log::info('GoogleAdsExecutionAgent: Using targeting config geo criterion IDs', ['count' => count($locationIds)]);
            } else {
                // Fallback: resolve raw location names/objects to IDs
                $locationIds = array_map(fn ($loc) => $this->resolveGeoTargetId($loc), $strategy->targetingConfig->geo_locations);
                Log::info('GoogleAdsExecutionAgent: Resolved targeting config locations to IDs', ['count' => count($locationIds)]);
            }
        }
        // 3. Check execution plan
        else {
            $campaignStructure = $plan->getCampaignStructure();
            if (isset($campaignStructure['locations']) && ! empty($campaignStructure['locations'])) {
                $locationIds = array_map(fn ($loc) => $this->resolveGeoTargetId($loc), $campaignStructure['locations']);
                Log::info('GoogleAdsExecutionAgent: Resolved execution plan locations to IDs', ['count' => count($locationIds)]);
            }
        }

        // Filter out nulls (unresolvable locations)
        $locationIds = array_filter($locationIds);

        if (empty($locationIds)) {
            // Default to English-speaking markets with meaningful SaaS ad spend
            $locationIds = [2840, 2124, 2036, 2826]; // US, CA, AU, GB
            Log::info('GoogleAdsExecutionAgent: No geo configured — defaulting to US/CA/AU/GB');
        }

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
            } catch (\Exception $e) {
                $result->addWarning("Failed to add location targeting for ID {$locationId}: ".$e->getMessage());
            }
        }
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
     * @see https://developers.google.com/google-ads/api/reference/data/geotargets
     */
    protected function geoTargetNameToId(string $name): ?int
    {
        static $map = [
            // Countries
            'united states' => 2840, 'us' => 2840, 'usa' => 2840,
            'united kingdom' => 2826, 'uk' => 2826, 'gb' => 2826,
            'canada' => 2124, 'ca' => 2124,
            'australia' => 2036, 'au' => 2036,
            'germany' => 2276, 'de' => 2276,
            'france' => 2250, 'fr' => 2250,
            'japan' => 2392, 'jp' => 2392,
            'india' => 2356, 'in' => 2356,
            'brazil' => 2076, 'br' => 2076,
            'mexico' => 2484, 'mx' => 2484,
            'italy' => 2380, 'it' => 2380,
            'spain' => 2724, 'es' => 2724,
            'netherlands' => 2528, 'nl' => 2528,
            'south korea' => 2410, 'kr' => 2410,
            'singapore' => 2702, 'sg' => 2702,
            'new zealand' => 2554, 'nz' => 2554,
            'ireland' => 2372, 'ie' => 2372,
            'south africa' => 2710, 'za' => 2710,
            'sweden' => 2752, 'se' => 2752,
            'norway' => 2578, 'no' => 2578,
            'denmark' => 2208, 'dk' => 2208,
            'finland' => 2246, 'fi' => 2246,
            'switzerland' => 2756, 'ch' => 2756,
            'austria' => 2040, 'at' => 2040,
            'belgium' => 2056, 'be' => 2056,
            'portugal' => 2620, 'pt' => 2620,
            'poland' => 2616, 'pl' => 2616,
            'israel' => 2376, 'il' => 2376,
            'united arab emirates' => 2784, 'uae' => 2784, 'ae' => 2784,
            'saudi arabia' => 2682, 'sa' => 2682,
            'philippines' => 2608, 'ph' => 2608,
            'indonesia' => 2360, 'id' => 2360,
            'malaysia' => 2458, 'my' => 2458,
            'thailand' => 2764, 'th' => 2764,
            'vietnam' => 2704, 'vn' => 2704,
            'china' => 2156, 'cn' => 2156,
            'hong kong' => 2344, 'hk' => 2344,
            'taiwan' => 2158, 'tw' => 2158,
            'argentina' => 2032, 'ar' => 2032,
            'colombia' => 2170, 'co' => 2170,
            'chile' => 2152, 'cl' => 2152,
            'nigeria' => 2566, 'ng' => 2566,
            'egypt' => 2818, 'eg' => 2818,
            'kenya' => 2404, 'ke' => 2404,
        ];

        $normalized = strtolower(trim($name));
        $id = $map[$normalized] ?? null;

        if (! $id) {
            Log::warning('GoogleAdsExecutionAgent: Unknown geo target name, cannot resolve to ID', ['name' => $name]);
        }

        return $id;
    }

    /**
     * Setup conversion tracking if needed
     */
}
