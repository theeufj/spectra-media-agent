<?php

namespace App\Services\Health;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\FacebookAdsPerformanceData;
use App\Models\LinkedInAdsPerformanceData;
use App\Models\MicrosoftAdsPerformanceData;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\Campaigns\GoogleBudgetPacingHistory;
use App\Services\GoogleAds\CommonServices\GetCampaignBudgetPacing;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus as GoogleStatus;
use Illuminate\Support\Facades\Log;

/** One read-only comparison for health cards and pacing emails. This never changes spending limits. */
class CampaignBudgetPacing
{
    public function assess(Campaign $campaign): array
    {
        $unknown = ['status' => 'unavailable', 'reason' => 'pacing_evidence_unavailable', 'actual_spend_micros' => null,
            'expected_spend_micros' => null, 'pacing_ratio' => null, 'checked_at' => now()->toIso8601String()];
        try {
            if ($campaign->exists) {
                $campaign->refresh()->load('customer');
            }
            if (in_array($campaign->status, CampaignStatus::nonDelivering(), true) || $campaign->hasPassedEndDate()
                || in_array(strtoupper($campaign->primary_status ?? ''), ['PAUSED', 'REMOVED', 'NOT_ELIGIBLE', 'ENDED'], true)) {
                return ['status' => 'skipped', 'reason' => 'campaign_not_delivering'] + $unknown;
            }
            $customer = $campaign->customer;
            $daily = CampaignSpendGuardrails::moneyToMicros($campaign->daily_budget);
            if (! $customer || $daily <= 0) {
                return ['reason' => 'daily_budget_unverified'] + $unknown;
            }
            $trial = CampaignSpendGuardrails::activeTrial($campaign);
            $changed = $campaign->last_budget_changed_at?->toIso8601String();
            $effective = null;
            if ($trial) {
                if (! is_int($trial['baseline_cost_micros'] ?? null) || $trial['baseline_cost_micros'] < 0
                    || ! is_int($trial['max_spend_micros'] ?? null) || $trial['max_spend_micros'] <= 0
                    || ! is_string($trial['started_at'] ?? null)) {
                    return ['reason' => 'trial_baseline_unverified', 'basis' => 'bounded_trial'] + $unknown;
                }
                $effective = CarbonImmutable::parse($trial['started_at']);
                if ($changed) {
                    $effective = $effective->max(CarbonImmutable::parse($changed));
                }
            } elseif ($changed) {
                $effective = CarbonImmutable::parse($changed);
            }
            $platforms = array_filter(['google' => $campaign->google_ads_campaign_id, 'facebook' => $campaign->facebook_ads_campaign_id,
                'microsoft' => $campaign->microsoft_ads_campaign_id, 'linkedin' => $campaign->linkedin_campaign_id]);
            if (count($platforms) !== 1 || ($trial && ! isset($platforms['google']))) {
                return ['reason' => 'platform_budget_allocation_unverified'] + $unknown;
            }
            if (isset($platforms['google'])) {
                $resources = $campaign->strategies()->whereNotNull('google_ads_campaign_id')->pluck('google_ads_campaign_id')
                    ->map(fn ($id) => preg_replace('#^.*?/campaigns/#', '', $id))->unique();
                if ($resources->count() > 1) {
                    return ['reason' => 'multiple_google_budget_windows_unverified'] + $unknown;
                }
                $history = app(GoogleBudgetPacingHistory::class);
                $proof = $history->latest($campaign);
                $confirmed = is_string($proof['confirmed_at'] ?? null) && $proof['confirmed_at'] !== ''
                    ? CarbonImmutable::parse($proof['confirmed_at']) : null;
                if ($confirmed?->gt(now()->addMinutes(5))) {
                    return ['reason' => 'platform_budget_effective_date_unverified'] + $unknown;
                }
                if ($confirmed && ($proof['daily_budget_micros'] ?? null) === $daily
                    && ($proof['campaign_resource'] ?? null) === $campaign->googleAdsResourceName()
                    && (! $changed || $confirmed->gte(CarbonImmutable::parse($changed)))) {
                    $effective = $trial ? $confirmed->max(CarbonImmutable::parse($trial['started_at'])) : $confirmed;
                    $effectiveSource = 'confirmed_google_budget_readback';
                } elseif ($trial && (! $changed || CarbonImmutable::parse($changed)->lte(CarbonImmutable::parse($trial['started_at'])))
                    && ($trial['max_daily_budget_micros'] ?? null) === $daily) {
                    // The immutable, confirmed trial baseline can establish
                    // its original budget window, never a later desired edit.
                    $effective = CarbonImmutable::parse($trial['started_at']);
                    $proof = null;
                    $effectiveSource = 'confirmed_bounded_trial_baseline';
                } else {
                    return ['reason' => 'platform_budget_effective_date_unverified',
                        'trial_spend_source' => $trial ? 'independent_spend_safety_monitor' : null] + $unknown;
                }
                $evidence = app(GetCampaignBudgetPacing::class, ['customer' => $customer])->forCampaign($campaign, $effective->toIso8601String(), $trial !== null);
                if (($evidence['status'] ?? null) !== GoogleStatus::ENABLED) {
                    return ['status' => 'skipped', 'reason' => 'google_campaign_not_enabled'] + $unknown;
                }
                if (($evidence['campaign_resource'] ?? null) !== $campaign->googleAdsResourceName()
                    || ($proof && ! $history->matches($proof, $evidence))) {
                    return ['reason' => 'confirmed_platform_budget_context_changed'] + $unknown;
                }
            } else {
                if (! $effective) {
                    return ['reason' => 'budget_effective_date_unknown', 'basis' => 'current_budget_window'] + $unknown;
                }
                $evidence = $this->storedEvidence($campaign, array_key_first($platforms), $effective);
                $effectiveSource = 'stored_budget_history';
            }
            if (($evidence['daily_budget_micros'] ?? null) !== $daily || ! preg_match('/^[A-Z]{3}$/', $evidence['currency_code'] ?? '')
                || ! in_array($evidence['account_timezone'] ?? '', timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
                return ['reason' => 'current_budget_or_account_context_unverified'] + $unknown;
            }
            if (! is_string($evidence['checked_at'] ?? null) || $evidence['checked_at'] === ''
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $evidence['from'] ?? '')) {
                return ['reason' => 'complete_day_spend_unverified'] + $unknown;
            }
            $checked = CarbonImmutable::parse($evidence['checked_at']);
            $today = CarbonImmutable::now($evidence['account_timezone'])->startOfDay();
            if ($checked->lt(now()->subHours(36)) || $checked->gt(now()->addMinutes(5))
                || ($evidence['through'] ?? null) !== $today->subDay()->toDateString()) {
                return ['reason' => 'complete_day_spend_stale'] + $unknown;
            }
            $from = CarbonImmutable::parse($evidence['from'], $evidence['account_timezone'])->startOfDay();
            $days = max(0, (int) $from->diffInDays($today, false));
            $result = ['status' => 'collecting_evidence', 'basis' => $trial ? 'bounded_trial' : 'current_budget_window',
                'from' => $from->toDateString(), 'through' => $evidence['through'], 'account_timezone' => $evidence['account_timezone'],
                'currency_code' => $evidence['currency_code'], 'complete_days' => $days, 'daily_budget_micros' => $daily,
                'budget_effective_at' => $effective->toIso8601String(), 'budget_effective_source' => $effectiveSource,
                'source' => $evidence['source'], 'checked_at' => $evidence['checked_at'],
                'comparison_note' => 'Average daily budget comparison for complete account days only; this is not a billing limit or a month-end forecast.'];
            if ($trial) {
                $reported = $evidence['reported_lifetime_cost_micros'] ?? null;
                if (! is_int($reported) || $reported < $trial['baseline_cost_micros']
                    || ($trial['currency_code'] ?? null) !== $evidence['currency_code']
                    || ($trial['account_timezone'] ?? null) !== $evidence['account_timezone']) {
                    return ['reason' => 'trial_reported_spend_unverified'] + $unknown + $result;
                }
                $spent = $reported - $trial['baseline_cost_micros'];
                $result += ['trial_started_at' => $trial['started_at'], 'trial_baseline_cost_micros' => $trial['baseline_cost_micros'],
                    'trial_reported_spend_micros' => $spent, 'trial_stop_threshold_micros' => $trial['max_spend_micros'],
                    'trial_remaining_reported_spend_micros' => max(0, $trial['max_spend_micros'] - $spent),
                    'trial_reporting_note' => 'Trial spend excludes the immutable pre-trial baseline. Google reporting can lag; the review stop threshold is not a hard billing cap.'];
            }
            $cost = $evidence['cost_micros'] ?? null;
            if ($days < 3) {
                return $result + ['reason' => 'insufficient_complete_days'] + $unknown;
            }
            if (! is_int($cost) || $cost < 0) {
                return ['reason' => 'complete_day_spend_unverified'] + $unknown + $result;
            }
            $expected = $daily * $days;

            return ['status' => 'ready', 'actual_spend_micros' => $cost, 'expected_spend_micros' => $expected,
                'pacing_ratio' => $cost / $expected] + $result;
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Campaign budget pacing evidence could not be verified', ['campaign_id' => $campaign->id, 'error' => $e->getMessage()]);

            return $unknown;
        }
    }

