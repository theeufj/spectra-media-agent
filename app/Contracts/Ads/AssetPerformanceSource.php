<?php

namespace App\Contracts\Ads;

/**
 * Per-asset performance for responsive ads.
 *
 * CreativeIntelligenceAgent uses this to decide which headlines, descriptions
 * and images are pulling their weight.
 */
interface AssetPerformanceSource
{
    /**
     * Keyed by asset field: ['headlines' => [...], 'descriptions' => [...]].
     * Typed loosely rather than as a sealed shape — implementations return
     * supersets, and false precision here flagged correct defensive access.
     *
     * @return array<string, mixed>
     */
    public function getResponsiveSearchAdAssets(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): array;

    /** @return array<int, array<string, mixed>> */
    public function getImageAssetPerformance(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): array;
}
