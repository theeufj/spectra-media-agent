<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Enums\CampaignStatus;
use App\Jobs\RetireReviewedPerformanceMaxImages;
use App\Models\AdCopy;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\Creative\CreativeRelease;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\GoogleAdStrengthRepair;
use App\Services\StorageHelper;
use Google\Ads\GoogleAds\V26\Common\ImageAsset;
use Google\Ads\GoogleAds\V26\Common\TextAsset;
use Google\Ads\GoogleAds\V26\Common\YoutubeVideoAsset;
use Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V26\Enums\AssetLinkPrimaryStatusEnum\AssetLinkPrimaryStatus;
use Google\Ads\GoogleAds\V26\Enums\AssetLinkStatusEnum\AssetLinkStatus;
use Google\Ads\GoogleAds\V26\Enums\AssetSourceEnum\AssetSource;
use Google\Ads\GoogleAds\V26\Resources\Asset;
use Google\Ads\GoogleAds\V26\Resources\AssetGroupAsset;
use Google\Ads\GoogleAds\V26\Services\AssetGroupAssetOperation;
use Google\Ads\GoogleAds\V26\Services\AssetOperation;
use Google\Ads\GoogleAds\V26\Services\MutateGoogleAdsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateOperation;
use Illuminate\Support\Facades\Cache;

/** Refresh the selected creative in one previously verified Performance Max asset group. */
class RefreshPerformanceMaxAssets extends BaseGoogleAdsService
{
    /** Queue precisely the image associations selected for retirement from this approved release. */
    public function retireAfterReview(Strategy $strategy, array $links): void
    {
        $current = $strategy->fresh(['campaign.customer']) ?? throw new \RuntimeException('Strategy no longer exists.');
        $scope = $this->retirementScope($current);
        $this->checkRetirementScope($current, $scope);
        if (! $links || $this->isDryRun() || ! app(CreativeRelease::class)->isApproved($current)) {
            throw new \RuntimeException('Select image associations and approve the current creative before queuing retirement.');
        }
        foreach ($links as $link) {
            $this->retirementField($scope['account'], $scope['group'], $link);
        }
        $links = array_values(array_unique($links));
        sort($links, SORT_STRING);
        RetireReviewedPerformanceMaxImages::dispatch($current->id, $scope, $links)->afterCommit();
    }

    private function retirementScope(Strategy $strategy): array
    {
        $campaign = $strategy->campaign;
        $stage = $strategy->execution_result['metadata']['google_pmax_staging'] ?? [];

        return ['customer_id' => $campaign?->customer_id, 'campaign_id' => $strategy->campaign_id,
            'account' => $campaign?->customer?->cleanGoogleCustomerId(), 'campaign' => $stage['campaign_resource'] ?? null,
            'group' => $stage['asset_group'] ?? null, 'fingerprint' => $strategy->creative_approval['fingerprint'] ?? null];
    }

    private function checkRetirementScope(Strategy $strategy, array $scope): void
    {
        if ($this->retirementScope($strategy) !== $scope || ! $scope['account'] || ! $scope['fingerprint']
            || $strategy->campaign->customer_id !== $this->customer?->id || $scope['account'] !== $this->customer->cleanGoogleCustomerId()
            || $strategy->campaign_type !== 'performance_max' || $strategy->deployment_status !== 'active'
            || ! $scope['campaign'] || $scope['campaign'] !== $strategy->reusableGoogleCampaignId()
            || ! preg_match('#^customers/'.preg_quote($scope['account'], '#').'/assetGroups/\d+$#D', $scope['group'] ?? '')) {
            throw new \RuntimeException('The approved release or its Performance Max account, campaign or asset group changed.');
        }
        // A local campaign can own several platform strategies. Check the hold
        // against this strategy's verified remote campaign.
        $campaign = clone $strategy->campaign;
        $campaign->setAttribute('google_ads_campaign_id', $scope['campaign']);
        if ($hold = app(GoogleAdStrengthRepair::class)->mutationBlocked($campaign, $strategy)) {
            throw new \RuntimeException('Performance Max image retirement is on hold: '.str_replace('_', ' ', $hold).'.');
        }
    }

