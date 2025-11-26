<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V22\Common\KeywordInfo;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V22\Enums\AdGroupCriterionStatusEnum\AdGroupCriterionStatus;
use Google\Ads\GoogleAds\V22\Services\AdGroupCriterionOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAdGroupCriteriaRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class AddKeyword extends BaseGoogleAdsService
{
    /**
     * Add a keyword to an ad group.
     *
     * @param string $customerId
     * @param string $adGroupResourceName
     * @param string $keyword The keyword text
     * @param int $matchType KeywordMatchType enum value (default: EXACT)
     * @return string|null Resource name of the created criterion
     */
    public function __invoke(
        string $customerId, 
        string $adGroupResourceName, 
        string $keyword,
        int $matchType = KeywordMatchType::EXACT
    ): ?string {
        $this->ensureClient();

        $keywordInfo = new KeywordInfo([
            'text' => $keyword,
            'match_type' => $matchType,
        ]);

        $adGroupCriterion = new AdGroupCriterion([
            'ad_group' => $adGroupResourceName,
            'keyword' => $keywordInfo,
            'status' => AdGroupCriterionStatus::ENABLED,
        ]);

        $operation = new AdGroupCriterionOperation();
        $operation->setCreate($adGroupCriterion);

        try {
            $adGroupCriterionServiceClient = $this->client->getAdGroupCriterionServiceClient();
            $response = $adGroupCriterionServiceClient->mutateAdGroupCriteria(
                new MutateAdGroupCriteriaRequest([
                    'customer_id' => $customerId,
                    'operations' => [$operation],
                ])
            );

            $result = $response->getResults()[0];
            return $result->getResourceName();

        } catch (GoogleAdsException $e) {
            $this->logError("Failed to add keyword: " . $e->getMessage());
            return null;
        }
    }
}
