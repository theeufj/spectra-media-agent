<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Contracts\Ads\SearchTermSource;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsException;

class GetSearchTermsReport extends BaseGoogleAdsService implements SearchTermSource
{
    private ?string $accountTimezone = null;

    /**
     * Get the Search Terms Report for a campaign.
     *
     * @param  string  $dateRange  Google date range, LAST_30_DAYS_INCLUDING_TODAY, or MATURED_LAST_30_DAYS.
     * @return array List of search terms with performance metrics
     */
    public function __invoke(string $customerId, string $campaignResourceName, string $dateRange = 'LAST_30_DAYS'): array
    {
        $this->ensureClient();
        if ($customerId !== $this->customer?->cleanGoogleCustomerId()
            || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/[1-9][0-9]*$#D', $campaignResourceName)) {
            throw new \InvalidArgumentException('Search term report must belong to the bound Google customer account.');
        }

        $maturity = [];
        if (in_array($dateRange, ['MATURED_LAST_30_DAYS', 'LAST_30_DAYS_INCLUDING_TODAY'], true)) {
            $timezone = $this->accountTimezone;
            if ($timezone === null) {
                foreach ($this->searchQuery($customerId, 'SELECT customer.time_zone FROM customer LIMIT 1')->getIterator() as $row) {
                    $timezone = $row->getCustomer()->getTimeZone();
                }
            }
            if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
                throw new \RuntimeException('Google account timezone is unavailable; matured search-term spend cannot be verified.');
            }
            $this->accountTimezone = $timezone;
            $today = CarbonImmutable::now($timezone)->startOfDay();
            $matured = $dateRange === 'MATURED_LAST_30_DAYS';
            $through = ($matured ? $today->subDays(max(1, (int) config('optimization.search_terms.conversion_lag_days', 2)) + 1) : $today)->toDateString();
            $from = $today->subDays(30)->toDateString();
            $dateFilter = "segments.date BETWEEN '{$from}' AND '{$through}'";
            $maturity = ['matured' => $matured, 'account_timezone' => $timezone, 'reported_from' => $from,
                ...($matured ? ['matured_through' => $through] : ['reported_through' => $through])];
        } else {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/D', $dateRange)) {
                throw new \InvalidArgumentException('Search term report requires a valid Google date range.');
            }
            $dateFilter = 'segments.date DURING '.$dateRange;
        }

        $query = 'SELECT '.
                 'search_term_view.search_term, '.
                 'search_term_view.status, '.
                 'metrics.impressions, '.
                 'metrics.clicks, '.
                 'metrics.cost_micros, '.
                 'metrics.conversions, '.
                 'metrics.all_conversions, '.
                 'metrics.ctr, '.
                 'campaign.resource_name, '.
                 'ad_group.resource_name '.
                 'FROM search_term_view '.
                 "WHERE campaign.resource_name = '$campaignResourceName' ".
                 "AND {$dateFilter} ".
                 'ORDER BY metrics.cost_micros DESC';

        try {
            $response = $this->searchQuery($customerId, $query);

            $searchTerms = [];
            foreach ($response->getIterator() as $googleAdsRow) {
                $searchTermView = $googleAdsRow->getSearchTermView();
                $metrics = $googleAdsRow->getMetrics();

                $searchTerms[] = [
                    'search_term' => $searchTermView->getSearchTerm(),
                    'status' => $searchTermView->getStatus(),
                    'impressions' => $metrics->getImpressions(),
                    'clicks' => $metrics->getClicks(),
                    'cost_micros' => $metrics->getCostMicros(),
                    'cost' => $metrics->getCostMicros() / 1000000,
                    'conversions' => $metrics->getConversions(),
                    'all_conversions' => $metrics->getAllConversions(),
                    'ctr' => $metrics->getCtr(),
                    'campaign_resource_name' => $googleAdsRow->getCampaign()->getResourceName(),
                    'ad_group_resource_name' => $googleAdsRow->getAdGroup()->getResourceName(),
                    ...$maturity,
                ];
            }

            return $searchTerms;

        } catch (GoogleAdsException $e) {
            $this->logError('Failed to get search terms report: '.$e->getMessage());

            return [];
        }
    }
}
