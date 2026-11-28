<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Campaigns\SingleGoogleSearchLandingPage;

class VerifySingleSearchLandingPage
{
    public function __construct(private Customer $customer) {}

    public static function reviewedUrl(Strategy $strategy): ?string
    {
        if (! SingleGoogleSearchLandingPage::enabled($strategy)) {
            return null;
        }
        try {
            $url = SingleGoogleSearchLandingPage::url($strategy->campaign, $strategy);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return ($strategy->execution_result['metadata']['google_search_baseline']['single_landing_page_url'] ?? null) === $url ? $url : null;
    }

    public function forEnable(Campaign $campaign, string $account, string $resource): array
    {
        $strategies = $campaign->strategies()->where(function ($query) {
            $query->where('bidding_strategy->single_landing_page', true)
                ->orWhereNotNull('execution_result->metadata->google_search_baseline->single_landing_page_url');
        })->get();
        foreach ($strategies as $strategy) {
            if (! SingleGoogleSearchLandingPage::required($strategy)) {
                continue;
            }
            $strategy->setRelation('campaign', $campaign);
            $id = $strategy->reusableGoogleCampaignId();
            if ($id !== $resource && (! is_string($id) || ! ctype_digit($id) || "customers/{$account}/campaigns/{$id}" !== $resource)) {
                if ($id === null && $campaign->googleAdsResourceName() === $resource) {
                    return ['The single-destination strategy has no verified Google campaign resource.'];
                }

                continue;
            }
            $url = self::reviewedUrl($strategy);
            if ($url === null) {
                return ['The reviewed Search landing page has changed or is missing.'];
            }
            $issues = $this->inspect($account, $resource, $url, true);
            if ($issues !== []) {
                return $issues;
            }
            $fresh = $strategy->fresh(['campaign.customer']);
            if (! $fresh || $fresh->google_ads_campaign_id !== $strategy->google_ads_campaign_id
                || $fresh->campaign->customer?->cleanGoogleCustomerId() !== $account
                || self::reviewedUrl($fresh) !== $url) {
                return ['The reviewed Search landing page changed during Google verification.'];
            }
        }

        return [];
    }

    /** Check what can serve now. Account-level automation for future assets is managed separately in Google Ads. */
    public function inspect(string $account, string $resource, string $url, bool $requirePaused = false): array
    {
        $state = app(ReadCampaignConfiguration::class, ['customer' => $this->customer])->singleLandingPageState($account, $resource);
        $settings = $state['settings'][0] ?? [];
        $campaign = $settings['campaign'] ?? [];
        if (count($state['settings']) !== 1 || ($campaign['resourceName'] ?? null) !== $resource
            || ($campaign['advertisingChannelType'] ?? null) !== 'SEARCH'
            || ! in_array($campaign['status'] ?? null, $requirePaused ? ['PAUSED'] : ['PAUSED', 'ENABLED'], true)) {
            return ['Google did not confirm the selected Search campaign and its serving status.'];
        }
        $issues = [];
        $expansion = array_values(array_filter($campaign['assetAutomationSettings'] ?? [],
            fn ($setting) => ($setting['assetAutomationType'] ?? null) === 'FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION'));
        $excluded = $campaign['excludedParentAssetFieldTypes'] ?? [];
        if (count($expansion) !== 1 || ($expansion[0]['assetAutomationStatus'] ?? null) !== 'OPTED_OUT') {
            $issues[] = 'Google has not confirmed that final URL expansion is disabled.';
        }
        if (! in_array('SITELINK', $excluded, true)) {
            $issues[] = 'Google has not confirmed that this campaign excludes inherited sitelinks.';
        }
        if ($this->hasUrlOverride($campaign) || $this->hasUrlOverride($settings['customer'] ?? [])) {
            $issues[] = 'The account or campaign has an unreviewed tracking URL override.';
        }
        if ($state['ads'] === [] || ! array_all($state['ads'], function ($row) use ($url) {
            $ad = $row['adGroupAd']['ad'] ?? [];

            return ($row['adGroup']['type'] ?? null) === 'SEARCH_STANDARD' && ($ad['type'] ?? null) === 'RESPONSIVE_SEARCH_AD'
                && ($ad['finalUrls'] ?? null) === [$url] && array_diff($ad['finalMobileUrls'] ?? [], [$url]) === []
                && empty($ad['finalAppUrls']) && ! $this->hasUrlOverride($ad) && ! $this->hasUrlOverride($row['adGroup']);
        })) {
            $issues[] = 'Every enabled Search ad must point to the exact reviewed landing page on desktop and mobile.';
        }
        foreach ($state['keywords'] as $row) {
            $criterion = $row['adGroupCriterion'] ?? [];
            if (array_diff([...($criterion['finalUrls'] ?? []), ...($criterion['finalMobileUrls'] ?? [])], [$url]) !== []
                || $this->hasUrlOverride($criterion)) {
                $issues[] = 'An enabled Search keyword overrides the reviewed landing page.';
                break;
            }
        }
        foreach (['customer_assets' => 'customerAsset', 'campaign_assets' => 'campaignAsset', 'group_assets' => 'adGroupAsset'] as $scope => $link) {
            foreach ($state[$scope] as $row) {
                $field = $row[$link]['fieldType'] ?? null;
                if ($scope === 'customer_assets' && in_array($field, $excluded, true)) {
                    continue;
                }
                $asset = $row['asset'] ?? [];
                if (! in_array($field, ['CALLOUT', 'STRUCTURED_SNIPPET', 'BUSINESS_NAME', 'BUSINESS_LOGO', 'AD_IMAGE'], true)
                    || ! empty($asset['finalUrls']) || ! empty($asset['finalMobileUrls']) || $this->hasUrlOverride($asset)) {
                    $issues[] = 'An enabled '.($scope === 'group_assets' ? 'ad group' : ($scope === 'customer_assets' ? 'account' : 'campaign')).' asset can direct clicks away from the Search ad.';
                    break;
                }
            }
        }
        if ($state['campaign_locations'] !== [] || $state['group_locations'] !== []
            || ($state['customer_locations'] !== [] && ! in_array('LOCATION_SYNC', $campaign['excludedParentAssetSetTypes'] ?? [], true))) {
            $issues[] = 'An enabled location asset set can direct clicks to a separate destination.';
        }

        return $issues;
    }

    private function hasUrlOverride(array $object): bool
    {
        return ! in_array($object['trackingUrlTemplate'] ?? '', ['', '{lpurl}'], true) || ($object['finalUrlSuffix'] ?? '') !== '';
    }
}
