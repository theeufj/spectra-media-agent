<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Carbon\CarbonImmutable;

/** Shared mutation limits, so an optimiser cannot undo an approved bounded trial. */
class CampaignSpendGuardrails
{
    public static function forGoogleResource(string $customerId, string $resource): ?Campaign
    {
        $customerId = preg_replace('/\D/', '', $customerId);
        if (! preg_match('/^(?:customers\/(\d+)\/campaigns\/)?(\d+)$/', $resource, $matches)) {
            return null;
        }
        if ($matches[1] !== '' && $matches[1] !== $customerId) {
            throw new \InvalidArgumentException('Google campaign resource belongs to a different account.');
        }
        $id = $matches[2];

        // Mutations identify the customer sub-account explicitly. Deliberate
        // cross-tenant lookup is still constrained to that exact account + ID.
        $campaign = Campaign::withoutCustomerScope()
            ->whereHas('customer', fn ($query) => $query->whereRaw("REPLACE(google_ads_customer_id, '-', '') = ?", [$customerId]))
            ->where(fn ($query) => $query->where('google_ads_campaign_id', $id)
                ->orWhere('google_ads_campaign_id', "customers/{$customerId}/campaigns/{$id}")
                ->orWhereHas('strategies', fn ($strategy) => $strategy->where('google_ads_campaign_id', $id)
                    ->orWhere('google_ads_campaign_id', "customers/{$customerId}/campaigns/{$id}")))
            ->first();

        return $campaign instanceof Campaign ? $campaign : null;
    }

    public static function activeTrial(Campaign $campaign): ?array
    {
        $trial = $campaign->spend_guardrails;

        return is_array($trial) && ($trial['enabled'] ?? false) === true ? $trial : null;
    }

    /** Ongoing limits do not suspend management like a fixed-spend trial does. */
    public static function automationLimits(Campaign $campaign): ?array
    {
        $limits = $campaign->spend_guardrails['automation_limits'] ?? null;

        // Keep an enabled but incomplete profile visible. Treating it as absent
        // would silently remove the very limits that its writer tried to impose.
        return is_array($limits) && ($limits['enabled'] ?? false) === true ? $limits : null;
    }

    /** Invalid enabled envelopes remain visible and keep legacy automation blocked. */
    public static function aiMaxPilot(Campaign $campaign): ?array
    {
        $pilot = $campaign->spend_guardrails['ai_max_pilot'] ?? null;

        return is_array($pilot) && ($pilot['enabled'] ?? false) === true ? $pilot : null;
    }

    /** A queued owner request freezes competing optimizers without relaxing CPC limits. */
    public static function pendingAiMaxRequest(Campaign $campaign): ?array
    {
        $request = $campaign->search_delivery_state['ai_max_request'] ?? null;

        return is_array($request) && ($request['enabled'] ?? false) === true
            && is_string($request['nonce'] ?? null) && trim($request['nonce']) !== ''
            && ! in_array($request['status'] ?? null, ['active', 'completed', 'cancelled', 'revoked'], true)
            ? $request : null;
    }

