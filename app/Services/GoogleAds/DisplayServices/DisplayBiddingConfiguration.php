<?php

namespace App\Services\GoogleAds\DisplayServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;

/** Display has no faithful keyword-forecast model for choosing Manual CPC. */
class DisplayBiddingConfiguration extends BaseGoogleAdsService
{
    public static function desired(array $bidding): ?array
    {
        $name = $bidding['name'] ?? null;
        if (! is_string($name)) {
            return null;
        }
        $name = strtoupper(preg_replace('/(?<=[a-z])(?=[A-Z])/', '_', $name) ?? '');
        if ($name === 'MAXIMIZE_CONVERSIONS') {
            return ['type' => 'MAXIMIZE_CONVERSIONS'];
        }
        $target = $bidding['parameters']['targetCpaMicros'] ?? null;
        if ($name === 'TARGET_CPA' && is_int($target) && $target > 0) {
            return ['type' => 'TARGET_CPA', 'target_cpa_micros' => $target];
        }

        return null;
    }

    public function read(string $customerId, string $resource): array
    {
        if (! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/\d+$#', $resource)) {
            throw new \InvalidArgumentException('Display campaign resource belongs to another account.');
        }
        $this->ensureClient();
        $query = "SELECT campaign.resource_name, campaign.status, campaign.advertising_channel_type, campaign.bidding_strategy_type,
            campaign.target_cpa.target_cpa_micros, campaign.bidding_strategy, campaign_budget.amount_micros,
            campaign_budget.explicitly_shared FROM campaign WHERE campaign.resource_name = '{$resource}'";
        foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
            $campaign = $row->getCampaign();

            return ['campaign_resource' => $campaign->getResourceName(), 'status' => CampaignStatus::name($campaign->getStatus()),
                'channel' => AdvertisingChannelType::name($campaign->getAdvertisingChannelType()),
                'bidding_strategy' => BiddingStrategyType::name($campaign->getBiddingStrategyType()),
                'portfolio_bidding' => $campaign->getBiddingStrategy(),
                'target_cpa_micros' => $campaign->getTargetCpa()?->getTargetCpaMicros(),
                'daily_budget_micros' => $row->getCampaignBudget()->getAmountMicros(),
                'shared_budget' => $row->getCampaignBudget()->getExplicitlyShared()];
        }

        return [];
    }

    public static function isPausedAutomated(array $actual): bool
    {
        $type = $actual['bidding_strategy'] ?? null;

        return ($actual['status'] ?? null) === 'PAUSED' && ($actual['channel'] ?? null) === 'DISPLAY'
            && in_array($type, ['MAXIMIZE_CONVERSIONS', 'TARGET_CPA'], true)
            && empty($actual['portfolio_bidding'])
            && ($type !== 'TARGET_CPA' || (is_int($actual['target_cpa_micros'] ?? null) && $actual['target_cpa_micros'] > 0));
    }
}
