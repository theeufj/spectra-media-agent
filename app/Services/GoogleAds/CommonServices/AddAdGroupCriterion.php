<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V22\Services\AdGroupCriterionService;
use Google\Ads\GoogleAds\V22\Services\AdGroupCriterionOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAdGroupCriteriaRequest;
use Google\Ads\GoogleAds\V22\Enums\CriterionTypeEnum\CriterionType;
use Google\Ads\GoogleAds\V22\Common\AudienceInfo;
use Google\Ads\GoogleAds\V22\Common\TopicInfo;
use Google\Ads\GoogleAds\V22\Common\PlacementInfo;
use Google\Ads\GoogleAds\V22\Common\GenderInfo;
use Google\Ads\GoogleAds\V22\Common\AgeRangeInfo;
use Google\Ads\GoogleAds\V22\Common\UserInterestInfo;
use Google\Ads\GoogleAds\V22\Common\ParentalStatusInfo;
use Google\Ads\GoogleAds\V22\Common\IncomeRangeInfo;
use Google\Ads\GoogleAds\V22\Common\KeywordInfo;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use App\Models\Customer;

class AddAdGroupCriterion extends BaseGoogleAdsService
{
    public function __construct(Customer $customer, bool $useMccCredentials = false)
    {
        parent::__construct($customer, $useMccCredentials);
    }

    /**
     * Adds various criteria (e.g., audience, topic, placement, demographic) to a given ad group.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param string $adGroupResourceName The resource name of the ad group to add criteria to.
     * @param array $criterionData Criterion details including type and specific fields.
     * @return string|null The resource name of the created ad group criterion, or null on failure.
     */
    public function __invoke(string $customerId, string $adGroupResourceName, array $criterionData): ?string
    {
        $this->ensureClient();
        
        $adGroupCriterion = new AdGroupCriterion([
            'ad_group' => $adGroupResourceName,
            // Common settings, e.g., 'status'
        ]);

        // Set specific criterion based on type
        if (!isset($criterionData['type'])) {
            $this->logError("Criterion type is missing in criterionData.");
            return null;
        }

        switch ($criterionData['type']) {
            case 'AUDIENCE':
                if (!isset($criterionData['audienceId'])) {
                    $this->logError("Audience ID is missing for AUDIENCE criterion type.");
                    return null;
                }
                $adGroupCriterion->setAudience(new AudienceInfo([
                    'audience' => $criterionData['audienceId'],
                ]));
                break;
            case 'TOPIC':
                if (!isset($criterionData['topicId'])) {
                    $this->logError("Topic ID is missing for TOPIC criterion type.");
                    return null;
                }
                $adGroupCriterion->setTopic(new TopicInfo([
                    'topic_constant' => $criterionData['topicId'],
                ]));
                break;
            case 'PLACEMENT':
                if (!isset($criterionData['url'])) {
                    $this->logError("URL is missing for PLACEMENT criterion type.");
                    return null;
                }
                $adGroupCriterion->setPlacement(new PlacementInfo([
                    'url' => $criterionData['url'],
                ]));
                break;
            case 'GENDER':
                if (!isset($criterionData['genderType'])) {
                    $this->logError("Gender type is missing for GENDER criterion type.");
                    return null;
                }
                $adGroupCriterion->setGender(new GenderInfo([
                    'type' => $criterionData['genderType'],
                ]));
                break;
            case 'AGE_RANGE':
                if (!isset($criterionData['ageRangeType'])) {
                    $this->logError("Age range type is missing for AGE_RANGE criterion type.");
                    return null;
                }
                $adGroupCriterion->setAgeRange(new AgeRangeInfo([
                    'type' => $criterionData['ageRangeType'],
                ]));
                break;
            case 'PARENTAL_STATUS':
                if (!isset($criterionData['parentalStatusType'])) {
                    $this->logError("Parental status type is missing for PARENTAL_STATUS criterion type.");
                    return null;
                }
                $adGroupCriterion->setParentalStatus(new ParentalStatusInfo([
                    'type' => $criterionData['parentalStatusType'],
                ]));
                break;
            case 'INCOME_RANGE':
                if (!isset($criterionData['incomeRangeType'])) {
                    $this->logError("Income range type is missing for INCOME_RANGE criterion type.");
                    return null;
                }
                $adGroupCriterion->setIncomeRange(new IncomeRangeInfo([
                    'type' => $criterionData['incomeRangeType'],
                ]));
                break;
            case 'USER_INTEREST':
                if (!isset($criterionData['userInterestId'])) {
                    $this->logError("User Interest ID is missing for USER_INTEREST criterion type.");
                    return null;
                }
                $adGroupCriterion->setUserInterest(new UserInterestInfo([
                    'user_interest_category' => $criterionData['userInterestId'],
                ]));
                break;
            case 'KEYWORD':
                if (!isset($criterionData['text'])) {
                    $this->logError("Text is missing for KEYWORD criterion type.");
                    return null;
                }
                $matchType = $criterionData['matchType'] ?? KeywordMatchType::BROAD;
                // If matchType is a string (e.g. 'BROAD', 'PHRASE', 'EXACT'), convert to enum value if needed
                // But usually the client library expects the integer value or the constant from the Enum class.
                // Let's assume the caller passes the correct value or we map it.
                
                // Simple mapping if string is passed
                if (is_string($matchType)) {
                    $matchTypeUpper = strtoupper($matchType);
                    $matchType = match($matchTypeUpper) {
                        'EXACT' => KeywordMatchType::EXACT,
                        'PHRASE' => KeywordMatchType::PHRASE,
                        'BROAD' => KeywordMatchType::BROAD,
                        default => KeywordMatchType::BROAD
                    };
                }

                $adGroupCriterion->setKeyword(new KeywordInfo([
                    'text' => $criterionData['text'],
                    'match_type' => $matchType
                ]));
                break;
            // Add more criterion types as needed
            default:
                $this->logError("Unsupported criterion type: " . $criterionData['type']);
                return null;
        }

        $adGroupCriterionOperation = new AdGroupCriterionOperation();
        $adGroupCriterionOperation->setCreate($adGroupCriterion);

        try {
            $adGroupCriterionServiceClient = $this->client->getAdGroupCriterionServiceClient();
            $request = new MutateAdGroupCriteriaRequest([
                'customer_id' => $customerId,
                'operations' => [$adGroupCriterionOperation],
            ]);
            $response = $adGroupCriterionServiceClient->mutateAdGroupCriteria($request);
            $newAdGroupCriterionResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully added ad group criterion: " . $newAdGroupCriterionResourceName);
            return $newAdGroupCriterionResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error adding ad group criterion for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }
}