    private function storedEvidence(Campaign $campaign, string $platform, CarbonImmutable $effective): array
    {
        $timezone = $campaign->customer->timezone;
        if (! in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new \RuntimeException('Stored pacing metrics have no verified account timezone.');
        }
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $local = $effective->setTimezone($timezone);
        $from = ($local->isStartOfDay() ? $local : $local->addDay())->startOfDay()->max($today->startOfMonth());
        $through = $today->subDay();
        $model = match ($platform) {
            'facebook' => FacebookAdsPerformanceData::class,
            'microsoft' => MicrosoftAdsPerformanceData::class,
            'linkedin' => LinkedInAdsPerformanceData::class,
            default => throw new \InvalidArgumentException('This platform has no stored pacing source.'),
        };
        $rows = $model::where('campaign_id', $campaign->id)->whereBetween('date', [$from->toDateString(), $through->toDateString()])->get();
        $cost = 0;
        for ($day = $from; $day->lte($through); $day = $day->addDay()) {
            $daily = $rows->filter(fn ($row) => $row->date->toDateString() === $day->toDateString());
            if ($daily->isEmpty()) {
                throw new \RuntimeException('A complete account day has no reported spend data; missing is not zero.');
            }
            foreach ($daily as $row) {
                $raw = (string) $row->getRawOriginal('cost');
                if (! preg_match('/^\d+(?:\.\d{1,6})?$/', $raw)) {
                    throw new \RuntimeException('Reported spend amount is invalid.');
                }
                $cost += CampaignSpendGuardrails::moneyToMicros($raw);
            }
        }
        $latest = $rows->filter(fn ($row) => $row->date->toDateString() === $through->toDateString())->min('updated_at');

        return ['cost_micros' => $cost, 'from' => $from->toDateString(), 'through' => $through->toDateString(),
            'currency_code' => $campaign->customer->currency_code, 'account_timezone' => $timezone,
            'daily_budget_micros' => CampaignSpendGuardrails::moneyToMicros($campaign->daily_budget),
            'source' => 'reported_daily_platform_metrics', 'checked_at' => $latest?->toIso8601String()];
    }

    public static function amount(int $micros, string $currency): string
    {
        return $currency.' '.number_format($micros / 1_000_000, 2);
    }

    public static function summary(array $pacing): string
    {
        $currency = $pacing['currency_code'];
        $text = 'Reported '.self::amount($pacing['actual_spend_micros'], $currency)
            .' against an average-budget pace of '.self::amount($pacing['expected_spend_micros'], $currency)
            .' over '.$pacing['complete_days'].' complete account days ('.$pacing['from'].' to '.$pacing['through']
            .', '.$pacing['account_timezone'].'), at '.self::amount($pacing['daily_budget_micros'], $currency).'/day.';
        if ($pacing['basis'] === 'bounded_trial') {
            $text .= ' Additional reported trial spend since '.$pacing['trial_started_at'].': '
                .self::amount($pacing['trial_reported_spend_micros'], $currency).' against the '
                .self::amount($pacing['trial_stop_threshold_micros'], $currency).' review stop threshold; pre-trial spend is excluded.';
        }

        return $text.' Reporting can lag. This comparison is not a billing limit or a month-end forecast.';
    }
}
