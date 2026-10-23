<?php

namespace App\Services\GoogleAds\VideoServices;

use App\Services\GoogleAds\BaseGoogleAdsService;

class CreateVideoCampaign extends BaseGoogleAdsService
{
    /**
     * Google Ads API v26 can report on legacy Video campaigns but cannot
     * create them. Demand Gen is the supported API path for new video ads.
     * Reject before provisioning a budget for an unsupported campaign.
     */
    public function __invoke(string $customerId, array $campaignData): ?string
    {
        $this->logError('Google Ads API cannot create legacy Video campaigns. Use a Demand Gen campaign for new video ads.');

        return null;
    }
}