    /**
     * Google validates the complete mutation by default. Pass apply: true to
     * commit it. The only operations are asset creation and asset group links.
     *
     * @param  list<int>  $imageIds
     * @param  list<int>  $videoIds
     * @param  list<string>  $retireImageLinks  Exact advertiser-owned associations to retire after an alternative is eligible.
     */
    public function refresh(Strategy $strategy, ?int $adCopyId = null, array $imageIds = [], array $videoIds = [], bool $apply = false, bool $replaceMedia = false, array $retireImageLinks = [], ?array $retirementScope = null): array
    {
        if ($retirementScope !== null && ! $retireImageLinks) {
            throw new \InvalidArgumentException('A pinned retirement requires exact image associations.');
        }
        if ($retireImageLinks && ($replaceMedia || $adCopyId !== null || $imageIds || $videoIds)) {
            throw new \InvalidArgumentException('Review the replacement creative before retiring its previous image associations.');
        }
        if ($adCopyId === null && $imageIds === [] && $videoIds === [] && $retireImageLinks === []) {
            throw new \InvalidArgumentException('Select the reviewed Performance Max assets to refresh.');
        }

        $result = Cache::lock('pmax-creative-refresh:'.$strategy->id, 300)->get(fn () => $this->refreshUnderLock(
            $strategy->fresh(['campaign.customer']) ?? throw new \RuntimeException('Strategy no longer exists.'),
            $adCopyId, array_unique($imageIds), array_unique($videoIds), $apply && ! $this->isDryRun(), $replaceMedia, array_unique($retireImageLinks), $retirementScope,
        ));
        if ($result === false) {
            throw new \RuntimeException('A Performance Max creative refresh is already running.');
        }

        return $result;
    }

    private function refreshUnderLock(Strategy $strategy, ?int $adCopyId, array $imageIds, array $videoIds, bool $apply, bool $replaceMedia, array $retireImageLinks, ?array $retirementScope): array
    {
        if ($retirementScope !== null) {
            $this->checkRetirementScope($strategy, $retirementScope);
        }
        $campaign = $strategy->campaign;
        $customer = $campaign->customer;
        $account = $customer?->cleanGoogleCustomerId();
        $stage = $strategy->execution_result['metadata']['google_pmax_staging'] ?? [];
        $resource = $stage['campaign_resource'] ?? '';
        $group = $stage['asset_group'] ?? '';
        $budget = (int) ($stage['budget_micros'] ?? 0);

        if (! $account || $customer->id !== $this->customer?->id || $account !== $this->customer->cleanGoogleCustomerId()
            || $campaign->status !== CampaignStatus::Active || ! CampaignSpendGuardrails::canEnable($campaign)
            || ! $campaign->budget_confirmed_at || $budget <= 0 || $budget > CampaignSpendGuardrails::moneyToMicros($campaign->approved_daily_budget)
            || ! CampaignSpendGuardrails::permitsDailyBudget($campaign, $budget)
            || $strategy->campaign_type !== 'performance_max' || $strategy->deployment_status !== 'active' || ! $strategy->signed_off_at
            || $strategy->reusableGoogleCampaignId() !== $resource
            || ! preg_match('#^customers/'.preg_quote($account, '#').'/campaigns/\d+$#D', $resource)
            || ! preg_match('#^customers/'.preg_quote($account, '#').'/assetGroups/\d+$#D', $group)) {
            throw new \RuntimeException('The strategy has no active, reviewed Performance Max asset group in this account.');
        }
        $reader = app(ReadCampaignConfiguration::class, ['customer' => $customer]);
        $snapshot = $reader->performanceMax($account, $resource);
        if (! app(PerformanceMaxLaunchCheck::class)->matches($stage, $snapshot, 'ENABLED')) {
            throw new \RuntimeException('The live Performance Max configuration differs from its reviewed destination, bidding, budget or asset group.');
        }
        $release = app(CreativeRelease::class);
        if ($apply && (! $release->isApproved($strategy)
            || ($adCopyId !== null && ! in_array($adCopyId, $strategy->creative_approval['copy_ids'], true))
            || array_diff($imageIds, $strategy->creative_approval['image_ids']) !== []
            || array_diff($videoIds, $strategy->creative_approval['video_ids']) !== [])) {
            throw new \RuntimeException('Approve the exact creative selection before updating the live campaign.');
        }

        $before = $this->links($account, $resource, $group);
        $text = $adCopyId === null ? [] : $this->reviewedText($strategy, $adCopyId);
        $keys = array_map(static fn (array $lines): array => array_map(PerformanceMaxAssetSpec::textKey(...), $lines), $text);
        $media = $this->reviewedMedia($strategy, $imageIds, $videoIds);
        if ($replaceMedia) {
            $keys += array_fill_keys([AssetFieldType::MARKETING_IMAGE, AssetFieldType::SQUARE_MARKETING_IMAGE,
                AssetFieldType::PORTRAIT_MARKETING_IMAGE, AssetFieldType::YOUTUBE_VIDEO], []);
            foreach ($media as $asset) {
                $keys[$asset['field']][] = $asset['key'];
            }
            if ($keys[AssetFieldType::MARKETING_IMAGE] === [] || $keys[AssetFieldType::SQUARE_MARKETING_IMAGE] === []) {
                throw new \RuntimeException('Select at least one landscape and one square marketing image.');
            }
        }
        $create = $this->missingAssets($before, $text, $media);
        $remove = array_filter($before, static function (array $link) use ($keys): bool {
            $desired = $keys[$link['field']] ?? null;

            return $desired !== null && $link['advertiser'] && ! in_array($link['key'], $desired, true);
        });
        if ($retireImageLinks) {
            $remove = $this->retiringImages($strategy, $account, $group, $before, $retireImageLinks);
        }

        $removedResources = array_column($remove, 'resource');
        $operations = $this->operations($account, $group, $create, $removedResources);
        if ($operations !== []) {
            if ($apply) {
                $current = $strategy->fresh(['campaign.customer']);
                if (! $current || ! $release->isApproved($current) || $strategy->creative_approval['fingerprint'] !== $current->creative_approval['fingerprint']) {
                    throw new \RuntimeException('The approved creative changed before Google Ads could update it.');
                }
                if ($retirementScope !== null) {
                    $this->checkRetirementScope($current, $retirementScope);
                }
            }
            $this->ensureClient();
            $request = new MutateGoogleAdsRequest([
                'validate_only' => $this->dryRun,
                'customer_id' => $account,
                'partial_failure' => false,
                'mutate_operations' => $operations,
            ]);
            if (! $apply) {
                $request->setValidateOnly(true);
            }
            $this->client->getGoogleAdsServiceClient()->mutate($request);
        }

        $groups = $apply && $operations !== [] ? $reader->performanceMaxGroups($account, $resource) : $snapshot['groups'];
        $after = $apply ? $this->links($account, $resource, $group) : null;

        return [
            'campaign' => $resource, 'asset_group' => $group, 'validate_only' => ! $apply,
            'before' => $this->counts($before), 'add' => $this->counts($create), 'remove' => $this->counts($remove),
            'after' => $after !== null ? $this->counts($after) : null,
            'links_confirmed' => $after !== null && $this->missingAssets($after, [], $create) === []
                && array_intersect($retireImageLinks ?: $removedResources, array_column($after, 'resource')) === [],
            'ad_strength' => collect(array_column($groups, 'assetGroup'))->firstWhere('resourceName', $group)['adStrength'] ?? null,
        ];
    }

