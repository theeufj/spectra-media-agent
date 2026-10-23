<?php

namespace App\Services\Onboarding;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\AgentIssue;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\GetConversionActionDetails;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use App\Services\GoogleAds\WebsiteConversionActions;
use App\Services\GTM\GTMContainerService;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus as GoogleStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Customer-reviewed conversion configuration, separate from installed or tested tracking. */
class SetupConversionGoal
{
    public function page(Campaign $campaign, ?string $strategyUuid = null): array
    {
        $customer = $this->customer($campaign);
        $strategy = $this->strategy($campaign, uuid: $strategyUuid);
        $status = $this->status($campaign, $strategy?->uuid);
        $saved = $strategy?->execution_result['metadata']['setup_conversion_goal'] ?? [];
        $category = $strategy ? ReconcileCampaignConversionGoals::categoryFor($strategy) : null;
        $goal = $saved['goal'] ?? ['objective' => array_search($category, WebsiteConversionActions::CATEGORIES, true) ?: 'lead',
            'trigger' => 'thank_you_url', 'thank_you_url' => '', 'event_name' => '', 'action_resource' => ''];
        $actions = [];
        $actionsError = null;
        if ($customer->google_ads_customer_id) {
            try {
                $actions = app(WebsiteConversionActions::class, ['customer' => $customer])->all();
            } catch (\Throwable $e) {
                report($e);
                $actionsError = 'Existing Google conversion actions could not be loaded. Retry before choosing an existing action.';
            }
        }

        return ['campaign' => $campaign->only(['id', 'uuid', 'name']),
            'customer' => $customer->only(['website', 'gtm_installed']), 'goal' => $goal,
            'existingActions' => array_map(fn ($action) => array_intersect_key($action, array_flip(['resource', 'name', 'objective'])), $actions),
            'availableActionsError' => $actionsError, 'canConfigure' => $strategy !== null,
            'strategies' => $this->strategies($campaign)->get(['uuid', 'platform'])->toArray(), 'selectedStrategyUuid' => $strategy?->uuid,
            'statusUrl' => route('campaigns.conversion-goal.status', ['campaign' => $campaign, 'strategy' => $strategy?->uuid]), ...$status];
    }

    public function status(Campaign $campaign, ?string $strategyUuid = null): array
    {
        $customer = $this->customer($campaign);
        $strategy = $this->strategy($campaign, uuid: $strategyUuid);
        $configuration = self::configurationFor($strategy);
        if (! $strategy) {
            $configuration['message'] = 'Your campaign draft is being prepared. Return here once it is ready.';
        }

        return ['configuration' => $configuration,
            'installation' => ['tag_detected' => (bool) $customer->gtm_installed,
                'setup_url' => route('customers.gtm.setup', $customer)],
            'nativeTracking' => $strategy ? app(\App\Services\OwnSitePurchaseTracking::class)->statusForStrategy($strategy) : ['connected' => false],
            'conversionVerification' => self::conversionVerificationFor($strategy)];
    }

    /** Only current Google reporting for this campaign and selected action can prove attribution. */
    public static function conversionVerificationFor(?Strategy $strategy): array
    {
        $configuration = self::configurationFor($strategy);
        $health = $strategy?->execution_result['metadata']['conversion_tracking_health'] ?? [];
        $resource = $strategy?->reusableGoogleCampaignId();
        if ($resource && ctype_digit($resource)) {
            $resource = 'customers/'.$strategy->campaign->customer->cleanGoogleCustomerId().'/campaigns/'.$resource;
        }
        $checked = strtotime($health['checked_at'] ?? '');
        $count = $health['selected_action_activity']['all_conversions'] ?? null;
        $verified = ($configuration['status'] ?? null) === 'configured' && ($health['status'] ?? null) === 'conversions_observed'
            && $resource && ($health['campaign_resource'] ?? null) === $resource
            && ! empty($configuration['action_resource']) && ($health['intent']['action_resource'] ?? null) === $configuration['action_resource']
            && $checked !== false && $checked <= now()->timestamp && $checked >= now()->subDay()->timestamp
            && $checked >= strtotime($configuration['requested_at'] ?? '1970-01-01')
            && is_numeric($count) && is_finite((float) $count) && $count > 0;

        return ['verified' => (bool) $verified, 'source' => $verified ? 'google_reported_selected_action' : null,
            'checked_at' => $verified ? $health['checked_at'] : null];
    }

