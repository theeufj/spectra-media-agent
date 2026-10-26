<?php

namespace App\Services\Notifications;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Strategy;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Routine readiness mail is actionable only for a currently managed, intended-live campaign. */
class GoogleReadinessAlertEligibility
{
    public static function allows(array $details, ?object $notifiable = null): bool
    {
        $campaignId = self::positiveId($details['campaign_id'] ?? null);
        $strategyId = self::positiveId($details['strategy_id'] ?? null);
        $customerId = self::positiveId($details['customer_id'] ?? null);
        if ($campaignId === null || $strategyId === null || $customerId === null) {
            return false;
        }
        // Queued mail has no tenant session. Deliberate cross-tenant access is
        // constrained by all three recorded identities and the recipient policy.
        $campaign = Campaign::withoutCustomerScope()->with('customer')->find($campaignId);
        if (! $campaign instanceof Campaign) {
            return false;
        }
        $strategy = $campaign->strategies()->find($strategyId);
        $customer = $campaign->customer;
        if (! $strategy instanceof Strategy || ! $customer instanceof Customer || $customer->id !== $customerId
            || $campaign->status !== CampaignStatus::Active || $campaign->hasPassedEndDate()
            || $campaign->spend_safety_hold || ($campaign->getRawOriginal('start_date') !== null && $campaign->start_date->isFuture())
            || in_array(strtoupper((string) $campaign->platform_status), ['PAUSED', 'REMOVED', 'ENDED', 'DISABLED'], true)
            || $customer->is_sandbox || $customer->service_type === 'setup_only'
            || ! $customer->google_ads_customer_id
            || in_array($customer->google_ads_link_status, ['pending', 'refused', 'cancelled', 'failed', 'revoked'], true)
            || ! $strategy->signed_off_at || in_array($strategy->deployment_status, ['queued', 'deploying'], true)
            || ! EnabledPlatform::where('slug', 'google')->where('is_enabled', true)->exists()) {
            return false;
        }
        if ($notifiable !== null) {
            // Use the registered ownership policy, including current pivot and
            // admin access, rather than retaining a queued recipient's old access.
            $recipient = $notifiable instanceof User ? $notifiable->fresh() : null;
            if (! $recipient || ! $recipient->can('view', $campaign)) {
                return false;
            }
        }
        $resource = $strategy->reusableGoogleCampaignId();
        $resource = $resource && ctype_digit($resource)
            ? 'customers/'.$customer->cleanGoogleCustomerId().'/campaigns/'.$resource : $resource;
        if (! is_string($resource) || ! preg_match('#^customers/'.preg_quote($customer->cleanGoogleCustomerId(), '#').'/campaigns/\d+$#D', $resource)
            || isset($details['campaign_resource']) && $details['campaign_resource'] !== $resource) {
            return false;
        }
        $snapshot = $strategy->execution_result['metadata']['google_readiness'] ?? [];
        if (! is_array($snapshot) || ! is_array($snapshot['issues'] ?? null) || ! is_array($details['issues'] ?? null)
            || ($snapshot['campaign_resource'] ?? null) !== $resource || ! self::currentObservation($snapshot)
            || self::knownInactiveObservation($snapshot)) {
            return false;
        }
        $current = array_filter(array_map(self::issueIdentity(...), $snapshot['issues']));
        $reported = array_filter(array_map(self::issueIdentity(...), $details['issues']));

        // A pause, a resolved check, or a newer issue set must not turn an old
        // queued observation into a new demand for customer action.
        return array_intersect($current, $reported) !== [];
    }

    private static function knownInactiveObservation(array $snapshot): bool
    {
        if (in_array($snapshot['skip_reason'] ?? null, ['campaign_not_active', 'campaign_ended', 'google_campaign_paused',
            'google_campaign_removed', 'google_campaign_not_enabled', 'google_disabled', 'spend_safety_hold'], true)
            || ($snapshot['ad_strength']['skipped'] ?? null) === 'google_campaign_not_enabled'
            || ($snapshot['audience_observation']['reason'] ?? null) === 'google_campaign_inactive') {
            return true;
        }
        foreach ([...($snapshot['ad_strength']['unresolved'] ?? []), ...($snapshot['ad_strength']['mutation_holds'] ?? [])] as $row) {
            if (is_array($row) && in_array($row['reason'] ?? null, ['google_campaign_paused', 'google_campaign_removed', 'google_campaign_not_enabled'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function currentObservation(array $snapshot): bool
    {
        try {
            $checkedAt = $snapshot['checked_at'] ?? null;
            if (! is_string($checkedAt) || $checkedAt === '') {
                return false;
            }
            $checked = CarbonImmutable::parse($checkedAt);
            $now = CarbonImmutable::instance(now());

            return $checked->lessThanOrEqualTo($now) && $checked->greaterThanOrEqualTo($now->subDay());
        } catch (\Throwable) {
            return false;
        }
    }

    private static function issueIdentity(mixed $issue): string
    {
        return is_array($issue) && is_string($issue['code'] ?? null) && is_string($issue['message'] ?? null)
            ? $issue['code'].':'.$issue['message'] : '';
    }

    private static function positiveId(mixed $value): ?int
    {
        return (is_int($value) && $value > 0) || (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value)) ? (int) $value : null;
    }
}
