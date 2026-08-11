<?php

namespace App\Services\FacebookAds\Adapters;

use App\Contracts\Ads\FacebookAdManager;
use App\Models\Customer;
use App\Services\FacebookAds\AdService;
use App\Services\FacebookAds\AdSetService;
use App\Services\FacebookAds\CreativeService;

/**
 * Live implementation: performs real ad, ad set and creative operations on Meta.
 *
 * Services are built lazily so listing ad sets does not also construct a
 * creative client that will never be used.
 */
class LiveFacebookAdManager implements FacebookAdManager
{
    private ?AdService $ads = null;

    private ?AdSetService $adSets = null;

    private ?CreativeService $creatives = null;

    public function __construct(private Customer $customer) {}

    public function listAdSets(string $campaignId): ?array
    {
        return ($this->adSets ??= new AdSetService($this->customer))->listAdSets($campaignId);
    }

    public function listAds(string $adSetId): ?array
    {
        return ($this->ads ??= new AdService($this->customer))->listAds($adSetId);
    }

    public function pauseAd(string $adId): bool
    {
        return ($this->ads ??= new AdService($this->customer))->pauseAd($adId);
    }

    public function createAd(string $accountId, string $adSetId, string $adName, string $creativeId, ?string $status = null): ?array
    {
        return ($this->ads ??= new AdService($this->customer))
            ->createAd($accountId, $adSetId, $adName, $creativeId, $status);
    }

    public function createImageCreative(
        string $accountId,
        string $creativeName,
        string $imageUrl,
        string $headline,
        string $description,
        string $callToAction = 'LEARN_MORE',
        ?string $linkUrl = null
    ): ?array {
        return ($this->creatives ??= new CreativeService($this->customer))
            ->createImageCreative($accountId, $creativeName, $imageUrl, $headline, $description, $callToAction, $linkUrl);
    }
}
