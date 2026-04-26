<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\MaximizeConversions;
use Google\Ads\GoogleAds\V22\Common\MaximizeConversionValue;
use Google\Ads\GoogleAds\V22\Common\TargetCpa;
use Google\Ads\GoogleAds\V22\Common\TargetRoas;
use Google\Ads\GoogleAds\V22\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Services\CampaignOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignsRequest;
use Google\Protobuf\FieldMask;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;

class UpdateCampaignBiddingStrategy extends BaseGoogleAdsService
{
    /**
     * Upgrade a campaign's bidding strategy.
     *
     * @param string $customerId
     * @param string $campaignResourceName
     * @param string $strategy  One of: ENHANCED_CPC | TARGET_CPA | TARGET_ROAS | MAXIMIZE_CONVERSIONS | MAXIMIZE_CONVERSION_VALUE
     * @param float|null $targetCpa   Target CPA in account currency (required for TARGET_CPA)
     * @param float|null $targetRoas  Target ROAS as a ratio, e.g. 3.5 = 350% (required for TARGET_ROAS)
     * @return bool
     */
    public function __invoke(
        string $customerId,
        string $campaignResourceName,
        string $strategy,
        ?float $targetCpa  = null,
        ?float $targetRoas = null
    ): bool {
        $this->ensureClient();

        $campaign = new Campaign(['resource_name' => $campaignResourceName]);

        $updateMask = new FieldMask();

        switch (strtoupper($strategy)) {
            case 'ENHANCED_CPC':
                $campaign->setManualCpc(new \Google\Ads\GoogleAds\V22\Common\ManualCpc(['enhanced_cpc_enabled' => true]));
                $updateMask->setPaths(['manual_cpc.enhanced_cpc_enabled']);
                break;

            case 'TARGET_CPA':
                $cpaMicros = (int) round(($targetCpa ?? 50) * 1_000_000);
                $campaign->setTargetCpa(new TargetCpa(['target_cpa_micros' => $cpaMicros]));
                $updateMask->setPaths(['target_cpa.target_cpa_micros']);
                break;

            case 'TARGET_ROAS':
                $campaign->setTargetRoas(new TargetRoas(['target_roas' => $targetRoas ?? 2.0]));
                $updateMask->setPaths(['target_roas.target_roas']);
                break;

            case 'MAXIMIZE_CONVERSIONS':
                $campaign->setMaximizeConversions(new MaximizeConversions());
                $updateMask->setPaths(['maximize_conversions']);
                break;

            case 'MAXIMIZE_CONVERSION_VALUE':
                $campaign->setMaximizeConversionValue(new MaximizeConversionValue());
                $updateMask->setPaths(['maximize_conversion_value']);
                break;

            default:
                $this->logError("UpdateCampaignBiddingStrategy: Unknown strategy '{$strategy}'");
                return false;
        }

        $operation = new CampaignOperation();
        $operation->setUpdate($campaign);
        $operation->setUpdateMask($updateMask);

        try {
            $this->client->getCampaignServiceClient()->mutateCampaigns(
                new MutateCampaignsRequest([
                    'customer_id' => $customerId,
                    'operations'  => [$operation],
                ])
            );

            $this->logInfo("Updated bidding strategy to {$strategy} for campaign {$campaignResourceName}");
            return true;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Failed to update bidding strategy: " . $e->getMessage());
            return false;
        }
    }
}
