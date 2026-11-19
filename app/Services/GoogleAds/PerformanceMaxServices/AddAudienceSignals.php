<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V26\Common\AudienceInfo;
use Google\Ads\GoogleAds\V26\Common\SearchThemeInfo;
use Google\Ads\GoogleAds\V26\Resources\AssetGroupSignal;
use Google\Ads\GoogleAds\V26\Services\AssetGroupSignalOperation;
use Google\Ads\GoogleAds\V26\Services\MutateAssetGroupSignalsRequest;
use Illuminate\Support\Facades\Log;

/**
 * Adds audience signals to a PMax asset group.
 *
 * Supports two signal types:
 *   - Search themes (text strings like keywords — tells PMax what search intent to chase)
 *   - Audience references (Google Ads Audience resource names)
 *
 * Both types can be mixed in a single call.
 */
class AddAudienceSignals extends BaseGoogleAdsService
{
    /** @return list<string> */
    public static function themes(mixed $values): array
    {
        $themes = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (! is_string($value)) {
                continue;
            }
            $theme = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
            if ($theme !== '' && mb_strlen($theme) <= 80 && count(explode(' ', $theme)) <= 10) {
                $themes[mb_strtolower($theme)] ??= $theme;
            }
        }

        return array_slice(array_values($themes), 0, 10);
    }

    /** Idempotent staged deployment. Only the missing reviewed themes are ever submitted. */
    public function ensureSearchThemes(string $customerId, string $group, array $themes): bool
    {
        if ($this->customer?->cleanGoogleCustomerId() !== $customerId || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/assetGroups/\d+$#D', $group)) {
            throw new \InvalidArgumentException('Invalid account or asset group for Performance Max search themes.');
        }
        $desired = self::themes($themes);
        if ($desired === []) {
            return true;
        }
        $compare = static fn (string $a, string $b) => strcmp(mb_strtolower($a), mb_strtolower($b));
        $missing = array_udiff($desired, $this->existingThemes($customerId, $group), $compare);
        if ($missing === []) {
            return true;
        }
        $this->addSearchThemes($customerId, $group, $missing);

        return array_udiff($desired, $this->existingThemes($customerId, $group), $compare) === [];
    }

    protected function existingThemes(string $customerId, string $group): array
    {
        $this->ensureClient();
        $themes = [];
        $rows = $this->searchQuery($customerId, "SELECT asset_group_signal.search_theme.text FROM asset_group_signal WHERE asset_group_signal.asset_group = '{$group}'");
        foreach ($rows->iterateAllElements() as $row) {
            if ($theme = $row->getAssetGroupSignal()->getSearchTheme()?->getText()) {
                $themes[] = $theme;
            }
        }

        return $themes;
    }

    /**
     * Add search-theme signals to a PMax asset group.
     *
     * Each theme is a keyword-like string (max 80 chars, max 10 words).
     * These guide PMax towards search-intent traffic rather than broad display.
     *
     * @param  string  $assetGroupResourceName  e.g. customers/123/assetGroups/456
     * @param  string[]  $themes  Plain-text search themes
     * @return int Number of signals successfully created
     */
    public function addSearchThemes(string $customerId, string $assetGroupResourceName, array $themes): int
    {
        $this->ensureClient();

        $operations = [];
        foreach (array_unique($themes) as $theme) {
            $theme = trim(mb_substr($theme, 0, 80));
            if (! $theme) {
                continue;
            }

            $signal = new AssetGroupSignal([
                'asset_group' => $assetGroupResourceName,
                'search_theme' => new SearchThemeInfo(['text' => $theme]),
            ]);

            $op = new AssetGroupSignalOperation;
            $op->setCreate($signal);
            $operations[] = $op;
        }

        if (empty($operations)) {
            return 0;
        }

        try {
            $response = $this->client->getAssetGroupSignalServiceClient()->mutateAssetGroupSignals(
                new MutateAssetGroupSignalsRequest([
                    'validate_only' => $this->dryRun,
                    'customer_id' => $customerId,
                    'operations' => $operations,
                ])
            );

            $count = count($response->getResults());
            $this->logInfo("AddAudienceSignals: Added {$count} search-theme signal(s) to {$assetGroupResourceName}");

            return $count;
        } catch (\Throwable $e) {
            report($e);
            $this->logError("AddAudienceSignals: Failed to add search themes to {$assetGroupResourceName}: ".$e->getMessage());

            return 0;
        }
    }

    /**
     * Add existing Audience resources to a PMax asset group.
     *
     * @param  string[]  $audienceResourceNames  e.g. ['customers/123/audiences/456']
     * @return int Number of signals successfully created
     */
    public function addAudienceInterests(string $customerId, string $assetGroupResourceName, array $audienceResourceNames): int
    {
        $this->ensureClient();

        $operations = [];
        foreach (array_unique($audienceResourceNames) as $resourceName) {
            if (! preg_match('#^customers/'.preg_quote($customerId, '#').'/audiences/\d+$#D', $resourceName)) {
                continue;
            }

            $signal = new AssetGroupSignal([
                'asset_group' => $assetGroupResourceName,
                'audience' => new AudienceInfo(['audience' => $resourceName]),
            ]);

            $op = new AssetGroupSignalOperation;
            $op->setCreate($signal);
            $operations[] = $op;
        }

        if (empty($operations)) {
            return 0;
        }

        try {
            $response = $this->client->getAssetGroupSignalServiceClient()->mutateAssetGroupSignals(
                new MutateAssetGroupSignalsRequest([
                    'validate_only' => $this->dryRun,
                    'customer_id' => $customerId,
                    'operations' => $operations,
                ])
            );

            $count = count($response->getResults());
            $this->logInfo("AddAudienceSignals: Added {$count} audience-interest signal(s) to {$assetGroupResourceName}");

            return $count;
        } catch (\Throwable $e) {
            report($e);
            // Audience signals can fail if the audience has no qualifying members yet — log but don't fail
            Log::warning("AddAudienceSignals: Audience interest signal failed for {$assetGroupResourceName}: ".$e->getMessage());

            return 0;
        }
    }
}
