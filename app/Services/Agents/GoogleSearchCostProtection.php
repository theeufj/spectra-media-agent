<?php

namespace App\Services\Agents;

use App\Enums\CampaignStatus;
use App\Features\AutoHealing;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Resources\AdGroup;
use Google\Ads\GoogleAds\V26\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V26\Services\AdGroupCriterionOperation;
use Google\Ads\GoogleAds\V26\Services\AdGroupOperation;
use Google\Ads\GoogleAds\V26\Services\MutateGoogleAdsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateOperation;
use Google\Protobuf\FieldMask;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/** Cost containment only: never buys additional reach, enables an entity or raises a budget. */
class GoogleSearchCostProtection
{
    public function check(Campaign $campaign): array
    {
        $state = ['checked_at' => now()->toIso8601String(), 'status' => 'not_applicable', 'actions_taken' => 0];
        $attempted = false;
        try {
            $campaign = $campaign->fresh(['customer']) ?? $campaign;
            $previous = $campaign->search_delivery_state['cost_protection'] ?? [];
            if (! empty($previous['mutation_started_at']) && empty($previous['verified_at'])) {
                // Permission/profile failures must not erase an unknown remote outcome.
                $state = [...$previous, ...$state];
            }
            if (! $this->authorized($campaign)) {
                return $this->save($campaign, $state + ['reason' => 'automatic_cost_changes_not_authorized']);
            }
            $snapshot = $this->inspect($campaign);
            if ($snapshot && (($snapshot['channel'] ?? null) !== 2
                || ($snapshot['bidding_strategy'] ?? null) !== BiddingStrategyType::MANUAL_CPC)) {
                return $this->save($campaign, $state + ['reason' => 'not_manual_cpc_search']);
            }
            if (! $this->supported($snapshot)) {
                return $this->save($campaign, [...$state, 'status' => 'needs_review', 'reason' => 'live_cost_settings_unverified']);
            }
            $budget = $snapshot['daily_budget_micros'];
            $approved = CampaignSpendGuardrails::moneyToMicros($campaign->approved_daily_budget);
            if (! $campaign->budget_confirmed_at || $approved <= 0 || $budget > $approved) {
                return $this->save($campaign, [...$state, 'status' => 'needs_review', 'reason' => 'approved_budget_unverified_or_exceeded']);
            }
            $metrics = $snapshot['auction'] ?? [];
            $clicks = (int) ($metrics['clicks'] ?? 0);
            $cost = (int) ($metrics['cost_micros'] ?? 0);
            $affordable = intdiv($approved, max(1, (int) config('optimization.search_cost_protection.min_clicks_per_day', 5)));
            $expensive = $clicks >= max(1, (int) config('optimization.search_cost_protection.min_observed_clicks', 1))
                && $cost > $clicks * $affordable;
            $state['observed'] = ['clicks' => $clicks, 'cost_micros' => $cost,
                'target_cpc_micros' => $affordable, 'expensive_clicks' => $expensive,
                'note' => 'Observed CPC is a cost signal, not evidence that conversion tracking or an individual query is bad.'];
            $limits = CampaignSpendGuardrails::automationLimits($campaign);
            if (! $limits) {
                return $this->save($campaign, [...$state, 'status' => $expensive ? 'needs_review' : 'observing',
                    'reason' => $expensive ? 'approved_automation_cpc_limit_required' : 'no_approved_cost_profile']);
            }
            $cap = $limits['max_cpc_bid_micros'] ?? null;
            if (! is_int($cap) || $cap <= 0 || ($limits['currency_code'] ?? null) !== ($snapshot['currency_code'] ?? null)
                || ! CampaignSpendGuardrails::permitsKeywordBid($campaign, $cap)
                || ! CampaignSpendGuardrails::permitsDailyBudget($campaign, $budget)) {
                return $this->save($campaign, [...$state, 'status' => 'needs_review', 'reason' => 'approved_cost_profile_invalid']);
            }
            $state['approved_cpc_limit_micros'] = $cap;
            $changes = $this->changes($campaign, $snapshot, $cap);
            $pending = ! empty($previous['mutation_started_at']) && empty($previous['verified_at']);
            if ($pending) {
                // An ambiguous write is read back, never blindly replayed by queue retry.
                if (($previous['attempt_cpc_limit_micros'] ?? $previous['approved_cpc_limit_micros'] ?? null) !== $cap) {
                    return $this->save($campaign, [...$previous, ...$state, 'status' => 'needs_review',
                        'attempt_cpc_limit_micros' => $previous['attempt_cpc_limit_micros'] ?? $previous['approved_cpc_limit_micros'],
                        'reason' => 'approved_cost_limit_changed_after_unknown_write']);
                }

                return $this->save($campaign, [...$previous, ...$state,
                    'status' => $changes === [] ? 'verified' : 'needs_review',
                    'reason' => $changes === [] ? 'previous_reduction_verified' : 'previous_reduction_outcome_unverified',
                    ...($changes === [] ? ['verified_at' => now()->toIso8601String(), 'limit_verified' => true] : [])]);
            }
            if ($changes === []) {
                return $this->save($campaign, [...$state, 'status' => $expensive ? 'needs_review' : 'verified',
                    'reason' => $expensive ? 'reported_cpc_above_affordability_target' : 'bids_within_approved_limit', 'limit_verified' => true,
                    'forecast_required_before_expansion' => true]);
            }
            $before = GoogleSearchReachGate::settingsFingerprint($snapshot);
            $state = [...$state, 'status' => 'validating', 'changes' => $changes, 'before_fingerprint' => $before, 'attempt_cpc_limit_micros' => $cap,
                'forecast_required_before_expansion' => true];
            $this->save($campaign, $state);
            $this->mutate($campaign, $changes, true);
            $current = $campaign->fresh(['customer']);
            $fresh = $current ? $this->inspect($current) : null;
            $current = $current?->fresh(['customer']); // Local permissions can change while the final provider read is in flight.
            if (! $current || ! $this->authorized($current) || ! $this->supported($fresh)
                || GoogleSearchReachGate::settingsFingerprint($fresh) !== $before
                || CampaignSpendGuardrails::automationLimits($current) !== $limits
                || ! CampaignSpendGuardrails::permitsKeywordBid($current, $cap)
                || ! CampaignSpendGuardrails::permitsDailyBudget($current, $budget)) {
                return $this->save($campaign, [...$state, 'status' => 'needs_review', 'reason' => 'cost_settings_changed_before_write']);
            }
            $state = [...$state, 'status' => 'applying', 'mutation_started_at' => now()->toIso8601String()];
            $this->save($campaign, $state);
            $attempted = true;
            $this->mutate($current, $changes, false);
            $after = $this->inspect($current);
            if (! $this->supported($after) || ! $this->readbackMatches($snapshot, $after, $changes, $cap)) {
                return $this->save($campaign, [...$state, 'status' => 'unverified', 'reason' => 'cost_reduction_readback_unverified']);
            }
            $state = [...$state, 'status' => 'verified', 'reason' => 'approved_bid_limit_restored',
                'actions_taken' => count($changes), 'verified_at' => now()->toIso8601String(), 'limit_verified' => true];
            AgentActivity::record('search_cost_protection', 'approved_cpc_limit_restored',
                'Lowered Google Search bids to their approved CPC limit; campaign status and budget were preserved.',
                $campaign->customer_id, $campaign->id, $state);

            return $this->save($campaign, $state);
        } catch (\Throwable $e) {
            report($e);

            return $this->save($campaign, [...$state, 'status' => $attempted ? 'unverified' : 'needs_review',
                'reason' => $attempted ? 'cost_reduction_outcome_unknown' : 'cost_check_unavailable',
                'errors' => [(new AgentIssue('search_cost_check_failed', 'Google Search cost protection could not verify its result.'))->toArray()]]);
        }
    }

