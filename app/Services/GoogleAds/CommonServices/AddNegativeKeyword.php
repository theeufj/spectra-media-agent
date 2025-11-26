<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\CampaignCriterion;
use Google\Ads\GoogleAds\V22\Common\KeywordInfo;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V22\Services\CampaignCriterionOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignCriteriaRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class AddNegativeKeyword extends BaseGoogleAdsService
{
    /**
     * Add a negative keyword to a campaign.
     *
     * @param string $customerId
     * @param string $campaignResourceName
     * @param string $keyword The keyword text
     * @param int $matchType KeywordMatchType enum value (default: EXACT)
     * @return string|null Resource name of the created criterion
     */
    public function __invoke(
        string $customerId, 
        string $campaignResourceName, 
        string $keyword,
        int $matchType = KeywordMatchType::EXACT
    ): ?string {
        $this->ensureClient();

        $keywordInfo = new KeywordInfo([
            'text' => $keyword,
            'match_type' => $matchType,
        ]);

        $campaignCriterion = new CampaignCriterion([
            'campaign' => $campaignResourceName,
            'keyword' => $keywordInfo,
            'negative' => true,
        ]);

        $operation = new CampaignCriterionOperation();
        $operation->setCreate($campaignCriterion);

        try {
            $campaignCriterionServiceClient = $this->client->getCampaignCriterionServiceClient();
            $response = $campaignCriterionServiceClient->mutateCampaignCriteria(
                new MutateCampaignCriteriaRequest([
                    'customer_id' => $customerId,
                    'operations' => [$operation],
                ])
            );

            $result = $response->getResults()[0];
            return $result->getResourceName();

        } catch (GoogleAdsException $e) {
            $this->logError("Failed to add negative keyword: " . $e->getMessage());
            return null;
        }
    }
}