    public static function validAiMaxPilot(Campaign $campaign): bool
    {
        $pilot = self::aiMaxPilot($campaign);
        $customerId = $campaign->customer?->cleanGoogleCustomerId();
        if (! $pilot || ! $customerId || self::activeTrial($campaign) !== null
            || ($pilot['permits_conversion_bidding'] ?? null) !== true
            || ($pilot['acknowledges_no_cpc_cap'] ?? null) !== true
            || ! is_int($pilot['max_daily_budget_micros'] ?? null) || $pilot['max_daily_budget_micros'] <= 0
            || ! is_int($pilot['baseline_cost_micros'] ?? null) || $pilot['baseline_cost_micros'] < 0
            || ! is_int($pilot['review_spend_micros'] ?? null) || $pilot['review_spend_micros'] <= 0
            || ! is_string($pilot['currency_code'] ?? null)
            || $pilot['currency_code'] !== strtoupper((string) $campaign->customer->currency_code)
            || ! preg_match('/^[A-Z]{3}$/D', $pilot['currency_code'])
            || ! $campaign->budget_confirmed_at
            || $pilot['max_daily_budget_micros'] > self::moneyToMicros($campaign->approved_daily_budget)
            || ! is_string($pilot['source'] ?? null) || trim($pilot['source']) === ''
            || ! is_string($pilot['approval'] ?? null) || trim($pilot['approval']) === ''
            || ! is_string($pilot['account_timezone'] ?? null)
            || ! in_array($pilot['account_timezone'], timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)
            || ! is_string($pilot['approved_at'] ?? null) || trim($pilot['approved_at']) === ''
            || ! is_string($pilot['starts_at'] ?? null) || trim($pilot['starts_at']) === ''
            || ! is_string($pilot['ends_at'] ?? null) || trim($pilot['ends_at']) === ''
            || ! is_string($pilot['selected_conversion_action'] ?? null)
            || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/conversionActions/[0-9]+$#D', $pilot['selected_conversion_action'])) {
            return false;
        }
        try {
            $approved = CarbonImmutable::parse($pilot['approved_at']);
            $start = CarbonImmutable::parse($pilot['starts_at']);
            $end = CarbonImmutable::parse($pilot['ends_at']);

            return $approved->lte(now()) && $approved->lte($start) && $end->gt($start)
                && $end->lte($start->addDays(14));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function automaticChangesSuspended(Campaign $campaign): bool
    {
        $current = $campaign->exists ? $campaign->fresh() : $campaign;

        return $current === null || $current->spend_safety_hold || self::activeTrial($current) !== null
            || self::aiMaxPilot($current) !== null || self::pendingAiMaxRequest($current) !== null;
    }

    public static function canEnable(Campaign $campaign, bool $ownerApprovedRestart = false): bool
    {
        if ($campaign->spend_safety_hold || $campaign->hasPassedEndDate()
            || in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::PendingAdminDeployment, CampaignStatus::Completed, CampaignStatus::Ended], true)) {
            return false;
        }
        if (self::pendingAiMaxRequest($campaign) !== null && self::aiMaxPilot($campaign) === null) {
            return false;
        }
        if (($pilot = self::aiMaxPilot($campaign)) !== null && (! $ownerApprovedRestart
            || ! self::validAiMaxPilot($campaign)
            || CarbonImmutable::parse($pilot['starts_at'])->gt(now())
            || CarbonImmutable::parse($pilot['ends_at'])->lte(now()))) {
            return false;
        }

        // A handover account is launched by its owner. A stale Active status
        // must not let an unattended sweep switch its paused ads back on.
        if (! $ownerApprovedRestart && $campaign->customer?->service_type === 'setup_only') {
            return false;
        }

        return $campaign->status !== CampaignStatus::Paused || $ownerApprovedRestart;
    }

    public static function permitsDailyBudget(Campaign $campaign, int $micros): bool
    {
        $current = $campaign->exists ? $campaign->fresh() : $campaign;
        if ($current === null || $micros <= 0) {
            return false;
        }
        $trial = self::activeTrial($current);
        $limits = self::automationLimits($current);
        $approved = self::moneyToMicros($current->approved_daily_budget);
        if ($current->budget_confirmed_at && ($approved <= 0 || $micros > $approved)) {
            return false;
        }
        if (($pilot = self::aiMaxPilot($current)) !== null) {
            return self::validAiMaxPilot($current) && ! $current->spend_safety_hold
                && $micros === $pilot['max_daily_budget_micros'];
        }
        if ($limits && (! self::validAutomationLimits($current, $limits) || $micros > $limits['max_daily_budget_micros'])) {
            return false;
        }

        return ! $trial || $micros <= (int) ($trial['max_daily_budget_micros'] ?? 0);
    }

    /** Compatibility at existing decimal/float budget boundaries; limits stay integer micros. */
    public static function permitsBudget(Campaign $campaign, float $amount): bool
    {
        return self::permitsDailyBudget($campaign, self::moneyToMicros($amount));
    }

