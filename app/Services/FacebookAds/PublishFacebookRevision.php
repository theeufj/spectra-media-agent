<?php

namespace App\Services\FacebookAds;

use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\Campaigns\CampaignTrackingUrl;
use App\Services\Creative\CreativePublication;
use App\Services\Creative\CreativeRelease;
use App\Services\StorageHelper;
use RuntimeException;

/** Stage new child ads under the existing verified budget. Leave the serving ads in place during review. */
class PublishFacebookRevision
{
    public function advance(Strategy $strategy, string $fingerprint): bool
    {
        $resource = CreativePublication::guard($strategy, $fingerprint);
        $account = $resource['account_id'];
        $campaignId = $resource['resource_id'];
        $campaign = $strategy->campaign;
        $customer = $campaign->customer;
        $params = ['customer' => $customer];
        $remote = app(CampaignService::class, $params)->getCampaign($campaignId);
        $set = (string) $strategy->facebook_adset_id;
        $sets = app(AdSetService::class, $params)->listAdSets($campaignId);
        $parent = collect($sets)->firstWhere('id', $set);
        $budget = (int) (($remote['daily_budget'] ?? null) ?: ($parent['daily_budget'] ?? 0));
        if ($set === '' || ! $parent || ($remote['id'] ?? null) !== $campaignId || ($remote['account_id'] ?? null) !== $account
            || ($remote['effective_status'] ?? null) !== 'ACTIVE' || ($parent['status'] ?? null) !== 'ACTIVE'
            || $budget <= 0 || $budget * 10000 > CampaignSpendGuardrails::moneyToMicros($strategy->daily_budget)) {
            throw new RuntimeException('Meta did not confirm the approved ad account, active campaign, ad set and daily budget.');
        }
        $ads = app(AdService::class, $params);
        $remoteAds = $ads->listAds($set) ?? throw new RuntimeException('Meta ad readback is unavailable.');
        $remoteAds = collect($remoteAds)->filter(fn ($ad) => ($ad['account_id'] ?? null) === $account
            && ($ad['campaign_id'] ?? null) === $campaignId && ($ad['adset_id'] ?? null) === $set);
        $state = $strategy->creative_publication;
        $replacements = $state['replacement_links'] ?? [];
        $prior = $state['predecessor_links'] ?? array_values(array_unique(array_filter([
            ...array_column($state['previous_managed'] ?? [], 'ad'), $strategy->facebook_ad_id,
            ...array_values(array_filter($strategy->execution_result['platform_ids'] ?? [], fn ($key) => preg_match('/^(image|video)_ad_\d+$/D', $key), ARRAY_FILTER_USE_KEY)),
        ])));
        if ($prior === [] || array_diff($prior, $remoteAds->pluck('id')->all()) !== []) {
            throw new RuntimeException('Meta did not confirm the previously managed ads in this exact campaign and ad set.');
        }
        CreativePublication::write($strategy->id, $fingerprint, ['predecessor_links' => $prior]);
        $images = ImageCollateral::forDeployment($strategy)->get();
        $videos = VideoCollateral::forDeployment($strategy)->get();
        $selection = $images->map(fn ($image) => ['image', $image])->concat($videos->map(fn ($video) => ['video', $video]))->values();
        if ($selection->isEmpty() || $selection->count() !== count($strategy->creative_approval['image_ids']) + count($strategy->creative_approval['video_ids'])) {
            throw new RuntimeException('Select the reviewed image and video files before updating Meta.');
        }
        $variants = FacebookCreativeCopy::variations($strategy);
        $destination = ($strategy->bidding_strategy['landing_page_url'] ?? null) ?: ($campaign->landing_page_url ?: $customer->website);
        $cta = FacebookCampaignMetrics::actionType($strategy) === 'complete_registration' ? 'SIGN_UP' : 'LEARN_MORE';
        $url = CampaignTrackingUrl::forStrategy($destination, $campaign, $strategy, 'facebook');
        $creative = app(CreativeService::class, $params);
        $allEligible = true;
        foreach ($selection as $index => [$type, $media]) {
            $key = $type.'_ad_'.$media->id;
            $name = 'Site to Spend '.$strategy->id.' '.$fingerprint.' '.$key;
            $saved = $replacements[$key] ?? [];
            $copy = $strategy->creative_approval['facebook_variants'][$key] ?? $variants[$index % count($variants)];
            $matches = $remoteAds->where('name', $name);
            if ($matches->count() > 1) {
                throw new RuntimeException('Meta returned ambiguous ad identifiers for this release.');
            }
            $found = $matches->first();
            if ($found && (($found['creative']['id'] ?? null) !== ($saved['creative'] ?? '') || isset($saved['ad']) && $found['id'] !== $saved['ad'])) {
                throw new RuntimeException('The staged Meta ad no longer references the reviewed creative.');
            }
            if (! $found && (isset($saved['ad']) || isset($saved['create_requested_at']))) {
                // Meta is eventually consistent. Never create a second ad after receiving an ID.
                $allEligible = false;

                continue;
            }
            if (! $found) {
                CreativePublication::guard($strategy, $fingerprint);
                if (! isset($saved['creative'])) {
                    $bytes = $media->s3_path ? StorageHelper::get($media->s3_path) : null;
                    if (! $bytes || ! app(CreativeRelease::class)->matchesMedia($strategy, $type, $media->id, $bytes)) {
                        throw new RuntimeException('A selected Meta file differs from the reviewed creative.');
                    }
                    $mediaUrl = StorageHelper::url($media->s3_path);
                    $result = $type === 'image'
                        ? $creative->createImageCreative($account, $name, $mediaUrl, $copy['headline'], $copy['body'], $cta, $url, $creative->reviewedImageHash($strategy, $media, true))
                        : $creative->createVideoCreative($account, $name, $mediaUrl, $copy['headline'], $copy['body'], $cta, $url);
                    $saved['creative'] = $result['id'] ?? throw new RuntimeException('Meta did not return the replacement creative ID.');
                    $replacements[$key] = $saved;
                    CreativePublication::write($strategy->id, $fingerprint, ['replacement_links' => $replacements]);
                }
                $saved['create_requested_at'] = now()->toIso8601String();
                $replacements[$key] = $saved;
                CreativePublication::write($strategy->id, $fingerprint, ['replacement_links' => $replacements]);
                CreativePublication::guard($strategy, $fingerprint);
                $result = $ads->createAd($account, $set, $name, $saved['creative'], 'PAUSED');
                $saved['ad'] = $result['id'] ?? throw new RuntimeException('Meta did not confirm creation of the paused replacement.');
                $replacements[$key] = $saved;
                CreativePublication::write($strategy->id, $fingerprint, ['replacement_links' => $replacements]);
                $allEligible = false;

                continue;
            }
            $saved['ad'] = $found['id'];
            $saved['effective_status'] = $found['effective_status'] ?? 'UNKNOWN';
            $replacements[$key] = $saved;
            if (in_array($saved['effective_status'], ['DISAPPROVED', 'WITH_ISSUES', 'ARCHIVED', 'DELETED'], true)) {
                throw new RuntimeException('Meta rejected or removed a replacement. The previous ads were retained.');
            }
            if ($saved['effective_status'] !== 'ACTIVE' || ($found['status'] ?? null) !== 'ACTIVE') {
                $allEligible = false;
            }
        }
        CreativePublication::write($strategy->id, $fingerprint, ['phase' => 'awaiting_platform_review', 'replacement_links' => $replacements]);
        if (! $allEligible) {
            // Enable only after readback of *every* replacement and its staged creative.
            if (count($replacements) === $selection->count() && array_all($replacements, fn ($link) => isset($link['ad']) && $remoteAds->contains('id', $link['ad']))) {
                foreach ($replacements as $link) {
                    $found = $remoteAds->firstWhere('id', $link['ad']);
                    if ($found['status'] === 'PAUSED') {
                        CreativePublication::guard($strategy, $fingerprint);
                        if (! $ads->resumeAd($link['ad'])) {
                            throw new RuntimeException('Meta has not enabled the complete replacement set.');
                        }
                    }
                }
            }

            return false;
        }
        $remaining = $remoteAds->whereIn('id', $prior)->reject(fn ($ad) => in_array($ad['status'], ['PAUSED', 'DELETED', 'ARCHIVED'], true));
        foreach ($remaining as $old) {
            CreativePublication::guard($strategy, $fingerprint);
            if (! $ads->pauseAd($old['id'])) {
                throw new RuntimeException('Meta did not confirm pausing an eligible predecessor.');
            }
        }
        if ($remaining->isNotEmpty()) {
            return false;
        }
        $execution = $strategy->execution_result ?? [];
        $execution['platform_ids'] = array_filter($execution['platform_ids'] ?? [], fn ($key) => ! preg_match('/^(image|video)_ad_\d+$/D', $key), ARRAY_FILTER_USE_KEY)
            + array_map(fn ($link) => $link['ad'], $replacements);
        $first = reset($replacements);

        return CreativePublication::write($strategy->id, $fingerprint, ['phase' => 'published'], [
            'execution_result' => $execution, 'facebook_creative_id' => $first['creative'], 'facebook_ad_id' => $first['ad'],
        ]);
    }
}
