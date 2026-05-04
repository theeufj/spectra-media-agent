<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\AdTextAsset;
use Google\Ads\GoogleAds\V22\Common\ResponsiveSearchAdInfo;
use Google\Ads\GoogleAds\V22\Resources\Ad;
use Google\Ads\GoogleAds\V22\Services\AdOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAdsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;
use Google\Protobuf\FieldMask;

class UpdateResponsiveSearchAd extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Append new headlines and descriptions to an existing RSA, capped at Google's limits (15/4).
     *
     * RSA headlines/descriptions are immutable via AdGroupAd — must use Ad service directly.
     *
     * @param string $customerId
     * @param string $adGroupAdResourceName  e.g. "customers/123/adGroupAds/456~789"
     * @param array  $existingHeadlines      Current headline strings on the ad
     * @param array  $existingDescriptions   Current description strings on the ad
     * @param array  $newHeadlines           New headline strings to append
     * @param array  $newDescriptions        New description strings to append
     * @return bool  true if updated, false if nothing changed or error
     */
    public function __invoke(
        string $customerId,
        string $adGroupAdResourceName,
        array $existingHeadlines,
        array $existingDescriptions,
        array $newHeadlines,
        array $newDescriptions
    ): bool {
        $this->ensureClient();

        $mergedHeadlines    = $this->mergeAssets($existingHeadlines, $newHeadlines, 15);
        $mergedDescriptions = $this->mergeAssets($existingDescriptions, $newDescriptions, 4);

        if ($mergedHeadlines === $existingHeadlines && $mergedDescriptions === $existingDescriptions) {
            return false;
        }

        return $this->sendUpdate($customerId, $adGroupAdResourceName, $mergedHeadlines, $mergedDescriptions);
    }

    /**
     * Fully replace the headlines and/or descriptions on an RSA.
     * Skips the equality check — use when you need to remove or swap specific assets.
     *
     * @param string $customerId
     * @param string $adGroupAdResourceName  e.g. "customers/123/adGroupAds/456~789"
     * @param array  $headlines              Complete new set of headlines (3–15)
     * @param array  $descriptions           Complete new set of descriptions (2–4)
     * @return bool  true if updated, false on error
     */
    public function replace(
        string $customerId,
        string $adGroupAdResourceName,
        array $headlines,
        array $descriptions
    ): bool {
        $this->ensureClient();

        $headlines    = array_values(array_unique($headlines));
        $descriptions = array_values(array_unique($descriptions));

        return $this->sendUpdate($customerId, $adGroupAdResourceName, $headlines, $descriptions);
    }

    private function sendUpdate(
        string $customerId,
        string $adGroupAdResourceName,
        array $headlines,
        array $descriptions
    ): bool {
        // Extract Ad ID from adGroupAd resource name: "customers/X/adGroupAds/AGID~ADID"
        preg_match('/~(\d+)$/', $adGroupAdResourceName, $m);
        $adId = $m[1] ?? null;
        if (!$adId) {
            $this->logError("UpdateResponsiveSearchAd: cannot extract ad ID from {$adGroupAdResourceName}");
            return false;
        }
        $adResourceName = "customers/{$customerId}/ads/{$adId}";

        $ad = new Ad([
            'resource_name'        => $adResourceName,
            'responsive_search_ad' => new ResponsiveSearchAdInfo([
                'headlines'    => array_map(
                    fn($t) => new AdTextAsset(['text' => substr($t, 0, 30)]),
                    $headlines
                ),
                'descriptions' => array_map(
                    fn($t) => new AdTextAsset(['text' => substr($t, 0, 90)]),
                    $descriptions
                ),
            ]),
        ]);

        $operation = new AdOperation();
        $operation->setUpdate($ad);
        $operation->setUpdateMask(new FieldMask([
            'paths' => ['responsive_search_ad.headlines', 'responsive_search_ad.descriptions'],
        ]));

        try {
            $this->client->getAdServiceClient()->mutateAds(
                new MutateAdsRequest([
                    'customer_id' => $customerId,
                    'operations'  => [$operation],
                ])
            );
            $this->logInfo("UpdateResponsiveSearchAd: updated ad {$adId} — "
                . count($headlines) . " headlines, " . count($descriptions) . " descriptions");
            return true;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("UpdateResponsiveSearchAd failed for ad {$adId}: " . $e->getMessage(), $e);
            return false;
        }
    }

    private function mergeAssets(array $existing, array $additions, int $limit): array
    {
        $seen = array_map('strtolower', $existing);
        foreach ($additions as $text) {
            if (count($existing) >= $limit) {
                break;
            }
            if (!in_array(strtolower($text), $seen, true)) {
                $existing[] = $text;
                $seen[]     = strtolower($text);
            }
        }
        return $existing;
    }
}
