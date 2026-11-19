<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Services\Creative\CreativeRelease;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\DisplayServices\UploadImageAsset;
use App\Services\GoogleAds\GoogleAdStrengthRepair;
use App\Services\StorageHelper;
use Google\Ads\GoogleAds\V26\Enums\AdStrengthActionItemTypeEnum\AdStrengthActionItemType;
use Google\Ads\GoogleAds\V26\Enums\AssetCoverageVideoAspectRatioRequirementEnum\AssetCoverageVideoAspectRatioRequirement;
use Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V26\Resources\AssetCoverage;
use Google\Ads\GoogleAds\V26\Resources\AssetGroupAsset;
use Google\Ads\GoogleAds\V26\Services\AssetGroupAssetOperation;
use Google\Ads\GoogleAds\V26\Services\MutateAssetGroupAssetsRequest;

/** Restore approved Performance Max assets and report remaining gaps. */
class HealAssetGroupStrength extends BaseGoogleAdsService
{
    private const HEAL_STRENGTHS = [2, 3, 4, 5];

    private const TEXT_TARGETS = [
        'HEADLINE' => 15,
        'LONG_HEADLINE' => 5,
        'DESCRIPTION' => 5,
    ];

    private const FIELD_TYPES = [
        'HEADLINE' => AssetFieldType::HEADLINE,
        'LONG_HEADLINE' => AssetFieldType::LONG_HEADLINE,
        'DESCRIPTION' => AssetFieldType::DESCRIPTION,
        'MARKETING_IMAGE' => AssetFieldType::MARKETING_IMAGE,
        'SQUARE_IMAGE' => AssetFieldType::SQUARE_MARKETING_IMAGE,
        'PORTRAIT_IMAGE' => AssetFieldType::PORTRAIT_MARKETING_IMAGE,
        'LOGO' => AssetFieldType::LOGO,
        'YOUTUBE_VIDEO' => AssetFieldType::YOUTUBE_VIDEO,
    ];

    /**
     * Heal weak asset groups. Scope to one campaign if given, else all enabled
     * PMax campaigns on the account.
     *
     * @return array<int, array{asset_group:string, resource:string, ad_strength:string, added:array, missing_media:array, requested_assets:array, requires_review:bool}>
     */
    public function heal(?Campaign $campaign = null): array
    {
        $actions = [];
        $customerId = $this->customer->cleanGoogleCustomerId();
        if (! $customerId) {
            return $actions;
        }

        try {
            $strategy = $campaign ? app(GoogleAdStrengthRepair::class)->strategyForCampaign($campaign) : null;
            $release = app(CreativeRelease::class);
            $approved = $strategy && $release->isApproved($strategy) ? $strategy : null;
            $this->ensureClient();

            $filter = "campaign.status = 'ENABLED' AND asset_group.status = 'ENABLED'";
            if ($campaign) {
                $campId = $campaign->googleCampaignNumericId();
                if (! $campId) {
                    return [];
                }
                $filter .= ' AND campaign.id = '.$campId;
            }

            $groups = [];
            $q = "SELECT asset_group.resource_name, asset_group.id, asset_group.name, asset_group.ad_strength, asset_group.asset_coverage.ad_strength_action_items, campaign.name FROM asset_group WHERE {$filter}";
            foreach ($this->searchQuery($customerId, $q)->iterateAllElements() as $row) {
                $ag = $row->getAssetGroup();
                $groups[] = [
                    'res' => $ag->getResourceName(),
                    'id' => $ag->getId(),
                    'name' => $ag->getName(),
                    'strength' => $ag->getAdStrength(),
                    'coverage' => $this->requestedAssets($ag->getAssetCoverage()),
                ];
            }

            foreach ($groups as $g) {
                if (! in_array($g['strength'], self::HEAL_STRENGTHS, true) && ! $g['coverage']) {
                    continue;
                }
                $result = $this->healGroup($customerId, $g, $campaign, $approved);
                if ($result) {
                    $actions[] = $result;
                }
            }
        } catch (\Throwable $e) {
            report($e);
            if ($campaign) {
                throw $e;
            }
            $this->logError('HealAssetGroupStrength: heal failed: '.$e->getMessage());
        }

        return $actions;
    }

