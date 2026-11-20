<?php

namespace App\Services\GoogleAds\DisplayServices;

use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\Campaigns\CampaignTrackingUrl;
use App\Services\Creative\CreativeRelease;
use App\Services\Creative\GoogleDisplayBannerRenderer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\StorageHelper;
use Google\Ads\GoogleAds\V26\Common\ImageAdInfo;
use Google\Ads\GoogleAds\V26\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V26\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Resources\Ad;
use Google\Ads\GoogleAds\V26\Resources\AdGroupAd;
use Google\Ads\GoogleAds\V26\Services\AdGroupAdOperation;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupAdsRequest;
use Illuminate\Support\Facades\Cache;

/** Stage the exact uploaded image ads the customer reviewed, inside their paused Display campaign. */
class StageDisplayBanners extends BaseGoogleAdsService
{
    /** @return list<string> Existing or newly staged ad resource names. */
    public function stage(Strategy $strategy, string $account, string $campaign, string $group): array
    {
        $approved = $strategy->creative_approval['google_display_banner_hashes'] ?? [];
        if (! $approved) {
            return [];
        }
        if (! ctype_digit($account) || $account !== $this->customer?->cleanGoogleCustomerId()
            || ! preg_match("#^customers/{$account}/adGroups/\\d+$#D", $group)
            || ! preg_match("#^customers/{$account}/campaigns/\\d+$#D", $campaign)) {
            throw new \RuntimeException('The uploaded Display banners require a verified customer account and ad group.');
        }

        return Cache::lock('google-display-banners:'.$group, 300)->block(5, function () use ($strategy, $account, $campaign, $group, $approved) {
            $resources = [];
            foreach ($approved as $imageId => $sizes) {
                $fresh = $this->approvedStrategy($strategy, $account, $campaign, $group);
                $image = ImageCollateral::forDeployment($fresh)->where('campaign_id', $fresh->campaign_id)->whereKey($imageId)->firstOrFail();
                $manifest = $image->google_display_banners;
                $destination = ($fresh->bidding_strategy['landing_page_url'] ?? null) ?: ($fresh->campaign->landing_page_url ?: $fresh->campaign->customer->website);
                if (! $destination || ! is_array($manifest) || $manifest['cta'] !== ($fresh->creative_approval['google_native_cta'] ?? null)) {
                    throw new \RuntimeException('The uploaded banners no longer match the approved destination and call to action.');
                }
                $url = CampaignTrackingUrl::forStrategy($destination, $fresh->campaign, $fresh, 'google');
                $pending = [];
                foreach ($sizes as $size => $digest) {
                    // Country-restricted inventory needs a verified matching location target.
                    if (isset(GoogleDisplayBannerRenderer::REGIONAL_SIZES[$size])) {
                        continue;
                    }
                    $path = $manifest['sizes'][$size] ?? null;
                    if (! is_string($path) || ! is_string($digest) || ($manifest['hashes'][$size] ?? null) !== $digest) {
                        throw new \RuntimeException('A selected Display banner has changed since approval.');
                    }
                    $bytes = StorageHelper::get($path, GoogleDisplayBannerRenderer::MAX_BYTES);
                    if (! is_string($bytes) || ! self::matches($bytes, $size, $digest)) {
                        throw new \RuntimeException("The approved {$size} Display banner could not be verified.");
                    }
                    $name = 'Site to Spend '.$image->id.' '.$size.' '.hash('sha256', $digest.'|'.$url);
                    $pending[$name] = $bytes;
                }
                if ($pending === []) {
                    continue;
                }
                $this->ensureClient();
                $rows = $this->searchQuery($account, "SELECT ad_group_ad.resource_name, ad_group_ad.status, ad_group_ad.ad.name, ad_group_ad.ad.final_urls FROM ad_group_ad WHERE ad_group.resource_name = '{$group}' AND campaign.resource_name = '{$campaign}' AND ad_group_ad.ad.type = 'IMAGE_AD' AND ad_group_ad.status != 'REMOVED'");
                foreach ($rows->iterateAllElements() as $row) {
                    $groupAd = $row->getAdGroupAd();
                    $ad = $groupAd->getAd();
                    if (isset($pending[$ad->getName()])) {
                        if ($groupAd->getStatus() !== AdGroupAdStatus::ENABLED || iterator_to_array($ad->getFinalUrls()) !== [$url]) {
                            throw new \RuntimeException('The existing uploaded Display ad has a different status or destination.');
                        }
                        $resources[] = $groupAd->getResourceName();
                        unset($pending[$ad->getName()]);
                    }
                }
                if ($pending === []) {
                    continue;
                }
                $this->approvedStrategy($strategy, $account, $campaign, $group);
                $scope = $this->searchQuery($account, "SELECT campaign.status, campaign.advertising_channel_type, ad_group.status FROM ad_group WHERE ad_group.resource_name = '{$group}' AND campaign.resource_name = '{$campaign}'")->iterateAllElements();
                $row = collect($scope)->sole();
                if ($row->getCampaign()->getStatus() !== CampaignStatus::PAUSED || $row->getCampaign()->getAdvertisingChannelType() !== AdvertisingChannelType::DISPLAY
                    || $row->getAdGroup()->getStatus() !== AdGroupStatus::ENABLED) {
                    throw new \RuntimeException('Google must confirm the uploaded ads belong to the reviewed, paused Display campaign.');
                }
                $operations = [];
                foreach ($pending as $name => $bytes) {
                    $operations[] = self::operation($group, $name, $url, $bytes);
                }
                $response = $this->client->getAdGroupAdServiceClient()->mutateAdGroupAds(new MutateAdGroupAdsRequest([
                    'customer_id' => $account, 'operations' => $operations, 'validate_only' => $this->dryRun,
                ]));
                foreach ($response->getResults() as $result) {
                    $resources[] = $result->getResourceName();
                }
            }

            return array_values(array_unique($resources));
        });
    }

