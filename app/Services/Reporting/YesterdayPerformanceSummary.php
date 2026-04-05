<?php

namespace App\Services\Reporting;

use App\Models\Customer;
use App\Models\GoogleAdsPerformanceData;
use App\Models\FacebookAdsPerformanceData;
use Illuminate\Support\Carbon;

class YesterdayPerformanceSummary
{
    /**
     * Get a cross-platform performance summary for a customer on a given date.
     */
    public function forCustomer(Customer $customer, ?string $date = null): array
    {
        $date = $date ?? Carbon::yesterday()->toDateString();
        $priorDate = Carbon::parse($date)->subDay()->toDateString();

        $campaignIds = $customer->campaigns()->pluck('id');

        $google = $this->getGoogleData($campaignIds, $date);
        $facebook = $this->getFacebookData($campaignIds, $date);

        $googlePrior = $this->getGoogleData($campaignIds, $priorDate);
        $facebookPrior = $this->getFacebookData($campaignIds, $priorDate);

        $combined = $this->combineMetrics($google, $facebook);
        $combinedPrior = $this->combineMetrics($googlePrior, $facebookPrior);

        return [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'date' => $date,
            'google' => $google,
            'facebook' => $facebook,
            'combined' => $combined,
            'prior_day' => $combinedPrior,
            'changes' => $this->calculateChanges($combined, $combinedPrior),
            'campaigns' => $this->getCampaignBreakdown($customer, $date),
        ];
    }

    protected function getGoogleData($campaignIds, string $date): array
    {
        $data = GoogleAdsPerformanceData::whereIn('campaign_id', $campaignIds)
            ->whereDate('date', $date)
            ->selectRaw('
                SUM(impressions) as impressions,
                SUM(clicks) as clicks,
                SUM(cost) as spend,
                SUM(conversions) as conversions,
                SUM(COALESCE(conversion_value, 0)) as conversion_value
            ')
            ->first();

        if (!$data || ($data->impressions ?? 0) == 0) {
            return $this->emptyMetrics();
        }

        return $this->formatMetrics(
            (int) $data->impressions,
            (int) $data->clicks,
            (float) $data->spend,
            (float) $data->conversions,
            (float) $data->conversion_value,
        );
    }

    protected function getFacebookData($campaignIds, string $date): array
    {
        $data = FacebookAdsPerformanceData::whereIn('campaign_id', $campaignIds)
            ->whereDate('date', $date)
            ->selectRaw('
                SUM(impressions) as impressions,
                SUM(clicks) as clicks,
                SUM(cost) as spend,
                SUM(conversions) as conversions
            ')
            ->first();

        if (!$data || ($data->impressions ?? 0) == 0) {
            return $this->emptyMetrics();
        }

        return $this->formatMetrics(
            (int) $data->impressions,
            (int) $data->clicks,
            (float) $data->spend,
            (float) $data->conversions,
            0,
        );
    }

    protected function formatMetrics(int $impressions, int $clicks, float $spend, float $conversions, float $conversionValue): array
    {
        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'spend' => round($spend, 2),
            'conversions' => round($conversions, 1),
            'conversion_value' => round($conversionValue, 2),
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0,
            'cpc' => $clicks > 0 ? round($spend / $clicks, 2) : 0,
            'cpa' => $conversions > 0 ? round($spend / $conversions, 2) : 0,
            'roas' => $spend > 0 && $conversionValue > 0 ? round($conversionValue / $spend, 2) : null,
        ];
    }

    protected function emptyMetrics(): array
    {
        return [
            'impressions' => 0,
            'clicks' => 0,
            'spend' => 0,
            'conversions' => 0,
            'conversion_value' => 0,
            'ctr' => 0,
            'cpc' => 0,
            'cpa' => 0,
            'roas' => null,
        ];
    }

    protected function combineMetrics(array $google, array $facebook): array
    {
        $impressions = $google['impressions'] + $facebook['impressions'];
        $clicks = $google['clicks'] + $facebook['clicks'];
        $spend = $google['spend'] + $facebook['spend'];
        $conversions = $google['conversions'] + $facebook['conversions'];
        $conversionValue = $google['conversion_value'] + $facebook['conversion_value'];

        return $this->formatMetrics($impressions, $clicks, $spend, $conversions, $conversionValue);
    }

    protected function calculateChanges(array $current, array $prior): array
    {
        $changes = [];
        $metrics = ['impressions', 'clicks', 'spend', 'conversions', 'ctr', 'cpc', 'cpa'];

        foreach ($metrics as $metric) {
            $curr = $current[$metric] ?? 0;
            $prev = $prior[$metric] ?? 0;

            if ($prev > 0) {
                $changes[$metric] = round(($curr - $prev) / $prev * 100, 1);
            } else {
                $changes[$metric] = $curr > 0 ? 100.0 : 0.0;
            }
        }

        return $changes;
    }

    protected function getCampaignBreakdown(Customer $customer, string $date): array
    {
        $campaigns = $customer->campaigns()
            ->where('status', 'active')
            ->get();

        $breakdown = [];

        foreach ($campaigns as $campaign) {
            $google = GoogleAdsPerformanceData::where('campaign_id', $campaign->id)
                ->whereDate('date', $date)
                ->first();

            $facebook = FacebookAdsPerformanceData::where('campaign_id', $campaign->id)
                ->whereDate('date', $date)
                ->first();

            if (!$google && !$facebook) {
                continue;
            }

            $entry = [
                'campaign_id' => $campaign->id,
                'campaign_name' => $campaign->name,
                'platform' => $google ? ($facebook ? 'both' : 'google') : 'facebook',
            ];

            if ($google) {
                $entry['google'] = [
                    'impressions' => $google->impressions,
                    'clicks' => $google->clicks,
                    'spend' => $google->cost,
                    'conversions' => $google->conversions,
                ];
            }

            if ($facebook) {
                $entry['facebook'] = [
                    'impressions' => $facebook->impressions,
                    'clicks' => $facebook->clicks,
                    'spend' => $facebook->cost,
                    'conversions' => $facebook->conversions,
                ];
            }

            $breakdown[] = $entry;
        }

        return $breakdown;
    }
}