    public static function configurationFor(?Strategy $strategy): array
    {
        $configuration = $strategy?->execution_result['metadata']['setup_conversion_goal'] ?? [
            'status' => 'not_configured', 'issues' => [], 'request_id' => null,
            'message' => 'Choose what a successful customer action means for your business.',
        ];
        $readiness = $strategy?->execution_result['metadata']['conversion_goal_readiness'] ?? [];
        $selected = $configuration['action_resource'] ?? null;
        $category = WebsiteConversionActions::CATEGORIES[$configuration['goal']['objective'] ?? ''] ?? null;
        $baseline = $strategy?->execution_result['metadata']['google_search_baseline']['conversion_goal'] ?? [];
        $resource = $strategy?->reusableGoogleCampaignId();
        $account = $strategy?->campaign->customer?->cleanGoogleCustomerId() ?? '';
        if ($resource && ctype_digit($resource)) {
            $resource = 'customers/'.$strategy->campaign->customer->cleanGoogleCustomerId().'/campaigns/'.$resource;
        }
        $newEnough = ! isset($configuration['requested_at']) || (isset($readiness['checked_at'])
            && strtotime($readiness['checked_at']) !== false && strtotime($readiness['checked_at']) >= strtotime($configuration['requested_at']));
        $verified = $strategy && $selected && $category && $resource && ctype_digit($account)
            && preg_match('#^customers/'.$account.'/campaigns/\d+$#', $resource)
            && preg_match('#^customers/'.$account.'/conversionActions/\d+$#', $selected)
            && ($readiness['ready'] ?? false) === true && ($readiness['configuration_verified'] ?? false) === true && $newEnough
            && ($readiness['campaign_resource'] ?? null) === $resource
            && ($readiness['intent']['action_resource'] ?? null) === $selected && ($readiness['intent']['category'] ?? null) === $category
            && ($baseline['action_resource'] ?? null) === $selected && ($baseline['category'] ?? null) === $category
            && ($strategy->conversion_goals['action_resource'] ?? null) === $selected
            && ReconcileCampaignConversionGoals::categoryFor($strategy) === $category;
        $finished = ($configuration['status'] ?? '') !== 'waiting_for_campaign' || $strategy?->isDeployed();
        if (in_array($configuration['status'] ?? '', ['waiting_for_campaign', 'configured'], true) && $verified && $finished) {
            $configuration['status'] = 'configured';
            $configuration['message'] = ($configuration['goal']['trigger'] ?? '') === 'prepare_only'
                ? 'Google Ads goal configured. Connect website tracking and test a real conversion before activation.'
                : 'Google confirmed the selected campaign goal. Website installation and a real test conversion are separate checks.';
        } elseif (($configuration['status'] ?? '') === 'configured') {
            $configuration['status'] = ($readiness['status'] ?? '') === 'unknown' ? 'unknown' : 'needs_review';
            $configuration['issues'] = [['code' => 'goal_configuration_requires_recheck', 'message' => 'The current campaign goal no longer has matching verified settings. Retry configuration.']];
            $configuration['message'] = 'The saved goal requires a current Google settings check.';
        }

        return $configuration;
    }

