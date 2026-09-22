<?php

namespace App\Services\Competition;

use App\Models\Campaign;
use App\Services\Agents\CampaignOptimizationAgent;
use App\Services\GoogleAds\CommonServices\AddKeyword;
use App\Services\GoogleAds\CommonServices\GetCompetitiveCampaignState;
use App\Services\GoogleAds\SearchServices\CreateResponsiveSearchAd;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;

/** Google changes use the existing MCC services and never create another budget. */
class CompetitivePlatformGateway
{
    public function state(Campaign $campaign): array
    {
        if (! $campaign->google_ads_campaign_id || ! $campaign->customer?->google_ads_customer_id) {
            throw new \RuntimeException('Automatic execution of competitor actions currently requires a Google campaign.');
        }

        return (new GetCompetitiveCampaignState($campaign->customer))(
            $campaign->customer->cleanGoogleCustomerId(), $campaign->googleAdsResourceName()
        );
    }

    public function apply(Campaign $campaign, array $payload, array $state, bool $approvedByUser = false): array
    {
        $customer = $campaign->customer;
        $type = $payload['type'];
        $resources = [];
        if ($type === 'COMPETITOR_KEYWORD_TEST') {
            foreach ($payload['keywords'] as $text) {
                $existing = collect($state['keywords'])->first(fn ($k) => mb_strtolower($k['text']) === mb_strtolower($text)
                    && $k['ad_group'] === $payload['ad_group'] && $k['match_type'] === KeywordMatchType::EXACT);
                if ($existing) {
                    if (! $existing['enabled']) {
                        return ['applied' => false, 'message' => 'A matching keyword is paused; review it before adding a duplicate.', 'resources' => $resources];
                    }
                    $resources[] = $existing['resource'];

                    continue;
                }
                $resource = (new AddKeyword($customer))($customer->cleanGoogleCustomerId(), $payload['ad_group'], $text);
                if (! $resource) {
                    return ['applied' => false, 'message' => 'Google did not confirm every keyword. Check the recorded resources before retrying.', 'resources' => $resources];
                }
                $resources[] = $resource;
            }
        } elseif ($type === 'COMPETITOR_AD_TEST') {
            $existing = collect($state['ads'])->first(fn ($ad) => $ad['ad_group'] === $payload['ad_group']
                && $ad['headlines'] === $payload['headlines'] && $ad['descriptions'] === $payload['descriptions']
                && $ad['final_urls'] === $payload['final_urls']);
            if ($existing) {
                return ['applied' => $existing['enabled'], 'resources' => [$existing['resource']], 'message' => 'Matching ad already exists.'];
            }
            if (collect($state['ads'])->where('ad_group', $payload['ad_group'])->count() >= 3) {
                return ['applied' => false, 'message' => 'This ad group already has three responsive search ads. Review them before adding a variation.'];
            }
            $resource = (new CreateResponsiveSearchAd($customer))($customer->cleanGoogleCustomerId(), $payload['ad_group'], [
                'headlines' => $payload['headlines'], 'descriptions' => $payload['descriptions'], 'finalUrls' => $payload['final_urls'],
            ]);
            if (! $resource) {
                return ['applied' => false, 'message' => 'Google did not confirm creation of the ad variation.'];
            }
            $resources[] = $resource;
        } else {
            $result = app(CampaignOptimizationAgent::class)->applyRecommendation($campaign, $payload, $approvedByUser);
            if (! ($result['applied'] ?? false) && $type === 'BUDGET'
                && (float) $campaign->fresh()->daily_budget === (float) $payload['suggested_value']) {
                // Budget reconciliation can finish an accepted desired-state write
                // after a platform outage. Keep watching instead of filing a failure.
                $result['pending_verification'] = true;
            }

            return $result;
        }

        return ['applied' => true, 'resources' => $resources, 'message' => 'Created within the existing campaign budget.'];
    }

    public function verified(array $payload, array $state): bool
    {
        return match ($payload['type']) {
            'BUDGET' => (int) ($state['budget_micros'] ?? -1) === (int) round($payload['suggested_value'] * 1000000),
            'NETWORK_SETTINGS' => ! ($state['network_settings']['target_search_network'] ?? true)
                && ! ($state['network_settings']['target_content_network'] ?? true),
            'BIDDING' => collect($state['keywords'])->contains(fn ($k) => $k['resource'] === $payload['keyword_resource']
                && (int) $k['cpc_bid_micros'] === (int) $payload['suggested_value']),
            'COMPETITOR_KEYWORD_TEST' => collect($payload['keywords'])->every(fn ($text) => collect($state['keywords'])->contains(
                fn ($k) => $k['enabled'] && $k['ad_group'] === $payload['ad_group'] && $k['match_type'] === KeywordMatchType::EXACT
                    && mb_strtolower($k['text']) === mb_strtolower($text))),
            'COMPETITOR_AD_TEST' => collect($state['ads'])->contains(fn ($ad) => $ad['enabled'] && $ad['ad_group'] === $payload['ad_group']
                && $ad['headlines'] === $payload['headlines'] && $ad['descriptions'] === $payload['descriptions']
                && $ad['final_urls'] === $payload['final_urls']),
            default => false,
        };
    }
}
