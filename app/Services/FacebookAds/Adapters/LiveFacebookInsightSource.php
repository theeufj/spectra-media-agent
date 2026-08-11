<?php

namespace App\Services\FacebookAds\Adapters;

use App\Contracts\Ads\FacebookInsightSource;
use App\Models\Customer;
use App\Services\FacebookAds\InsightService;

/**
 * Live implementation: reads insights from Meta.
 *
 * Thin adapter over InsightService so agents depend on the contract rather than
 * constructing it. Behaviour unchanged.
 */
class LiveFacebookInsightSource implements FacebookInsightSource
{
    private InsightService $insights;

    public function __construct(Customer $customer)
    {
        $this->insights = new InsightService($customer);
    }

    public function getAccountInsightsByLevel(
        string $accountId,
        string $dateStart,
        string $dateEnd,
        string $level = 'account',
        ?array $fields = null,
        int $limit = 100
    ): array {
        return $this->insights->getAccountInsightsByLevel($accountId, $dateStart, $dateEnd, $level, $fields, $limit);
    }

    public function getCampaignInsights(string $campaignId, string $dateStart, string $dateEnd, ?array $fields = null): ?array
    {
        return $this->insights->getCampaignInsights($campaignId, $dateStart, $dateEnd, $fields);
    }

    public function parseAction(?array $actions, string $actionType = 'purchase'): float
    {
        return $this->insights->parseAction($actions, $actionType);
    }
}
