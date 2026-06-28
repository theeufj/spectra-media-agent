<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\AudienceInfo;
use Google\Ads\GoogleAds\V22\Common\SearchThemeInfo;
use Google\Ads\GoogleAds\V22\Resources\AssetGroupSignal;
use Google\Ads\GoogleAds\V22\Services\AssetGroupSignalOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAssetGroupSignalsRequest;
use Illuminate\Support\Facades\Log;

/**
 * Adds audience signals to a PMax asset group.
 *
 * Supports two signal types:
 *   - Search themes (text strings like keywords — tells PMax what search intent to chase)
 *   - Audience references (user_interest or audience resource names)
 *
 * Both types can be mixed in a single call.
 */
class AddAudienceSignals extends BaseGoogleAdsService
{
    /**
     * Add search-theme signals to a PMax asset group.
     *
     * Each theme is a keyword-like string (max 80 chars, max 10 words).
     * These guide PMax towards search-intent traffic rather than broad display.
     *
     * @param  string   $customerId
     * @param  string   $assetGroupResourceName  e.g. customers/123/assetGroups/456
     * @param  string[] $themes                  Plain-text search themes
     * @return int      Number of signals successfully created
     */
    public function addSearchThemes(string $customerId, string $assetGroupResourceName, array $themes): int
    {
        $this->ensureClient();

        $operations = [];
        foreach (array_unique($themes) as $theme) {
            $theme = trim(mb_substr($theme, 0, 80));
            if (!$theme) {
                continue;
            }

            $signal = new AssetGroupSignal([
                'asset_group'  => $assetGroupResourceName,
                'search_theme' => new SearchThemeInfo(['text' => $theme]),
            ]);

            $op = new AssetGroupSignalOperation();
            $op->setCreate($signal);
            $operations[] = $op;
        }

        if (empty($operations)) {
            return 0;
        }

        try {
            $response = $this->client->getAssetGroupSignalServiceClient()->mutateAssetGroupSignals(
                new MutateAssetGroupSignalsRequest([
                    'customer_id' => $customerId,
                    'operations'  => $operations,
                ])
            );

            $count = count($response->getResults());
            $this->logInfo("AddAudienceSignals: Added {$count} search-theme signal(s) to {$assetGroupResourceName}");
            return $count;
        } catch (\Exception $e) {
            $this->logError("AddAudienceSignals: Failed to add search themes to {$assetGroupResourceName}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Add audience signals (user interests / in-market segments) to a PMax asset group.
     *
     * @param  string   $customerId
     * @param  string   $assetGroupResourceName
     * @param  string[] $audienceResourceNames   e.g. ['userInterests/92', 'userInterests/301']
     * @return int      Number of signals successfully created
     */
    public function addAudienceInterests(string $customerId, string $assetGroupResourceName, array $audienceResourceNames): int
    {
        $this->ensureClient();

        $operations = [];
        foreach (array_unique($audienceResourceNames) as $resourceName) {
            if (!$resourceName) {
                continue;
            }

            $signal = new AssetGroupSignal([
                'asset_group' => $assetGroupResourceName,
                'audience'    => new AudienceInfo(['audience' => $resourceName]),
            ]);

            $op = new AssetGroupSignalOperation();
            $op->setCreate($signal);
            $operations[] = $op;
        }

        if (empty($operations)) {
            return 0;
        }

        try {
            $response = $this->client->getAssetGroupSignalServiceClient()->mutateAssetGroupSignals(
                new MutateAssetGroupSignalsRequest([
                    'customer_id' => $customerId,
                    'operations'  => $operations,
                ])
            );

            $count = count($response->getResults());
            $this->logInfo("AddAudienceSignals: Added {$count} audience-interest signal(s) to {$assetGroupResourceName}");
            return $count;
        } catch (\Exception $e) {
            // Audience signals can fail if the audience has no qualifying members yet — log but don't fail
            Log::warning("AddAudienceSignals: Audience interest signal failed for {$assetGroupResourceName}: " . $e->getMessage());
            return 0;
        }
    }
}
