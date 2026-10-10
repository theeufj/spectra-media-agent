<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\GoogleAds\CommonServices\GetCampaignSpendSafety;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V22\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus as GoogleStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** A stop is a verified platform pause, never an AI suggestion or a local status alone. */
class GoogleCampaignSpendSafety
{
    public function check(Campaign $campaign): array
    {
        return Cache::lock("campaign-spend-safety:{$campaign->id}", 180)->block(5, function () use ($campaign) {
            $campaign->refresh()->load('customer');
            $customer = $campaign->customer;
            if (! $customer || ! $customer->cleanGoogleCustomerId() || ! $campaign->googleAdsResourceName()) {
                throw new \RuntimeException("Campaign {$campaign->id} has no valid customer account for spend safety.");
            }
            if ($customer->is_sandbox || $customer->google_ads_link_status === 'revoked'
                || ($customer->service_type === 'setup_only' && ! CampaignSpendGuardrails::activeTrial($campaign))) {
                return ['action' => 'skipped', 'reason' => 'account is not under automatic management'];
            }

            $snapshot = $this->snapshot($campaign);
            if ($snapshot['status'] === GoogleStatus::REMOVED) {
                $campaign->applyPlatformStatus('REMOVED');

                return ['action' => 'skipped', 'reason' => 'campaign was removed'];
            }
            $hold = $campaign->spend_safety_hold;
            $reason = $hold['reason'] ?? $this->stopReason($campaign, $snapshot);
            if ($reason === null) {
                if ($snapshot['status'] === GoogleStatus::PAUSED) {
                    $campaign->applyPlatformStatus('PAUSED');
                }

                return ['action' => 'observed', 'cost_micros' => $snapshot['cost_micros']];
            }
            if ($snapshot['status'] === GoogleStatus::PAUSED && isset($hold['verified_at'])) {
                $this->recordDelayedSpend($campaign, $snapshot);

                return ['action' => 'held', 'reason' => $reason];
            }

            // Save intent first. A rejected pause or failed read-back still
            // blocks automatic enabling and is retried by the next safety run.
            $campaign->update(['spend_safety_hold' => [
                'reason' => $reason,
                'requested_at' => $hold['requested_at'] ?? now()->toIso8601String(),
                'snapshot' => $snapshot,
            ]]);
            if ($snapshot['status'] !== GoogleStatus::PAUSED && ! $this->pause($campaign)) {
                throw new \RuntimeException("Google rejected the spend safety pause for campaign {$campaign->id}.");
            }
            if (! $this->isPaused($campaign)) {
                throw new \RuntimeException("Google has not verified the spend safety pause for campaign {$campaign->id}.");
            }

            $trial = CampaignSpendGuardrails::activeTrial($campaign);
            $spend = $trial ? max(0, $snapshot['cost_micros'] - (int) $trial['baseline_cost_micros']) : $snapshot['window_matured_cost_micros'];
            $overshoot = $trial ? max(0, $spend - (int) $trial['max_spend_micros']) : 0;
            $verified = $campaign->spend_safety_hold;
            $verified['verified_at'] = now()->toIso8601String();
            $verified['observed_cap_overshoot_micros'] = $overshoot;
            $verified['last_alerted_overshoot_micros'] = $overshoot;
            DB::transaction(function () use ($campaign, $verified, $reason, $snapshot, $trial, $spend, $overshoot) {
                $campaign->applyPlatformStatus('PAUSED');
                $campaign->update(['spend_safety_hold' => $verified, 'primary_status' => 'PAUSED', 'last_checked_at' => now()]);
                AgentActivity::record('spend_safety', 'campaign_spend_safety_paused',
                    'Paused "'.$campaign->name.'" after a spend safety stop: '.str_replace('_', ' ', $reason).'. Google confirmed it is paused.',
                    $campaign->customer_id, $campaign->id, [
                        'reason' => $reason, 'verified_platform_status' => 'PAUSED', 'snapshot' => $snapshot,
                        'trial_spend_micros' => $trial ? $spend : null,
                        'observed_cap_overshoot_micros' => $overshoot,
                        'reporting_note' => 'Google reports spend with a delay. This stop is checked every 15 minutes and is not a hard billing cap.',
                    ]);
            });

            return ['action' => 'paused', 'reason' => $reason, 'overshoot_micros' => $overshoot];
        });
    }

