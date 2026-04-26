<?php

namespace App\Services\GoogleAds\DSAServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\WebpageConditionInfo;
use Google\Ads\GoogleAds\V22\Common\WebpageInfo;
use Google\Ads\GoogleAds\V22\Enums\WebpageConditionOperandEnum\WebpageConditionOperand;
use Google\Ads\GoogleAds\V22\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V22\Services\AdGroupCriterionOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAdGroupCriteriaRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;

class AddDSATarget extends BaseGoogleAdsService
{
    /**
     * Add a webpage target (DSA target) to a DSA ad group.
     *
     * Supports two targeting modes:
     *   - URL_EQUALS: targets a specific page URL
     *   - PAGE_TITLE: targets pages whose title contains a keyword
     *
     * @param string      $customerId
     * @param string      $adGroupResourceName
     * @param string      $criterionName  Human-readable name for the criterion
     * @param string      $mode           'url' or 'title'
     * @param string      $value          The URL or title substring to match
     * @param float       $cpcBidMicros   Optional CPC override for this target
     * @return string|null
     */
    public function __invoke(
        string $customerId,
        string $adGroupResourceName,
        string $criterionName,
        string $mode,
        string $value,
        float  $cpcBidMicros = 0
    ): ?string {
        $this->ensureClient();

        $operand = match (strtolower($mode)) {
            'title' => WebpageConditionOperand::PAGE_TITLE,
            default => WebpageConditionOperand::URL,
        };

        $condition = new WebpageConditionInfo([
            'operand'  => $operand,
            'argument' => $value,
        ]);

        $webpageInfo = new WebpageInfo([
            'criterion_name' => $criterionName,
            'conditions'     => [$condition],
        ]);

        $params = [
            'ad_group' => $adGroupResourceName,
            'webpage'  => $webpageInfo,
        ];

        if ($cpcBidMicros > 0) {
            $params['cpc_bid_micros'] = (int) $cpcBidMicros;
        }

        $criterion = new AdGroupCriterion($params);
        $operation = new AdGroupCriterionOperation();
        $operation->setCreate($criterion);

        try {
            $response = $this->client->getAdGroupCriterionServiceClient()->mutateAdGroupCriteria(
                new MutateAdGroupCriteriaRequest(['customer_id' => $customerId, 'operations' => [$operation]])
            );
            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Added DSA target ({$mode}={$value}): {$resourceName}");
            return $resourceName;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError('AddDSATarget failed: ' . $e->getMessage());
            return null;
        }
    }
}