    private function approvedStrategy(Strategy $strategy, string $account, string $campaign, string $group): Strategy
    {
        $fresh = $strategy->fresh(['campaign.customer']);
        if (! $fresh || $fresh->campaign_type !== 'display' || ! $fresh->signed_off_at || $fresh->reusableGoogleCampaignId() !== $campaign
            || $fresh->google_ads_ad_group_id !== $group || $fresh->campaign->customer_id !== $this->customer?->id
            || $fresh->campaign->customer->cleanGoogleCustomerId() !== $account || $fresh->campaign->hasPassedEndDate()
            || CampaignSpendGuardrails::automaticChangesSuspended($fresh->campaign) || $fresh->creative_approval !== $strategy->creative_approval
            || ! app(CreativeRelease::class)->isApproved($fresh)) {
            throw new \RuntimeException('The uploaded Display banners require current approval for this campaign and ad group.');
        }

        return $fresh;
    }

    public static function matches(string $bytes, string $size, string $digest): bool
    {
        if (! isset(GoogleDisplayBannerRenderer::SIZES[$size]) || strlen($bytes) > GoogleDisplayBannerRenderer::MAX_BYTES || ! hash_equals($digest, hash('sha256', $bytes))) {
            return false;
        }
        $info = @getimagesizefromstring($bytes);

        return $info !== false && $info[2] === IMAGETYPE_JPEG && [$info[0], $info[1]] === GoogleDisplayBannerRenderer::SIZES[$size];
    }

    public static function operation(string $group, string $name, string $url, string $bytes): AdGroupAdOperation
    {
        return new AdGroupAdOperation(['create' => new AdGroupAd([
            'ad_group' => $group, 'status' => AdGroupAdStatus::ENABLED,
            'ad' => new Ad(['name' => $name, 'final_urls' => [$url], 'image_ad' => new ImageAdInfo(['data' => $bytes])]),
        ])]);
    }
}