    private function healGroup(string $customerId, array $group, ?Campaign $campaign, ?Strategy $approved): ?array
    {
        $existing = $this->existingAssets($customerId, $group['id']);
        $missingMedia = array_filter(array_map(static fn (int $count) => (int) ($count === 0), $existing['media']));
        foreach (array_intersect_key($group['coverage'], $existing['media']) as $field => $count) {
            $missingMedia[$field] = max($missingMedia[$field] ?? 0, $count);
        }
        $needed = array_combine(array_keys(self::TEXT_TARGETS), array_map(
            static fn (string $type) => max(0, self::TEXT_TARGETS[$type] - count($existing['text'][$type])),
            array_keys(self::TEXT_TARGETS),
        ));
        $needed = array_filter($needed);
        if ($needed === [] && $missingMedia === [] && $group['coverage'] === []) {
            return null;
        }

        $added = [];
        if ($campaign && $approved) {
            $operations = [];
            $this->restoreApprovedCopy($customerId, $group['res'], $campaign, $approved, $needed, $existing, $operations, $added);
            $this->restoreApprovedImages($customerId, $group['res'], $campaign, $approved, $missingMedia, $existing['linked'], $operations, $added);
            if ($operations && ! $this->batchLink($customerId, $operations, $campaign, $approved)) {
                $added = [];
            }
        }

        $remaining = array_filter($missingMedia, static fn (int $count, string $field) => ($added[$field] ?? 0) < $count, ARRAY_FILTER_USE_BOTH);
        $coverage = [];
        foreach ($group['coverage'] as $field => $count) {
            if ($count > ($added[$field] ?? 0)) {
                $coverage[$field] = $count - ($added[$field] ?? 0);
            }
        }

        return [
            'asset_group' => $group['name'],
            'resource' => $group['res'],
            'ad_strength' => $this->strengthLabel($group['strength']),
            'added' => $added,
            'missing_media' => array_keys($remaining),
            'requested_assets' => $coverage,
            'requires_review' => array_filter($needed, static fn (int $count, string $type) => $count > ($added[$type] ?? 0), ARRAY_FILTER_USE_BOTH) !== []
                || array_diff(array_keys($remaining), ['YOUTUBE_VIDEO']) !== [] || array_diff_key($coverage, ['SITELINK' => true]) !== [],
        ];
    }

    private function restoreApprovedCopy(string $customerId, string $group, Campaign $campaign, Strategy $strategy, array $needed, array $existing, array &$operations, array &$added): void
    {
        $copy = $strategy->deployableAdCopies()->whereRaw('LOWER(platform) LIKE ?', ['%google%'])->first();
        if (! $copy instanceof AdCopy || ! $needed) {
            return;
        }

        $types = ['HEADLINE' => $copy->headlines, 'LONG_HEADLINE' => $copy->long_headlines, 'DESCRIPTION' => $copy->descriptions];
        $creator = app(CreateTextAsset::class, ['customer' => $this->customer]);
        foreach ($needed as $type => $count) {
            $existingText = array_map('mb_strtolower', $existing['text'][$type]);
            $candidates = collect($types[$type] ?? [])->unique(fn ($text) => mb_strtolower($text))
                ->reject(fn ($text) => in_array(mb_strtolower($text), $existingText, true))->take($count);
            foreach ($candidates as $text) {
                $this->requireMutationAllowed($campaign, $strategy);
                if ($asset = $creator($customerId, $text)) {
                    $operations[] = $this->linkOperation($group, $asset, self::FIELD_TYPES[$type]);
                    $added[$type] = ($added[$type] ?? 0) + 1;
                }
            }
        }
    }

    private function restoreApprovedImages(string $customerId, string $group, Campaign $campaign, Strategy $strategy, array $missing, array $linked, array &$operations, array &$added): void
    {
        $imageTypes = array_intersect_key(self::FIELD_TYPES, array_flip(['MARKETING_IMAGE', 'SQUARE_IMAGE', 'PORTRAIT_IMAGE', 'LOGO']));
        $upload = app(UploadImageAsset::class, ['customer' => $this->customer]);
        foreach (ImageCollateral::forDeployment($strategy)->get() as $image) {
            $bytes = StorageHelper::get($image->s3_path);
            if (! $bytes || ! app(CreativeRelease::class)->matchesMedia($strategy, 'image', $image->id, $bytes)) {
                continue;
            }
            $field = PerformanceMaxLogo::imageField($image, $strategy, $bytes);
            $type = array_search($field, $imageTypes, true);
            $name = 'PMax image '.substr(hash('sha256', $bytes), 0, 32);
            if ($type === false || ($added[$type] ?? 0) >= ($missing[$type] ?? 0)
                || isset($linked[$type][$name]) || isset($linked[$type][$image->s3_path])) {
                continue;
            }
            $this->requireMutationAllowed($campaign, $strategy);
            $asset = $upload($customerId, $bytes, $name);
            if ($asset && ! isset($linked[$type][$asset])) {
                $operations[] = $this->linkOperation($group, $asset, $field);
                $linked[$type][$name] = $linked[$type][$asset] = true;
                $added[$type] = ($added[$type] ?? 0) + 1;
            }
        }
    }

    private function linkOperation(string $group, string $asset, int $field): AssetGroupAssetOperation
    {
        $operation = new AssetGroupAssetOperation;
        $operation->setCreate(new AssetGroupAsset(['asset_group' => $group, 'asset' => $asset, 'field_type' => $field]));

        return $operation;
    }

