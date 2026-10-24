<?php

namespace App\Services\Agents;

use App\Enums\CampaignStatus;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Strategy;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBiddingStrategy;
use Illuminate\Support\Facades\DB;

/** Evidence-based automatic progression. Thresholds are our policy, not Google eligibility rules. */
class BiddingStrategyProgressionAgent
{
    private function thresholds(): array
    {
        $cfg = config('optimization.bidding_strategy.thresholds', []);

        return [
            'MANUAL_CPC' => ['min_conversions' => $cfg['MANUAL_CPC'] ?? 30, 'next' => 'MAXIMIZE_CONVERSIONS', 'stability_check' => false],
            'MAXIMIZE_CLICKS' => ['min_conversions' => $cfg['MAXIMIZE_CLICKS'] ?? 30, 'next' => 'MAXIMIZE_CONVERSIONS', 'stability_check' => false],
            'MAXIMIZE_CONVERSIONS' => ['min_conversions' => $cfg['MAXIMIZE_CONVERSIONS'] ?? 30, 'next' => 'TARGET_CPA', 'stability_check' => true],
            'TARGET_CPA' => ['min_conversions' => $cfg['TARGET_CPA'] ?? 100, 'next' => 'TARGET_ROAS', 'stability_check' => true],
        ];
    }

    public function evaluate(Campaign $campaign): array
    {
        $context = $this->context($campaign);
        if (! $context) {
            return $this->skip('Campaign is not approved for automatic bidding changes.');
        }
        [$campaign, $strategy, $customerId, $resource] = $context;
        $updater = $this->updater($campaign->customer);
        $before = $updater->readBiddingState($customerId, $resource);
        if (! $this->eligibleState($before, $resource)) {
            return $this->skip('A live enabled campaign with standard bidding must be confirmed.');
        }
        $current = $before['strategy'];
        $threshold = $this->thresholds()[$current] ?? null;
        if (! $threshold) {
            return $this->skip('Current bidding strategy is not in the supported progression.');
        }
        $perf = $this->performance($campaign, $resource, 'LAST_30_DAYS');
        if (! $perf || ! isset($perf['conversions']) || $perf['conversions'] < $threshold['min_conversions']) {
            return $this->skip('Not enough confirmed conversions for our automatic progression policy.');
        }
        if ($threshold['stability_check'] && ! $this->hasStableCpa($campaign, $resource, $perf)) {
            return $this->skip('CPA is not stable enough for a target-based strategy.');
        }
        $next = $threshold['next'];
        $cpa = null;
        $roas = null;
        if ($next === 'TARGET_CPA') {
            $actual = $this->cpa($perf);
            $cpa = $actual === null ? null : round($actual * (float) config('optimization.bidding_strategy.cpa_target_multiplier', 0.9), 2);
            if ($cpa === null || ! is_finite($cpa) || $cpa <= 0) {
                return $this->skip('A measured positive CPA is required; no target is inferred.');
            }
        }
        if ($next === 'TARGET_ROAS') {
            $actual = $this->roas($perf);
            $roas = $actual === null ? null : round($actual * (float) config('optimization.bidding_strategy.roas_target_multiplier', 0.9), 2);
            if ($roas === null || ! is_finite($roas) || $roas <= 0) {
                return $this->skip('Recorded conversion value is required; no ROAS target is inferred.');
            }
        }

        return $this->apply($campaign, $strategy, $resource, $before, $updater, $next, $cpa, $roas,
            'strategy_graduated', 'Our conversion-volume and performance-evidence policy is satisfied.', ['conversions' => $perf['conversions']]);
    }

    public function checkForRegression(Campaign $campaign): array
    {
        $context = $this->context($campaign);
        if (! $context) {
            return $this->skip('Campaign is not approved for automatic bidding changes.');
        }
        [$campaign, $strategy, $customerId, $resource] = $context;
        $updater = $this->updater($campaign->customer);
        $before = $updater->readBiddingState($customerId, $resource);
        if (! $this->eligibleState($before, $resource) || ! in_array($before['strategy'], ['TARGET_CPA', 'TARGET_ROAS'], true)) {
            return $this->skip('Confirmed bidding strategy is not eligible for regression.');
        }
        $last = AgentActivity::where('campaign_id', $campaign->id)->where('customer_id', $campaign->customer_id)
            ->where('action', 'strategy_graduated')->latest('id')->first();
        if (! $last || $last->created_at->gt(now()->subDays(14))
            || ($last->details['campaign_resource'] ?? null) !== $resource
            || ($last->details['to'] ?? null) !== $before['strategy']) {
            return $this->skip('No matching graduation has completed its observation period.');
        }
        $perf = $this->performance($campaign, $resource, 'LAST_14_DAYS');
        if (! $perf) {
            return $this->skip('No performance data.');
        }
        $thresholds = AdaptiveThresholds::forCampaign($campaign);
        $next = null;
        $cpa = null;
        $reason = '';
        if ($before['strategy'] === 'TARGET_CPA') {
            $actual = $this->cpa($perf);
            $target = $before['target_cpa_micros'] / 1_000_000;
            if ($actual !== null && $target > 0 && $actual > $target * (1 + $thresholds['cpa_regression_tolerance'])) {
                $next = 'MAXIMIZE_CONVERSIONS';
                $reason = 'Measured CPA exceeded the confirmed target beyond this campaign’s tolerance; removed the CPA target.';
            }
        } else {
            $actual = $this->roas($perf);
            if ($actual !== null && $before['target_roas'] > 0 && $actual < $before['target_roas'] * (1 - $thresholds['roas_regression_tolerance'])) {
                $observedCpa = $this->cpa($perf);
                if ($observedCpa === null) {
                    return $this->skip('Cannot infer a CPA target for reversion without recorded conversions.');
                }
                $cpa = round($observedCpa * 1.10, 2);
                if ($cpa <= 0) {
                    return $this->skip('Measured CPA is too small for a valid target.');
                }
                $next = 'TARGET_CPA';
                $reason = 'Measured ROAS fell below the confirmed target beyond this campaign’s tolerance; returned to a CPA target based on observed cost.';
            }
        }
        if (! $next) {
            return $this->skip('No regression detected.');
        }

        return $this->apply($campaign, $strategy, $resource, $before, $updater, $next, $cpa, null, 'strategy_reverted', $reason);
    }