    /** Configure explicitly approved limits against a fresh PAUSED campaign baseline. Does not enable ads. */
    public function configureBoundedTrial(Campaign $campaign, int $maxSpendMicros, int $dailyBudgetMicros, int $cpcBidMicros, int $goalCpaMicros, string $approval): array
    {
        if (min($maxSpendMicros, $dailyBudgetMicros, $cpcBidMicros, $goalCpaMicros) < 1 || trim($approval) === '') {
            throw new \InvalidArgumentException('A bounded trial needs positive integer-micro limits and an approval record.');
        }

        return Cache::lock("campaign-spend-safety:{$campaign->id}", 180)->block(5, function () use ($campaign, $maxSpendMicros, $dailyBudgetMicros, $cpcBidMicros, $goalCpaMicros, $approval) {
            $campaign->refresh()->load('customer');
            if (! $campaign->customer || ! $campaign->googleAdsResourceName()) {
                throw new \RuntimeException('A bounded trial requires a valid Google customer and campaign.');
            }
            $snapshot = $this->snapshot($campaign);
            if ($snapshot['status'] !== GoogleStatus::PAUSED) {
                throw new \RuntimeException('Pause the Google campaign before configuring a bounded trial.');
            }
            if ($snapshot['campaign_type'] !== AdvertisingChannelType::SEARCH) {
                throw new \RuntimeException('A CPC-capped bounded trial requires a Google Search campaign.');
            }
            if ($snapshot['amplifying_bid_modifiers'] !== []) {
                throw new \RuntimeException('Remove bid modifiers above 1.0 before configuring the CPC-capped trial.');
            }
            $trial = [
                'enabled' => true, 'started_at' => now()->toIso8601String(),
                'baseline_cost_micros' => $snapshot['cost_micros'],
                'baseline_conversions' => $snapshot['conversions'],
                'max_spend_micros' => $maxSpendMicros,
                'max_daily_budget_micros' => $dailyBudgetMicros,
                'max_cpc_bid_micros' => $cpcBidMicros,
                'goal_cpa_micros' => $goalCpaMicros, 'approval' => $approval,
                'currency_code' => $snapshot['currency_code'],
                'account_timezone' => $snapshot['account_timezone'],
            ];
            DB::transaction(function () use ($campaign, $trial, $approval) {
                $locked = Campaign::withoutCustomerScope()->lockForUpdate()->findOrFail($campaign->id);
                if (! $locked instanceof Campaign) {
                    throw new \RuntimeException('Campaign was unavailable while saving trial limits.');
                }
                $locked->update(['spend_guardrails' => $trial]);
                AgentActivity::record('spend_safety', 'bounded_trial_configured',
                    'Configured approved test limits for "'.$campaign->name.'"; ads remain paused.',
                    $campaign->customer_id, $campaign->id, $trial + ['approval' => $approval]);
            });
            $campaign->refresh();

            return $trial;
        });
    }

    /** Explicit owner/operator decision, after preparing a fresh baseline and safe live settings. */
    public function releaseForApprovedRestart(Campaign $campaign, string $approval): void
    {
        if (trim($approval) === '') {
            throw new \InvalidArgumentException('Releasing a pause hold requires an approval record.');
        }
        Cache::lock("campaign-spend-safety:{$campaign->id}", 180)->block(5, function () use ($campaign, $approval) {
            $campaign->refresh()->load('customer');
            $snapshot = $this->snapshot($campaign);
            if ($snapshot['status'] !== GoogleStatus::PAUSED || $this->stopReason($campaign, $snapshot, false) !== null) {
                throw new \RuntimeException('The campaign still fails its spend limits. Review limits and baseline before restarting.');
            }
            DB::transaction(function () use ($campaign, $approval) {
                $locked = Campaign::withoutCustomerScope()->lockForUpdate()->findOrFail($campaign->id);
                if (! $locked instanceof Campaign) {
                    throw new \RuntimeException('Campaign was unavailable while releasing its pause hold.');
                }
                $previous = $locked->spend_safety_hold;
                $locked->update(['spend_safety_hold' => null]);
                AgentActivity::record('spend_safety', 'campaign_pause_hold_released',
                    'Approved a restart review for "'.$campaign->name.'". This action does not enable ads.',
                    $campaign->customer_id, $campaign->id, ['approval' => $approval, 'previous_hold' => $previous]);
            });
            $campaign->refresh();
        });
    }