    private function authorized(Campaign $campaign): bool
    {
        return $campaign->exists && $campaign->customer?->service_type === 'managed'
            && $campaign->customer->google_ads_link_status !== 'revoked'
            && ! $campaign->customer->is_sandbox && $campaign->status === CampaignStatus::Active
            && $campaign->googleCampaignNumericId() && $campaign->customer->cleanGoogleCustomerId()
            && ! $campaign->spend_safety_hold && ! $campaign->hasPassedEndDate()
            && CampaignSpendGuardrails::activeTrial($campaign) === null
            && CampaignSpendGuardrails::aiMaxPilot($campaign) === null
            && CampaignSpendGuardrails::pendingAiMaxRequest($campaign) === null
            && Feature::for($campaign->customer)->active(AutoHealing::class);
    }

    private function supported(?array $snapshot): bool
    {
        if (! $snapshot || ($snapshot['status'] ?? null) !== 2 || ($snapshot['channel'] ?? null) !== 2
            || ($snapshot['bidding_strategy'] ?? null) !== BiddingStrategyType::MANUAL_CPC
            || ($snapshot['portfolio_bidding_strategy'] ?? '') !== ''
            || ($snapshot['account_time_zone_verified'] ?? null) !== true
            || ! preg_match('/^[A-Z]{3}$/', (string) ($snapshot['currency_code'] ?? ''))
            || ! in_array($snapshot['time_zone'] ?? null, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)
            || ! is_int($snapshot['daily_budget_micros'] ?? null) || $snapshot['daily_budget_micros'] <= 0
            || ! array_key_exists('unsupported_targeting', $snapshot) || $snapshot['unsupported_targeting'] !== []
            || ! array_key_exists('device_bid_modifiers', $snapshot)) {
            return false;
        }
        foreach ($snapshot['device_bid_modifiers'] as $modifier) {
            if (! isset($modifier['bid_modifier']) || ! is_numeric($modifier['bid_modifier'])
                || $modifier['bid_modifier'] < 0 || $modifier['bid_modifier'] > 1) {
                return false;
            }
        }

        return ! empty($snapshot['keywords']);
    }