    private function apply(Campaign $campaign, Strategy $strategy, string $resource, array $before,
        UpdateCampaignBiddingStrategy $updater, string $next, ?float $cpa, ?float $roas, string $action, string $reason, array $details = []): array
    {
        // Evidence collection can take time. Recheck local authorization and exact remote state.
        \Laravel\Pennant\Feature::flushCache();
        \Illuminate\Support\Facades\Cache::forget('enabled_platform_slugs');
        $fresh = $this->context($campaign);
        if (! $fresh || $fresh[1]->id !== $strategy->id || $fresh[3] !== $resource
            || $fresh[0]->only(['daily_budget', 'approved_daily_budget', 'budget_confirmed_at', 'start_date', 'end_date']) != $campaign->only(['daily_budget', 'approved_daily_budget', 'budget_confirmed_at', 'start_date', 'end_date'])
            || $fresh[1]->getRawOriginal('signed_off_at') !== $strategy->getRawOriginal('signed_off_at')
            || $fresh[1]->bidding_strategy !== $strategy->bidding_strategy
            || $updater->readBiddingState($fresh[2], $resource) !== $before) {
            return $this->skip('Campaign authorization or Google bidding changed during evaluation.');
        }
        if (! $updater($fresh[2], $resource, $next, $cpa, $roas)) {
            return ['success' => false, 'error' => 'Google did not confirm the requested bidding change.'];
        }
        // The writer reads back the exact requested configuration before returning success.
        DB::transaction(function () use ($campaign, $strategy, $resource, $before, $next, $cpa, $roas, $action, $reason, $details): void {
            $lockedCampaign = Campaign::lockForUpdate()->findOrFail($campaign->id);
            $locked = Strategy::lockForUpdate()->findOrFail($strategy->id);
            $target = $locked->reusableGoogleCampaignId();
            $target = $target && ! str_contains($target, '/') ? 'customers/'.$campaign->customer->cleanGoogleCustomerId().'/campaigns/'.$target : $target;
            if ($locked->campaign_id !== $lockedCampaign->id || $lockedCampaign->customer_id !== $campaign->customer_id
                || $lockedCampaign->googleAdsResourceName() !== $resource || $target !== $resource) {
                throw new \RuntimeException('Campaign ownership or target changed before recording the verified bidding update.');
            }
            $data = (array) $locked->bidding_strategy;
            foreach (['target_cpa', 'target_roas', 'cpc_bid_ceiling_micros', 'initial_cpc_bid_micros'] as $key) {
                unset($data[$key]);
            }
            $parameters = (array) ($data['parameters'] ?? []);
            foreach (['targetCpaMicros', 'targetRoas', 'cpcBidCeilingMicros', 'initialCpcBidMicros'] as $key) {
                unset($parameters[$key]);
            }
            $data['name'] = $data['bid_strategy'] = $data['bidding_strategy_type'] = $next;
            if ($cpa !== null) {
                $data['target_cpa'] = $cpa;
                $parameters['targetCpaMicros'] = (int) round($cpa * 1_000_000);
            }
            if ($roas !== null) {
                $data['target_roas'] = $roas;
                $parameters['targetRoas'] = $roas;
            }
            $data['parameters'] = $parameters;
            $execution = (array) $locked->execution_result;
            $wireType = match ($next) {
                'TARGET_CPA' => 'MAXIMIZE_CONVERSIONS',
                'TARGET_ROAS' => 'MAXIMIZE_CONVERSION_VALUE',
                'MAXIMIZE_CLICKS' => 'TARGET_SPEND',
                default => $next,
            };
            if (isset($execution['metadata']['google_search_baseline'])) {
                $execution['metadata']['google_search_baseline']['bidding'] = $wireType;
            }
            $execution['metadata']['expected_bidding'] = $wireType;
            $locked->update(['bidding_strategy' => $data, 'execution_result' => $execution]);
            $lockedCampaign->update(['last_bidding_changed_at' => now()]);
            AgentActivity::record('bidding', $action,
                'Changed "'.$campaign->name.'" from '.$before['strategy'].' to '.$next.': '.$reason,
                $campaign->customer_id, $campaign->id, $details + ['from' => $before['strategy'], 'to' => $next,
                    'campaign_resource' => $resource, 'reason' => $reason, 'target_cpa' => $cpa, 'target_roas' => $roas,
                    'currency' => $before['currency'], 'verified' => true]);
        });

        return ['success' => true, 'from' => $before['strategy'], 'to' => $next, 'target_cpa' => $cpa, 'target_roas' => $roas, 'reason' => $reason];
    }

