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
        $adObjects = array_map(fn (array $ad) => $this->buildResponsiveSearchAd($ad), $ads);

        return $this->apiCall('AddAds', [
            'AdGroupId' => $adGroupId,
            'Ads' => ['Ad' => $adObjects],
        ]);
    }

    /**
     * Build one ResponsiveSearchAd for AddAds.
     *
     * Two encoding traps, both of which used to silently empty the ad:
     *
     * 1. `Headlines` and `Descriptions` are `ArrayOfAssetLink`, and `AssetLink`
     *    has exactly four members — Asset, PinnedField, AssetPerformanceLabel,
     *    EditorialStatus (see the vendored SDK's AssetLink::$openAPITypes).
     *    There is no `Text`. The text lives on the nested TextAsset, and the
     *    array needs the `AssetLink` element name the same way `FinalUrls`
     *    already needs `string`.
     * 2. `Ad` and `Asset` are abstract in the SOAP schema; the concrete
     *    ResponsiveSearchAd / TextAsset members exist only on the subtypes. A
     *    WSDL-typed SoapClient encodes an associative array against the
     *    *declared* element type and drops every key the base type does not
     *    declare, so a plain array sent Headlines, Descriptions, Path1 and
     *    Path2 nowhere. SoapVar names the concrete type, which puts the
     *    xsi:type on the wire and keeps the subtype's fields.
     *
     * The visible symptom was an ad with no headlines and no descriptions,
     * which AddAds rejects as a PartialError — not a SoapFault — so the caller
     * saw a normal response and recorded the ad as created.
     */
    protected function buildResponsiveSearchAd(array $ad): \SoapVar
    {
        return $this->soapObject('ResponsiveSearchAd', [
            'Type' => 'ResponsiveSearchAd',
            'Headlines' => ['AssetLink' => array_map(
                fn ($h) => ['Asset' => $this->textAsset((string) $h)],
                array_values($ad['headlines'] ?? [])
            )],
            'Descriptions' => ['AssetLink' => array_map(
                fn ($d) => ['Asset' => $this->textAsset((string) $d)],
                array_values($ad['descriptions'] ?? [])
            )],
            'Path1' => $ad['path1'] ?? '',
            'Path2' => $ad['path2'] ?? '',
            'FinalUrls' => ['string' => [$ad['final_url'] ?? '']],
            'Status' => 'Active',
        ]);
    }

    /**
     * A TextAsset carrying one headline or description.
     */
    protected function textAsset(string $text): \SoapVar
    {
        return $this->soapObject('TextAsset', [
            'Type' => 'TextAsset',
            'Text' => $text,
        ]);
    }

    /**
     * Tag a payload with its concrete Campaign Management type so SoapClient
     * encodes the subtype rather than its abstract base.
     */
    protected function soapObject(string $type, array $value): \SoapVar
    {
        return new \SoapVar($value, SOAP_ENC_OBJECT, $type, $this->namespace);
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
                        'Status' => 'Active',
                    ],
                ],
            ],
        ];

        try {
            $response = $this->apiCall('AddKeywords', $request);
            if (isset($response['KeywordIds']['long'][0])) {
                return (string) $response['KeywordIds']['long'][0];
            }

            return null;
        } catch (\Throwable $e) {
            report($e);
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
            'EntityIds' => ['long' => $campaignIds],
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
                                    'Text' => $text,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = $this->apiCall('AddNegativeKeywordsToEntities', $request);

            return 'added';
        } catch (\Throwable $e) {
            report($e);
            Log::error("Microsoft Ads: Failed to add negative keyword '{$text}'", ['error' => $e->getMessage()]);

            return null;
        }
    }
}