    /** @return array<int, array{type: string, resource_name: string, before_micros: int, bid_micros: int}> */
    private function changes(Campaign $campaign, array $snapshot, int $cap): array
    {
        $customer = preg_quote($campaign->customer->cleanGoogleCustomerId(), '~');
        $changes = [];
        foreach ($snapshot['keywords'] as $keyword) {
            if (($keyword['ad_group_status'] ?? null) !== 'ENABLED') {
                continue;
            }
            $group = $keyword['ad_group_resource'] ?? '';
            $resource = $keyword['resource_name'] ?? '';
            if (! preg_match('#^customers/'.$customer.'/adGroups/(\d+)$#', $group, $groupMatch)
                || ! preg_match('#^customers/'.$customer.'/adGroupCriteria/'.preg_quote($groupMatch[1], '#').'~\d+$#', $resource)) {
                throw new \RuntimeException('Search keyword ownership is unverified.');
            }
            $groupBid = $keyword['ad_group_cpc_bid_micros'] ?? null;
            $explicit = $keyword['keyword_cpc_bid_micros'] ?? null;
            if (! is_int($groupBid) || $groupBid <= 0 || ! is_int($explicit) || $explicit < 0) {
                throw new \RuntimeException('Effective Search bids are unverified.');
            }
            if ($groupBid > $cap) {
                $changes[$group] = ['type' => 'ad_group', 'resource_name' => $group, 'before_micros' => $groupBid, 'bid_micros' => $cap];
            }
            if ($explicit > $cap) {
                $changes[$resource] = ['type' => 'keyword', 'resource_name' => $resource, 'before_micros' => $explicit, 'bid_micros' => $cap];
            }
        }

        return array_values($changes);
    }

