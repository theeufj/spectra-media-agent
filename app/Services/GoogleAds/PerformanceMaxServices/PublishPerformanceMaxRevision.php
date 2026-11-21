<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Models\Strategy;
use App\Services\Creative\CreativePublication;
use App\Services\Creative\GoogleCreativeCta;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use Google\Ads\GoogleAds\V26\Common\CallToActionAsset;
use Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V26\Enums\AssetGroupStatusEnum\AssetGroupStatus;
use Google\Ads\GoogleAds\V26\Resources\AssetGroup;
use Google\Ads\GoogleAds\V26\Resources\AssetGroupAsset;
use Google\Ads\GoogleAds\V26\Resources\AssetGroupSignal;
use Google\Ads\GoogleAds\V26\Services\AssetGroupAssetOperation;
use Google\Ads\GoogleAds\V26\Services\AssetGroupOperation;
use Google\Ads\GoogleAds\V26\Services\AssetGroupSignalOperation;
use Google\Ads\GoogleAds\V26\Services\MutateGoogleAdsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateOperation;
use Google\Protobuf\FieldMask;
use RuntimeException;

/** A separate group also works when the serving group is already at its asset limits. */
class PublishPerformanceMaxRevision extends RefreshPerformanceMaxAssets
{
    public function advance(Strategy $strategy, string $fingerprint): bool
    {
        $scope = CreativePublication::guard($strategy, $fingerprint);
        $account = $scope['account_id'];
        $campaign = $scope['resource_name'];
        $stage = $strategy->execution_result['metadata']['google_pmax_staging'] ?? [];
        $old = $stage['asset_group'] ?? '';
        if ($this->isDryRun() || $account !== $this->customer?->cleanGoogleCustomerId() || ! preg_match('#^customers/'.$account.'/assetGroups/\d+$#D', $old)) {
            throw new RuntimeException('Google did not confirm the live ad account and original group.');
        }
        $reader = app(ReadCampaignConfiguration::class, ['customer' => $this->customer]);
        $snapshot = $reader->performanceMax($account, $campaign);
        $name = 'Site to Spend reviewed '.$strategy->id.' '.$fingerprint;
        $groups = collect($snapshot['groups'])->pluck('assetGroup');
        $previous = $groups->firstWhere('resourceName', $old);
        $matching = $groups->where('name', $name)->values();
        $new = $matching->first();
        if (! $previous || $matching->count() > 1 || ($new && ($new['resourceName'] === $old || $new['finalUrls'] !== $stage['final_urls']))) {
            throw new RuntimeException('The reviewed campaign group could not be uniquely identified.');
        }
        $oldPaused = $previous['status'] === 'PAUSED';
        $validation = $snapshot;
        $validation['groups'] = array_values(array_filter($snapshot['groups'], fn ($row) => $row['assetGroup']['resourceName'] === $old));
        // After a successful pause with a lost response, validate the new group instead.
        $verifiedStage = $stage;
        unset($verifiedStage['required_assets']);
        if ($oldPaused && $new) {
            $verifiedStage['asset_group'] = $new['resourceName'];
            $verifiedStage['native_cta'] = $strategy->creative_approval['google_native_cta'];
            $validation['groups'] = [['assetGroup' => $new]];
        }
        if (! app(PerformanceMaxLaunchCheck::class)->matches($verifiedStage, $validation, 'ENABLED')) {
            throw new RuntimeException('The live Performance Max campaign differs from its approved configuration.');
        }
        $approval = $strategy->creative_approval;
        if (count($approval['copy_ids']) !== 1) {
            throw new RuntimeException('Select one complete Performance Max copy set for this release.');
        }
        $assets = $this->missingAssets([], $this->reviewedText($strategy, $approval['copy_ids'][0]),
            $this->reviewedMedia($strategy, $approval['image_ids'], $approval['video_ids']));
        $oldLinks = $this->links($account, $campaign, $old);
        $business = collect($oldLinks)->where('field', AssetFieldType::BUSINESS_NAME)->where('advertiser', true)->first();
        if (! $business || ! preg_match('#^customers/'.$account.'/assets/\d+$#D', $business['asset'])) {
            throw new RuntimeException('The verified business name for this asset group is unavailable.');
        }
        // A group can contain assets added outside this app. Preserve their exact
        // asset and role across revisions, including assets carried by an earlier revision.
        $managed = collect($stage['required_assets'] ?? [])->map(fn ($link) => $link['field_type'].'/'.$link['asset']);
        $retained = array_values(array_filter($oldLinks, fn ($link) => ($link['enabled'] ?? true) && $link['asset'] !== $business['asset']
            && ! $managed->contains(AssetFieldType::name($link['field']).'/'.$link['asset'])));
        foreach ($retained as $link) {
            if (! $link['advertiser'] || ! preg_match('#^customers/'.$account.'/assets/\d+$#D', $link['asset'])) {
                throw new RuntimeException('The current group includes a platform-managed asset that cannot be safely transferred.');
            }
        }
        $cta = $approval['google_native_cta'] ?? null;
        if ($cta !== null) {
            $assets[] = ['field' => AssetFieldType::CALL_TO_ACTION_SELECTION, 'key' => '', 'data' => [
                'call_to_action_asset' => new CallToActionAsset(['call_to_action' => GoogleCreativeCta::type($cta)]),
            ]];
        }
        if (! $new) {
            if (isset($strategy->creative_publication['create_requested_at'])) {
                // A timeout may occur after the provider commits. Recover by reading the deterministic name.
                return false;
            }
            $group = "customers/{$account}/assetGroups/-100000";
            $create = array_values(array_filter($assets, fn ($asset) => ! collect($retained)->contains(
                fn ($link) => $link['field'] === $asset['field'] && $link['key'] === $asset['key'])));
            $operations = $this->operations($account, $group, $create, []);
            foreach ($retained as $link) {
                $operations[] = new MutateOperation(['asset_group_asset_operation' => new AssetGroupAssetOperation([
                    'create' => new AssetGroupAsset(['asset_group' => $group, 'asset' => $link['asset'], 'field_type' => $link['field']]),
                ])]);
            }
            $businessLink = new MutateOperation(['asset_group_asset_operation' => new AssetGroupAssetOperation([
                'create' => new AssetGroupAsset(['asset_group' => $group, 'asset' => $business['asset'], 'field_type' => AssetFieldType::BUSINESS_NAME]),
            ])]);
            $groupOperation = new MutateOperation(['asset_group_operation' => new AssetGroupOperation([
                'create' => new AssetGroup(['resource_name' => $group, 'name' => $name, 'campaign' => $campaign,
                    'status' => AssetGroupStatus::ENABLED, 'final_urls' => $stage['final_urls']]),
            ])]);
            $signals = $this->copySignals($account, $old, $group);
            CreativePublication::write($strategy->id, $fingerprint, ['phase' => 'staging', 'predecessor_links' => [$old], 'create_requested_at' => now()->toIso8601String()]);
            $this->submitReviewedOperations($strategy, $fingerprint, $account, [$groupOperation, ...$operations, $businessLink, ...$signals]);

            return false;
        }
        $group = $new['resourceName'];
        $links = $this->links($account, $campaign, $group);
        $eligible = array_values(array_filter($links, fn ($link) => $link['eligible']));
        $creative = array_values(array_filter($assets, fn ($asset) => $asset['field'] !== AssetFieldType::CALL_TO_ACTION_SELECTION));
        $ready = ($new['status'] ?? null) === 'ENABLED' && ($new['primaryStatus'] ?? null) === 'ELIGIBLE'
            && array_all($retained, fn ($oldLink) => collect($eligible)->contains(
                fn ($link) => $link['field'] === $oldLink['field'] && $link['asset'] === $oldLink['asset']))
            && $this->missingAssets($eligible, [], $creative) === []
            && collect($eligible)->contains(fn ($link) => $link['field'] === AssetFieldType::BUSINESS_NAME && $link['asset'] === $business['asset'])
            && ($cta === null || collect($eligible)->contains('field', AssetFieldType::CALL_TO_ACTION_SELECTION)
                && PerformanceMaxLaunchCheck::linkedCtas($snapshot, $group) === [\Google\Ads\GoogleAds\V26\Enums\CallToActionTypeEnum\CallToActionType::name(GoogleCreativeCta::type($cta))]);
        $progress = ['phase' => 'awaiting_platform_review', 'replacement_links' => [$group], 'predecessor_links' => [$old],
            'ad_strength' => $new['adStrength'] ?? null, 'platform_status' => $new['primaryStatus'] ?? null];
        CreativePublication::write($strategy->id, $fingerprint, $progress);
        if (! $ready) {
            if (in_array($new['primaryStatus'] ?? null, ['NOT_ELIGIBLE', 'REMOVED'], true)) {
                throw new RuntimeException('Google rejected or removed the replacement asset group. The previous group was retained.');
            }

            return false;
        }
        if (! $oldPaused) {
            $pause = new AssetGroupOperation(['update' => new AssetGroup(['resource_name' => $old, 'status' => AssetGroupStatus::PAUSED]),
                'update_mask' => new FieldMask(['paths' => ['status']])]);
            $this->submitReviewedOperations($strategy, $fingerprint, $account, [new MutateOperation(['asset_group_operation' => $pause])]);

            return false;
        }
        $mediaBindings = [];
        foreach ($creative as $item) {
            if (! isset($item['local_id'])) {
                continue;
            }
            $link = collect($eligible)->first(fn ($link) => $link['field'] === $item['field'] && $link['key'] === $item['key']);
            if ($link) {
                $mediaBindings[$link['asset']] = ['type' => $item['local_type'], 'id' => $item['local_id'], 'field' => AssetFieldType::name($item['field'])];
            }
        }
        $result = $strategy->execution_result;
        $managedEligible = array_values(array_filter($eligible, fn ($link) => ! collect($retained)->contains(
            fn ($oldLink) => $oldLink['field'] === $link['field'] && $oldLink['asset'] === $link['asset'])));
        $newStage = array_replace($stage, ['asset_group' => $group, 'native_cta' => $cta, 'media_bindings' => $mediaBindings, 'retired_groups' => [...($stage['retired_groups'] ?? []), $old], 'required_assets' => array_map(
            fn ($link) => ['asset' => $link['asset'], 'field_type' => AssetFieldType::name($link['field'])], $managedEligible)]);
        $result['metadata']['google_pmax_staging'] = $newStage;
        $result['platform_ids']['asset_group'] = $group;

        return CreativePublication::write($strategy->id, $fingerprint, ['phase' => 'published'], ['execution_result' => $result]);
    }

    protected function copySignals(string $account, string $old, string $new): array
    {
        $operations = [];
        $query = "SELECT asset_group_signal.audience.audience, asset_group_signal.search_theme.text FROM asset_group_signal WHERE asset_group_signal.asset_group = '{$old}'";
        foreach ($this->searchQuery($account, $query)->iterateAllElements() as $row) {
            $signal = $row->getAssetGroupSignal();
            $data = $signal->getAudience() ? ['audience' => $signal->getAudience()] : ['search_theme' => $signal->getSearchTheme()];
            $operations[] = new MutateOperation(['asset_group_signal_operation' => new AssetGroupSignalOperation([
                'create' => new AssetGroupSignal(['asset_group' => $new] + $data),
            ])]);
        }

        return $operations;
    }

    protected function submitReviewedOperations(Strategy $strategy, string $fingerprint, string $account, array $operations): void
    {
        CreativePublication::guard($strategy, $fingerprint);
        $this->ensureClient();
        $this->client->getGoogleAdsServiceClient()->mutate(new MutateGoogleAdsRequest([
            'validate_only' => $this->dryRun,
            'customer_id' => $account, 'partial_failure' => false, 'mutate_operations' => $operations,
        ]));
    }
}
