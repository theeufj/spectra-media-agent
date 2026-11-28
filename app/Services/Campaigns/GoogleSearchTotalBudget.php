<?php

namespace App\Services\Campaigns;

use App\Models\Strategy;
use App\Services\DeploymentService;
use Illuminate\Validation\ValidationException;

final class GoogleSearchTotalBudget
{
    /**
     * The campaign marker survives strategy regeneration. The strategy copy is
     * hashed into the reviewed launch intent. A disagreement must never fall
     * back to creating an average daily budget.
     *
     * @return array{total_micros: int, currency: string, start_date: string, end_date: string}|null
     */
    public static function forStrategy(Strategy $strategy): ?array
    {
        $campaign = $strategy->campaign;
        $limits = $campaign->spend_guardrails ?? [];
        $bidding = $strategy->bidding_strategy ?? [];
        if (! array_key_exists('google_total_budget', $limits) && ! array_key_exists('google_total_budget', $bidding)) {
            return null;
        }

        $cap = $limits['google_total_budget'] ?? null;
        $reviewed = $bidding['google_total_budget'] ?? null;
        $fail = static fn () => ValidationException::withMessages(['daily_budget' => 'The fixed Google Search budget, currency, dates and daily allocation must match the reviewed campaign.']);
        if (! $campaign || ! is_array($cap) || ! is_array($reviewed) || count($cap) !== 4 || count($reviewed) !== 4
            || $campaign->platforms !== ['google'] || $strategy->campaign_type !== 'search'
            || DeploymentService::normalizePlatform((string) $strategy->platform) !== 'google') {
            throw $fail();
        }
        foreach (['total_micros', 'currency', 'start_date', 'end_date'] as $key) {
            if (! isset($cap[$key]) || $cap[$key] !== ($reviewed[$key] ?? null)) {
                throw $fail();
            }
        }
        $start = $campaign->start_date;
        $end = $campaign->end_date;
        $daily = CampaignSpendGuardrails::moneyToMicros($strategy->daily_budget);
        if ($end->lt($start) || $cap['start_date'] !== $start->toDateString()
            || $cap['end_date'] !== $end->toDateString() || ! is_string($cap['currency'])
            || ! preg_match('/^[A-Z]{3}$/D', $cap['currency']) || $cap['currency'] !== $campaign->customer?->billingCurrency()
            || ! is_int($cap['total_micros']) || $cap['total_micros'] <= 0
            || $cap['total_micros'] !== CampaignSpendGuardrails::moneyToMicros($campaign->total_budget)
            || $daily <= 0 || $daily !== CampaignSpendGuardrails::moneyToMicros($campaign->daily_budget)) {
            throw $fail();
        }
        $days = (int) $start->diffInDays($end) + 1;
        if ($days > 90 || $daily * $days !== $cap['total_micros']) {
            throw $fail();
        }

        return ['total_micros' => $cap['total_micros'], 'currency' => $cap['currency'],
            'start_date' => $cap['start_date'], 'end_date' => $cap['end_date']];
    }
}