    private function retiringImages(Strategy $strategy, string $account, string $group, array $links, array $resources): array
    {
        $byResource = array_column($links, null, 'resource');
        $remove = [];
        $pendingFields = [];
        $approvedKeys = [];
        $approvedLogos = [];
        $hashes = $strategy->creative_approval['image_hashes'] ?? [];
        $logoHashes = $strategy->creative_approval['logo_hashes'] ?? [];
        foreach (ImageCollateral::forDeployment($strategy)->whereKey(array_keys($hashes))->get() as $image) {
            $name = 'PMax image '.substr($hashes[$image->id], 0, 32);
            $approvedKeys[$name] = $approvedKeys[$image->s3_path] = true;
            if (($logoHashes[$image->id] ?? null) === $hashes[$image->id]) {
                $approvedLogos[$name] = $approvedLogos[$image->s3_path] = true;
            }
        }
        foreach ($resources as $resource) {
            $field = $this->retirementField($account, $group, $resource);
            $link = $byResource[$resource] ?? null;
            if ($link === null) {
                continue; // An earlier successful request may already have retired this link.
            }
            if (! $link['advertiser'] || $link['field'] !== $field || isset($approvedKeys[$link['key']])) {
                throw new \RuntimeException('Only advertiser-owned images outside the approved selection can be retired.');
            }
            $survivors = array_filter($links, static fn ($other) => $other['field'] === $field && $other['advertiser']
                && ! in_array($other['resource'], $resources, true)
                && ($field !== AssetFieldType::LOGO || isset($approvedLogos[$other['key']])));
            if (! array_any($survivors, static fn ($other) => $other['eligible'])) {
                if (! array_any($survivors, static fn ($other) => $other['pending'] ?? false)) {
                    throw new \RuntimeException('Wait for an eligible alternative in the '.AssetFieldType::name($field).' role. Google has no qualifying replacement pending review.');
                }
                $pendingFields[] = AssetFieldType::name($field);
            }
            $remove[] = $link;
        }

        if ($pendingFields !== []) {
            throw new PerformanceMaxReplacementPending('Google is reviewing replacement images for '.implode(', ', array_unique($pendingFields)).'.');
        }

        return $remove;
    }

