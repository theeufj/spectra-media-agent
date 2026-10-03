<?php

namespace App\Services\Agents\Google;

use App\Models\Campaign;
use Carbon\CarbonImmutable;

/** Keep the dates sent to Google aligned with the dates the customer approved. */
class GoogleCampaignSchedule
{
    /** @return array{startDate: string, endDate: string} */
    public static function for(Campaign $campaign): array
    {
        if ($campaign->getRawOriginal('start_date') === null || $campaign->getRawOriginal('end_date') === null) {
            throw new \RuntimeException('Campaign dates are required before Google Ads deployment.');
        }

        $today = CarbonImmutable::now($campaign->customer?->timezone ?: config('app.timezone'))->startOfDay();
        $start = CarbonImmutable::parse($campaign->start_date)->startOfDay();
        $end = CarbonImmutable::parse($campaign->end_date)->startOfDay();

        if ($end->lt($today)) {
            throw new \RuntimeException('Campaign end date has passed. Choose a new end date before deploying to Google Ads.');
        }

        if ($end->lt($start)) {
            throw new \RuntimeException('Campaign end date must be on or after its start date.');
        }

        return [
            'startDate' => $start->lt($today) ? $today->toDateString() : $start->toDateString(),
            'endDate' => $end->toDateString(),
        ];
    }
}
