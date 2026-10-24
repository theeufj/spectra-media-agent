<?php

namespace App\Services\Deployment;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Strategy;
use Carbon\CarbonImmutable;

/** Read stored deployment and delivery evidence without changing lifecycle state. */
class DeploymentStatusPresenter
{
    /** @return array{deployment_complete: bool, configuration_verification: mixed, delivery_status: ?array{status: string, checked_at: ?string, reason: ?string}} */
    public function present(Campaign $campaign, Strategy $strategy): array
    {
        $metadata = $strategy->execution_result['metadata'] ?? [];
        $configuration = $metadata['configuration_verification'] ?? null;
        $scope = $configuration['scope'] ?? null;
        $checked = $this->date($configuration['checked_at'] ?? null);
        $failed = in_array($strategy->deployment_status, ['failed', 'deploy_unverified'], true)
            || (bool) $strategy->deployment_error || ($configuration['passed'] ?? null) === false;
        $deployed = in_array($strategy->deployment_status, Strategy::DEPLOYED_STATUSES, true);
        $configurationPassed = $deployed && ! $failed && ($configuration['passed'] ?? null) === true
            && ($configuration['issues'] ?? []) === [] && $checked !== null && $checked->lessThanOrEqualTo(now())
            && in_array($scope, ['configuration_and_goals', 'paused_objects'], true)
            && $strategy->deployed_at !== null && $checked->greaterThanOrEqualTo($strategy->deployed_at);
        $complete = ! $failed && ($configurationPassed || $strategy->deployment_status === 'verified' && $configuration === null);
        $delivery = ['status' => 'unknown', 'checked_at' => null, 'reason' => 'Current delivery has not been confirmed.'];

        if (! str_contains(strtolower($strategy->platform ?? ''), 'google')) {
            return ['deployment_complete' => $complete, 'configuration_verification' => $configuration, 'delivery_status' => null];
        }

        if ($failed) {
            $delivery['status'] = 'needs_review';
            $delivery['reason'] = 'Deployment configuration needs review.';
        } elseif ($campaign->customer?->service_type === 'setup_only' || $campaign->status === CampaignStatus::Paused) {
            $delivery['status'] = 'paused';
            $delivery['reason'] = 'This campaign is paused.';
        } else {
            $delivery = $this->googleDelivery($campaign, $strategy, $metadata, $configurationPassed && $scope === 'configuration_and_goals');
        }

        return ['deployment_complete' => $complete, 'configuration_verification' => $configuration, 'delivery_status' => $delivery];
    }

    /** @param array<string, mixed> $metadata
     * @return array{status: string, checked_at: ?string, reason: ?string}
     */
    private function googleDelivery(Campaign $campaign, Strategy $strategy, array $metadata, bool $configurationPassed): array
    {
        $unknown = ['status' => 'unknown', 'checked_at' => null, 'reason' => 'Current Google delivery has not been confirmed.'];
        $account = $campaign->customer?->cleanGoogleCustomerId();
        $id = $strategy->google_ads_campaign_id ?: ($strategy->execution_result['platform_ids']['campaign'] ?? $strategy->reusableGoogleCampaignId());
        $resource = is_string($id) && ctype_digit($id) ? "customers/{$account}/campaigns/{$id}" : $id;
        // Parent monitoring and policy checks cover its primary Google object,
        // never every Google strategy belonging to the same campaign.
        if (! $account || ! is_string($resource) || ! preg_match('#^customers/'.preg_quote($account, '#').'/campaigns/\d+$#D', $resource)
            || $resource !== $campaign->googleAdsResourceName()) {
            return $unknown;
        }

        if ($campaign->platform_status === 'PAUSED') {
            return ['status' => 'paused', 'checked_at' => $campaign->last_checked_at?->toIso8601String(), 'reason' => 'This campaign is paused.'];
        }
        if (! $configurationPassed) {
            return $unknown;
        }

        $activation = $metadata['search_reach_activation'] ?? [];
        if (isset($activation['campaign_resource']) && $activation['campaign_resource'] !== $resource) {
            return $unknown;
        }
        $today = now($campaign->customer->timezone ?: config('app.timezone'))->toDateString();
        if ($campaign->status !== CampaignStatus::Active || $campaign->spend_safety_hold
            || $campaign->hasPassedEndDate() || $campaign->start_date->toDateString() > $today
            || ! $this->fresh($campaign->last_checked_at?->toIso8601String())) {
            return $unknown;
        }

        $policy = $campaign->policy_checks['platforms']['google_ads'] ?? [];
        $checkedAt = $policy['checked_at'] ?? null;
        if (($policy['issues'] ?? []) !== []) {
            return ['status' => 'needs_review', 'checked_at' => is_string($checkedAt) ? $checkedAt : null, 'reason' => 'Google policy restrictions need review.'];
        }
        if ($this->fresh($checkedAt) && $campaign->platform_status === 'ENABLED' && $campaign->primary_status === 'PENDING'
            && (in_array('MOST_ADS_UNDER_REVIEW', $campaign->primary_status_reasons ?? [], true)
                || ($policy['error'] ?? null) === 'The current ads are still awaiting review. Approval has not been confirmed.')) {
            return ['status' => 'under_review', 'checked_at' => $campaign->last_checked_at?->toIso8601String(), 'reason' => 'Google review is pending.'];
        }
        if (! $this->fresh($checkedAt) || ($policy['status'] ?? null) !== 'clear' || ! empty($policy['error'])) {
            return $unknown;
        }
        // A later goal/configuration check can invalidate an earlier successful
        // verification. Ad-strength quality itself is deliberately independent.
        $goal = $metadata['conversion_goal_readiness'] ?? [];
        if (($goal['ready'] ?? null) === false && $this->date($goal['checked_at'] ?? null)?->greaterThanOrEqualTo($this->date($metadata['configuration_verification']['checked_at'] ?? null))) {
            return ['status' => ($goal['status'] ?? null) === 'unknown' ? 'unknown' : 'needs_review',
                'checked_at' => $goal['checked_at'], 'reason' => 'Current conversion-goal settings need review.'];
        }
        if ($campaign->platform_status === 'ENABLED' && $campaign->isServing()) {
            return ['status' => 'live', 'checked_at' => $campaign->last_checked_at?->toIso8601String(), 'reason' => null];
        }

        return $unknown;
    }

    private function fresh(mixed $value): bool
    {
        $date = $this->date($value);

        return $date !== null && $date->lessThanOrEqualTo(now())
            && $date->greaterThanOrEqualTo(now()->subHours((int) config('optimization.search_delivery.stale_after_hours', 3)));
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
