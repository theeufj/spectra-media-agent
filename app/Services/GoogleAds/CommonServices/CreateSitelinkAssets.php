<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Models\Campaign;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\SitelinkAsset;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V22\Enums\AssetTypeEnum\AssetType;
use Google\Ads\GoogleAds\V22\Resources\Asset;
use Google\Ads\GoogleAds\V22\Services\AssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAssetsRequest;

/**
 * Ensures a campaign has sitelink assets (improves ad strength + ad real estate).
 * Sourced from the customer's known pages, with a sensible website-based fallback.
 * Idempotent: only tops up to a target count, so re-running is safe.
 */
class CreateSitelinkAssets extends BaseGoogleAdsService
{
    /**
     * @return int Number of sitelinks created + linked this run.
     */
    public function heal(Campaign $campaign, int $target = 4): int
    {
        $this->ensureClient();

        $customerId = $this->customer->cleanGoogleCustomerId();
        $campaignId = $campaign->googleCampaignNumericId();
        $resource   = $campaign->googleAdsResourceName();
        if (!$campaignId || !$resource) {
            return 0;
        }

        // How many sitelinks does the campaign already have?
        $existing = 0;
        try {
            // campaign.id must be in the SELECT clause when filtering campaign_asset by it.
            $q = "SELECT campaign.id, campaign_asset.resource_name FROM campaign_asset "
                . "WHERE campaign.id = {$campaignId} AND campaign_asset.field_type = 'SITELINK'";
            foreach ($this->searchQuery($customerId, $q)->getIterator() as $_) {
                $existing++;
            }
        } catch (\Throwable $e) {
            $this->logError('CreateSitelinkAssets: sitelink count query failed: ' . $e->getMessage());
            return 0;
        }

        if ($existing >= $target) {
            return 0;
        }

        $sitelinks = array_slice($this->buildSitelinks($campaign), 0, $target - $existing);
        if (empty($sitelinks)) {
            return 0;
        }

        $linker = new LinkCampaignAsset($this->customer);
        $added  = 0;

        foreach ($sitelinks as $sl) {
            $assetResource = $this->createSitelinkAsset($customerId, $sl);
            if ($assetResource && $linker($customerId, $resource, $assetResource, AssetFieldType::SITELINK)) {
                $added++;
            }
        }

        return $added;
    }

    private function createSitelinkAsset(string $customerId, array $sl): ?string
    {
        $sitelink = new SitelinkAsset(['link_text' => mb_substr($sl['text'], 0, 25)]);
        if (!empty($sl['desc1'])) {
            $sitelink->setDescription1(mb_substr($sl['desc1'], 0, 35));
        }
        if (!empty($sl['desc2'])) {
            $sitelink->setDescription2(mb_substr($sl['desc2'], 0, 35));
        }

        $asset = new Asset([
            'name'           => 'Sitelink: ' . mb_substr($sl['text'], 0, 25) . ' - ' . uniqid(),
            'type'           => AssetType::SITELINK,
            'sitelink_asset' => $sitelink,
            'final_urls'     => [$sl['url']],
        ]);

        $operation = new AssetOperation();
        $operation->setCreate($asset);

        try {
            $resp = $this->client->getAssetServiceClient()->mutateAssets(new MutateAssetsRequest([
                'customer_id' => $customerId,
                'operations'  => [$operation],
            ]));
            return $resp->getResults()[0]->getResourceName();
        } catch (\Throwable $e) {
            $this->logError('CreateSitelinkAssets: asset create failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @return array<int,array{text:string,url:string,desc1:?string,desc2:?string}>
     */
    private function buildSitelinks(Campaign $campaign): array
    {
        $customer  = $campaign->customer;
        $sitelinks = [];
        $seen      = [];

        foreach ($customer->pages()->whereNotNull('url')->limit(12)->get() as $page) {
            $text = trim((string) ($page->title ?? ''));
            $url  = trim((string) $page->url);
            if ($text === '' || $url === '') {
                continue;
            }
            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $sitelinks[] = ['text' => $text, 'url' => $url, 'desc1' => null, 'desc2' => null];
        }

        // Fallback to conventional pages off the website root.
        if (empty($sitelinks) && $customer->website) {
            $base = rtrim($customer->website, '/');
            foreach ([['Get Started', '/'], ['Pricing', '/pricing'], ['About Us', '/about'], ['Contact', '/contact']] as [$t, $path]) {
                $sitelinks[] = ['text' => $t, 'url' => $base . $path, 'desc1' => null, 'desc2' => null];
            }
        }

        return $sitelinks;
    }
}