    private function stopReason(Campaign $campaign, array $snapshot, bool $respectLocalPause = true): ?string
    {
        if ($campaign->hasPassedEndDate()) {
            return 'approved_end_date_passed';
        }
        if ($respectLocalPause && $campaign->status === CampaignStatus::Paused && $snapshot['status'] === GoogleStatus::ENABLED) {
            return 'manual_pause_drift';
        }
        $trial = CampaignSpendGuardrails::activeTrial($campaign);
        if ($trial) {
            if ($snapshot['campaign_type'] !== AdvertisingChannelType::SEARCH) {
                return 'trial_campaign_type_changed';
            }
            if (($snapshot['amplifying_bid_modifiers'] ?? []) !== []) {
                return 'trial_bid_modifier_exceeded';
            }
            if ($snapshot['daily_budget_micros'] > (int) $trial['max_daily_budget_micros']) {
                return 'trial_daily_budget_exceeded';
            }
            if ($snapshot['bidding_strategy'] !== BiddingStrategyType::TARGET_SPEND
                || $snapshot['cpc_bid_ceiling_micros'] <= 0
                || $snapshot['cpc_bid_ceiling_micros'] > (int) $trial['max_cpc_bid_micros']) {
                return 'trial_bidding_limit_missing';
            }
            if (max(0, $snapshot['cost_micros'] - (int) $trial['baseline_cost_micros']) >= (int) $trial['max_spend_micros']) {
                return 'trial_spend_cap_reached';
            }
            if ($snapshot['conversions'] <= ($trial['baseline_conversions'] ?? 0)
                && max(0, $snapshot['matured_cost_micros'] - (int) $trial['baseline_cost_micros']) >= (int) $trial['goal_cpa_micros']) {
                return 'trial_no_conversions_after_goal_spend';
            }

            return null;
        }

        return $snapshot['window_conversions'] <= 0
            && $snapshot['window_matured_cost_micros'] >= (int) config('optimization.campaign_spend_safety.zero_conversion_spend_micros', 50_000_000)
            ? 'no_conversions_after_matured_spend' : null;
    }

    protected function snapshot(Campaign $campaign): array
    {
        return (new GetCampaignSpendSafety($campaign->customer))->forCampaign($campaign);
    }

    private function recordDelayedSpend(Campaign $campaign, array $snapshot): void
    {
        $trial = CampaignSpendGuardrails::activeTrial($campaign);
        if (! $trial) {
            return;
        }
        $hold = $campaign->spend_safety_hold;
        $spend = max(0, $snapshot['cost_micros'] - (int) $trial['baseline_cost_micros']);
        $overshoot = max(0, $spend - (int) $trial['max_spend_micros']);
        $alert = $overshoot - (int) ($hold['last_alerted_overshoot_micros'] ?? 0)
            >= (int) config('optimization.campaign_spend_safety.overshoot_alert_micros', 1_000_000);
        $hold['latest_snapshot'] = $snapshot;
        $hold['observed_trial_spend_micros'] = $spend;
        $hold['observed_cap_overshoot_micros'] = $overshoot;
        if ($alert) {
            $hold['last_alerted_overshoot_micros'] = $overshoot;
        }
        DB::transaction(function () use ($campaign, $hold, $alert, $spend, $overshoot) {
            $campaign->update(['spend_safety_hold' => $hold, 'last_checked_at' => now()]);
            if ($alert) {
                AgentActivity::record('spend_safety', 'trial_spend_overshoot_reported',
                    'Google reported additional spend above the test stop threshold for "'.$campaign->name.'" after it was paused.',
                    $campaign->customer_id, $campaign->id,
                    ['trial_spend_micros' => $spend, 'observed_cap_overshoot_micros' => $overshoot, 'verified_platform_status' => 'PAUSED'], 'needs_review');
            }
        });
    }

    protected function pause(Campaign $campaign): bool
    {
        return (new UpdateCampaignStatus($campaign->customer))->pause($campaign->customer->cleanGoogleCustomerId(), $campaign->googleAdsResourceName())['success'];
    }

    protected function isPaused(Campaign $campaign): bool
    {
        $status = (new GetCampaignStatus($campaign->customer))($campaign->customer->cleanGoogleCustomerId(), $campaign->googleAdsResourceName());

        return ($status['status'] ?? null) === GoogleStatus::PAUSED;
    }
}
