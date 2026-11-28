<?php

namespace App\Services\GoogleAds\Support;

/** Settings returned by Google for the budget and serving period of one campaign. */
final class SearchBudgetReadback
{
    public const FIELDS = 'customer.currency_code, campaign.start_date_time, campaign.end_date_time, campaign_budget.period, campaign_budget.explicitly_shared, campaign_budget.amount_micros, campaign_budget.total_amount_micros';

    public static function fromRow(array $row): array
    {
        $budget = $row['campaignBudget'] ?? [];

        return [
            'budget_period' => $budget['period'] ?? null,
            'budget_explicitly_shared' => $budget['explicitlyShared'] ?? null,
            'provider_daily_budget_micros' => (int) ($budget['amountMicros'] ?? 0),
            'total_budget_micros' => (int) ($budget['totalAmountMicros'] ?? 0),
            'currency_code' => $row['customer']['currencyCode'] ?? null,
            'start_date_time' => $row['campaign']['startDateTime'] ?? null,
            'end_date_time' => $row['campaign']['endDateTime'] ?? null,
        ];
    }

    /** Include the verified live cap in forecasts made using a daily planning allocation. */
    public static function totalBudgetSettings(array $snapshot): array
    {
        if (($snapshot['budget_period'] ?? null) !== 'CUSTOM_PERIOD') {
            return [];
        }

        return array_intersect_key($snapshot, array_flip(['budget_period', 'budget_explicitly_shared', 'provider_daily_budget_micros',
            'total_budget_micros', 'start_date_time', 'end_date_time']));
    }

    public static function matches(array $expected, array $live): bool
    {
        $start = $expected['start_date'] ?? '';
        $end = $expected['end_date'] ?? '';
        $actualStart = $live['start_date_time'] ?? null;

        return is_string($start) && $start !== '' && CampaignDateTimes::calendarDate($start) === $start
            && is_string($end) && $end >= $start && CampaignDateTimes::calendarDate($end) === $end
            && is_int($expected['total_micros'] ?? null) && $expected['total_micros'] > 0
            && is_string($expected['currency'] ?? null) && preg_match('/^[A-Z]{3}$/D', $expected['currency']) === 1
            && ($live['budget_period'] ?? null) === 'CUSTOM_PERIOD'
            && ($live['budget_explicitly_shared'] ?? null) === false
            && ($live['provider_daily_budget_micros'] ?? null) === 0
            && ($live['total_budget_micros'] ?? null) === $expected['total_micros']
            && ($live['currency_code'] ?? null) === $expected['currency']
            && is_string($actualStart) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $actualStart) === 1
            && CampaignDateTimes::calendarDate($actualStart) === $start
            && ($live['end_date_time'] ?? null) === $end.' 23:59:59';
    }
}