    private function retirementField(string $account, string $group, mixed $resource): int
    {
        $prefix = 'customers/'.$account.'/assetGroupAssets/'.basename($group).'~';
        if (! is_string($resource) || ! preg_match('#^'.preg_quote($prefix, '#').'\d+~(LOGO|MARKETING_IMAGE|SQUARE_MARKETING_IMAGE|PORTRAIT_MARKETING_IMAGE)$#D', $resource, $match)) {
            throw new \InvalidArgumentException('Select exact image associations from this Performance Max asset group.');
        }

        return AssetFieldType::value($match[1]);
    }

    protected function reviewedText(Strategy $strategy, int $id): array
    {
        $adCopy = $strategy->deployableAdCopies()->whereKey($id)->whereRaw('LOWER(platform) LIKE ?', ['%google%'])->first();
        if (! $adCopy instanceof AdCopy || ! PerformanceMaxCopy::hasCompleteDescriptions($adCopy->descriptions ?? [])) {
            throw new \RuntimeException('Select approved Performance Max copy with complete descriptions.');
        }

        $text = PerformanceMaxAssetSpec::text($adCopy);
        if (! $adCopy->long_headlines || count($text[AssetFieldType::HEADLINE]) < 11 || count($text[AssetFieldType::LONG_HEADLINE]) < 2
            || count($text[AssetFieldType::DESCRIPTION]) < 4 || ! array_any($text[AssetFieldType::DESCRIPTION], static fn (string $line): bool => mb_strlen($line) <= 60)) {
            throw new \RuntimeException('The reviewed copy needs at least 11 headlines, two long headlines and four descriptions, with one description of 60 characters or fewer.');
        }

        return $text;
    }

    protected function reviewedMedia(Strategy $strategy, array $imageIds, array $videoIds): array
    {
        $images = ImageCollateral::forDeployment($strategy)->where('campaign_id', $strategy->campaign_id)->whereKey($imageIds)->get();
        $videos = VideoCollateral::forDeployment($strategy)->where('campaign_id', $strategy->campaign_id)->whereKey($videoIds)
            ->where('status', 'completed')->whereNotNull('youtube_video_id')->get();
        if ($images->count() !== count($imageIds) || $videos->count() !== count($videoIds)) {
            throw new \RuntimeException('Every selected media asset must be approved for this strategy. Videos also need a ready YouTube ID.');
        }

        $media = [];
        $release = app(CreativeRelease::class);
        foreach ($images as $image) {
            $bytes = $image->s3_path ? StorageHelper::get($image->s3_path) : null;
            $field = $bytes ? PerformanceMaxLogo::imageField($image, $strategy, $bytes) : null;
            if ($field === null) {
                throw new \RuntimeException("Image {$image->id} does not match a supported Performance Max format.");
            }
            if ($strategy->creative_approval !== null && ! $release->matchesMedia($strategy, 'image', $image->id, $bytes)) {
                throw new \RuntimeException("Image {$image->id} no longer matches the approved render.");
            }

            $name = 'PMax image '.substr(hash('sha256', $bytes), 0, 32);
            $media[] = ['field' => $field, 'key' => $name, 'local_type' => 'image', 'local_id' => $image->id, 'data' => ['name' => $name, 'image_asset' => new ImageAsset(['data' => $bytes])]];
        }
        foreach ($videos as $video) {
            if (! preg_match('/^[A-Za-z0-9_-]{11}$/D', $video->youtube_video_id)) {
                throw new \RuntimeException("Video {$video->id} has an invalid YouTube ID.");
            }
            if ($strategy->creative_approval !== null) {
                $bytes = $video->s3_path ? StorageHelper::get($video->s3_path) : null;
                if (! $bytes || ! $release->matchesMedia($strategy, 'video', $video->id, $bytes)) {
                    throw new \RuntimeException("Video {$video->id} no longer matches the approved render.");
                }
            }
            $media[] = ['field' => AssetFieldType::YOUTUBE_VIDEO, 'key' => $video->youtube_video_id, 'local_type' => 'video', 'local_id' => $video->id,
                'data' => ['name' => "Reviewed video {$video->id}", 'youtube_video_asset' => new YoutubeVideoAsset(['youtube_video_id' => $video->youtube_video_id])]];
        }

        return $media;
    }

