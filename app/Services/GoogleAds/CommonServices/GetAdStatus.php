<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class GetAdStatus extends BaseGoogleAdsService
{
    /**
     * Get the status and policy details of ads in a campaign or ad group.
     *
     * @param string $customerId
     * @param string|null $campaignResourceName Filter by campaign (optional)
     * @param string|null $adGroupResourceName Filter by ad group (optional)
     * @return array List of ads with their status and policy info
     */
    public function __invoke(string $customerId, ?string $campaignResourceName = null, ?string $adGroupResourceName = null): array
    {
        $this->ensureClient();

        $whereClause = "";
        if ($campaignResourceName) {
            $whereClause = "WHERE campaign.resource_name = '$campaignResourceName'";
        } elseif ($adGroupResourceName) {
            $whereClause = "WHERE ad_group.resource_name = '$adGroupResourceName'";
        }

        $query = "SELECT " .
                 "ad_group_ad.resource_name, " .
                 "ad_group_ad.status, " .
                 "ad_group_ad.policy_summary.approval_status, " .
                 "ad_group_ad.policy_summary.policy_topic_entries, " .
                 "ad_group_ad.policy_summary.review_status, " .
                 "ad_group_ad.ad.responsive_search_ad.headlines, " .
                 "ad_group_ad.ad.responsive_search_ad.descriptions, " .
                 "ad_group.resource_name " .
                 "FROM ad_group_ad " .
                 $whereClause;

        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $stream = $googleAdsServiceClient->search($customerId, $query);

            $ads = [];
            foreach ($stream->iterateAllElements() as $googleAdsRow) {
                $adGroupAd = $googleAdsRow->getAdGroupAd();
                $policySummary = $adGroupAd->getPolicySummary();
                
                $policyTopics = [];
                foreach ($policySummary->getPolicyTopicEntries() as $entry) {
                    $policyTopics[] = [
                        'topic' => $entry->getTopic(),
                        'type' => $entry->getType(),
                    ];
                }

                $headlines = [];
                $descriptions = [];
                
                $ad = $adGroupAd->getAd();
                if ($ad->hasResponsiveSearchAd()) {
                    $rsa = $ad->getResponsiveSearchAd();
                    foreach ($rsa->getHeadlines() as $headline) {
                        $headlines[] = $headline->getText();
                    }
                    foreach ($rsa->getDescriptions() as $description) {
                        $descriptions[] = $description->getText();
                    }
                }

                $ads[] = [
                    'resource_name' => $adGroupAd->getResourceName(),
                    'ad_group_resource_name' => $googleAdsRow->getAdGroup()->getResourceName(),
                    'status' => $adGroupAd->getStatus(),
                    'approval_status' => $policySummary->getApprovalStatus(),
                    'review_status' => $policySummary->getReviewStatus(),
                    'policy_topics' => $policyTopics,
                    'headlines' => $headlines,
                    'descriptions' => $descriptions,
                ];
            }

            return $ads;

        } catch (GoogleAdsException $e) {
            $this->logError("Failed to get ad status: " . $e->getMessage());
            return [];
        }
    }
}
