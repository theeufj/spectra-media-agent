<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Models\Campaign;
use App\Services\Agents\AdExtensionAgent;
use App\Services\Agents\AgentIssue;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\GoogleAdStrengthRepair;

/** Fill campaign sitelink coverage with distinct verified destinations. */
class CreateSitelinkAssets extends BaseGoogleAdsService
{
    /** @return int Number of sitelinks Google confirmed created and linked. */
    public function heal(Campaign $campaign, int $target = 4): int
    {
        $guard = app(GoogleAdStrengthRepair::class);
        $strategy = $guard->strategyForCampaign($campaign);
        if (! $strategy || $guard->mutationBlocked($campaign, $strategy)) {
            return 0;
        }
        $customer = $campaign->customer;
        $reader = app(ReadCampaignConfiguration::class, ['customer' => $customer]);
        if ($guard->campaignMutationBlocked($campaign, $strategy, $reader)) {
            return 0;
        }
        $evidence = app(\App\Services\Campaigns\AdvertisingEvidence::class);
        $urls = [];
        foreach ($reader->sitelinks($customer->cleanGoogleCustomerId(), $campaign->googleAdsResourceName()) as $row) {
            foreach ($row['asset']['finalUrls'] ?? [] as $url) {
                $normalized = $evidence->url($url);
                if ($normalized) {
                    $urls[$normalized] = true;
                }
            }
        }
        $needed = max(0, min(6, $target) - count($urls));
        if (! $needed) {
            return 0;
        }
        $result = app(AdExtensionAgent::class)->repairSitelinks($campaign, $needed, $strategy);
        if ($result['errors']) {
            throw new \RuntimeException(AgentIssue::toSentence($result['errors']));
        }

        return count($result['created']);
    }
}