    protected function missingAssets(array $links, array $text, array $media): array
    {
        $seen = [];
        foreach ($links as $link) {
            $seen[$link['field']][$link['key']] = true;
        }
        foreach ($text as $field => $lines) {
            foreach ($lines as $line) {
                $media[] = ['field' => $field, 'key' => PerformanceMaxAssetSpec::textKey($line), 'data' => ['text_asset' => new TextAsset(['text' => $line])]];
            }
        }

        $missing = [];
        foreach ($media as $asset) {
            if (! isset($seen[$asset['field']][$asset['key']])) {
                $missing[] = $asset;
                $seen[$asset['field']][$asset['key']] = true;
            }
        }

        return $missing;
    }

    protected function links(string $account, string $campaign, string $group): array
    {
        $this->ensureClient();
        $links = [];
        $query = "SELECT asset_group_asset.resource_name, asset_group_asset.asset, asset_group_asset.field_type, asset_group_asset.source, asset_group_asset.status, asset_group_asset.primary_status, asset.name, asset.text_asset.text, asset.youtube_video_asset.youtube_video_id FROM asset_group_asset WHERE campaign.resource_name = '{$campaign}' AND asset_group.resource_name = '{$group}' AND asset_group_asset.status != 'REMOVED'";
        foreach ($this->searchQuery($account, $query)->iterateAllElements() as $row) {
            $link = $row->getAssetGroupAsset();
            $asset = $row->getAsset();
            $field = $link->getFieldType();
            $text = $asset?->getTextAsset()?->getText() ?? '';
            $key = match ($field) {
                AssetFieldType::HEADLINE, AssetFieldType::LONG_HEADLINE, AssetFieldType::DESCRIPTION => PerformanceMaxAssetSpec::textKey($text),
                AssetFieldType::YOUTUBE_VIDEO => $asset?->getYoutubeVideoAsset()?->getYoutubeVideoId() ?? '',
                default => $asset?->getName() ?? '',
            };
            $links[] = ['field' => $field, 'resource' => $link->getResourceName(), 'asset' => $link->getAsset(), 'key' => $key, 'advertiser' => $link->getSource() === AssetSource::ADVERTISER,
                'enabled' => $link->getStatus() === AssetLinkStatus::ENABLED,
                'eligible' => $link->getStatus() === AssetLinkStatus::ENABLED && $link->getPrimaryStatus() === AssetLinkPrimaryStatus::ELIGIBLE,
                'pending' => $link->getStatus() === AssetLinkStatus::ENABLED && $link->getPrimaryStatus() === AssetLinkPrimaryStatus::PENDING];
        }

        return $links;
    }

    protected function operations(string $account, string $group, array $create, array $remove): array
    {
        $assets = [];
        $links = array_map(static fn (string $resource): MutateOperation => new MutateOperation([
            'asset_group_asset_operation' => new AssetGroupAssetOperation(['remove' => $resource]),
        ]), $remove);

        foreach ($create as $index => $asset) {
            $assetResource = "customers/{$account}/assets/-".($index + 1);
            $assets[] = new MutateOperation(['asset_operation' => new AssetOperation([
                'create' => new Asset($asset['data'] + ['resource_name' => $assetResource]),
            ])]);
            $links[] = new MutateOperation(['asset_group_asset_operation' => new AssetGroupAssetOperation([
                'create' => new AssetGroupAsset(['asset_group' => $group, 'asset' => $assetResource, 'field_type' => $asset['field']]),
            ])]);
        }

        // Google checks minimum and maximum assets at the end of each
        // consecutive set of asset-group link operations.
        return [...$assets, ...$links];
    }

    private function counts(array $assets): array
    {
        return array_count_values(array_map(static fn (array $asset): string => AssetFieldType::name($asset['field']), $assets));
    }
}
