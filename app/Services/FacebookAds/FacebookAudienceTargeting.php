<?php

namespace App\Services\FacebookAds;

use App\Models\Strategy;

class FacebookAudienceTargeting
{
    /** Estimate and deploy the same audience. Persona demographics describe buyers;
     * explicit platform controls, such as a hard upper age, govern delivery.
     */
    public static function forReview(Strategy $strategy, array $countries): array
    {
        $config = $strategy->targetingConfig;
        $targeting = $config->facebook_options ?? [];
        $targeting['geo_locations'] = [...($targeting['geo_locations'] ?? []), 'countries' => $countries];
        $targeting['age_min'] ??= 18;
        $targeting['age_max'] ??= 65;
        // An age ceiling below 65 or a required gender cannot be expanded.
        $manual = $targeting['age_max'] < 65 || $targeting['age_min'] > 25
            || (! empty($targeting['genders']) && count(array_unique($targeting['genders'])) === 1);
        $targeting['targeting_automation']['advantage_audience'] = $manual ? 0
            : ($targeting['targeting_automation']['advantage_audience'] ?? 1);

        return $targeting;
    }
}
