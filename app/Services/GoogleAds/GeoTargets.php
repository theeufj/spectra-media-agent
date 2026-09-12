<?php

namespace App\Services\GoogleAds;

/**
 * Country and region names to Google Ads geo target constant IDs.
 *
 * This map lived as a protected method on GeoTargetResolver, which meant the
 * only way to reach it was to already hold an execution agent bound to a
 * customer. The forecast needs the same answer and has neither, and the cost of
 * not having it was not theoretical: config('demo.geo_target') pins
 * geoTargetConstants/2036 — Australia — so every forecast ran against
 * Australian search volume and Australian bids regardless of where the business
 * was. Wrong geo is the worst kind of wrong here, because every number it
 * produces is internally consistent and looks entirely reasonable.
 *
 * @see https://developers.google.com/google-ads/api/reference/data/geotargets
 */
class GeoTargets
{
    /**
     * Names and ISO codes to criterion IDs.
     *
     * @var array<string, int>
     */
    private const MAP = [
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

    /**
     * The criterion ID for a country name or ISO code, or null if unknown.
     */
    public static function idFor(string $name): ?int
    {
        return self::MAP[strtolower(trim($name))] ?? null;
    }

    /**
     * The resource name Keyword Planner takes, for a customer's country.
     *
     * Falls back to the configured default rather than returning null: a
     * forecast against the default geo is worth more to the caller than no
     * forecast, and an unknown country is a gap in the map above rather than a
     * reason to show the customer nothing.
     */
    public static function resourceNameForCountry(?string $country): string
    {
        $id = $country ? self::idFor($country) : null;

        return $id ? "geoTargetConstants/{$id}" : (string) config('demo.geo_target');
    }

    /**
     * Whether a country resolves to a real target rather than the fallback.
     */
    public static function knows(?string $country): bool
    {
        return $country !== null && self::idFor($country) !== null;
    }
}