    private function context(Campaign $campaign): ?array
    {
        $campaign = $campaign->fresh();
        $customer = $campaign?->customer;
        if (! $campaign || ! $customer || $customer->is_sandbox || $customer->service_type !== 'managed'
            || ! EnabledPlatform::isEnabled('google')
            || ! \Laravel\Pennant\Feature::for($customer)->active(\App\Features\AutoOptimization::class)
            || $customer->google_ads_link_status !== null && $customer->google_ads_link_status !== 'active'
            || $campaign->status !== CampaignStatus::Active || strtoupper((string) $campaign->platform_status) === 'PAUSED' || $campaign->hasPassedEndDate()
            || $campaign->getRawOriginal('start_date') === null
            || $campaign->start_date->toDateString() > now($customer->timezone ?: config('app.timezone'))->toDateString()
            || CampaignSpendGuardrails::automaticChangesSuspended($campaign)
            || ! $campaign->budget_confirmed_at || (float) $campaign->approved_daily_budget <= 0
            || (float) $campaign->daily_budget > (float) $campaign->approved_daily_budget
            || $customer->adSpendCredit()->value('payment_status') === \App\Models\AdSpendCredit::PAYMENT_PAUSED) {
            return null;
        }
        $id = $customer->cleanGoogleCustomerId();
        $resource = $campaign->googleAdsResourceName();
        if (! $id || ! $resource || ! preg_match('#^customers/'.preg_quote($id, '#').'/campaigns/\d+$#D', $resource)) {
            return null;
        }
        $matches = $campaign->strategies()->get()->filter(function (Strategy $strategy) use ($id, $resource): bool {
            $target = $strategy->reusableGoogleCampaignId();
            $target = $target && ! str_contains($target, '/') ? "customers/{$id}/campaigns/{$target}" : $target;

            return str_contains(strtolower($strategy->platform), 'google') && $target === $resource
                && $strategy->signed_off_at !== null && in_array($strategy->deployment_status, Strategy::DEPLOYED_STATUSES, true);
        });
        if ($matches->count() !== 1) {
            return null;
        }

        return [$campaign, $matches->first(), $id, $resource];
    }

    private function eligibleState(?array $state, string $resource): bool
    {
        return $state !== null && ($state['resource'] ?? null) === $resource && ($state['status'] ?? null) === 'ENABLED'
            && ($state['portfolio'] ?? null) === '' && in_array($state['channel'] ?? null, ['SEARCH', 'DISPLAY', 'PERFORMANCE_MAX'], true)
            && preg_match('/^[A-Z]{3}$/D', $state['currency'] ?? '') === 1;
    }

    protected function updater(Customer $customer): UpdateCampaignBiddingStrategy
    {
        return new UpdateCampaignBiddingStrategy($customer);
    }

    protected function performance(Campaign $campaign, string $resource, string $range): ?array
    {
        return (new GetCampaignPerformance($campaign->customer))($campaign->customer->cleanGoogleCustomerId(), $resource, $range);
    }

    private function cpa(array $perf): ?float
    {
        return ($perf['conversions'] ?? 0) > 0 && ($perf['cost_micros'] ?? 0) > 0
            ? $perf['cost_micros'] / 1_000_000 / $perf['conversions'] : null;
    }

    private function roas(array $perf): ?float
    {
        return isset($perf['conversion_value']) && is_numeric($perf['conversion_value']) && $perf['conversion_value'] >= 0 && ($perf['cost_micros'] ?? 0) > 0
            ? $perf['conversion_value'] * 1_000_000 / $perf['cost_micros'] : null;
    }

    private function hasStableCpa(Campaign $campaign, string $resource, array $month): bool
    {
        $recent = $this->performance($campaign, $resource, 'LAST_14_DAYS');
        if (! $recent) {
            return false;
        }
        // Compare disjoint portions of the same 30-day window, rather than two overlapping averages.
        $prior = ['conversions' => ($month['conversions'] ?? 0) - ($recent['conversions'] ?? 0),
            'cost_micros' => ($month['cost_micros'] ?? 0) - ($recent['cost_micros'] ?? 0)];
        $recentCpa = $this->cpa($recent);
        $priorCpa = $this->cpa($prior);

        return $recentCpa !== null && $priorCpa !== null && abs($recentCpa - $priorCpa) / $priorCpa < 0.20;
    }

    private function skip(string $reason): array
    {
        return ['skipped' => true, 'reason' => $reason];
    }
}
