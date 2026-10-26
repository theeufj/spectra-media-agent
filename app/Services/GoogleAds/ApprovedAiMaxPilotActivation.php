<?php

namespace App\Services\GoogleAds;

use App\Enums\CampaignStatus;
use App\Jobs\ActivateApprovedAiMaxPilot;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\Agents\GoogleSearchReachGate;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\Campaigns\GoogleCampaignSpendSafety;
use App\Services\GoogleAds\CommonServices\GetCampaignSpendSafety;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The owner's immutable request outlives a provider propagation delay, not a changed approval. */
class ApprovedAiMaxPilotActivation
{
    public function request(Campaign $campaign, Strategy $strategy, string $approval, int $dailyBudgetMicros = 50_000_000,
        int $reviewSpendMicros = 200_000_000, int $days = 14): array
    {
        if (trim($approval) === '' || min($dailyBudgetMicros, $reviewSpendMicros, $days) <= 0 || $days > 14) {
            throw new \InvalidArgumentException('An owner approval, positive reviewed limits and at most fourteen days are required.');
        }

        return Cache::lock('ai-max-owner-request:'.$campaign->id, 900)->block(5, function () use ($campaign, $strategy, $approval, $dailyBudgetMicros, $reviewSpendMicros, $days): array {
            [$fresh, $selected] = $this->scope($campaign->id, $strategy->id, CampaignStatus::Active);
            $existing = $fresh->search_delivery_state['ai_max_request'] ?? null;
            if (is_array($existing) && ($existing['enabled'] ?? false) === true) {
                if (($existing['status'] ?? null) === 'waiting_for_conversion'
                    && ($existing['approval'] ?? null) === $approval && ($existing['daily_budget_micros'] ?? null) === $dailyBudgetMicros
                    && ($existing['review_spend_micros'] ?? null) === $reviewSpendMicros && ($existing['days'] ?? null) === $days
                    && $this->validRequest($existing)) {
                    return $existing;
                }
                throw new \RuntimeException('An existing AI Max owner request must be resolved before creating another.');
            }
            if (CampaignSpendGuardrails::aiMaxPilot($fresh) !== null || CampaignSpendGuardrails::activeTrial($fresh) !== null) {
                throw new \RuntimeException('An existing pilot or bounded trial cannot be replaced by this request.');
            }
            $snapshot = $this->snapshot($fresh);
            $this->legacySnapshot($fresh, $snapshot, $dailyBudgetMicros);
            $request = ['enabled' => true, 'nonce' => (string) Str::uuid(), 'status' => 'waiting_for_conversion',
                'customer_id' => $fresh->customer_id, 'google_customer_id' => $fresh->customer->cleanGoogleCustomerId(),
                'campaign_resource' => $fresh->googleAdsResourceName(), 'strategy_id' => $selected->id,
                'approval' => $approval, 'approved_at' => now()->toIso8601String(), 'expires_at' => now()->addDay()->toIso8601String(),
                'daily_budget_micros' => $dailyBudgetMicros, 'review_spend_micros' => $reviewSpendMicros, 'days' => $days,
                'landing_page' => $fresh->landing_page_url, 'geo_target_constants' => $snapshot['geo_target_constants'] ?? [],
                'currency_code' => $snapshot['currency_code'], 'account_timezone' => $snapshot['time_zone'],
                'initial_local_fingerprint' => $this->localFingerprint($fresh, $selected),
                'initial_remote_fingerprint' => GoogleSearchReachGate::settingsFingerprint($snapshot),
                'initial_goal_fingerprint' => $this->digest($this->goalConfiguration($fresh)),
                'original_conversion_goals' => $selected->conversion_goals,
                'original_bidding_strategy' => $selected->bidding_strategy,
                'original_end_date' => $fresh->getRawOriginal('end_date'),
                'requested_conversion_action' => ProvisionPaidSetupConversion::NAME,
                'automatic_replay_allowed' => false];
            $request['approval_hash'] = $this->approvalHash($request);
            DB::transaction(function () use ($fresh, $selected, $request): void {
                $locked = Campaign::withoutCustomerScope()->whereKey($fresh->id)->lockForUpdate()->firstOrFail();
                if (! $locked instanceof Campaign) {
                    throw new \RuntimeException('The campaign is no longer available.');
                }
                $strategy = $locked->strategies()->whereKey($selected->id)->firstOrFail();
                if ($locked->status !== CampaignStatus::Active || $locked->spend_safety_hold
                    || $this->localFingerprint($locked, $strategy) !== $request['initial_local_fingerprint']
                    || ! empty($locked->search_delivery_state['ai_max_request']['enabled'])) {
                    throw new \RuntimeException('The campaign changed before its owner request could be recorded.');
                }
                $locked->update(['search_delivery_state' => [...($locked->search_delivery_state ?? []), 'ai_max_request' => $request]]);
                ActivateApprovedAiMaxPilot::dispatch((int) $locked->id, $request['nonce'])->afterCommit();
            });

            return $request;
        });
    }

