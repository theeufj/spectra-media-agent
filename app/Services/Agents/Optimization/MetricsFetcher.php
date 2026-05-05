<?php

namespace App\Services\Agents\Optimization;

use App\Models\Campaign;
use App\Models\GoogleAdsPerformanceData;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\FacebookAds\InsightService;
use Illuminate\Support\Facades\Log;

/**
 * Fetches current and historical performance metrics for a campaign
 * across all supported platforms and normalises them to a common shape.
 */
class MetricsFetcher
{
    public function __construct(
        private GetCampaignPerformance $getGooglePerformance
    ) {}

    public function fetchCurrent(Campaign $campaign): ?array
    {
        if ($campaign->google_ads_campaign_id && $campaign->customer?->google_ads_customer_id) {
            return $this->fetchGoogle($campaign);
        }

        if ($campaign->facebook_ads_campaign_id && $campaign->customer) {
            return $this->fetchFacebook($campaign);
        }

        if ($campaign->microsoft_ads_campaign_id && $campaign->customer) {
            return $this->fetchMicrosoft($campaign);
        }

        if ($campaign->linkedin_campaign_id && $campaign->customer) {
            return $this->fetchLinkedIn($campaign);
        }

        return null;
    }

    public function fetchHistorical(Campaign $campaign): ?array
    {
        if ($campaign->google_ads_campaign_id) {
            return $this->fetchGoogleHistorical($campaign);
        }

        if ($campaign->facebook_ads_campaign_id && $campaign->customer) {
            return $this->fetchFacebookHistorical($campaign);
        }

        if ($campaign->microsoft_ads_campaign_id) {
            return $this->fetchMicrosoftHistorical($campaign);
        }

        if ($campaign->linkedin_campaign_id) {
            return $this->fetchLinkedInHistorical($campaign);
        }

        return null;
    }

    public function platform(Campaign $campaign): ?string
    {
        if ($campaign->google_ads_campaign_id)    return 'Google Ads';
        if ($campaign->facebook_ads_campaign_id)  return 'Facebook Ads';
        if ($campaign->microsoft_ads_campaign_id) return 'Microsoft Ads';
        if ($campaign->linkedin_campaign_id)      return 'LinkedIn Ads';
        return null;
    }

    private function fetchGoogle(Campaign $campaign): ?array
    {
        $customer = $campaign->customer;
        if (!$customer?->google_ads_customer_id) {
            return null;
        }

        try {
            $customerId   = $customer->cleanGoogleCustomerId();
            $resourceName = $campaign->googleAdsResourceName();
            if (!$resourceName) return null;
            return ($this->getGooglePerformance)($customerId, $resourceName);
        } catch (\Exception $e) {
            Log::error("MetricsFetcher: Failed to get Google metrics: " . $e->getMessage());
            return null;
        }
    }

    private function fetchGoogleHistorical(Campaign $campaign): ?array
    {
        try {
            $data = GoogleAdsPerformanceData::where('campaign_id', $campaign->id)
                ->whereBetween('date', [
                    now()->subDays(60)->toDateString(),
                    now()->subDays(30)->toDateString(),
                ])
                ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions')
                ->first();

            if (!$data || ($data->impressions ?? 0) == 0) {
                return null;
            }

            return [
                'impressions'        => (int) $data->impressions,
                'clicks'             => (int) $data->clicks,
                'cost_micros'        => (float) $data->cost * 1_000_000,
                'conversions'        => (float) $data->conversions,
                'ctr'                => $data->impressions > 0 ? $data->clicks / $data->impressions : 0,
                'average_cpc'        => $data->clicks > 0 ? ($data->cost / $data->clicks) * 1_000_000 : 0,
                'cost_per_conversion' => $data->conversions > 0 ? ($data->cost / $data->conversions) * 1_000_000 : 0,
            ];
        } catch (\Exception) {
            return null;
        }
    }

