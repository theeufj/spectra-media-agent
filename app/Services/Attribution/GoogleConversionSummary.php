<?php

namespace App\Services\Attribution;

use App\Models\Customer;
use App\Models\GoogleAdsPerformanceData;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class GoogleConversionSummary
{
    /** @return array{conversions: float|null, conversion_value: float|null, latest_date: string|null} */
    public function forCustomer(Customer $customer): array
    {
        return $this->summarize(GoogleAdsPerformanceData::query()
            ->whereHas('campaign', fn ($query) => $query->where('customer_id', $customer->id)));
    }

    /** @return array{conversions: float|null, conversion_value: float|null, latest_date: string|null} */
    public function forCampaign(int $campaignId): array
    {
        return $this->summarize(GoogleAdsPerformanceData::query()->where('campaign_id', $campaignId));
    }

    /** @return array{conversions: float|null, conversion_value: float|null, latest_date: string|null} */
    private function summarize(Builder $query): array
    {
        $row = $query->where('date', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('SUM(conversions) AS total_conversions, SUM(conversion_value) AS total_value, MAX(date) AS latest_date')
            ->first();
        $conversions = $row?->getAttribute('total_conversions');
        $value = $row?->getAttribute('total_value');
        $latestDate = $row?->getAttribute('latest_date');

        return [
            'conversions' => $conversions === null ? null : round((float) $conversions, 2),
            'conversion_value' => $value === null ? null : round((float) $value, 2),
            'latest_date' => $latestDate instanceof CarbonInterface
                ? $latestDate->toDateString()
                : $latestDate,
        ];
    }
}
