<?php

namespace App\Services\Testing\Sandbox;

use App\Contracts\Ads\AssetPerformanceSource;
use App\Models\Customer;

/**
 * Deterministic asset-performance fixtures.
 *
 * Spans the performance labels CreativeIntelligenceAgent acts on — LOW assets
 * are candidates for replacement, BEST ones must be preserved — so both
 * branches of its logic run.
 */
class SandboxAssetPerformanceSource implements AssetPerformanceSource
{
    public function __construct(private Customer $customer) {}

    public function getResponsiveSearchAdAssets(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): array
    {
        // Keyed by field type, matching GetAdPerformanceByAsset. Each list spans
        // BEST and LOW so both branches of categorizeAssets run.
        return [
            'headlines' => [
                ['asset' => 'Automate '.$this->customer->name.' ad campaigns', 'performance_label' => 'BEST', 'impressions' => 5000],
                ['asset' => 'Click here now', 'performance_label' => 'LOW', 'impressions' => 4200],
            ],
            'descriptions' => [
                ['asset' => 'AI that manages your ads end to end', 'performance_label' => 'GOOD', 'impressions' => 4800],
                ['asset' => 'Lorem ipsum filler copy', 'performance_label' => 'LOW', 'impressions' => 3900],
            ],
        ];
    }

    public function getImageAssetPerformance(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): array
    {
        return [
            ['asset' => 'sandbox-image-'.$this->customer->id.'-1', 'performance_label' => 'BEST', 'impressions' => 3000, 'clicks' => 150],
            ['asset' => 'sandbox-image-2', 'performance_label' => 'LOW', 'impressions' => 2800, 'clicks' => 12],
        ];
    }
}
