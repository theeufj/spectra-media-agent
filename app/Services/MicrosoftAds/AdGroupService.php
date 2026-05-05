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

    /**
     * Add a target keyword to an ad group.
     */
    public function addKeyword(string $adGroupId, string $text, string $matchType = 'Broad'): ?string
    {
        Log::info("Microsoft Ads: Adding {$matchType} keyword '{$text}' to {$adGroupId}");
        
        $request = [
            'AdGroupId' => $adGroupId,
            'Keywords' => [
                'Keyword' => [
                    [
                        'MatchType' => $matchType,
                        'Text' => $text,
                        'Status' => 'Active'
                    ]
                ]
            ],
        ];

        try {
            $response = $this->apiCall('AddKeywords', $request);
            if (isset($response['KeywordIds']['long'][0])) {
                return (string) $response['KeywordIds']['long'][0];
            }
            return null;
        } catch (\Exception $e) {
            Log::error("Microsoft Ads: Failed to add keyword '{$text}'", ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function getAdGroupsByCampaignId(string $campaignId): array
    {
        $result = $this->apiCallWithRetry('GetAdGroupsByCampaignId', ['CampaignId' => $campaignId]);
        $groups = $result['AdGroups']['AdGroup'] ?? [];
        return isset($groups['Id']) ? [$groups] : $groups;
    }

    public function getKeywordsByAdGroupId(string $adGroupId): array
    {
        $result = $this->apiCallWithRetry('GetKeywordsByAdGroupId', ['AdGroupId' => $adGroupId]);
        $kws = $result['Keywords']['Keyword'] ?? [];
        return isset($kws['Id']) ? [$kws] : $kws;
    }

    public function getNegativeKeywordsByCampaignIds(array $campaignIds): array
    {
        $result = $this->apiCallWithRetry('GetNegativeKeywordsByEntityIds', [
            'EntityIds'  => ['long' => $campaignIds],
            'EntityType' => 'Campaign',
        ]);
        return $result['EntityNegativeKeywords']['EntityNegativeKeyword'] ?? [];
    }

    /**
     * Add a negative keyword to a campaign or ad group.
     */
    public function addNegativeKeyword(string $entityId, string $text, string $matchType = 'Exact', bool $isCampaign = true): ?string
    {
        $entityType = $isCampaign ? 'Campaign' : 'AdGroup';
        Log::info("Microsoft Ads: Adding negative {$matchType} keyword '{$text}' to {$entityType} {$entityId}");
        
        // This generally works by creating a negative keyword list and associating or directly adding EntityNegativeKeywords
        // For simplicity we will log an alert since native direct add without lists is more complex in Bing SOAP,
        // but we can provide a simulated API call here.
        $request = [
            'EntityNegativeKeywords' => [
                'EntityNegativeKeyword' => [
                    [
                        'EntityId' => $entityId,
                        'EntityType' => $entityType,
                        'NegativeKeywords' => [
                            'NegativeKeyword' => [
                                [
                                    'MatchType' => $matchType,
                                    'Text' => $text
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        try {
            $response = $this->apiCall('AddNegativeKeywordsToEntities', $request);
            return 'added';
        } catch (\Exception $e) {
            Log::error("Microsoft Ads: Failed to add negative keyword '{$text}'", ['error' => $e->getMessage()]);
            return null;
        }
    }
}
