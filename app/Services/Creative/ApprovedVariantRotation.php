<?php

namespace App\Services\Creative;

use App\Jobs\PublishApprovedCreative;
use App\Jobs\ReviewCreativeRelease;
use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The customer's release authorizes rotation only among its previously reviewed files and claims. */
final class ApprovedVariantRotation
{
    public function proposeMeta(Campaign $campaign, string $remoteAdId): bool
    {
        $matches = [];
        foreach ($campaign->strategies()->where('deployment_status', 'active')->get() as $strategy) {
            if (CreativePublication::channel($strategy) !== 'facebook') {
                continue;
            }
            $bindings = [];
            foreach ($strategy->execution_result['platform_ids'] ?? [] as $key => $remote) {
                if (preg_match('/^(image|video)_ad_(\d+)$/D', $key, $match)) {
                    $model = $match[1] === 'image' ? ImageCollateral::class : VideoCollateral::class;
                    $asset = $model::whereKey((int) $match[2])->where('campaign_id', $campaign->id)->where('strategy_id', $strategy->id)->first();
                    if ($asset) {
                        $bindings[(string) $remote] = ['type' => $match[1], 'id' => $asset->id,
                            'field' => $match[1].':'.($match[1] === 'image' ? $asset->format : $asset->platform)];
                    }
                }
            }
            if (isset($bindings[$remoteAdId])) {
                $matches[] = [$strategy, $bindings[$remoteAdId], $bindings];
            }
        }

        return count($matches) === 1 && $this->propose(...$matches[0]);
    }

    /**
     * Exclude one measured underperformer if another explicitly approved treatment in its role remains.
     * Campaign-level shared assets, new files, unmapped legacy groups and new offers remain proposals.
     */
    public function propose(Strategy $strategy, array $underperformer, array $roleBindings): bool
    {
        return DB::transaction(function () use ($strategy, $underperformer, $roleBindings) {
            $current = Strategy::lockForUpdate()->findOrFail($strategy->id);
            $approval = $current->creative_approval ?? [];
            $release = app(CreativeRelease::class);
            $type = $underperformer['type'] ?? null;
            $id = $underperformer['id'] ?? null;
            $field = $underperformer['field'] ?? null;
            if (! in_array($type, ['image', 'video'], true) || ! is_int($id) || ! $field || ! ($approval['allow_variant_rotation'] ?? false)
                || $current->deployment_status !== 'active' || ! $release->isApproved($current)
                || in_array($current->creative_publication['phase'] ?? '', ['staging', 'awaiting_platform_review'], true)
                || in_array($current->creative_revision['status'] ?? '', ['draft', 'checking_rotation'], true)) {
                return false;
            }
            $allowed = $approval[$type.'_ids'] ?? [];
            $sameRole = array_filter($roleBindings, fn ($item) => ($item['field'] ?? null) === $field && ($item['type'] ?? null) === $type
                && ($item['id'] ?? null) !== $id && in_array($item['id'] ?? null, $allowed, true));
            if (! $sameRole || ! in_array($id, $allowed, true)) {
                return false;
            }
            $model = $type === 'image' ? ImageCollateral::class : VideoCollateral::class;
            $row = $model::whereKey($id)->where('strategy_id', $current->id)->where('campaign_id', $current->campaign_id)->lockForUpdate()->first();
            if (! $row || ! $row->should_deploy || ! $row->is_active) {
                return false;
            }
            $row->update(['should_deploy' => false]);
            $fresh = $release->selection($current);
            $current->forceFill(['creative_revision' => ['id' => (string) Str::uuid(), 'status' => 'checking_rotation',
                'created_at' => now()->toIso8601String(), 'fingerprint' => $fresh['fingerprint'], 'previous_approval' => $approval,
                'affected' => [$type], 'retiring' => ['type' => $type, 'id' => $id]]])->save();
            ReviewCreativeRelease::dispatch($current->id, $fresh['fingerprint'])->afterCommit();

            return true;
        });
    }

    /** Called with the reviewed strategy locked by ReviewCreativeRelease. */
    public function complete(Strategy $strategy, array $verdict): void
    {
        $rotation = $strategy->creative_revision ?? [];
        if (($rotation['status'] ?? null) !== 'checking_rotation' || ($rotation['fingerprint'] ?? null) !== ($strategy->creative_release_review['fingerprint'] ?? null)) {
            return;
        }
        $release = app(CreativeRelease::class);
        $selection = $release->selection($strategy);
        $previous = $rotation['previous_approval'];
        $unchanged = ($previous['fingerprint'] ?? null) === ($strategy->creative_approval['fingerprint'] ?? null)
            && ($previous['mandate'] ?? '') === hash('sha256', json_encode($selection['mandate'], JSON_THROW_ON_ERROR))
            && ($previous['google_native_cta'] ?? null) === $selection['google_native_cta']
            && ($previous['copy_digest'] ?? null) === $selection['copy_digest']
            && $previous['copy_ids'] === $selection['copy']->pluck('id')->all();
        foreach (['image' => 'images', 'video' => 'videos'] as $type => $collection) {
            $ids = $selection[$collection]->pluck('id')->all();
            if (array_diff($ids, $previous[$type.'_ids']) !== []) {
                $unchanged = false;
            }
            foreach ($ids as $id) {
                $hash = $verdict[$type.'_hashes'][$id] ?? null;
                if (! is_string($hash) || $hash !== ($previous[$type.'_hashes'][$id] ?? null)
                    || $type === 'image' && ($previous['google_display_banner_hashes'][$id] ?? []) !== ($verdict['google_display_banner_hashes'][$id] ?? [])) {
                    $unchanged = false;
                }
            }
        }
        if (! $unchanged || ($verdict['status'] ?? null) !== 'passed' || $rotation['fingerprint'] !== $selection['fingerprint']) {
            $strategy->forceFill(['creative_revision' => [...$rotation, 'status' => 'needs_approval']])->save();

            return;
        }
        $release->approve($strategy, $rotation['fingerprint'], $previous['facebook_variants'] ?? null);
        CreativePublication::begin($strategy, $rotation['fingerprint'], $previous);
        $strategy->forceFill(['creative_revision' => [...$rotation, 'status' => 'approved_rotation']])->save();
        PublishApprovedCreative::dispatch($strategy->id, $rotation['fingerprint'])->afterCommit();
    }
}
