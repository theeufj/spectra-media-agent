<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Models\Campaign;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;

/** Fresh critical evidence for PAUSED staging; never substitutes for activation verification. */
class ReadCreativeStagingSafety extends BaseGoogleAdsService
{
    public function forCampaign(Campaign $campaign): array
    {
        $customerId = $this->customer->cleanGoogleCustomerId();
        $resource = $campaign->googleAdsResourceName();
        if (! $customerId || $resource !== "customers/{$customerId}/campaigns/{$campaign->googleCampaignNumericId()}") {
            throw new \RuntimeException('Creative staging requires an exact customer campaign resource.');
        }
        $query = 'SELECT campaign.resource_name, campaign.campaign_budget, campaign_budget.resource_name, campaign_budget.amount_micros, '
            .'customer.time_zone, customer.currency_code, campaign.status, campaign.start_date, campaign.end_date, '
            .'campaign.advertising_channel_type, campaign.bidding_strategy_type, campaign.bidding_strategy, '
            .'campaign.target_spend.cpc_bid_ceiling_micros, campaign.network_settings.target_google_search, '
            .'campaign.network_settings.target_search_network, campaign.network_settings.target_content_network, '
            .'campaign.geo_target_type_setting.positive_geo_target_type, metrics.cost_micros '
            ."FROM campaign WHERE campaign.resource_name = '{$resource}'";
        $snapshot = null;
        foreach ($this->rows($customerId, $query) as $row) {
            $remote = $row->getCampaign();
            $budget = $row->getCampaignBudget();
            $metrics = $row->getMetrics();
            if ($remote->getResourceName() !== $resource || $remote->getCampaignBudget() !== $budget->getResourceName()
                || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaignBudgets/\d+$#', $budget->getResourceName())
                || ! $metrics->hasCostMicros() || (int) $metrics->getCostMicros() < 0) {
                throw new \RuntimeException('Creative staging budget identity or lifetime spend is unavailable.');
            }
            $snapshot = ['campaign_resource' => $resource, 'budget_resource' => $budget->getResourceName(),
                'status' => $remote->getStatus(), 'channel' => $remote->getAdvertisingChannelType(),
                'start_date' => $this->calendarDate($remote->getStartDate()),
                'end_date' => $remote->getEndDate() === '' ? null : $this->calendarDate($remote->getEndDate()),
                'bidding_strategy' => $remote->getBiddingStrategyType(), 'portfolio_bidding_strategy' => $remote->getBiddingStrategy(),
                'actual_cpc_ceiling_micros' => (int) ($remote->getTargetSpend()?->getCpcBidCeilingMicros() ?? 0),
                'daily_budget_micros' => (int) $budget->getAmountMicros(), 'cost_micros' => (int) $metrics->getCostMicros(),
                'currency_code' => $row->getCustomer()->getCurrencyCode(), 'time_zone' => $row->getCustomer()->getTimeZone(),
                'google_search_enabled' => $remote->getNetworkSettings()?->getTargetGoogleSearch(),
                'search_partners_enabled' => $remote->getNetworkSettings()?->getTargetSearchNetwork(),
                'display_enabled' => $remote->getNetworkSettings()?->getTargetContentNetwork(),
                'positive_geo_target_type' => $remote->getGeoTargetTypeSetting()?->getPositiveGeoTargetType()];
        }
        if (! $snapshot || $snapshot['daily_budget_micros'] <= 0 || $snapshot['actual_cpc_ceiling_micros'] <= 0
            || ! preg_match('/^[A-Z]{3}$/', $snapshot['currency_code'])
            || ! in_array($snapshot['time_zone'], timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new \RuntimeException('Fresh critical creative staging settings could not be verified.');
        }
        $snapshot['cpc_bid_ceiling_micros'] = $snapshot['actual_cpc_ceiling_micros'];
        $snapshot['amplifying_bid_modifiers'] = [];
        foreach (['campaign_criterion', 'ad_group_bid_modifier', 'ad_group_criterion'] as $entity) {
            $query = "SELECT {$entity}.resource_name, {$entity}.bid_modifier FROM {$entity} "
                ."WHERE campaign.resource_name = '{$resource}' AND {$entity}.bid_modifier > 1.0";
            $query .= $entity === 'campaign_criterion' ? " AND campaign_criterion.status = 'ENABLED'" : " AND ad_group.status = 'ENABLED'";
            if ($entity === 'ad_group_criterion') {
                $query .= " AND ad_group_criterion.status = 'ENABLED'";
            }
            foreach ($this->rows($customerId, $query) as $row) {
                $modifier = match ($entity) {
                    'campaign_criterion' => $row->getCampaignCriterion(),
                    'ad_group_criterion' => $row->getAdGroupCriterion(),
                    default => $row->getAdGroupBidModifier(),
                };
                if (! $modifier->hasBidModifier() || $modifier->getBidModifier() <= 1
                    || ! str_starts_with($modifier->getResourceName(), "customers/{$customerId}/")) {
                    throw new \RuntimeException('An amplifying modifier read was incomplete.');
                }
                $snapshot['amplifying_bid_modifiers'][] = ['resource' => $modifier->getResourceName(), 'multiplier' => $modifier->getBidModifier()];
            }
        }

        return $snapshot + ['modifier_reads_complete' => true, 'checked_at' => now()->toIso8601String()];
    }

    /** @return iterable<GoogleAdsRow> */
    protected function rows(string $customerId, string $query): iterable
    {
        $this->ensureClient();

        return $this->searchQuery($customerId, $query)->iterateAllElements();
    }

    private function calendarDate(string $value): string
    {
        if (! preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/', $value, $date)
            || ! checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            throw new \RuntimeException('The live creative staging campaign calendar could not be verified.');
        }

        return "{$date[1]}-{$date[2]}-{$date[3]}";
    }
}