    public function advance(int $campaignId, string $nonce): array
    {
        $lock = Cache::lock('ai-max-owner-request:'.$campaignId, 900);
        if (! $lock->get()) {
            return ['status' => 'busy', 'retry_after_seconds' => 60, 'actions_taken' => 0];
        }
        try {
            $campaign = Campaign::withoutCustomerScope()->with('customer')->find($campaignId);
            $request = $campaign instanceof Campaign ? ($campaign->search_delivery_state['ai_max_request'] ?? null) : null;
            if (! $campaign instanceof Campaign || ! is_array($request) || ($request['nonce'] ?? null) !== $nonce) {
                return ['status' => 'revoked', 'actions_taken' => 0, 'message' => 'The owner request no longer exists.'];
            }
            if (in_array($request['status'] ?? null, ['active', 'completed', 'cancelled', 'revoked', 'needs_review'], true)) {
                return ['status' => $request['status'], 'actions_taken' => 0, 'historical_result_only' => true];
            }
            if (! $this->validRequest($request)) {
                return $this->review($campaignId, $nonce, 'The owner request expired or its immutable approval changed.');
            }
            if (! empty($request['pause_started_at'])) {
                return $this->review($campaignId, $nonce, 'An earlier activation attempt has an unresolved write journal.');
            }
            try {
                [$campaign, $strategy] = $this->current($campaignId, $request, CampaignStatus::Active, true);
                $this->unchangedRemote($campaign, $request);
            } catch (\Throwable $e) {
                return $this->revoke($campaignId, $nonce, 'The campaign was paused, held or changed while awaiting Google.');
            }
            $provision = $this->provision($campaign);
            [$campaign, $strategy] = $this->current($campaignId, $request, CampaignStatus::Active, true);
            $this->unchangedRemote($campaign, $request);
            $resource = $provision['resource'] ?? null;
            if (! is_string($resource) || ! preg_match('#^customers/'.preg_quote($request['google_customer_id'], '#').'/conversionActions/[0-9]+$#D', $resource)) {
                throw new \RuntimeException('Google did not identify the dedicated setup purchase action.');
            }
            if (isset($request['selected_conversion_action']) && $request['selected_conversion_action'] !== $resource) {
                throw new \RuntimeException('The dedicated purchase destination changed during the owner request.');
            }
            if (($provision['status'] ?? null) === 'awaiting_delivery_validation' && ($provision['delivery_validated'] ?? null) === false) {
                $this->save($campaignId, $nonce, ['last_checked_at' => now()->toIso8601String(),
                    'selected_conversion_action' => $resource, 'provider_status' => 'awaiting_delivery_validation']);

                return ['status' => 'awaiting_delivery_validation', 'retry_after_seconds' => 1800, 'actions_taken' => 0,
                    'message' => 'Google has not propagated the new conversion destination yet. Existing campaign spending settings are preserved.'];
            }
            if (($provision['status'] ?? null) !== 'ready' || ($provision['delivery_validated'] ?? null) !== true) {
                throw new \RuntimeException('The purchase action did not pass provider delivery validation.');
            }
            $request = $this->save($campaignId, $nonce, ['status' => 'pausing', 'selected_conversion_action' => $resource,
                'provider_status' => 'ready', 'pause_started_at' => now()->toIso8601String()]);
            [$campaign] = $this->current($campaignId, $request, CampaignStatus::Active, true);
            $this->pause($campaign);
            [$campaign] = $this->current($campaignId, $request, CampaignStatus::Active, true);
            $paused = $this->snapshot($campaign);
            $pausedComparable = $paused;
            $pausedComparable['status'] = 2;
            if (($paused['status'] ?? null) !== 3
                || GoogleSearchReachGate::settingsFingerprint($pausedComparable) !== $request['initial_remote_fingerprint']
                || $this->digest($this->goalConfiguration($campaign)) !== $request['initial_goal_fingerprint']) {
                throw new \RuntimeException('Google did not confirm only the requested campaign pause.');
            }
            $this->setPaused($campaignId, $nonce);
            $request = $this->save($campaignId, $nonce, ['status' => 'paused', 'pause_verified_at' => now()->toIso8601String()]);
            [$campaign, $strategy] = $this->current($campaignId, $request, CampaignStatus::Paused);
            $this->verifiedSpend($campaign, $request);
            $goals = ['primary_goal' => 'PURCHASE', 'selection_mode' => 'exact_action', 'action_resource' => $resource, 'origin' => 'WEBSITE'];
            $request = $this->pinGoal($campaignId, $nonce, $request, $goals);
            [$campaign, $strategy] = $this->current($campaignId, $request, CampaignStatus::Paused);
            $goal = $this->reconcileGoal($campaign, $strategy, $nonce);
            if (($goal['ready'] ?? null) !== true || ($goal['intent']['action_resource'] ?? null) !== $resource) {
                throw new \RuntimeException('Google did not verify the campaign-specific setup purchase goal.');
            }
            $request = $this->save($campaignId, $nonce, ['status' => 'goals_ready', 'goals_verified_at' => now()->toIso8601String()]);
            [$campaign] = $this->current($campaignId, $request, CampaignStatus::Paused);
            $spend = $this->verifiedSpend($campaign, $request);
            $starts = CarbonImmutable::now($request['account_timezone'])->startOfSecond();
            $pilot = ['enabled' => true, 'approval' => $request['approval'], 'source' => 'owner_approved_deferred_ai_max',
                'approved_at' => $request['approved_at'], 'starts_at' => $starts->toIso8601String(),
                // Search campaign end timestamps must be the end of an account day.
                // Count the partial activation date as day one; never extend beyond the approval.
                'ends_at' => $starts->addDays($request['days'] - 1)->endOfDay()->startOfSecond()->toIso8601String(),
                'currency_code' => $request['currency_code'], 'account_timezone' => $request['account_timezone'],
                'max_daily_budget_micros' => $request['daily_budget_micros'], 'review_spend_micros' => $request['review_spend_micros'],
                'baseline_cost_micros' => $spend['cost_micros'], 'selected_conversion_action' => $resource,
                'permits_conversion_bidding' => true, 'acknowledges_no_cpc_cap' => true,
                'strategy_id' => $request['strategy_id'], 'landing_page' => $request['landing_page'],
                'geo_target_constants' => $request['geo_target_constants'], 'owner_request_nonce' => $nonce];
            $request = $this->save($campaignId, $nonce, ['status' => 'preparing', 'pilot' => $pilot]);
            [$campaign] = $this->current($campaignId, $request, CampaignStatus::Paused);
            $prepared = $this->preparePilot($campaign, $pilot);
            if (($prepared['status'] ?? null) !== 'prepared' || ($prepared['ready'] ?? null) !== true) {
                throw new \RuntimeException('The approved AI Max configuration did not complete verified preparation.');
            }
            $request = $this->save($campaignId, $nonce, ['status' => 'prepared', 'prepared_at' => now()->toIso8601String()]);
            [$campaign] = $this->current($campaignId, $request, CampaignStatus::Paused);
            $request = $this->save($campaignId, $nonce, ['status' => 'activating', 'activation_started_at' => now()->toIso8601String()]);
            [$campaign] = $this->current($campaignId, $request, CampaignStatus::Paused);
            $active = $this->activatePilot($campaign);
            if (($active['status'] ?? null) !== 'active' || ($active['ready'] ?? null) !== true) {
                throw new \RuntimeException('Google has not confirmed the approved AI Max pilot activation.');
            }
            $this->current($campaignId, $request, CampaignStatus::Active);
            $this->save($campaignId, $nonce, ['status' => 'active', 'activated_at' => now()->toIso8601String(),
                'message' => 'Google confirmed the owner-approved AI Max purchase pilot.']);
            AgentActivity::record('google_ai_max', 'ai_max_owner_request_completed',
                'Completed the approved AI Max activation after Google validated the dedicated setup purchase destination.',
                $campaign->customer_id, $campaignId, ['request_nonce' => $nonce, 'selected_conversion_action' => $resource,
                    'actual_purchase_results_verified' => false]);

            return ['status' => 'active', 'ready' => true, 'actions_taken' => 1, 'actual_purchase_results_verified' => false];
        } catch (\Throwable $e) {
            $this->review($campaignId, $nonce, 'Activation could not be verified. Unknown writes will not be replayed.');
            report($e);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /** Recheck the durable owner request after slow provider reads, immediately before enabling. */
    public function ownerRequestAllowsActivation(Campaign $campaign, array $pilot): bool
    {
        if (! array_key_exists('owner_request_nonce', $pilot)) {
            return true; // Existing explicit two-stage operator setup is still supported.
        }
        try {
            $fresh = $campaign->fresh(['customer']);
            $request = $fresh?->search_delivery_state['ai_max_request'] ?? [];
            $active = ($request['status'] ?? null) === 'active';
            if (! $fresh || ! is_string($pilot['owner_request_nonce']) || $pilot['owner_request_nonce'] === ''
                || ($request['nonce'] ?? null) !== $pilot['owner_request_nonce']
                || ! in_array($request['status'] ?? null, ['activating', 'active'], true)
                || ! $this->validRequest($request, $active)
                || ($active && empty($request['activated_at']))
                || ($request['customer_id'] ?? null) !== $fresh->customer_id
                || ($request['google_customer_id'] ?? null) !== $fresh->customer?->cleanGoogleCustomerId()
                || ($request['campaign_resource'] ?? null) !== $fresh->googleAdsResourceName()
                || ($request['strategy_id'] ?? null) !== ($pilot['strategy_id'] ?? null)
                || ! in_array($fresh->status, [CampaignStatus::Active, CampaignStatus::Paused], true)) {
                return false;
            }
            $approvedPilot = $request['pilot'] ?? [];
            unset($pilot['settings_fingerprint']);
            if ($this->digest($approvedPilot) !== $this->digest($pilot)) {
                return false;
            }
            $strategy = $fresh->strategies()->find($request['strategy_id']);
            if (! $strategy instanceof Strategy) {
                return false;
            }
            $this->assertScope($fresh, $strategy, $fresh->status);

            return $this->localFingerprint($fresh, $strategy) === $request['initial_local_fingerprint']
                && $this->digest($strategy->conversion_goals) === $this->digest($request['expected_conversion_goals'] ?? null);
        } catch (\Throwable) {
            return false;
        }
    }

    public function exhaust(int $campaignId, string $nonce): array
    {
        return $this->review($campaignId, $nonce, 'Google conversion readiness did not complete within the approved waiting period.');
    }

    private function scope(int $campaignId, int $strategyId, CampaignStatus $status): array
    {
        $campaign = Campaign::withoutCustomerScope()->with('customer')->findOrFail($campaignId);
        if (! $campaign instanceof Campaign) {
            throw new \RuntimeException('The campaign is no longer available.');
        }
        $strategy = $campaign->strategies()->findOrFail($strategyId);
        $this->assertScope($campaign, $strategy, $status);

        return [$campaign, $strategy];
    }

    private function assertScope(Campaign $campaign, Strategy $strategy, CampaignStatus $status): void
    {
        $customer = $campaign->customer;
        if (! $customer || $customer->cleanGoogleCustomerId() !== (string) config('conversions.google_ads_customer_id')
            || $customer->service_type !== 'managed' || $customer->is_sandbox || $customer->google_ads_link_status === 'revoked'
            || $campaign->status !== $status || $campaign->spend_safety_hold || $campaign->hasPassedEndDate()
            || $strategy->campaign_type !== 'search' || ! str_contains(strtolower($strategy->platform), 'google')
            || ! $strategy->signed_off_at || ! in_array($strategy->deployment_status, Strategy::DEPLOYED_STATUSES, true)
            || basename((string) $strategy->reusableGoogleCampaignId()) !== $campaign->googleCampaignNumericId()
            || (auth()->check() && ! auth()->user()->hasRole('admin'))) {
            throw new \RuntimeException('The approved own-site campaign, account, strategy or owner state changed.');
        }

    }

    /** The caller locks campaign then strategy for a local write; reads use the same ownership checks. */
    private function assertRequestAuthority(Campaign $campaign, Strategy $strategy, array $stored, string $nonce,
        array $phases, CampaignStatus $status, bool $original = false): void
    {
        $this->assertScope($campaign, $strategy, $status);
        if (($stored['nonce'] ?? null) !== $nonce || ! $this->validRequest($stored)
            || ! in_array($stored['status'] ?? null, $phases, true)
            || ($stored['customer_id'] ?? null) !== $campaign->customer_id
            || ($stored['google_customer_id'] ?? null) !== $campaign->customer->cleanGoogleCustomerId()
            || ($stored['campaign_resource'] ?? null) !== $campaign->googleAdsResourceName()
            || ($stored['strategy_id'] ?? null) !== $strategy->id
            || $this->localFingerprint($campaign, $strategy) !== $stored['initial_local_fingerprint']) {
            throw new \RuntimeException('The owner request was revoked, expired or changed.');
        }
        if (($original || CampaignSpendGuardrails::aiMaxPilot($campaign) === null)
            && ($this->digest($strategy->bidding_strategy) !== $this->digest($stored['original_bidding_strategy'])
                || $campaign->getRawOriginal('end_date') !== $stored['original_end_date'])) {
            throw new \RuntimeException('The original reviewed bidding or serving dates changed.');
        }
        $goals = $stored['expected_conversion_goals'] ?? $stored['original_conversion_goals'];
        if ($this->digest($strategy->conversion_goals) !== $this->digest($goals)) {
            throw new \RuntimeException('The selected setup purchase goal changed.');
        }
    }

    private function current(int $campaignId, array $request, CampaignStatus $status, bool $original = false): array
    {
        [$campaign, $strategy] = $this->scope($campaignId, $request['strategy_id'], $status);
        $stored = $campaign->search_delivery_state['ai_max_request'] ?? [];
        $this->assertRequestAuthority($campaign, $strategy, $stored, $request['nonce'], [$request['status']], $status, $original);
        if (($stored['approval_hash'] ?? null) !== $request['approval_hash']) {
            throw new \RuntimeException('The immutable owner approval changed during activation.');
        }

        return [$campaign, $strategy];
    }

    private function legacySnapshot(Campaign $campaign, array $snapshot, int $daily): void
    {
        if (($snapshot['status'] ?? null) !== 2 || ($snapshot['channel'] ?? null) !== 2
            || ($snapshot['daily_budget_micros'] ?? null) !== $daily
            || ! CampaignSpendGuardrails::permitsDailyBudget($campaign, $daily)
            || ! in_array($snapshot['bidding_strategy'] ?? null, [BiddingStrategyType::MANUAL_CPC, BiddingStrategyType::TARGET_SPEND], true)
            || ! is_int($snapshot['actual_cpc_ceiling_micros'] ?? null) || $snapshot['actual_cpc_ceiling_micros'] <= 0
            || ! CampaignSpendGuardrails::permitsKeywordBid($campaign, $snapshot['actual_cpc_ceiling_micros'])
            || ! is_string($snapshot['currency_code'] ?? null) || ! is_string($snapshot['time_zone'] ?? null)
            || ! in_array($snapshot['time_zone'], timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)
            || empty($snapshot['geo_target_constants'])) {
            throw new \RuntimeException('The current Google campaign and its approved capped spending settings must be verified first.');
        }
    }

    private function unchangedRemote(Campaign $campaign, array $request): void
    {
        $snapshot = $this->snapshot($campaign);
        $this->legacySnapshot($campaign, $snapshot, $request['daily_budget_micros']);
        if (GoogleSearchReachGate::settingsFingerprint($snapshot) !== $request['initial_remote_fingerprint']
            || $this->digest($this->goalConfiguration($campaign)) !== $request['initial_goal_fingerprint']) {
            throw new \RuntimeException('Google campaign settings changed after owner approval.');
        }
    }

    private function verifiedSpend(Campaign $campaign, array $request): array
    {
        $snapshot = $this->spendSnapshot($campaign);
        if (($snapshot['status'] ?? null) !== 3 || ! is_int($snapshot['cost_micros'] ?? null) || $snapshot['cost_micros'] < 0
            || ($snapshot['daily_budget_micros'] ?? null) !== $request['daily_budget_micros']
            || ($snapshot['currency_code'] ?? null) !== $request['currency_code']
            || ($snapshot['account_timezone'] ?? null) !== $request['account_timezone']) {
            throw new \RuntimeException('The paused campaign spend baseline could not be freshly verified.');
        }

        return $snapshot;
    }

    private function pinGoal(int $campaignId, string $nonce, array $request, array $goals): array
    {
        return DB::transaction(function () use ($campaignId, $nonce, $request, $goals): array {
            $locked = Campaign::withoutCustomerScope()->whereKey($campaignId)->lockForUpdate()->firstOrFail();
            if (! $locked instanceof Campaign) {
                throw new \RuntimeException('The campaign is no longer available.');
            }
            $stored = $locked->search_delivery_state['ai_max_request'] ?? [];
            $strategy = $locked->strategies()->whereKey($request['strategy_id'])->lockForUpdate()->firstOrFail();
            $this->assertRequestAuthority($locked, $strategy, $stored, $nonce, ['paused'], CampaignStatus::Paused);
            if (($stored['approval_hash'] ?? null) !== $request['approval_hash']) {
                throw new \RuntimeException('The immutable owner approval changed before selecting its goal.');
            }
            $strategy->update(['conversion_goals' => $goals]);
            $stored = [...$stored, 'status' => 'goals_pending', 'expected_conversion_goals' => $goals];
            $locked->update(['search_delivery_state' => [...($locked->search_delivery_state ?? []), 'ai_max_request' => $stored]]);

            return $stored;
        });
    }

    private function setPaused(int $campaignId, string $nonce): void
    {
        DB::transaction(function () use ($campaignId, $nonce): void {
            $campaign = Campaign::withoutCustomerScope()->whereKey($campaignId)->lockForUpdate()->firstOrFail();
            if (! $campaign instanceof Campaign) {
                throw new \RuntimeException('The campaign is no longer available.');
            }
            $request = $campaign->search_delivery_state['ai_max_request'] ?? [];
            $strategy = $campaign->strategies()->whereKey($request['strategy_id'] ?? null)->lockForUpdate()->firstOrFail();
            $this->assertRequestAuthority($campaign, $strategy, $request, $nonce, ['pausing'], CampaignStatus::Active, true);
            $campaign->applyPlatformStatus('PAUSED');
        });
    }

    private function save(int $campaignId, string $nonce, array $changes): array
    {
        return DB::transaction(function () use ($campaignId, $nonce, $changes): array {
            $campaign = Campaign::withoutCustomerScope()->whereKey($campaignId)->lockForUpdate()->firstOrFail();
            if (! $campaign instanceof Campaign) {
                throw new \RuntimeException('The campaign is no longer available.');
            }
            $request = $campaign->search_delivery_state['ai_max_request'] ?? [];
            $phase = $changes['status'] ?? 'waiting_for_conversion';
            $previous = match ($phase) {
                'waiting_for_conversion', 'pausing' => 'waiting_for_conversion',
                'paused' => 'pausing',
                'goals_ready' => 'goals_pending',
                'preparing' => 'goals_ready',
                'prepared' => 'preparing',
                'activating' => 'prepared',
                'active' => 'activating',
                default => throw new \RuntimeException('Unsupported AI Max owner-request transition.'),
            };
            $status = in_array($phase, ['waiting_for_conversion', 'pausing', 'active'], true)
                ? CampaignStatus::Active : CampaignStatus::Paused;
            $strategy = $campaign->strategies()->whereKey($request['strategy_id'] ?? null)->lockForUpdate()->firstOrFail();
            $this->assertRequestAuthority($campaign, $strategy, $request, $nonce, [$previous], $status);
            $request = [...$request, ...$changes];
            $campaign->update(['search_delivery_state' => [...($campaign->search_delivery_state ?? []), 'ai_max_request' => $request]]);

            return $request;
        });
    }

    private function revoke(int $campaignId, string $nonce, string $reason): array
    {
        return $this->terminal($campaignId, $nonce, 'revoked', $reason);
    }

    private function review(int $campaignId, string $nonce, string $reason): array
    {
        return $this->terminal($campaignId, $nonce, 'needs_review', $reason);
    }

    private function terminal(int $campaignId, string $nonce, string $status, string $reason): array
    {
        $outcome = DB::transaction(function () use ($campaignId, $nonce, $status, $reason): array {
            $campaign = Campaign::withoutCustomerScope()->whereKey($campaignId)->lockForUpdate()->first();
            $request = $campaign instanceof Campaign ? ($campaign->search_delivery_state['ai_max_request'] ?? []) : [];
            if (! $campaign instanceof Campaign || ($request['nonce'] ?? null) !== $nonce) {
                return ['campaign' => null, 'status' => 'revoked', 'message' => 'The owner request no longer exists.'];
            }
            if (in_array($request['status'] ?? null, ['active', 'completed'], true)) {
                return ['campaign' => null, 'status' => $request['status'], 'message' => $request['message'] ?? $reason];
            }
            // A late provider result cannot roll back an owner's terminal decision.
            $effective = in_array($request['status'] ?? null, ['revoked', 'cancelled', 'needs_review'], true) ? $request['status'] : $status;
            $message = $effective !== $status ? ($request['message'] ?? $reason) : $reason;
            $request = [...$request, 'status' => $effective, 'message' => $message, 'checked_at' => now()->toIso8601String(),
                'automatic_replay_allowed' => false];
            $values = ['search_delivery_state' => [...($campaign->search_delivery_state ?? []), 'ai_max_request' => $request]];
            $requiresStop = ! empty($request['pause_started_at']) && in_array($effective, ['needs_review', 'revoked', 'cancelled'], true);
            if ($requiresStop) {
                if (! in_array($campaign->status, [CampaignStatus::Ended, CampaignStatus::Completed], true)) {
                    $values['status'] = CampaignStatus::Paused;
                }
                $values['spend_safety_hold'] = $campaign->spend_safety_hold ?: ['reason' => 'ai_max_owner_activation_unverified',
                    'requested_at' => now()->toIso8601String()];
            }
            $campaign->update($values);

            // Persist the hold before attempting the independent protective pause. Never
            // target an account/resource changed by the owner during this request.
            $stop = $requiresStop && $campaign->customer_id === $request['customer_id']
                && $campaign->customer?->cleanGoogleCustomerId() === $request['google_customer_id']
                && $campaign->googleAdsResourceName() === $request['campaign_resource'] ? $campaign : null;

            return ['campaign' => $stop, 'status' => $effective, 'message' => $message];
        });
        $pauseVerified = false;
        if ($outcome['campaign'] instanceof Campaign) {
            try {
                $this->contain($outcome['campaign']);
                $pauseVerified = ! empty($outcome['campaign']->fresh()?->spend_safety_hold['verified_at']);
            } catch (\Throwable $stopError) {
                report($stopError); // The durable hold remains unverified for the next safety run.
            }
        }

        return ['platform_pause_verified' => $pauseVerified, 'status' => $outcome['status'], 'actions_taken' => 0,
            'message' => $outcome['message'], 'automatic_replay_allowed' => false];
    }

    private function validRequest(array $request, bool $activated = false): bool
    {
        try {
            foreach (['nonce', 'approval', 'approved_at', 'expires_at', 'google_customer_id', 'campaign_resource',
                'landing_page', 'currency_code', 'account_timezone', 'initial_local_fingerprint',
                'initial_remote_fingerprint', 'initial_goal_fingerprint', 'approval_hash'] as $field) {
                if (! is_string($request[$field] ?? null) || trim($request[$field]) === '') {
                    return false;
                }
            }
            foreach (['customer_id', 'strategy_id', 'daily_budget_micros', 'review_spend_micros', 'days'] as $field) {
                if (! is_int($request[$field] ?? null) || $request[$field] <= 0) {
                    return false;
                }
            }
            $approved = CarbonImmutable::parse($request['approved_at']);
            $expires = CarbonImmutable::parse($request['expires_at']);

            return ($request['enabled'] ?? null) === true && $request['days'] <= 14
                && $approved->lte(now()) && $expires->gt($approved) && $expires->lte($approved->addDay())
                && ($expires->gt(now()) || ($activated && ($request['status'] ?? null) === 'active'))
                && hash_equals($request['approval_hash'], $this->approvalHash($request));
        } catch (\Throwable) {
            return false;
        }
    }

    private function approvalHash(array $request): string
    {
        return $this->digest(array_intersect_key($request, array_flip(['nonce', 'customer_id', 'google_customer_id', 'campaign_resource',
            'strategy_id', 'approval', 'approved_at', 'expires_at', 'daily_budget_micros', 'review_spend_micros', 'days', 'landing_page',
            'geo_target_constants', 'currency_code', 'account_timezone', 'initial_local_fingerprint', 'initial_remote_fingerprint',
            'initial_goal_fingerprint', 'original_conversion_goals', 'original_bidding_strategy', 'original_end_date', 'requested_conversion_action'])));
    }

    private function localFingerprint(Campaign $campaign, Strategy $strategy): string
    {
        return $this->digest(['customer_id' => $campaign->customer_id, 'google_customer_id' => $campaign->customer->cleanGoogleCustomerId(),
            'campaign_resource' => $campaign->googleAdsResourceName(), 'strategy_id' => $strategy->id,
            'strategy_target' => $strategy->reusableGoogleCampaignId(), 'signed_off_at' => $strategy->signed_off_at?->toIso8601String(),
            'landing_page' => $campaign->landing_page_url, 'offer' => $campaign->product_focus, 'audience' => $campaign->target_market,
            'goals' => $campaign->goals, 'approved_daily_budget' => $campaign->approved_daily_budget,
            'budget_confirmed_at' => $campaign->budget_confirmed_at?->toIso8601String()]);
    }

    private function digest(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            $item = array_map($sort, $item);
            if (array_is_list($item)) {
                usort($item, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
            } else {
                ksort($item);
            }

            return $item;
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR));
    }

    protected function contain(Campaign $campaign): void
    {
        app(GoogleCampaignSpendSafety::class)->check($campaign);
    }

    protected function snapshot(Campaign $campaign): array
    {
        $snapshot = (new InspectSearchDelivery($campaign->customer))->inspect($campaign);
        if (! $snapshot) {
            throw new \RuntimeException('Google campaign settings are unavailable.');
        }

        return $snapshot;
    }

    protected function goalConfiguration(Campaign $campaign): array
    {
        $data = (new ReadCampaignConfiguration($campaign->customer))->read($campaign->customer->cleanGoogleCustomerId(), $campaign->googleAdsResourceName());
        $custom = $data['conversion_goal_config']['conversionGoalCampaignConfig']['customConversionGoal'] ?? null;

        return ['goals' => $data['goals'], 'config' => $data['conversion_goal_config'],
            'selected_custom' => array_values(array_filter($data['custom_conversion_goals'], fn ($row) => ($row['customConversionGoal']['resourceName'] ?? null) === $custom))];
    }

    protected function provision(Campaign $campaign): array
    {
        return (new ProvisionPaidSetupConversion($campaign->customer))->provision(app(DataManagerService::class));
    }

    protected function pause(Campaign $campaign): void
    {
        $result = (new UpdateCampaignStatus($campaign->customer))->pause($campaign->customer->cleanGoogleCustomerId(), $campaign->googleAdsResourceName());
        if (($result['success'] ?? null) !== true) {
            throw new \RuntimeException('Google did not accept the campaign pause request.');
        }
    }

    protected function spendSnapshot(Campaign $campaign): array
    {
        return (new GetCampaignSpendSafety($campaign->customer))->forCampaign($campaign);
    }

    protected function reconcileGoal(Campaign $campaign, Strategy $strategy, string $nonce): array
    {
        return (new ReconcileCampaignConversionGoals($campaign->customer))->reconcileForAiMaxRequest($strategy, $campaign->googleAdsResourceName(), $nonce);
    }

    protected function preparePilot(Campaign $campaign, array $pilot): array
    {
        return (new ConfigureAiMaxPilot($campaign->customer))->prepare($campaign, $pilot);
    }

    protected function activatePilot(Campaign $campaign): array
    {
        return (new ConfigureAiMaxPilot($campaign->customer))->activate($campaign);
    }
}
