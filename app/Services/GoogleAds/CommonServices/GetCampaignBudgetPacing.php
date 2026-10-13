<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Models\Campaign;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Carbon\CarbonImmutable;

/** Read-only pacing evidence in the Google account's currency and calendar. */
class GetCampaignBudgetPacing extends BaseGoogleAdsService
{
    public function forCampaign(Campaign $campaign, string $effectiveAt, bool $trial = false): array
    {
        $this->ensureClient();
        $resource = $campaign->googleAdsResourceName();
        $customerId = $this->customer->cleanGoogleCustomerId();
        if (! $resource || ! $customerId || ! str_starts_with($resource, "customers/{$customerId}/campaigns/")) {
            throw new \RuntimeException('Budget pacing requires a valid customer campaign resource.');
        }
        $query = 'SELECT customer.time_zone, customer.currency_code, campaign.resource_name, campaign.campaign_budget, campaign.start_date, campaign.status, campaign_budget.amount_micros '
            ."FROM campaign WHERE campaign.resource_name = '{$resource}'";
        $snapshot = null;
        foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
            $snapshot = ['campaign_resource' => $row->getCampaign()->getResourceName(), 'budget_resource' => $row->getCampaign()->getCampaignBudget(),
                'account_timezone' => $row->getCustomer()->getTimeZone(), 'currency_code' => $row->getCustomer()->getCurrencyCode(),
                'status' => $row->getCampaign()->getStatus(), 'campaign_start_date' => $row->getCampaign()->getStartDate(),
                'daily_budget_micros' => (int) $row->getCampaignBudget()->getAmountMicros()];
        }
        if (! $snapshot || $snapshot['campaign_resource'] !== $resource
            || ! str_starts_with($snapshot['budget_resource'], "customers/{$customerId}/campaignBudgets/")
            || ! in_array($snapshot['account_timezone'], timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)
            || ! preg_match('/^[A-Z]{3}$/', $snapshot['currency_code'])) {
            throw new \RuntimeException('Google account context is unavailable for pacing.');
        }
        $effective = CarbonImmutable::parse($effectiveAt)->setTimezone($snapshot['account_timezone']);
        $today = CarbonImmutable::now($snapshot['account_timezone'])->startOfDay();
        // The edit/start day can contain the previous budget's spend. Compare
        // only full account days after it, never today's incomplete metrics.
        $from = $effective->isStartOfDay() ? $effective->toDateString() : $effective->addDay()->toDateString();
        if (! $trial) {
            $from = max($from, $today->startOfMonth()->toDateString());
        }
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/', $snapshot['campaign_start_date'], $parts)) {
            $snapshot['campaign_start_date'] = "{$parts[1]}-{$parts[2]}-{$parts[3]}";
        }
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $snapshot['campaign_start_date'], $snapshot['account_timezone']);
        if ($start === null || $start->toDateString() !== $snapshot['campaign_start_date']) {
            throw new \RuntimeException('Google campaign start date is unavailable for pacing.');
        }
        $from = max($from, $start->toDateString());
        $through = $today->subDay()->toDateString();
        $snapshot += ['from' => $from, 'through' => $through, 'checked_at' => now()->toIso8601String(),
            'source' => 'google_ads_api', 'cost_micros' => $from <= $through ? $this->cost($customerId, $resource, $from, $through) : null];
        if ($trial) {
            $snapshot['reported_lifetime_cost_micros'] = $this->cost($customerId, $resource);
        }

        return $snapshot;
    }

    private function cost(string $customerId, string $resource, ?string $from = null, ?string $through = null): int
    {
        $query = "SELECT metrics.cost_micros FROM campaign WHERE campaign.resource_name = '{$resource}'"
            .($from ? " AND segments.date BETWEEN '{$from}' AND '{$through}'" : '');
        $cost = 0;
        // A successful metrics query with no rows is Google's zero-activity
        // response. A failed request propagates; it cannot become a zero.
        foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
            $cost += (int) $row->getMetrics()->getCostMicros();
        }

        return $cost;
    }
}
