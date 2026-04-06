<?php

namespace App\Services\MicrosoftAds;

use Illuminate\Support\Facades\Log;

class AdGroupService extends BaseMicrosoftAdsService
{
    public function createAdGroup(string $campaignId, array $params): ?array
    {
        $adGroup = [
            'Name' => $params['name'],
            'CpcBid' => ['Amount' => $params['cpc_bid'] ?? 1.50],
            'Status' => $params['status'] ?? 'Paused',
            'Language' => $this->config['defaults']['language'] ?? 'English',
        ];

        $result = $this->apiCall('AddAdGroups', [
            'CampaignId' => $campaignId,
            'AdGroups' => ['AdGroup' => [$adGroup]],
        ]);

        if ($result && isset($result['AdGroupIds'])) {
            Log::info('Microsoft Ads: Created ad group', ['id' => $result['AdGroupIds']]);
            return $result;
        }

        return null;
    }

    public function addKeywords(string $adGroupId, array $keywords): ?array
    {
        $kwObjects = [];
        foreach ($keywords as $kw) {
            $kwObjects[] = [
                'Text' => $kw['text'],
                'MatchType' => $kw['match_type'] ?? 'Broad', // Exact, Phrase, Broad
                'Bid' => ['Amount' => $kw['bid'] ?? 1.00],
                'Status' => 'Active',
            ];
        }

        return $this->apiCall('AddKeywords', [
            'AdGroupId' => $adGroupId,
            'Keywords' => ['Keyword' => $kwObjects],
        ]);
    }

    public function addExpandedTextAds(string $adGroupId, array $ads): ?array
    {
        $adObjects = [];
        foreach ($ads as $ad) {
            $adObjects[] = [
                'Type' => 'ResponsiveSearchAd',
                'Headlines' => array_map(fn ($h) => ['Text' => $h], $ad['headlines'] ?? []),
                'Descriptions' => array_map(fn ($d) => ['Text' => $d], $ad['descriptions'] ?? []),
                'Path1' => $ad['path1'] ?? '',
                'Path2' => $ad['path2'] ?? '',
                'FinalUrls' => ['string' => [$ad['final_url'] ?? '']],
                'Status' => 'Active',
            ];
        }

        return $this->apiCall('AddAds', [
            'AdGroupId' => $adGroupId,
            'Ads' => ['Ad' => $adObjects],
        ]);
    }
}