    private function readbackMatches(array $before, array $after, array $changes, int $cap): bool
    {
        $expected = $before;
        foreach ($expected['keywords'] as &$keyword) {
            foreach ($changes as $change) {
                if ($change['type'] === 'ad_group' && ($keyword['ad_group_resource'] ?? null) === $change['resource_name']) {
                    $keyword['ad_group_cpc_bid_micros'] = $cap;
                }
                if ($change['type'] === 'keyword' && ($keyword['resource_name'] ?? null) === $change['resource_name']) {
                    $keyword['keyword_cpc_bid_micros'] = $cap;
                }
            }
            $keyword['cpc_bid_micros'] = $keyword['keyword_cpc_bid_micros'] ?: $keyword['ad_group_cpc_bid_micros'];
        }
        unset($keyword);
        $active = array_filter($expected['keywords'], fn ($row) => ($row['status'] ?? null) === 'ENABLED' && ($row['ad_group_status'] ?? null) === 'ENABLED');
        $expected['actual_cpc_ceiling_micros'] = $expected['cpc_bid_ceiling_micros'] = max(array_column($active, 'cpc_bid_micros') ?: [0]);
        if (GoogleSearchReachGate::settingsFingerprint($expected) !== GoogleSearchReachGate::settingsFingerprint($after)) {
            return false;
        }
        foreach ($after['keywords'] as $row) {
            foreach ($changes as $change) {
                if ($change['type'] === 'ad_group' && ($row['ad_group_resource'] ?? null) === $change['resource_name']
                    && ($row['ad_group_cpc_bid_micros'] ?? null) !== $cap) {
                    return false;
                }
                if ($change['type'] === 'keyword' && ($row['resource_name'] ?? null) === $change['resource_name']
                    && ($row['keyword_cpc_bid_micros'] ?? null) !== $cap) {
                    return false;
                }
            }
        }

        return true;
    }

    private function save(Campaign $campaign, array $state): array
    {
        DB::transaction(function () use ($campaign, $state): void {
            $locked = Campaign::withoutCustomerScope()->whereKey($campaign->id)->lockForUpdate()->first();
            if ($locked) {
                $locked->update(['search_delivery_state' => [...($locked->search_delivery_state ?? []), 'cost_protection' => $state]]);
            }
        });

        return $state;
    }

    protected function inspect(Campaign $campaign): ?array
    {
        return app(InspectSearchDelivery::class, ['customer' => $campaign->customer])->inspect($campaign);
    }

    protected function mutate(Campaign $campaign, array $changes, bool $validateOnly): void
    {
        $client = app(GetCampaignStatus::class, ['customer' => $campaign->customer])->getClient();
        if (! $client) {
            throw new \RuntimeException('Google Ads client is unavailable.');
        }
        $operations = [];
        foreach ($changes as $change) {
            $mask = new FieldMask(['paths' => ['cpc_bid_micros']]);
            $values = ['resource_name' => $change['resource_name'], 'cpc_bid_micros' => $change['bid_micros']];
            $operations[] = $change['type'] === 'ad_group'
                ? new MutateOperation(['ad_group_operation' => new AdGroupOperation(['update' => new AdGroup($values), 'update_mask' => $mask])])
                : new MutateOperation(['ad_group_criterion_operation' => new AdGroupCriterionOperation(['update' => new AdGroupCriterion($values), 'update_mask' => $mask])]);
        }
        $response = $client->getGoogleAdsServiceClient()->mutate(new MutateGoogleAdsRequest([
            'customer_id' => $campaign->customer->cleanGoogleCustomerId(), 'mutate_operations' => $operations,
            'partial_failure' => false, 'validate_only' => $validateOnly,
        ]), ['retrySettings' => ['retriesEnabled' => false, 'noRetriesRpcTimeoutMillis' => 30000]]);
        if ($response->hasPartialFailureError()) {
            throw new \RuntimeException('Google did not confirm the complete cost reduction.');
        }
    }
}
