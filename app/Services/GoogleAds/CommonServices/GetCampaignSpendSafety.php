<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Models\Campaign;
use App\Services\Campaigns\RestartOwnedSignupCampaign;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\Support\CampaignDateTimes;
use App\Services\GoogleAds\Support\SearchBudgetReadback;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;

/** Fresh platform metrics. API failures propagate; stale local spend is not a stop signal. */
class GetCampaignSpendSafety extends BaseGoogleAdsService
{
    public function forCampaign(Campaign $campaign): array
    {
        $this->ensureClient();
        $resource = $campaign->googleAdsResourceName();
        $customerId = $this->customer->cleanGoogleCustomerId();
        if (! $resource || ! $customerId) {
            throw new \RuntimeException('Spend safety requires a valid Google customer and campaign resource.');
        }
        if ($resource !== "customers/{$customerId}/campaigns/{$campaign->googleCampaignNumericId()}") {
            throw new \RuntimeException('Spend safety campaign resource does not match its customer account.');
        }
        $query = 'SELECT customer.id, customer.status, customer.manager, customer.test_account, customer.time_zone, campaign.resource_name, campaign.advertising_channel_type, campaign.status, campaign.bidding_strategy_type, campaign.target_spend.cpc_bid_ceiling_micros, '
            .SearchBudgetReadback::FIELDS.' FROM campaign '
            ."WHERE campaign.resource_name = '{$resource}'";
        $snapshot = null;
        foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
            $snapshot = [
                'status' => $row->getCampaign()->getStatus(),
                'customer_id' => (string) $row->getCustomer()->getId(),
                'customer_status' => $row->getCustomer()->getStatus(),
                'manager_or_test' => $row->getCustomer()->getManager() || $row->getCustomer()->getTestAccount(),
                'resource' => $row->getCampaign()->getResourceName(),
                'campaign_type' => $row->getCampaign()->getAdvertisingChannelType(),
                'account_timezone' => $row->getCustomer()->getTimeZone(),
                ...SearchBudgetReadback::fromRow(json_decode($row->serializeToJsonString(), true, flags: JSON_THROW_ON_ERROR)),
                'campaign_start_date' => $row->getCampaign()->getStartDateTime(),
                'starts_at_local' => $row->getCampaign()->getStartDateTime(),
                'ends_at_local' => $row->getCampaign()->getEndDateTime(),
                'campaign_end_date' => CampaignDateTimes::calendarDate($row->getCampaign()->getEndDateTime()),
                'bidding_strategy' => $row->getCampaign()->getBiddingStrategyType(),
                'cpc_bid_ceiling_micros' => (int) ($row->getCampaign()->getTargetSpend()?->getCpcBidCeilingMicros() ?? 0),
                'daily_budget_micros' => (int) $row->getCampaignBudget()->getAmountMicros(),
            ];
        }
        if ($snapshot === null) {
            throw new \RuntimeException('Google did not return the campaign spend safety snapshot.');
        }
        // V26 returns an account-local timestamp. GAQL metrics still require
        // date bounds, so retain its local calendar date without UTC conversion.
        $startDate = CampaignDateTimes::calendarDate($snapshot['campaign_start_date']);
        if ($startDate === null) {
            throw new \RuntimeException('Google did not return a valid campaign start date for spend safety.');
        }
        $snapshot['campaign_start_date'] = $startDate;
        $lifetime = $this->metrics($customerId, $resource);
        $snapshot['cost_micros'] = $lifetime['cost_micros'];
        $snapshot['conversions'] = $lifetime['conversions'];

        if (! in_array($snapshot['account_timezone'], timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new \RuntimeException('Google did not return a valid account time zone for spend safety.');
        }
        $today = now($snapshot['account_timezone'])->startOfDay();
        $end = $today->copy()->subDays(max(1, (int) config('optimization.campaign_spend_safety.conversion_lag_days', 2)))->toDateString();
        $start = $today->copy()->subDays(max(3, (int) config('optimization.campaign_spend_safety.window_days', 30)))->toDateString();
        // Lifetime matured cost supports a trial baseline taken mid-day. A
        // lifetime baseline including recent cost is conservative until those
        // clicks mature; it cannot accidentally count pre-trial spend twice.
        $snapshot['matured_cost_micros'] = $this->metricsBetween($customerId, $resource, $snapshot['campaign_start_date'], $end)['cost_micros'];
        $windowStart = max($start, $snapshot['campaign_start_date']);
        $restartDate = $snapshot['campaign_type'] === AdvertisingChannelType::PERFORMANCE_MAX
            ? RestartOwnedSignupCampaign::verifiedBaselineDate($campaign, $resource, $snapshot['account_timezone']) : null;
        if ($restartDate !== null) {
            $windowStart = max($windowStart, $restartDate);
        }
        $matured = $this->metricsBetween($customerId, $resource, $windowStart, $end);
        $recent = $this->metricsBetween($customerId, $resource, $windowStart, $today->toDateString());
        $snapshot['window_matured_cost_micros'] = $matured['cost_micros'];
        $snapshot['window_conversions'] = $recent['conversions'];
        $snapshot['window_start'] = $restartDate === null ? $start : $windowStart;
        $snapshot['matured_through'] = $end;
        $snapshot['amplifying_bid_modifiers'] = [];
        foreach (['campaign_criterion', 'ad_group_bid_modifier', 'ad_group_criterion'] as $entity) {
            $query = "SELECT {$entity}.resource_name, {$entity}.bid_modifier FROM {$entity} "
                ."WHERE campaign.resource_name = '{$resource}' AND {$entity}.bid_modifier > 1.0";
            if ($entity === 'campaign_criterion') {
                $query .= " AND campaign_criterion.status = 'ENABLED'";
            } else {
                $query .= " AND ad_group.status = 'ENABLED'";
                if ($entity === 'ad_group_criterion') {
                    $query .= " AND ad_group_criterion.status = 'ENABLED'";
                }
            }
            foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
                $modifier = match ($entity) {
                    'campaign_criterion' => $row->getCampaignCriterion(),
                    'ad_group_criterion' => $row->getAdGroupCriterion(),
                    default => $row->getAdGroupBidModifier(),
                };
                $snapshot['amplifying_bid_modifiers'][] = ['resource' => $modifier->getResourceName(), 'multiplier' => $modifier->getBidModifier()];
            }
        }

        return $snapshot;
    }

    private function metrics(string $customerId, string $resource, ?string $dateFilter = null): array
    {
        $metrics = ['cost_micros' => 0, 'conversions' => 0.0];
        $query = "SELECT metrics.cost_micros, metrics.conversions FROM campaign WHERE campaign.resource_name = '{$resource}'"
            .($dateFilter ? " AND {$dateFilter}" : '');
        foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
            $metrics['cost_micros'] += (int) $row->getMetrics()->getCostMicros();
            $metrics['conversions'] += (float) $row->getMetrics()->getConversions();
        }

        return $metrics;
    }

    private function metricsBetween(string $customerId, string $resource, string $start, string $end): array
    {
        // Google requires finite date bounds. A not-yet-started campaign has
        // no mature clicks; do not issue an inverted range to the API.
        return $start > $end ? ['cost_micros' => 0, 'conversions' => 0.0]
            : $this->metrics($customerId, $resource, "segments.date BETWEEN '{$start}' AND '{$end}'");
    }
}
