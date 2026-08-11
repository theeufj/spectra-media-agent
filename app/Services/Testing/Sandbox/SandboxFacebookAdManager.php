<?php

namespace App\Services\Testing\Sandbox;

use App\Contracts\Ads\FacebookAdManager;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic Meta ad fixtures; writes are recorded, never sent.
 *
 * The ad list deliberately straddles SelfHealingAgent's decision boundaries: one
 * ad is disapproved (must be healed), one is active and performing (must be left
 * alone), and one is active but exhausted (fatigue candidate). Fixtures built to
 * look plausible would test nothing in particular.
 */
class SandboxFacebookAdManager implements FacebookAdManager
{
    /** @var list<array{action: string, target: string, detail: string}> */
    private array $recorded = [];

    public function __construct(private Customer $customer) {}

    public function listAdSets(string $campaignId): ?array
    {
        return [
            ['id' => 'sandbox-adset-1', 'name' => 'Sandbox Ad Set', 'status' => 'ACTIVE', 'campaign_id' => $campaignId],
        ];
    }

    public function listAds(string $adSetId): ?array
    {
        return [
            [
                'id' => 'sandbox-ad-disapproved',
                'name' => 'Sandbox Ad (disapproved)',
                'status' => 'ACTIVE',
                'effective_status' => 'DISAPPROVED',
                'adset_id' => $adSetId,
            ],
            [
                'id' => 'sandbox-ad-healthy',
                'name' => 'Sandbox Ad (healthy)',
                'status' => 'ACTIVE',
                'effective_status' => 'ACTIVE',
                'adset_id' => $adSetId,
            ],
            [
                'id' => 'sandbox-ad-paused',
                'name' => 'Sandbox Ad (already paused)',
                'status' => 'PAUSED',
                'effective_status' => 'PAUSED',
                'adset_id' => $adSetId,
            ],
        ];
    }

    public function pauseAd(string $adId): bool
    {
        $this->record('pause_ad', $adId, 'status → PAUSED');

        return true;
    }

    public function createAd(string $accountId, string $adSetId, string $adName, string $creativeId, ?string $status = null): ?array
    {
        $this->record('create_ad', $adSetId, $adName.' ('.($status ?? 'default').')');

        return [
            'id' => 'sandbox-ad-'.substr(md5($adSetId.$adName.$creativeId), 0, 8),
            'name' => $adName,
        ];
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
        $this->record('create_creative', $accountId, $headline);

        return [
            'id' => 'sandbox-creative-'.substr(md5($creativeName.$headline), 0, 8),
            'name' => $creativeName,
        ];
    }

    /** @return list<array{action: string, target: string, detail: string}> */
    public function recorded(): array
    {
        return $this->recorded;
    }

    private function record(string $action, string $target, string $detail): void
    {
        $this->recorded[] = compact('action', 'target', 'detail');

        Log::info('SandboxFacebookAdManager: recorded intended change (nothing sent)', [
            'customer_id' => $this->customer->id,
            'action' => $action,
            'target' => $target,
        ]);
    }
}
