<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\AdTextAsset;
use Google\Ads\GoogleAds\V22\Common\ResponsiveSearchAdInfo;
use Google\Ads\GoogleAds\V22\Resources\Ad;
use Google\Ads\GoogleAds\V22\Resources\AdGroupAd;
use Google\Ads\GoogleAds\V22\Services\AdGroupAdOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAdGroupAdsRequest;
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
     * @param string $customerId
     * @param string $adGroupAdResourceName  e.g. "customers/123/adGroupAds/456~789"
     * @param array  $existingHeadlines      Current headline strings on the ad
     * @param array  $existingDescriptions   Current description strings on the ad
     * @param array  $newHeadlines           New headline strings to append
     * @param array  $newDescriptions        New description strings to append
     * @return bool  true if the ad was updated, false if nothing changed or an error occurred
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

        $headlineAssets = array_map(
            fn($text) => new AdTextAsset(['text' => substr($text, 0, 30)]),
            $mergedHeadlines
        );
        $descriptionAssets = array_map(
            fn($text) => new AdTextAsset(['text' => substr($text, 0, 90)]),
            $mergedDescriptions
        );

        $adGroupAd = new AdGroupAd([
            'resource_name' => $adGroupAdResourceName,
            'ad' => new Ad([
                'responsive_search_ad' => new ResponsiveSearchAdInfo([
                    'headlines'    => $headlineAssets,
                    'descriptions' => $descriptionAssets,
                ]),
            ]),
        ]);

        $operation = new AdGroupAdOperation();
        $operation->setUpdate($adGroupAd);
        $operation->setUpdateMask(new FieldMask([
            'paths' => ['ad.responsive_search_ad.headlines', 'ad.responsive_search_ad.descriptions'],
        ]));

        try {
            $this->client->getAdGroupAdServiceClient()->mutateAdGroupAds(
                new MutateAdGroupAdsRequest([
                    'customer_id' => $customerId,
                    'operations'  => [$operation],
                ])
            );
            $this->logInfo("UpdateResponsiveSearchAd: updated {$adGroupAdResourceName} — "
                . count($mergedHeadlines) . " headlines, " . count($mergedDescriptions) . " descriptions");
            return true;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("UpdateResponsiveSearchAd failed for {$adGroupAdResourceName}: " . $e->getMessage(), $e);
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
