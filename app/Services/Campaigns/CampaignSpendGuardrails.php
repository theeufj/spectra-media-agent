<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;

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

    public static function automaticChangesSuspended(Campaign $campaign): bool
    {
        $current = $campaign->exists ? $campaign->fresh() : $campaign;

        return $current === null || $current->spend_safety_hold || self::activeTrial($current) !== null;
    }

    public static function canEnable(Campaign $campaign, bool $ownerApprovedRestart = false): bool
    {
        if ($campaign->spend_safety_hold || $campaign->hasPassedEndDate()
            || in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::PendingAdminDeployment, CampaignStatus::Completed, CampaignStatus::Ended], true)) {
            return false;
        }

        return $campaign->status !== CampaignStatus::Paused || $ownerApprovedRestart;
    }

    public static function permitsDailyBudget(Campaign $campaign, int $micros): bool
    {
        $current = $campaign->exists ? $campaign->fresh() : $campaign;
        if ($current === null) {
            return false;
        }
        $trial = self::activeTrial($current);

        return ! $trial || ($micros > 0 && $micros <= (int) ($trial['max_daily_budget_micros'] ?? 0));
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
        if ($current === null) {
            return false;
        }
        $trial = self::activeTrial($current);

        return ! $trial || ($micros > 0 && $micros <= (int) ($trial['max_cpc_bid_micros'] ?? 0));
    }

    public static function permitsBidModifier(Campaign $campaign, float $multiplier): bool
    {
        $current = $campaign->exists ? $campaign->fresh() : $campaign;
        if ($current === null || $current->spend_safety_hold || ! is_finite($multiplier) || $multiplier < 0) {
            return false;
        }

        // Maximize Clicks can apply modifiers above its CPC ceiling. During a
        // bounded trial an explicit change may reduce a bid, never amplify it.
        return self::activeTrial($current) === null || $multiplier <= 1.0;
    }

    public static function permitsBiddingStrategy(Campaign $campaign, string $strategy, ?int $ceiling): bool
    {
        $current = $campaign->exists ? $campaign->fresh() : $campaign;
        if ($current === null) {
            return false;
        }
        $trial = self::activeTrial($current);

        // A target CPA is a bidding objective, not a per-click or total-spend
        // limit. Switching to uncapped Smart Bidding defeats this trial.
        return ! $trial || (strtoupper($strategy) === 'MAXIMIZE_CLICKS'
            && $ceiling !== null && self::permitsKeywordBid($campaign, $ceiling));
    }
}