    private function fetchFacebook(Campaign $campaign): ?array
    {
        try {
            $insights = (new InsightService($campaign->customer))->getCampaignInsights(
                $campaign->facebook_ads_campaign_id,
                now()->subDays(30)->format('Y-m-d'),
                now()->format('Y-m-d')
            );

            if (empty($insights) || !isset($insights['data'][0])) {
                return null;
            }

            $data = $insights['data'][0];

            return [
                'impressions'        => (int) ($data['impressions'] ?? 0),
                'clicks'             => (int) ($data['clicks'] ?? 0),
                'cost_micros'        => (float) ($data['spend'] ?? 0) * 1_000_000,
                'conversions'        => $this->sumFbActions($data['actions'] ?? [], ['purchase', 'lead', 'complete_registration']),
                'ctr'                => ($data['impressions'] ?? 0) > 0 ? ($data['clicks'] ?? 0) / $data['impressions'] : 0,
                'average_cpc'        => (float) ($data['cpc'] ?? 0) * 1_000_000,
                'cost_per_conversion' => (float) ($data['cost_per_action_type'][0]['value'] ?? 0) * 1_000_000,
                'frequency'          => (float) ($data['frequency'] ?? 0),
                'reach'              => (int) ($data['reach'] ?? 0),
            ];
        } catch (\Exception $e) {
            Log::error("MetricsFetcher: Failed to get Facebook metrics for campaign {$campaign->id}: " . $e->getMessage());
            return null;
        }
    }

    private function fetchFacebookHistorical(Campaign $campaign): ?array
    {
        try {
            $insights = (new InsightService($campaign->customer))->getCampaignInsights(
                $campaign->facebook_ads_campaign_id,
                now()->subDays(60)->format('Y-m-d'),
                now()->subDays(30)->format('Y-m-d')
            );

            if (empty($insights) || !isset($insights[0])) {
                return null;
            }

            $data = $insights[0];

            return [
                'impressions'        => (int) ($data['impressions'] ?? 0),
                'clicks'             => (int) ($data['clicks'] ?? 0),
                'cost_micros'        => (float) ($data['spend'] ?? 0) * 1_000_000,
                'conversions'        => $this->sumFbActions($data['actions'] ?? [], ['purchase', 'lead', 'complete_registration']),
                'ctr'                => ($data['impressions'] ?? 0) > 0 ? ($data['clicks'] ?? 0) / $data['impressions'] : 0,
                'average_cpc'        => (float) ($data['cpc'] ?? 0) * 1_000_000,
                'cost_per_conversion' => (float) ($data['cost_per_action_type'][0]['value'] ?? 0) * 1_000_000,
            ];
        } catch (\Exception) {
            return null;
        }
    }

    private function fetchMicrosoft(Campaign $campaign): ?array
    {
        return $this->aggregateLocalPerformance(\App\Models\MicrosoftAdsPerformanceData::class, $campaign, 30);
    }

    private function fetchMicrosoftHistorical(Campaign $campaign): ?array
    {
        return $this->aggregateLocalPerformanceBetween(\App\Models\MicrosoftAdsPerformanceData::class, $campaign, 60, 30);
    }

    private function fetchLinkedIn(Campaign $campaign): ?array
    {
        return $this->aggregateLocalPerformance(\App\Models\LinkedInAdsPerformanceData::class, $campaign, 30);
    }

    private function fetchLinkedInHistorical(Campaign $campaign): ?array
    {
        return $this->aggregateLocalPerformanceBetween(\App\Models\LinkedInAdsPerformanceData::class, $campaign, 60, 30);
    }

    private function aggregateLocalPerformance(string $model, Campaign $campaign, int $days): ?array
    {
        $data = $model::where('campaign_id', $campaign->id)
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->get();

        if ($data->isEmpty()) {
            return null;
        }

        return $this->normalise($data);
    }

    private function aggregateLocalPerformanceBetween(string $model, Campaign $campaign, int $from, int $to): ?array
    {
        $data = $model::where('campaign_id', $campaign->id)
            ->whereBetween('date', [now()->subDays($from)->toDateString(), now()->subDays($to)->toDateString()])
            ->get();

        if ($data->isEmpty()) {
            return null;
        }

        return $this->normalise($data);
    }

    private function normalise($data): array
    {
        $impressions = $data->sum('impressions');
        $clicks      = $data->sum('clicks');
        $cost        = $data->sum('cost');
        $conversions = $data->sum('conversions');

        return [
            'impressions'        => $impressions,
            'clicks'             => $clicks,
            'cost_micros'        => $cost * 1_000_000,
            'conversions'        => $conversions,
            'ctr'                => $impressions > 0 ? $clicks / $impressions : 0,
            'average_cpc'        => $clicks > 0 ? ($cost / $clicks) * 1_000_000 : 0,
            'cost_per_conversion' => $conversions > 0 ? ($cost / $conversions) * 1_000_000 : 0,
        ];
    }

    private function sumFbActions(array $actions, array $types): int
    {
        $total = 0;
        foreach ($actions as $action) {
            if (in_array($action['action_type'], $types)) {
                $total += (int) $action['value'];
            }
        }
        return $total;
    }
}
