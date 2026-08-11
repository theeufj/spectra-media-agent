<?php

namespace App\Contracts\Ads;

/**
 * Meta ad, ad set and creative operations.
 *
 * Mixes reads and writes deliberately: SelfHealingAgent lists ad sets, lists
 * their ads and then creates a replacement creative as one healing sequence,
 * and splitting that across contracts would obscure it. In the sandbox the
 * reads return fixtures and the writes are recorded, so the whole sequence runs
 * without touching a real ad account.
 */
interface FacebookAdManager
{
    public function listAdSets(string $campaignId): ?array;

    public function listAds(string $adSetId): ?array;

    public function pauseAd(string $adId): bool;

    public function createAd(string $accountId, string $adSetId, string $adName, string $creativeId, ?string $status = null): ?array;

    public function createImageCreative(
        string $accountId,
        string $creativeName,
        string $imageUrl,
        string $headline,
        string $description,
        string $callToAction = 'LEARN_MORE',
        ?string $linkUrl = null
    ): ?array;
}