    /** Link all assets in one atomic mutate, retrying transient concurrent-modification. */
    private function batchLink(string $customerId, array $ops, Campaign $campaign, Strategy $strategy): bool
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->requireMutationAllowed($campaign, $strategy);
            try {
                $this->client->getAssetGroupAssetServiceClient()->mutateAssetGroupAssets(
                    new MutateAssetGroupAssetsRequest(['validate_only' => $this->dryRun, 'customer_id' => $customerId, 'operations' => $ops])
                );

                return true;
            } catch (\Throwable $e) {
                if ($attempt < 3 && str_contains($e->getMessage(), 'CONCURRENT_MODIFICATION')) {
                    usleep(1_000_000 * $attempt); // 1s, then 2s backoff

                    continue;
                }
                report($e);
                throw $e;
            }
        }

        return false;
    }

    private function requireMutationAllowed(Campaign $campaign, Strategy $approved): void
    {
        $guard = app(GoogleAdStrengthRepair::class);
        $current = $guard->strategyForCampaign($campaign);
        if (! $current || $current->id !== $approved->id
            || ($current->creative_approval['fingerprint'] ?? null) !== $approved->creative_approval['fingerprint']
            || ! app(CreativeRelease::class)->isApproved($current)
            || ($blocked = $guard->campaignMutationBlocked($campaign, $current))) {
            throw new \DomainException($blocked ?? 'approved_creative_changed');
        }
    }

    /**
     * @return array{text: array<string, string[]>, media: array<string, int>, linked: array}
     */
    private function existingAssets(string $customerId, int $assetGroupId): array
    {
        $text = ['HEADLINE' => [], 'LONG_HEADLINE' => [], 'DESCRIPTION' => []];
        $media = ['LOGO' => 0, 'SQUARE_IMAGE' => 0, 'MARKETING_IMAGE' => 0, 'PORTRAIT_IMAGE' => 0, 'YOUTUBE_VIDEO' => 0];

        $fieldName = array_flip(self::FIELD_TYPES);
        $linked = [];

        $q = 'SELECT asset_group_asset.field_type, asset_group_asset.asset, asset.name, asset.text_asset.text FROM asset_group_asset '
            ."WHERE asset_group.id = {$assetGroupId} AND asset_group_asset.status = 'ENABLED'";
        foreach ($this->searchQuery($customerId, $q)->iterateAllElements() as $row) {
            $ft = $row->getAssetGroupAsset()->getFieldType();
            $label = $fieldName[$ft] ?? null;
            if (! $label) {
                continue;
            }
            if (isset($text[$label])) {
                $t = $row->getAsset()?->getTextAsset()?->getText();
                if ($t !== null && $t !== '') {
                    $text[$label][] = $t;
                }
            } elseif (isset($media[$label])) {
                $media[$label]++;
                $linked[$label][$row->getAssetGroupAsset()->getAsset()] = true;
                $linked[$label][$row->getAsset()?->getName() ?? ''] = true;
            }
        }

        return ['text' => $text, 'media' => $media, 'linked' => $linked];
    }

    /** Preserve the requested shape of each video even when a different video is already linked. */
    private function requestedAssets(?AssetCoverage $coverage): array
    {
        $requests = [];
        $fields = array_flip(self::FIELD_TYPES) + [AssetFieldType::SITELINK => 'SITELINK'];
        foreach ($coverage?->getAdStrengthActionItems() ?? [] as $item) {
            $detail = $item->getAddAssetDetails();
            if ($item->getActionItemType() !== AdStrengthActionItemType::ADD_ASSET || ! $detail || $detail->getAssetCount() < 1) {
                continue;
            }
            $field = $fields[$detail->getAssetFieldType()] ?? null;
            if ($field === null) {
                continue;
            }
            if ($field === 'YOUTUBE_VIDEO' && in_array($detail->getVideoAspectRatioRequirement(), [
                AssetCoverageVideoAspectRatioRequirement::HORIZONTAL, AssetCoverageVideoAspectRatioRequirement::SQUARE, AssetCoverageVideoAspectRatioRequirement::VERTICAL,
            ], true)) {
                $field .= '_'.AssetCoverageVideoAspectRatioRequirement::name($detail->getVideoAspectRatioRequirement());
            }
            $requests[$field] = max($requests[$field] ?? 0, $detail->getAssetCount());
        }

        return $requests;
    }

    private function strengthLabel(int $s): string
    {
        return [1 => 'UNKNOWN', 2 => 'PENDING', 3 => 'NO_ADS', 4 => 'POOR', 5 => 'AVERAGE', 6 => 'GOOD', 7 => 'EXCELLENT'][$s] ?? (string) $s;
    }
}
