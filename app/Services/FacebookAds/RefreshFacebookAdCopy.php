<?php

namespace App\Services\FacebookAds;

use App\Models\AgentActivity;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Services\Creative\CreativeRelease;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Refresh existing deployment ads with selected copy and optional reviewed replacement images. */
class RefreshFacebookAdCopy extends BaseFacebookAdsService
{
    /** @param array<int|string, int> $imageIdsByAd Existing Meta ad ID => selected ImageCollateral ID. */
    public function run(Strategy $strategy, bool $apply = false, ?array $adIds = null, array $imageIdsByAd = []): array
    {
        $strategy = $strategy->fresh() ?? throw new RuntimeException('The deployed Meta strategy could not be found.');
        $campaign = $strategy->campaign;
        if (! $campaign || $this->customer?->id !== $campaign->customer_id || ! $this->customer->facebook_bm_owned
            || ! $strategy->facebook_campaign_id || ! $strategy->facebook_adset_id
            || (string) $strategy->facebook_campaign_id !== (string) $campaign->facebook_ads_campaign_id) {
            throw new RuntimeException('A deployed Meta strategy in this managed workspace is required.');
        }
        $legacySigned = $imageIdsByAd === [] && $strategy->signed_off_at && $strategy->creative_approval === null
            && ! $strategy->deployableAdCopies()->where('updated_at', '>', $strategy->signed_off_at)->exists();
        if (! $legacySigned && ! app(CreativeRelease::class)->isApproved($strategy)) {
            throw new RuntimeException('Approve the current creative selection before refreshing live Meta ads.');
        }
        if ($imageIdsByAd !== []) {
            $adIds ??= array_keys($imageIdsByAd);
            if (array_diff($adIds, array_keys($imageIdsByAd)) !== [] || array_diff(array_keys($imageIdsByAd), $adIds) !== []) {
                throw new RuntimeException('Specify one reviewed image for every selected Meta ad.');
            }
        }
        $variations = FacebookCreativeCopy::variations($strategy);
        $mappedIds = array_filter($strategy->execution_result['platform_ids'] ?? [], fn ($key) => preg_match('/^(image|video)_ad_\d+$/D', $key), ARRAY_FILTER_USE_KEY);
        ksort($mappedIds, SORT_NATURAL);
        $mappedIds = array_values(array_map('strval', $mappedIds));
        if ($mappedIds === [] || count(array_unique($mappedIds)) !== count($mappedIds)
            || ($adIds !== null && ($adIds === [] || array_diff($adIds, $mappedIds) !== []))) {
            throw new RuntimeException('Select ads from this strategy’s verified deployment.');
        }

        $signup = FacebookCampaignMetrics::actionType($strategy) === 'complete_registration';
        $accountId = preg_replace('/^act_/', '', (string) $this->customer->facebook_ads_account_id);
        $lock = Cache::lock('meta-copy-refresh:'.$strategy->facebook_adset_id, 600);
        if ($apply && ! $lock->get()) {
            throw new RuntimeException('This ad set already has a creative refresh in progress.');
        }

        try {
            $images = ImageCollateral::forDeployment($strategy)->whereIn('id', array_values($imageIdsByAd))->get()->keyBy('id');
            $uploads = new CreativeService($this->customer);
            foreach (array_unique($imageIdsByAd) as $imageId) {
                $image = $images->get($imageId);
                if (! $image) {
                    throw new RuntimeException("Image {$imageId} must be selected and reviewed for this strategy.");
                }
                $uploads->reviewedImageHash($strategy, $image, upload: false);
            }

            $result = [];
            foreach ($mappedIds as $index => $adId) {
                if ($adIds !== null && ! in_array($adId, $adIds, false)) {
                    continue;
                }
                $ad = $this->get('/'.$adId, ['fields' => 'id,account_id,campaign_id,adset_id,status,name,creative{id,object_story_spec,asset_feed_spec,url_tags,degrees_of_freedom_spec}']);
                if (($ad['account_id'] ?? '') !== $accountId || (string) ($ad['campaign_id'] ?? '') !== (string) $strategy->facebook_campaign_id
                    || (string) ($ad['adset_id'] ?? '') !== (string) $strategy->facebook_adset_id || ($ad['status'] ?? '') !== 'ACTIVE') {
                    throw new RuntimeException("Could not verify active ad {$adId} in the expected account, campaign and ad set.");
                }

                $creative = $ad['creative'] ?? [];
                $story = $creative['object_story_spec'] ?? [];
                $image = isset($story['link_data']['image_hash'], $story['link_data']['link']);
                $video = isset($story['video_data']['video_id'], $story['video_data']['call_to_action']['value']['link']);
                if (empty($creative['id']) || empty($story['page_id']) || ! empty($creative['asset_feed_spec'])
                    || ! empty($story['link_data']['child_attachments']) || $image === $video) {
                    throw new RuntimeException("Ad {$adId} has an unsupported creative format for an in-place copy refresh.");
                }
                $copy = $variations[$index % count($variations)];
                $imageId = $imageIdsByAd[$adId] ?? null;
                if ($imageId !== null) {
                    $hash = $uploads->reviewedImageHash($strategy, $images->get($imageId), upload: $apply);
                    if ($video) {
                        $previous = $story['video_data'];
                        $story['link_data'] = ['link' => $previous['call_to_action']['value']['link'], 'call_to_action' => $previous['call_to_action']];
                        unset($story['video_data']);
                    }
                    // A crop and thumbnail reference the previous asset. Let the new image use its own framing.
                    if (($story['link_data']['image_hash'] ?? null) !== $hash) {
                        unset($story['link_data']['image_crops'], $story['link_data']['picture']);
                    }
                    $story['link_data']['image_hash'] = $hash ?? 'pending image upload';
                    $story['link_data']['name'] = $copy['headline'];
                    $story['link_data']['message'] = $copy['body'];
                    $key = 'link_data';
                } else {
                    $key = $image ? 'link_data' : 'video_data';
                    $title = $image ? 'name' : 'title';
                    $story[$key][$title] = $copy['headline'];
                    if ($video && ($story[$key]['link_description'] ?? null) === ($story[$key]['message'] ?? '')) {
                        $story[$key]['link_description'] = $copy['body'];
                    }
                    $story[$key]['message'] = $copy['body'];
                    if ($signup) {
                        $story[$key]['call_to_action']['type'] = 'SIGN_UP';
                    }
                }
                $changed = $story !== $creative['object_story_spec'];
                $result[$adId] = ['ad_id' => $adId, 'old_creative_id' => $creative['id'], ...$copy,
                    'call_to_action' => $story[$key]['call_to_action']['type'] ?? null,
                    'status' => $changed ? 'planned' : 'unchanged'];
                if ($imageId !== null) {
                    $result[$adId]['approved_image_id'] = $imageId;
                    $result[$adId]['destination'] = $story['link_data']['link'];
                }
                if (! $changed || ! $apply) {
                    continue;
                }

                // Meta can return both thumbnail fields, but accepts a single source on create.
                if ($key === 'video_data' && ! empty($story[$key]['image_hash'])) {
                    unset($story[$key]['image_url']);
                }
                $payload = Arr::only($creative, ['url_tags', 'degrees_of_freedom_spec']);
                $payload['name'] = $ad['name'].' — approved creative';
                $payload['object_story_spec'] = $story;
                $cacheKey = 'meta-copy:'.hash('sha256', $adId.json_encode($payload, JSON_THROW_ON_ERROR));
                $newId = Cache::get($cacheKey);
                if (! $newId) {
                    $created = $this->post('/act_'.$accountId.'/adcreatives', $payload);
                    $newId = $created['id'] ?? null;
                    if (! $newId) {
                        throw new RuntimeException("Meta could not create the replacement creative for ad {$adId}.");
                    }
                    Cache::put($cacheKey, $newId, now()->addDay());
                }
                if (! ($this->post('/'.$adId, ['creative' => ['creative_id' => $newId]])['success'] ?? false)) {
                    throw new RuntimeException("Meta could not assign replacement creative {$newId} to ad {$adId}.");
                }
                $verified = $this->get('/'.$adId, ['fields' => 'creative{id,object_story_spec},status,effective_status']);
                if (($verified['creative']['id'] ?? null) !== $newId || ($verified['status'] ?? '') !== 'ACTIVE') {
                    throw new RuntimeException("Meta has not confirmed replacement creative {$newId} on ad {$adId}.");
                }

                if ($imageId !== null) {
                    $verifiedStory = $verified['creative']['object_story_spec'] ?? [];
                    $actual = $verifiedStory['link_data'] ?? [];
                    $expected = $story['link_data'];
                    if (($verifiedStory['page_id'] ?? null) !== $story['page_id']
                        || ($actual['image_hash'] ?? null) !== $expected['image_hash']
                        || ($actual['link'] ?? null) !== $expected['link']
                        || ($actual['call_to_action']['type'] ?? null) !== ($expected['call_to_action']['type'] ?? null)
                        || ($actual['name'] ?? null) !== $copy['headline'] || ($actual['message'] ?? null) !== $copy['body']) {
                        throw new RuntimeException("Meta did not confirm the reviewed media, destination and copy on ad {$adId}.");
                    }
                    $result[$adId]['image_hash'] = $actual['image_hash'];
                }
                $result[$adId]['new_creative_id'] = $newId;
                $result[$adId]['effective_status'] = $verified['effective_status'] ?? null;
                $result[$adId]['status'] = 'updated';
                if ((string) $strategy->facebook_ad_id === $adId) {
                    $strategy->facebook_creative_id = $newId;
                    $strategy->save();
                }
                AgentActivity::record('facebook_creative', $imageId === null ? 'refreshed_ad_copy' : 'refreshed_ad_media', 'Updated a Meta ad with approved campaign creative.',
                    $campaign->customer_id, $campaign->id, Arr::only($result[$adId], ['ad_id', 'old_creative_id', 'new_creative_id', 'effective_status', 'approved_image_id', 'image_hash']));
            }

            return array_values($result);
        } finally {
            if ($apply) {
                $lock->release();
            }
        }
    }
}
