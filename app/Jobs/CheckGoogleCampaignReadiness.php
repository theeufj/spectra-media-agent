<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Features\AutoHealing;
use App\Jobs\Concerns\RecordsAgentRun;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Setting;
use App\Models\Strategy;
use App\Notifications\CriticalAgentAlert;
use App\Services\Agents\AgentIssue;
use App\Services\Agents\QualityScoreImprovementAgent;
use App\Services\Deployment\DeploymentVerifier;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use App\Services\GoogleAds\ReconcileSearchAudienceObservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

/** Check goals, Search audience mode and ad strength independently; never resume. */
class CheckGoogleCampaignReadiness implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RecordsAgentRun, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 1800;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $campaignId) {}

    public function uniqueId(): string
    {
        return 'google-readiness:'.$this->campaignId;
    }

    public function handle(QualityScoreImprovementAgent $strengthAgent, DeploymentVerifier $verifier): void
    {
        $lock = Cache::lock('google-readiness:campaign:'.$this->campaignId, 650);
        if (! $lock->get()) {
            return;
        }
        $started = $this->startRun();
        $actions = $errors = $warnings = $checked = 0;
        try {
            // Resolve after acquiring the lock: queued eligibility can be stale.
            $campaign = Campaign::with(['customer', 'strategies'])->find($this->campaignId);
            if (! $campaign || ! $campaign->customer) {
                return;
            }
            foreach ($campaign->strategies as $strategy) {
                if (! str_contains(strtolower($strategy->platform ?? ''), 'google')) {
                    continue;
                }
                $resource = $strategy->reusableGoogleCampaignId();
                if (! $resource && ! in_array($strategy->deployment_status, [...Strategy::DEPLOYED_STATUSES, 'deploy_unverified'], true)) {
                    continue;
                }
                try {
                    $snapshot = $this->checkStrategy($campaign, $strategy, $resource, $strengthAgent, $verifier);
                    $this->persist($strategy, $snapshot);
                    $actions += count($snapshot['conversion_goals']['actions'] ?? []) + count($snapshot['audience_observation']['actions'] ?? []) + count($snapshot['ad_strength']['actions'] ?? []);
                    $errors += count($snapshot['errors']);
                    $warnings += $snapshot['status'] === 'needs_review' ? 1 : 0;
                    $checked++;
                    if ($snapshot['issues'] !== []) {
                        $this->escalate($campaign, $strategy, $snapshot);
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $errors++;
                    Log::error('Google readiness check could not be recorded', ['campaign_id' => $campaign->id, 'strategy_id' => $strategy->id, 'error' => $e->getMessage()]);
                }
            }
        } finally {
            $this->finishRun($started, actions: $actions, errors: $errors, warnings: $warnings, scope: $checked.' Google strategies', details: ['campaign_id' => $this->campaignId]);
            $lock->release();
        }
    }

    private function checkStrategy(Campaign $campaign, Strategy $strategy, ?string $resource, QualityScoreImprovementAgent $strengthAgent, DeploymentVerifier $verifier): array
    {
        $previous = $strategy->execution_result['metadata']['google_readiness'] ?? [];
        $customer = $campaign->customer;
        $readBlocked = $this->readBlocked($customer);
        $snapshot = ['checked_at' => now()->toIso8601String(), 'status' => 'skipped', 'ready' => false,
            'mutation_allowed' => false, 'skip_reason' => $readBlocked, 'campaign_resource' => $resource,
            'conversion_goals' => ['status' => 'unknown', 'ready' => false, 'actions' => [], 'issues' => []],
            'audience_observation' => ['status' => 'unknown', 'ready' => false, 'applicable' => null, 'actions' => [], 'issues' => [], 'ad_groups' => []],
            'ad_strength' => ['checked' => false, 'verified' => false, 'actions' => [], 'errors' => [], 'unresolved' => []],
            'issues' => [], 'errors' => []];
        if ($readBlocked) {
            return $this->retainUnobservedEvidence($snapshot, $previous);
        }
        $resource = $resource && str_starts_with($resource, 'customers/') ? $resource
            : ($resource ? 'customers/'.$customer->cleanGoogleCustomerId().'/campaigns/'.$resource : null);
        $snapshot['campaign_resource'] = $resource;
        $campaign->refresh();
        $strategy->refresh();
        $customer = $campaign->customer;
        if (! $customer) {
            $snapshot['skip_reason'] = 'customer_inactive';

            return $this->retainUnobservedEvidence($snapshot, $previous);
        }
        if ($blocked = $this->readBlocked($customer)) {
            $snapshot['skip_reason'] = $blocked;

            return $this->retainUnobservedEvidence($snapshot, $previous);
        }
        $reason = $this->mutationBlocked($campaign, $strategy);
        $snapshot['mutation_allowed'] = $reason === null;
        $snapshot['skip_reason'] = $reason;

        // A failed component must not prevent the other independent checks.
        try {
            $goals = app(ReconcileCampaignConversionGoals::class, ['customer' => $customer]);
            $result = $reason === null ? $goals->reconcile($strategy, $resource) : $goals->inspect($strategy, $resource);
            $snapshot['conversion_goals'] = $result;
            $snapshot['conversion_goals']['issues'] = $this->issues($result['issues'] ?? []);
            $snapshot['issues'] = $snapshot['conversion_goals']['issues'];
        } catch (\Throwable $e) {
            report($e);
            $issue = (new AgentIssue('conversion_readiness_unavailable', 'The Google conversion goal check failed. Current goal readiness is unknown.'))->toArray();
            $snapshot['conversion_goals']['issues'] = [$issue];
            $snapshot['issues'][] = $issue;
            $snapshot['errors'][] = $issue;
            Log::error('Google conversion readiness unavailable', ['campaign_id' => $campaign->id, 'strategy_id' => $strategy->id, 'error' => $e->getMessage()]);
        }

        try {
            $campaign->refresh();
            $strategy->refresh();
            $customer = $campaign->customer;
            $reason = $this->mutationBlocked($campaign, $strategy);
            $snapshot['mutation_allowed'] = $reason === null;
            $snapshot['skip_reason'] = $reason;
            if ($this->knownNonSearch($strategy)) {
                $snapshot['audience_observation'] = ['status' => 'not_applicable', 'ready' => true,
                    'applicable' => false, 'checked_at' => now()->toIso8601String(), 'actions' => [], 'issues' => [], 'ad_groups' => []];
            } elseif ($customer && ! $this->readBlocked($customer)) {
                $currentResource = $strategy->reusableGoogleCampaignId();
                $currentResource = $currentResource && str_starts_with($currentResource, 'customers/') ? $currentResource
                    : ($currentResource ? 'customers/'.$customer->cleanGoogleCustomerId().'/campaigns/'.$currentResource : null);
                if ($currentResource !== $resource || ($resource && ! preg_match('#^customers/'.preg_quote($customer->cleanGoogleCustomerId(), '#').'/campaigns/\d+$#', $resource))) {
                    throw new \LogicException('The strategy-specific Google campaign identity changed during readiness checks.');
                }
                $audience = app(ReconcileSearchAudienceObservation::class, ['customer' => $customer]);
                $result = $reason === null && $resource ? $audience->reconcile($resource) : $audience->inspect($resource);
                $snapshot['audience_observation'] = $result;
                $snapshot['audience_observation']['issues'] = $this->issues($result['issues'] ?? []);
                if (($result['status'] ?? 'unknown') === 'unknown') {
                    if ($snapshot['audience_observation']['issues'] === []) {
                        $snapshot['audience_observation']['issues'][] = (new AgentIssue('audience_observation_unavailable', 'Google has not provided enough evidence to verify Search audience mode.'))->toArray();
                    }
                    $snapshot['errors'] = array_merge($snapshot['errors'], $snapshot['audience_observation']['issues']);
                }
                $snapshot['issues'] = array_merge($snapshot['issues'], $snapshot['audience_observation']['issues']);
            }
        } catch (\Throwable $e) {
            report($e);
            $issue = (new AgentIssue('audience_observation_unavailable', 'The Google Search audience Observation check failed. Current audience mode is unknown.'))->toArray();
            $snapshot['audience_observation']['issues'] = [$issue];
            $snapshot['issues'][] = $issue;
            $snapshot['errors'][] = $issue;
            Log::error('Google Search audience Observation unavailable', ['campaign_id' => $campaign->id, 'strategy_id' => $strategy->id, 'error' => $e->getMessage()]);
        }

        try {
            $campaign->refresh();
            $strategy->refresh();
            $reason = $this->mutationBlocked($campaign, $strategy);
            if ($reason === null && $resource) {
                // Only an explicit stored type can establish that RSA strength
                // does not apply. Missing/unknown types still need an API check.
                $type = $this->campaignType($strategy);
                if ($this->knownNonSearch($strategy)) {
                    $snapshot['ad_strength'] = ['status' => 'not_applicable', 'applicable' => false, 'campaign_type' => $type,
                        'checked' => false, 'verified' => false, 'actions' => [], 'errors' => [], 'unresolved' => []];
                } else {
                    // Each strategy may have its own Google campaign, not the legacy
                    // first campaign ID kept on the parent for compatibility.
                    $target = clone $campaign;
                    $target->setAttribute('google_ads_campaign_id', $resource);
                    $target->setRelation('strategies', collect([$strategy]));
                    $snapshot['ad_strength'] = $strengthAgent->checkAdStrength($target);
                }
                if (! empty($snapshot['ad_strength']['skipped'])) {
                    $snapshot['mutation_allowed'] = false;
                    $snapshot['skip_reason'] = $snapshot['ad_strength']['skipped'];
                }
                $snapshot['issues'] = array_merge($snapshot['issues'], $this->issues($snapshot['ad_strength']['errors'] ?? []), $this->unresolved($snapshot['ad_strength']['unresolved'] ?? []));
                $snapshot['errors'] = array_merge($snapshot['errors'], $this->issues($snapshot['ad_strength']['errors'] ?? []));
            } else {
                $snapshot['ad_strength']['skipped'] = $reason ?? 'missing_google_campaign';
                $snapshot['mutation_allowed'] = false;
                $snapshot['skip_reason'] = $reason ?? 'missing_google_campaign';
            }
        } catch (\Throwable $e) {
            report($e);
            $issue = (new AgentIssue('ad_strength_unavailable', 'The Google ad strength check failed. Current ad strength is unknown.'))->toArray();
            $snapshot['ad_strength']['errors'] = [$issue];
            $snapshot['issues'][] = $issue;
            $snapshot['errors'][] = $issue;
            Log::error('Google ad strength unavailable', ['campaign_id' => $campaign->id, 'strategy_id' => $strategy->id, 'error' => $e->getMessage()]);
        }
        $snapshot['ready'] = ($snapshot['conversion_goals']['status'] ?? 'unknown') === 'ready' && ($snapshot['conversion_goals']['ready'] ?? false)
            && ($snapshot['audience_observation']['ready'] ?? false)
            && (($snapshot['ad_strength']['status'] ?? null) === 'not_applicable'
                || ($snapshot['ad_strength']['checked'] ?? false) && ($snapshot['ad_strength']['verified'] ?? false) && empty($snapshot['ad_strength']['skipped']))
            && $snapshot['issues'] === [];
        $snapshot['status'] = $snapshot['errors'] !== [] || ($snapshot['conversion_goals']['status'] ?? 'unknown') === 'unknown'
            || ($snapshot['audience_observation']['status'] ?? 'unknown') === 'unknown' ? 'unknown'
            : ($snapshot['issues'] !== [] || ($snapshot['conversion_goals']['status'] ?? null) === 'needs_review'
                || ($snapshot['audience_observation']['status'] ?? null) === 'needs_review' ? 'needs_review' : ($snapshot['ready'] ? 'ready' : 'skipped'));

        $audienceRepairedWithUnverifiedConfiguration = ! empty($snapshot['audience_observation']['actions'])
            && ($strategy->execution_result['metadata']['configuration_verification']['passed'] ?? null) === false;
        if ($snapshot['ready'] && ($strategy->deployment_status === 'deploy_unverified' || $audienceRepairedWithUnverifiedConfiguration)) {
            try {
                if ($verifier->supports($strategy->platform) && $verifier->verify($strategy, $customer)) {
                    if ($strategy->deployment_status === 'deploy_unverified') {
                        $strategy->update(['deployment_status' => 'verified']);
                    }
                    $snapshot['deployment_verified'] = true;
                } else {
                    $snapshot['deployment_verified'] = false;
                    $snapshot['issues'][] = (new AgentIssue('deployment_configuration_not_verified', 'Google has not confirmed that the repaired deployment matches its approved configuration.'))->toArray();
                    $snapshot['ready'] = false;
                    $snapshot['status'] = 'needs_review';
                }
            } catch (\Throwable $e) {
                report($e);
                $issue = (new AgentIssue('deployment_reverification_unavailable', 'The repaired configuration could not be verified against Google.'))->toArray();
                $snapshot['errors'][] = $issue;
                $snapshot['issues'][] = $issue;
                $snapshot['ready'] = false;
                $snapshot['status'] = 'unknown';
            }
        }

        return $this->retainUnobservedEvidence($snapshot, $previous);
    }

    /** A pause or failed read does not establish that an earlier issue is resolved. */
    private function retainUnobservedEvidence(array $snapshot, array $previous): array
    {
        $previousUnknown = false;
        foreach (['conversion_goals', 'audience_observation', 'ad_strength'] as $component) {
            if (($snapshot[$component]['status'] ?? null) === 'not_applicable') {
                continue;
            }
            $notObserved = $component === 'ad_strength' ? ! ($snapshot[$component]['checked'] ?? false)
                : ($snapshot[$component]['status'] ?? 'unknown') === 'unknown';
            if (! $notObserved || empty($previous[$component])) {
                continue;
            }
            $last = $previous[$component]['last_known'] ?? $previous[$component];
            unset($last['last_known']);
            $snapshot[$component]['last_known'] = $last;
            $snapshot[$component]['last_known_checked_at'] = $previous[$component]['last_known_checked_at'] ?? $previous['checked_at'] ?? null;
            $issues = $component !== 'ad_strength' ? $this->issues($last['issues'] ?? [])
                : array_merge($this->issues($last['errors'] ?? []), $this->unresolved($last['unresolved'] ?? []));
            $snapshot['issues'] = array_merge($snapshot['issues'], $issues);
            $previousUnknown = $previousUnknown || (! empty($issues) && (($last['status'] ?? null) === 'unknown' || ! empty($last['errors'])));
        }
        $snapshot['issues'] = array_values(array_unique($snapshot['issues'], SORT_REGULAR));
        if ($snapshot['issues'] !== []) {
            $snapshot['ready'] = false;
            $snapshot['status'] = $snapshot['status'] === 'unknown' || $previousUnknown ? 'unknown' : 'needs_review';
        }

        return $snapshot;
    }

    private function readBlocked(Customer $customer): ?string
    {
        return match (true) {
            $customer->is_sandbox => 'sandbox',
            ! EnabledPlatform::isEnabled('google') => 'google_disabled',
            ! $customer->google_ads_customer_id => 'missing_google_account',
            in_array($customer->google_ads_link_status, ['pending', 'refused', 'cancelled', 'failed', 'revoked'], true) => 'google_management_unavailable',
            default => null,
        };
    }

    private function mutationBlocked(Campaign $campaign, Strategy $strategy): ?string
    {
        // Long-lived workers must observe flags changed while an API check ran.
        Feature::flushCache();
        Cache::forget('enabled_platform_slugs');
        Cache::forget('setting_managed_billing_enabled');
        $customer = $campaign->customer;
        if (! $customer) {
            return 'customer_inactive';
        }
        if ($blocked = $this->readBlocked($customer)) {
            return $blocked;
        }
        if ($campaign->status !== CampaignStatus::Active) {
            return 'campaign_not_active';
        }
        if ($campaign->platform_status === 'PAUSED') {
            return 'google_campaign_paused';
        }
        if ($campaign->hasPassedEndDate()) {
            return 'campaign_ended';
        }
        if ($customer->service_type === 'setup_only') {
            return 'setup_only';
        }
        if (! $strategy->signed_off_at) {
            return 'strategy_not_approved';
        }
        if (! Feature::for($customer)->active(AutoHealing::class)) {
            return 'auto_healing_disabled';
        }
        if (Setting::get('managed_billing_enabled', true) && ! $customer->isSelfFundedAds()
            && ! $customer->adSpendCredit()->first()?->canRunCampaigns()) {
            return 'ad_spend_unfunded';
        }

        return null;
    }

    private function campaignType(Strategy $strategy): string
    {
        $type = strtolower(trim($strategy->campaign_type ?? ''));

        // Older Search deployments inherited the schema's display default.
        return $type === 'display' && ($strategy->execution_result['metadata']['google_search_baseline']['version'] ?? null) === 1 ? 'search' : $type;
    }

    private function knownNonSearch(Strategy $strategy): bool
    {
        return in_array($this->campaignType($strategy), ['display', 'performance_max', 'video', 'demand_gen', 'shopping', 'local_services', 'app'], true);
    }

    private function persist(Strategy $strategy, array $snapshot): void
    {
        DB::transaction(function () use ($strategy, $snapshot) {
            $locked = Strategy::whereKey($strategy->id)->lockForUpdate()->firstOrFail();
            $execution = $locked->execution_result ?? [];
            $previous = $execution['metadata']['google_readiness'] ?? [];
            $execution['metadata']['google_readiness'] = $snapshot;
            $locked->update(['execution_result' => $execution]);
            if (($previous['status'] ?? null) !== $snapshot['status'] || ($previous['issues'] ?? []) !== $snapshot['issues']
                || ! empty($snapshot['conversion_goals']['actions']) || ! empty($snapshot['audience_observation']['actions']) || ! empty($snapshot['ad_strength']['actions'])) {
                AgentActivity::record('google_readiness', 'google_readiness_checked',
                    $snapshot['ready'] ? 'Google readiness checks are verified for this campaign type.' : 'Google readiness '.$snapshot['status'].($snapshot['skip_reason'] ? ': '.str_replace('_', ' ', $snapshot['skip_reason']) : '.'),
                    $locked->campaign->customer_id, $locked->campaign_id, ['strategy_id' => $locked->id, 'readiness' => $snapshot], $snapshot['ready'] ? 'completed' : ($snapshot['status'] === 'skipped' ? 'skipped' : 'needs_review'));
            }
        });
    }

    private function escalate(Campaign $campaign, Strategy $strategy, array $snapshot): void
    {
        $fingerprint = hash('sha256', json_encode([$strategy->id, $snapshot['issues']], JSON_THROW_ON_ERROR));
        if (AgentActivity::where('campaign_id', $campaign->id)->where('action', 'google_readiness_alerted')
            ->where('details->fingerprint', $fingerprint)->where('created_at', '>=', now()->subDay())->exists()) {
            return;
        }
        CriticalAgentAlert::deliver('google_campaign_readiness', 'Google campaign readiness needs attention',
            'Conversion goals, Search audience mode or ad strength could not be verified for "'.$campaign->name.'". Review the reported issues; campaign pause and budget settings have been preserved.',
            ['campaign_id' => $campaign->id, 'customer_id' => $campaign->customer_id, 'strategy_id' => $strategy->id, 'issues' => $snapshot['issues'], 'action_url' => route('admin.campaigns.show', $campaign), 'dedupe_key' => $fingerprint],
            CriticalAgentAlert::RECIPIENTS_ADMINS, $campaign->customer);
        AgentActivity::record('google_readiness', 'google_readiness_alerted', 'Unresolved Google readiness issues escalated for review.',
            $campaign->customer_id, $campaign->id, ['strategy_id' => $strategy->id, 'fingerprint' => $fingerprint, 'issues' => $snapshot['issues']], 'needs_review');
    }

    /** @return list<array{code:string,message:string}> */
    private function issues(array $issues): array
    {
        return array_map(fn (AgentIssue $issue) => $issue->toArray(), AgentIssue::list($issues));
    }

    /** @return list<array{code:string,message:string}> */
    private function unresolved(array $issues): array
    {
        return array_map(function ($issue) {
            if (is_array($issue) && isset($issue['reason'])) {
                return (new AgentIssue('ad_strength_'.$issue['reason'], 'Google ad strength needs review: '.str_replace('_', ' ', $issue['reason']).'.'))->toArray();
            }

            return AgentIssue::from($issue)->toArray();
        }, $issues);
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordRunFailure($exception);
        Log::error('Google campaign readiness job failed', ['campaign_id' => $this->campaignId, 'error' => $exception->getMessage()]);
    }
}