    public function request(Campaign $campaign, array $goal, ?string $strategyUuid = null): string
    {
        $customer = $this->customer($campaign);
        $this->validateDefinition($customer, $goal);

        try {
            return Cache::lock($this->lockKey($campaign), 300)->block(5, function () use ($campaign, $goal, $strategyUuid) {
                return DB::transaction(function () use ($campaign, $goal, $strategyUuid) {
                    $current = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
                    $this->customer($current);
                    if (! $strategyUuid && $this->strategies($current)->count() > 1) {
                        throw ValidationException::withMessages(['strategy' => 'Choose which Google Search strategy to configure.']);
                    }
                    $strategy = $this->strategy($current, true, $strategyUuid);
                    if (! $strategy) {
                        throw ValidationException::withMessages(['objective' => 'Your campaign draft is still being prepared. Try again when it is ready.']);
                    }
                    if (in_array($strategy->deployment_status, ['queued', 'deploying'], true)) {
                        throw ValidationException::withMessages(['objective' => 'Wait for the current campaign build to finish before changing its goal.']);
                    }
                    $requestId = (string) Str::uuid();
                    $execution = $strategy->execution_result ?? [];
                    $mayHaveTag = $execution['metadata']['setup_conversion_goal']['managed_tag_may_exist'] ?? false;
                    $managedAction = $execution['metadata']['setup_conversion_goal']['managed_tag_action_resource'] ?? null;
                    $execution['metadata']['setup_conversion_goal'] = ['request_id' => $requestId, 'requested_at' => now()->toIso8601String(),
                        'status' => 'configuring', 'goal' => $goal, 'issues' => [], 'managed_tag_may_exist' => $mayHaveTag,
                        'managed_tag_action_resource' => $managedAction];
                    $strategy->update(['execution_result' => $execution]);

                    return $requestId;
                });
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            throw ValidationException::withMessages(['objective' => 'Goal setup is still running. Wait for it to finish before saving another choice.']);
        }
    }

    public function configure(Campaign $campaign, string $requestId): void
    {
        Cache::lock($this->lockKey($campaign), 300)->block(5, function () use ($campaign, $requestId) {
            $strategy = $this->strategies($campaign)->where('execution_result->metadata->setup_conversion_goal->request_id', $requestId)->first();
            $configuration = $strategy?->execution_result['metadata']['setup_conversion_goal'] ?? [];
            if (! $strategy || ($configuration['request_id'] ?? null) !== $requestId) {
                return; // A newer saved choice superseded this queued job.
            }
            try {
                $customer = $this->customer($campaign->fresh());
                $goal = $configuration['goal'];
                $this->validateDefinition($customer, $goal);
                $this->assertEligible($customer, $strategy);
                if (! $customer->google_ads_customer_id) {
                    $this->save($strategy, $requestId, ['status' => 'waiting_for_campaign', 'message' => 'Goal saved. Your Google Ads account is being prepared; configuration will continue after it is created.']);

                    return;
                }
                $resource = $this->resource($strategy, $customer);
                $reviewedWebsite = $customer->website;
                $checkPointers = function () use ($customer, $reviewedWebsite) {
                    $currentCustomer = $customer->fresh();
                    if ($currentCustomer->website !== $reviewedWebsite || $currentCustomer->gtm_container_id !== $customer->gtm_container_id
                        || $currentCustomer->gtm_account_id !== $customer->gtm_account_id || $currentCustomer->gtm_workspace_id !== $customer->gtm_workspace_id
                        || ($currentCustomer->gtm_config['container_path'] ?? null) !== ($customer->gtm_config['container_path'] ?? null)) {
                        throw ValidationException::withMessages(['objective' => 'Your website or managed tag container changed. Retry configuration with the current website setup.']);
                    }
                };
                $authorize = function () use ($customer, $strategy, $resource, $checkPointers) {
                    if ($this->resource($strategy->fresh(), $customer) !== $resource) {
                        throw ValidationException::withMessages(['objective' => 'The Google campaign changed while its goal was being prepared. Retry against the current paused campaign.']);
                    }
                    $checkPointers();
                    $resource ? $this->assertPaused($customer, $strategy, $resource) : $this->assertEligible($customer, $strategy);
                    $checkPointers();
                };
                if ($resource) {
                    $this->assertPaused($customer, $strategy, $resource);
                }
                $actions = app(WebsiteConversionActions::class, ['customer' => $customer]);
                $action = $goal['trigger'] === 'existing_action'
                    ? collect($actions->all())->firstWhere('resource', $goal['action_resource'])
                    : $actions->createFor($strategy, $goal['objective'], $authorize);
                if (! $action || $action['objective'] !== $goal['objective']) {
                    throw ValidationException::withMessages(['action_resource' => 'Choose an enabled website conversion action that matches this business goal in your Google Ads account.']);
                }
                if (in_array($goal['trigger'], ['thank_you_url', 'event'], true)) {
                    $gtm = app(GTMContainerService::class);
                    if (! $customer->gtm_container_id || ($customer->gtm_config['customer_uuid'] ?? null) !== $customer->uuid) {
                        $provision = $gtm->provisionContainerForCustomer($customer, customerScoped: true, authorize: $authorize);
                        if (! ($provision['success'] ?? false)) {
                            throw new \RuntimeException('The managed website tag container could not be prepared.');
                        }
                        $customer->refresh();
                    }
                    $details = app(GetConversionActionDetails::class, ['customer' => $customer])($customer->cleanGoogleCustomerId(), $action['resource']);
                    if (! ($details['conversion_id'] ?? null) || ! ($details['conversion_label'] ?? null)) {
                        throw new \RuntimeException('Google has not supplied a website tag for this conversion action yet. Retry shortly.');
                    }
                    // Remember the possibility before publishing, so a timeout
                    // cannot hide an old tag from a later prepare-only choice.
                    $this->save($strategy, $requestId, ['managed_tag_may_exist' => true, 'managed_tag_action_resource' => $action['resource']]);
                    $tag = $gtm->configureReviewedConversionTag($customer, $strategy->uuid, $goal, $details['conversion_id'], $details['conversion_label'], $authorize);
                    if (! ($tag['success'] ?? false)) {
                        throw new \RuntimeException('The reviewed website conversion tag could not be published. Retry configuration; the campaign remains paused.');
                    }
                } elseif (($configuration['managed_tag_may_exist'] ?? false)
                    && ($goal['trigger'] === 'prepare_only' || ($configuration['managed_tag_action_resource'] ?? null) !== $action['resource'])
                    && $customer->gtm_container_id && ($customer->gtm_config['customer_uuid'] ?? null) === $customer->uuid
                    && (string) $customer->gtm_account_id === (string) config('services.gtm.platform_account_id')) {
                    $retired = app(GTMContainerService::class)->retireReviewedConversionTag($customer, $strategy->uuid, $authorize);
                    if (! ($retired['success'] ?? false)) {
                        throw new \RuntimeException('The previous managed conversion tag could not be retired. Retry before using the replacement action.');
                    }
                    $this->save($strategy, $requestId, ['managed_tag_may_exist' => false, 'managed_tag_action_resource' => null]);
                }
                $authorize();
                DB::transaction(function () use ($strategy, $action, $goal, $requestId) {
                    $locked = Strategy::whereKey($strategy->id)->lockForUpdate()->firstOrFail();
                    if (($locked->execution_result['metadata']['setup_conversion_goal']['request_id'] ?? null) !== $requestId) {
                        throw new \LogicException('The reviewed goal changed during configuration.');
                    }
                    $locked->update(['conversion_goals' => ['primary_goal' => WebsiteConversionActions::CATEGORIES[$goal['objective']],
                        'action_resource' => $action['resource'], 'origin' => 'WEBSITE', 'selection_mode' => 'exact_action']]);
                });
                $strategy->refresh();
                if (! $resource) {
                    $this->save($strategy, $requestId, ['status' => 'waiting_for_campaign', 'action_name' => $action['name'],
                        'action_resource' => $action['resource'], 'message' => $goal['trigger'] === 'prepare_only'
                            ? 'Google action prepared without a website trigger. Create your paused campaign so we can apply its goal; connect website tracking next.'
                            : 'Goal prepared. Create your paused campaign so we can apply and verify its Google goal settings.']);

                    return;
                }
                $readiness = app(ReconcileCampaignConversionGoals::class, ['customer' => $customer])->reconcilePausedSetup($strategy, $resource);
                $this->assertPaused($customer, $strategy, $resource);
                if (($readiness['ready'] ?? false) !== true || ($readiness['intent']['action_resource'] ?? null) !== $action['resource']) {
                    $this->save($strategy, $requestId, ['status' => 'needs_review', 'action_name' => $action['name'], 'action_resource' => $action['resource'],
                        'issues' => array_map(fn ($issue) => $issue->toArray(), AgentIssue::list($readiness['issues'] ?? [])),
                        'message' => 'The goal choice is saved. Google campaign settings still require a successful verification; retry configuration.']);

                    return;
                }
                $this->save($strategy, $requestId, ['status' => 'configured', 'action_name' => $action['name'], 'action_resource' => $action['resource'],
                    'issues' => [], 'message' => $goal['trigger'] === 'prepare_only'
                        ? 'Google Ads goal configured. Connect website tracking and test a real conversion before activation.'
                        : 'Google confirmed the selected campaign goal. Install your website tag and test a real conversion before activation.']);
                \App\Jobs\CheckGoogleCampaignReadiness::dispatch($campaign->id);
            } catch (ValidationException $e) {
                $this->save($strategy, $requestId, ['status' => 'needs_review', 'issues' => [['code' => 'goal_choice_needs_review', 'message' => collect($e->errors())->flatten()->implode(' ')]],
                    'message' => 'Your goal needs review. The campaign has not been activated.']);
            } catch (\Throwable $e) {
                report($e);
                $this->save($strategy, $requestId, ['status' => 'unknown', 'issues' => [['code' => 'goal_configuration_unavailable',
                    'message' => 'We could not verify the goal configuration. Retry, or contact support if this continues.']], 'message' => 'Configuration needs another check. The campaign has not been activated.']);
                throw $e;
            }
        });
    }

    private function customer(Campaign $campaign): Customer
    {
        $customer = $campaign->customer()->firstOrFail();
        if (! $customer->isPaidSetupOnly()) {
            throw ValidationException::withMessages(['objective' => 'This workflow is for a paid one-time setup account.']);
        }

        return $customer;
    }

    private function strategies(Campaign $campaign)
    {
        return $campaign->strategies()->where('campaign_type', 'search')->whereRaw('LOWER(platform) LIKE ?', ['%google%'])->orderBy('id');
    }

    private function strategy(Campaign $campaign, bool $lock = false, ?string $uuid = null): ?Strategy
    {
        $query = $this->strategies($campaign);
        if ($uuid) {
            $query->where('uuid', $uuid);
        }

        $query = $lock ? $query->lockForUpdate() : $query;

        return $uuid ? $query->firstOrFail() : $query->first();
    }

    private function assertPaused(Customer $customer, Strategy $strategy, string $resource): void
    {
        $this->assertEligible($customer, $strategy);
        $fresh = $strategy->fresh()->load('campaign.customer');
        $current = $fresh->campaign->customer;
        $id = $customer->cleanGoogleCustomerId();
        if (! $current?->isPaidSetupOnly() || $current->id !== $customer->id || $current->cleanGoogleCustomerId() !== $id
            || $this->resource($fresh, $current) !== $resource || ! preg_match('#^customers/'.$id.'/campaigns/\d+$#', $resource)
            || in_array($fresh->deployment_status, ['queued', 'deploying'], true)
            || $fresh->campaign->hasPassedEndDate() || in_array($fresh->campaign->status->value, ['ended', 'completed'], true)
            || $fresh->campaign->spend_safety_hold || \App\Services\Campaigns\CampaignSpendGuardrails::activeTrial($fresh->campaign)
            || in_array($current->google_ads_link_status, ['pending', 'refused', 'cancelled', 'failed', 'revoked'], true)) {
            throw ValidationException::withMessages(['objective' => 'Your account or campaign changed. Review its setup before configuring a goal.']);
        }
        $status = app(GetCampaignStatus::class, ['customer' => $current])($id, $resource);
        if (($status['status'] ?? null) !== GoogleStatus::PAUSED) {
            throw ValidationException::withMessages(['objective' => 'The campaign must be verified paused before its conversion goal can be changed. No activation is performed here.']);
        }
        $this->assertEligible($customer, $strategy);
        if ($this->resource($strategy->fresh(), $customer) !== $resource) {
            throw ValidationException::withMessages(['objective' => 'The campaign changed during its paused-status check. Retry configuration.']);
        }
    }

    private function assertEligible(Customer $customer, Strategy $strategy): void
    {
        $fresh = $strategy->fresh()->load('campaign.customer');
        $current = $fresh->campaign->customer;
        if (! $current?->isPaidSetupOnly() || $current->id !== $customer->id || $current->cleanGoogleCustomerId() !== $customer->cleanGoogleCustomerId()
            || in_array($fresh->deployment_status, ['queued', 'deploying'], true)
            || $fresh->campaign->hasPassedEndDate() || in_array($fresh->campaign->status->value, ['ended', 'completed'], true)
            || $fresh->campaign->spend_safety_hold || \App\Services\Campaigns\CampaignSpendGuardrails::activeTrial($fresh->campaign)
            || in_array($current->google_ads_link_status, ['pending', 'refused', 'cancelled', 'failed', 'revoked'], true)) {
            throw ValidationException::withMessages(['objective' => 'Your account or campaign changed. Review its setup before configuring a goal.']);
        }
    }

    private function resource(Strategy $strategy, Customer $customer): ?string
    {
        $resource = $strategy->reusableGoogleCampaignId();

        return $resource && ctype_digit($resource) ? 'customers/'.$customer->cleanGoogleCustomerId().'/campaigns/'.$resource : $resource;
    }

    private function validateDefinition(Customer $customer, array $goal): void
    {
        if (! isset(WebsiteConversionActions::CATEGORIES[$goal['objective'] ?? '']) || ! in_array($goal['trigger'] ?? '', ['thank_you_url', 'event', 'existing_action', 'prepare_only'], true)) {
            throw ValidationException::withMessages(['objective' => 'Choose a supported business goal and conversion trigger.']);
        }
        if ($goal['trigger'] === 'thank_you_url') {
            $url = parse_url($goal['thank_you_url'] ?? '');
            $site = parse_url($customer->website ?? '');
            if (! $url || ! $site || ($url['scheme'] ?? null) !== 'https' || ($url['host'] ?? null) !== ($site['host'] ?? null)
                || ($url['port'] ?? 443) !== ($site['port'] ?? 443) || in_array($url['path'] ?? '/', ['', '/'], true)
                || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) {
                throw ValidationException::withMessages(['thank_you_url' => 'Use an exact HTTPS confirmation-page URL on your business website, excluding the home page.']);
            }
        } elseif ($goal['trigger'] === 'event') {
            $event = $goal['event_name'] ?? '';
            if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{1,99}$/', $event) || in_array(strtolower($event), ['page_view', 'pageview', 'click', 'scroll', 'gtm'], true)) {
                throw ValidationException::withMessages(['event_name' => 'Use the specific data-layer event fired after a successful conversion, rather than a generic page visit or click.']);
            }
        } elseif ($goal['trigger'] === 'existing_action' && ! preg_match('#^customers/'.preg_quote($customer->cleanGoogleCustomerId(), '#').'/conversionActions/\d+$#', $goal['action_resource'] ?? '')) {
            throw ValidationException::withMessages(['action_resource' => 'Select a conversion action from this customer Google Ads account.']);
        }
    }

    private function save(Strategy $strategy, string $requestId, array $changes): void
    {
        DB::transaction(function () use ($strategy, $requestId, $changes) {
            $locked = Strategy::whereKey($strategy->id)->lockForUpdate()->firstOrFail();
            $execution = $locked->execution_result ?? [];
            $setup = $execution['metadata']['setup_conversion_goal'] ?? [];
            if (($setup['request_id'] ?? null) !== $requestId) {
                return;
            }
            $execution['metadata']['setup_conversion_goal'] = array_replace($setup, $changes, ['checked_at' => now()->toIso8601String()]);
            $locked->update(['execution_result' => $execution]);
        });
    }

    public function failed(Campaign $campaign, string $requestId): void
    {
        $strategy = $this->strategies($campaign)->where('execution_result->metadata->setup_conversion_goal->request_id', $requestId)->first();
        if ($strategy) {
            $this->save($strategy, $requestId, ['status' => 'unknown', 'issues' => [['code' => 'goal_configuration_interrupted',
                'message' => 'Configuration did not finish. Your choice is saved; retry configuration.']], 'message' => 'The campaign has not been activated.']);
        }
    }

    private function lockKey(Campaign $campaign): string
    {
        return 'setup-conversion-goal:'.$campaign->id;
    }
}
