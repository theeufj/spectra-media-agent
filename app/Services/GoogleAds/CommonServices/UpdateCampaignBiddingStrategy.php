<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V26\Common\MaximizeConversions;
use Google\Ads\GoogleAds\V26\Common\MaximizeConversionValue;
use Google\Ads\GoogleAds\V26\Common\TargetSpend;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Resources\Campaign;
use Google\Ads\GoogleAds\V26\Services\CampaignOperation;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignsRequest;
use Google\Protobuf\FieldMask;

class UpdateCampaignBiddingStrategy extends BaseGoogleAdsService
{
    /** Target CPA is expressed in account currency; target ROAS is a ratio. */
    public function __invoke(
        string $customerId,
        string $campaignResourceName,
        string $strategy,
        ?float $targetCpa = null,
        ?float $targetRoas = null,
        ?int $cpcBidCeilingMicros = null
    ): bool {
        $strategy = strtoupper($strategy);
        if (! $this->validResource($customerId, $campaignResourceName)
            || ! in_array($strategy, ['TARGET_CPA', 'TARGET_ROAS', 'MAXIMIZE_CLICKS', 'MAXIMIZE_CONVERSIONS', 'MAXIMIZE_CONVERSION_VALUE'], true)
            || $strategy === 'TARGET_CPA' && ($targetCpa === null || ! is_finite($targetCpa) || $targetCpa <= 0 || (int) round($targetCpa * 1_000_000) <= 0)
            || $strategy === 'TARGET_ROAS' && ($targetRoas === null || ! is_finite($targetRoas) || $targetRoas <= 0)
            || $cpcBidCeilingMicros !== null && $cpcBidCeilingMicros <= 0) {
            $this->logError('Bidding mutation refused: unsupported strategy, invalid account resource, or missing target.');

            return false;
        }
        $local = CampaignSpendGuardrails::forGoogleResource($customerId, $campaignResourceName);
        if ($local && ! CampaignSpendGuardrails::permitsBiddingStrategy($local, $strategy, $cpcBidCeilingMicros)) {
            $this->logError('Bidding mutation refused: the bounded trial requires Maximize Clicks within its approved CPC bid ceiling.');

            return false;
        }
        $campaign = new Campaign(['resource_name' => $campaignResourceName]);
        // Search uses the supported maximize strategies, with optional CPA/ROAS targets.
        // Enhanced CPC was retired; never emit enhanced_cpc_enabled again.
        if (in_array($strategy, ['TARGET_CPA', 'MAXIMIZE_CONVERSIONS'], true)) {
            $campaign->setMaximizeConversions(new MaximizeConversions(['target_cpa_micros' => $strategy === 'TARGET_CPA' ? (int) round($targetCpa * 1_000_000) : 0]));
            $paths = ['maximize_conversions.target_cpa_micros'];
        } elseif (in_array($strategy, ['TARGET_ROAS', 'MAXIMIZE_CONVERSION_VALUE'], true)) {
            $campaign->setMaximizeConversionValue(new MaximizeConversionValue(['target_roas' => $strategy === 'TARGET_ROAS' ? $targetRoas : 0]));
            $paths = ['maximize_conversion_value.target_roas'];
        } else {
            $campaign->setTargetSpend(new TargetSpend(['cpc_bid_ceiling_micros' => $cpcBidCeilingMicros ?? 0]));
            $paths = ['target_spend.cpc_bid_ceiling_micros'];
        }
        $operation = new CampaignOperation;
        $operation->setUpdate($campaign);
        $operation->setUpdateMask(new FieldMask(['paths' => $paths]));

        try {
            $this->ensureClient();
            if (! $this->mutate($customerId, $operation)) {
                return false;
            }
            if ($this->dryRun) {
                return true;
            }
            $after = $this->readBiddingState($customerId, $campaignResourceName);
            if (! $after || ($after['resource'] ?? null) !== $campaignResourceName || $after['strategy'] !== $strategy || $after['portfolio'] !== ''
                || $strategy === 'TARGET_CPA' && $after['target_cpa_micros'] !== (int) round($targetCpa * 1_000_000)
                || $strategy === 'TARGET_ROAS' && abs($after['target_roas'] - $targetRoas) > 0.000001
                || $strategy === 'MAXIMIZE_CLICKS' && $after['cpc_bid_ceiling_micros'] !== ($cpcBidCeilingMicros ?? 0)) {
                $this->logError('Google did not confirm the requested bidding strategy and targets.');

                return false;
            }
            $this->logInfo("Verified bidding strategy {$strategy} for campaign {$campaignResourceName}");

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to update or verify bidding strategy: '.$e->getMessage(), $e);

            return false;
        }
    }

    /** A narrow live read for progression and confirmation; portfolio targets are never guessed. */
    public function readBiddingState(string $customerId, string $resource): ?array
    {
        if (! $this->validResource($customerId, $resource)) {
            return null;
        }
        $this->ensureClient();
        $query = 'SELECT campaign.resource_name, campaign.status, campaign.advertising_channel_type, '
            .'campaign.bidding_strategy_type, campaign.bidding_strategy, campaign.target_spend.cpc_bid_ceiling_micros, '
            .'campaign.maximize_conversions.target_cpa_micros, campaign.maximize_conversion_value.target_roas, '
            .'campaign.target_cpa.target_cpa_micros, campaign.target_roas.target_roas, customer.currency_code '
            ."FROM campaign WHERE campaign.resource_name = '{$resource}'";
        foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
            $campaign = $row->getCampaign();
            if ($campaign->getResourceName() !== $resource) {
                continue;
            }
            $type = BiddingStrategyType::name($campaign->getBiddingStrategyType());
            $cpa = (int) ($campaign->getMaximizeConversions()?->getTargetCpaMicros() ?? $campaign->getTargetCpa()?->getTargetCpaMicros() ?? 0);
            $roas = (float) ($campaign->getMaximizeConversionValue()?->getTargetRoas() ?? $campaign->getTargetRoas()?->getTargetRoas() ?? 0);
            $strategy = match (true) {
                $type === 'TARGET_SPEND' => 'MAXIMIZE_CLICKS',
                $type === 'ENHANCED_CPC' => 'MANUAL_CPC',
                $type === 'MAXIMIZE_CONVERSIONS' && $cpa > 0 => 'TARGET_CPA',
                $type === 'MAXIMIZE_CONVERSION_VALUE' && $roas > 0 => 'TARGET_ROAS',
                default => $type,
            };

            return ['resource' => $resource, 'status' => CampaignStatus::name($campaign->getStatus()),
                'channel' => AdvertisingChannelType::name($campaign->getAdvertisingChannelType()),
                'strategy' => $strategy, 'portfolio' => $campaign->getBiddingStrategy(),
                'target_cpa_micros' => $cpa, 'target_roas' => $roas,
                'cpc_bid_ceiling_micros' => (int) ($campaign->getTargetSpend()?->getCpcBidCeilingMicros() ?? 0),
                'currency' => $row->getCustomer()->getCurrencyCode()];
        }

        return null;
    }

    protected function mutate(string $customerId, CampaignOperation $operation): bool
    {
        $response = $this->client->getCampaignServiceClient()->mutateCampaigns(new MutateCampaignsRequest([
            'validate_only' => $this->dryRun, 'customer_id' => $customerId, 'operations' => [$operation],
        ]));

        return $this->dryRun || count($response->getResults()) > 0;
    }

    private function validResource(string $customerId, string $resource): bool
    {
        return $this->customer->cleanGoogleCustomerId() === $customerId
            && preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/\d+$#D', $resource) === 1;
    }
}
