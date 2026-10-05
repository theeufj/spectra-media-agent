<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Contracts\Ads\AdStatusSource;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Support\GoogleAdPolicy;

class GetAdStatus extends BaseGoogleAdsService implements AdStatusSource
{
    /**
     * Get the status and policy details of ads in a campaign or ad group.
     *
     * @param  string|null  $campaignResourceName  Filter by campaign (optional)
     * @param  string|null  $adGroupResourceName  Filter by ad group (optional)
     * @return array List of ads with their status and policy info
     */
    public function __invoke(string $customerId, ?string $campaignResourceName = null, ?string $adGroupResourceName = null): array
    {
        return $this->readAds($customerId, $campaignResourceName, $adGroupResourceName);
    }

    /** Check the exact replacement ad, never interpret campaign existence as approval. */
    public function forAd(string $customerId, string $adResourceName): ?array
    {
        $customerId = str_replace('-', '', trim($customerId));
        if (! preg_match('#^customers/(\d+)/adGroupAds/\d+~\d+$#', $adResourceName, $matches)
            || $matches[1] !== $customerId) {
            throw new \InvalidArgumentException('Invalid Google ad resource for the target customer.');
        }

        return $this->readAds($customerId, null, null, $adResourceName)[0] ?? null;
    }

    private function readAds(string $customerId, ?string $campaignResourceName, ?string $adGroupResourceName, ?string $adResourceName = null): array
    {
        $customerId = str_replace('-', '', trim($customerId));

        $whereClause = '';
        if ($adResourceName) {
            $whereClause = "WHERE ad_group_ad.resource_name = '$adResourceName'";
        } elseif ($campaignResourceName) {
            $whereClause = "WHERE campaign.resource_name = '$campaignResourceName'";
        } elseif ($adGroupResourceName) {
            $whereClause = "WHERE ad_group.resource_name = '$adGroupResourceName'";
        }

        $query = 'SELECT '.
                 'ad_group_ad.resource_name, '.
                 'ad_group_ad.status, '.
                 'ad_group_ad.policy_summary.approval_status, '.
                 'ad_group_ad.policy_summary.policy_topic_entries, '.
                 'ad_group_ad.policy_summary.review_status, '.
                 'ad_group_ad.ad.final_urls, '.
                 'ad_group_ad.ad.responsive_search_ad.headlines, '.
                 'ad_group_ad.ad.responsive_search_ad.descriptions, '.
                 'ad_group.resource_name, '.
                 'ad_group.status '.
                 'FROM ad_group_ad '.
                 $whereClause;

        try {
            $this->ensureClient();
            $response = $this->searchQuery($customerId, $query);

            $ads = [];
            foreach ($response->getIterator() as $googleAdsRow) {
                $adGroupAd = $googleAdsRow->getAdGroupAd();
                $policySummary = $adGroupAd->getPolicySummary();

                $policyTopics = [];
                foreach ($policySummary?->getPolicyTopicEntries() ?? [] as $entry) {
                    $policyTopics[] = GoogleAdPolicy::topic($entry);
                }

                $headlines = [];
                $descriptions = [];

                $ad = $adGroupAd->getAd();
                if ($ad?->hasResponsiveSearchAd()) {
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
                    'ad_group_resource_name' => $googleAdsRow->getAdGroup()?->getResourceName(),
                    'ad_group_status' => $googleAdsRow->getAdGroup()?->getStatus(),
                    'status' => $adGroupAd->getStatus(),
                    'approval_status' => $policySummary?->getApprovalStatus(),
                    'review_status' => $policySummary?->getReviewStatus(),
                    'final_urls' => $ad ? iterator_to_array($ad->getFinalUrls()) : [],
                    'policy_topics' => $policyTopics,
                    'headlines' => $headlines,
                    'descriptions' => $descriptions,
                ];
            }

            return $ads;

        } catch (\Throwable $e) {
            $this->logError('Failed to get ad status: '.$e->getMessage(), $e);
            throw $e;
        }
    }
}