    public static function moneyToMicros(mixed $amount): int
    {
        $value = (string) $amount;
        if (! preg_match('/^\d+(?:\.\d{1,6})?$/', $value)) {
            return 0;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * 1_000_000 + (int) str_pad($fraction, 6, '0');
    }

    public static function permitsKeywordBid(Campaign $campaign, int $micros): bool
    {
        $current = $campaign->exists ? $campaign->fresh() : $campaign;
        if ($current === null || $micros <= 0) {
            return false;
        }
        if (self::aiMaxPilot($current) !== null) {
            return false; // Conversion bidding does not retain per-keyword CPC limits.
        }
        $trial = self::activeTrial($current);
        $limits = self::automationLimits($current);
        if ($limits && (! self::validAutomationLimits($current, $limits) || $micros > $limits['max_cpc_bid_micros'])) {
            return false;
        }

        return ! $trial || $micros <= (int) ($trial['max_cpc_bid_micros'] ?? 0);
    }

    public static function permitsBidModifier(Campaign $campaign, float $multiplier): bool
    {
        $current = $campaign->exists ? $campaign->fresh() : $campaign;
        if ($current === null || $current->spend_safety_hold || ! is_finite($multiplier) || $multiplier < 0) {
            return false;
        }
        if (self::aiMaxPilot($current) !== null) {
            return false;
        }

        // Maximize Clicks can apply modifiers above its CPC ceiling. During a
        // bounded trial an explicit change may reduce a bid, never amplify it.
        $limits = self::automationLimits($current);
        if ($limits && ! self::validAutomationLimits($current, $limits)) {
            return false;
        }

        return (self::activeTrial($current) === null && $limits === null) || $multiplier <= 1.0;
    }

    public static function permitsBiddingStrategy(Campaign $campaign, string $strategy, ?int $ceiling): bool
    {
        $current = $campaign->exists ? $campaign->fresh() : $campaign;
        if ($current === null) {
            return false;
        }
        $trial = self::activeTrial($current);
        $limits = self::automationLimits($current);
        $strategy = strtoupper(trim($strategy));
        if (($pilot = self::aiMaxPilot($current)) !== null) {
            return self::validAiMaxPilot($current) && ! $current->spend_safety_hold
                && ! $current->hasPassedEndDate() && $strategy === 'MAXIMIZE_CONVERSIONS' && $ceiling === null
                && CarbonImmutable::parse($pilot['starts_at'])->lte(now())
                && CarbonImmutable::parse($pilot['ends_at'])->gt(now());
        }
        if ($limits && (! self::validAutomationLimits($current, $limits)
            || ! in_array($strategy, ['MANUAL_CPC', 'MAXIMIZE_CLICKS'], true)
            || $ceiling === null || ! self::permitsKeywordBid($current, $ceiling))) {
            return false;
        }

        // A target CPA is a bidding objective, not a per-click or total-spend
        // limit. Switching to uncapped Smart Bidding defeats this trial.
        return ! $trial || ($strategy === 'MAXIMIZE_CLICKS'
            && $ceiling !== null && self::permitsKeywordBid($campaign, $ceiling));
    }

    private static function validAutomationLimits(Campaign $campaign, array $limits): bool
    {
        if (! is_int($limits['max_daily_budget_micros'] ?? null) || $limits['max_daily_budget_micros'] <= 0
            || ! is_int($limits['max_cpc_bid_micros'] ?? null) || $limits['max_cpc_bid_micros'] <= 0
            || ! is_string($limits['currency_code'] ?? null) || ! preg_match('/^[A-Z]{3}$/', $limits['currency_code'])
            || $limits['currency_code'] !== strtoupper((string) $campaign->customer?->currency_code)
            || ! $campaign->budget_confirmed_at || self::moneyToMicros($campaign->approved_daily_budget) <= 0
            || ! is_string($limits['approved_at'] ?? null) || trim($limits['approved_at']) === ''
            || ! is_string($limits['source'] ?? null) || trim($limits['source']) === '') {
            return false;
        }

        try {
            return \Carbon\CarbonImmutable::parse($limits['approved_at'])->lessThanOrEqualTo(now());
        } catch (\Throwable) {
            return false;
        }
    }
}
