<?php

namespace App\Services\GoogleAds;

use App\Features\AutoHealing;
use App\Models\AgentActivity;
use App\Models\EnabledPlatform;
use App\Models\Setting;
use App\Models\Strategy;
use App\Services\Agents\AgentIssue;
use Google\Ads\GoogleAds\V22\Enums\CustomConversionGoalStatusEnum\CustomConversionGoalStatus;
use Google\Ads\GoogleAds\V22\Resources\CampaignConversionGoal;
use Google\Ads\GoogleAds\V22\Resources\ConversionGoalCampaignConfig;
use Google\Ads\GoogleAds\V22\Resources\CustomConversionGoal;
use Google\Ads\GoogleAds\V22\Services\CampaignConversionGoalOperation;
use Google\Ads\GoogleAds\V22\Services\ConversionGoalCampaignConfigOperation;
use Google\Ads\GoogleAds\V22\Services\CustomConversionGoalOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCustomConversionGoalsRequest;
use Google\Ads\GoogleAds\V22\Services\MutateGoogleAdsRequest;
use Google\Ads\GoogleAds\V22\Services\MutateOperation;
use Google\Protobuf\FieldMask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/** Reconcile one campaign with declared intent, preserving account-wide primaries and defaults. */
class ReconcileCampaignConversionGoals extends BaseGoogleAdsService
{
    public static function categoryFor(Strategy $strategy): ?string
    {
        $declared = $strategy->conversion_goals['primary_goal'] ?? null;
        if ($declared === null || $declared === '') {
            $declared = $strategy->execution_result['metadata']['google_search_baseline']['conversion_goal']['category']
                ?? $strategy->execution_result['metadata']['google_search_baseline']['conversion_category'] ?? null;
        }
        if (! is_string($declared)) {
            return null;
        }

        return match (strtoupper(preg_replace('/[\s\-\/]+/', '_', trim($declared)))) {
            'PURCHASE', 'PURCHASES', 'SALE', 'SALES', 'PAID_SUBSCRIPTION', 'PAID_SUBSCRIPTIONS' => 'PURCHASE',
            'SIGNUP', 'SIGNUPS', 'SIGN_UP', 'SIGN_UPS', 'SIGN_UPS_REGISTRATIONS' => 'SIGNUP',
            'LEAD', 'LEADS', 'SUBMIT_LEAD_FORM' => 'SUBMIT_LEAD_FORM',
            default => null,
        };
    }

    /** Check action eligibility before creating a new Google campaign. */
    public function prepare(Strategy $strategy): array
    {
        return $this->check($strategy, null, false, true);
    }

    /** Read-only diagnosis; ready is false for a safely repairable campaign mismatch. */
    public function inspect(Strategy $strategy, ?string $campaignResource = null): array
    {
        return $this->check($strategy, $campaignResource, false);
    }

    /** Apply only campaign goal operations, then verify their effective settings. */
    public function reconcile(Strategy $strategy, ?string $campaignResource = null, bool $allowInactive = false): array
    {
        $lock = Cache::lock('campaign-conversion-goals:'.$strategy->id, 180);
        if (! $lock->get()) {
            return ['status' => 'needs_review', 'ready' => false, 'intent' => null, 'actions' => [],
                'issues' => [new AgentIssue('conversion_goal_check_in_progress', 'Another conversion goal check is in progress. Retry after it completes.')], 'repairable' => false];
        }
        try {
            $strategy->refresh()->load('campaign.customer');
            $campaign = $strategy->campaign;
            $credit = $campaign->customer?->adSpendCredit()->first();
            if (! $allowInactive && ($campaign->status->value !== 'active'
                || $campaign->platform_status === 'PAUSED' || $campaign->hasPassedEndDate()
                || ! $strategy->signed_off_at
                || $campaign->customer?->service_type === 'setup_only'
                || $campaign->customer?->google_ads_link_status === 'revoked'
                || (Setting::get('managed_billing_enabled', true) && ! $campaign->customer?->isSelfFundedAds()
                    && ! $credit?->canRunCampaigns()))) {
                return $this->result($strategy, null, [new AgentIssue('conversion_goal_campaign_inactive', 'Conversion goal changes are on hold while this campaign is unapproved, paused, inactive, restricted by billing or outside its approved dates.')]);
            }

            return $this->check($strategy, $campaignResource, true, allowInactive: $allowInactive);
        } finally {
            $lock->release();
        }
    }

    private function check(Strategy $strategy, ?string $resource, bool $mutate, bool $prepare = false, bool $allowInactive = false): array
    {
        if (! $this->customer || $strategy->campaign->customer_id !== $this->customer->id) {
            throw new \LogicException('Conversion goals must belong to the strategy customer.');
        }
        $customerId = $this->customer->cleanGoogleCustomerId();
        if (! $customerId || ! ctype_digit($customerId)) {
            return $this->result($strategy, null, [new AgentIssue('conversion_goal_account_missing', 'A Google Ads account is required to verify conversion goals.')]);
        }

        try {
            $category = self::categoryFor($strategy);
            if (! $category) {
                return $this->result($strategy, null, [new AgentIssue('conversion_goal_intent_missing', 'Choose an explicit supported conversion goal before using conversion bidding.')]);
            }
            $baseline = $strategy->execution_result['metadata']['google_search_baseline']['conversion_goal'] ?? [];
            $selected = $strategy->conversion_goals['action_resource'] ?? $strategy->conversion_goals['conversion_action_resource'] ?? $strategy->conversion_goals['conversion_action_id'] ?? null;
            if (! $selected && $customerId === (string) config('conversions.google_ads_customer_id')) {
                $event = match ($category) {
                    'SIGNUP' => 'signup_import', 'PURCHASE' => 'paid_subscription', default => null
                };
                if ($event) {
                    $selected = Setting::get('conversion_resource_name.'.$event);
                    if (! $selected) {
                        return $this->result($strategy, null, [new AgentIssue('conversion_goal_mapping_missing', 'The declared own-site conversion event has no configured action. Provision and validate it before changing bidding goals.')]);
                    }
                }
            }
            $selected ??= $baseline['action_resource'] ?? null;
            if ((is_string($selected) || is_int($selected)) && ctype_digit((string) $selected)) {
                $selected = "customers/{$customerId}/conversionActions/{$selected}";
            }
            $origin = $strategy->conversion_goals['origin'] ?? $baseline['origin'] ?? null;
            $rows = $this->readRows($customerId, "SELECT conversion_action.resource_name, conversion_action.name, conversion_action.status, conversion_action.type, conversion_action.category, conversion_action.origin, conversion_action.primary_for_goal FROM conversion_action WHERE conversion_action.status = 'ENABLED'");
            $candidates = array_values(array_filter(array_column($rows, 'conversionAction'), fn ($action) => ($action['category'] ?? '') === $category
                && ($action['status'] ?? '') === 'ENABLED'
                && ($selected || ($action['primaryForGoal'] ?? false))
                && (! $selected || ($action['resourceName'] ?? null) === $selected)
                && (! $origin || ($action['origin'] ?? null) === $origin)));
            if (count($candidates) !== 1) {
                return $this->result($strategy, null, [new AgentIssue('conversion_goal_primary_ambiguous',
                    $candidates === [] ? 'The intended goal has no matching enabled primary action. Review its tracking and primary settings.'
                        : 'Several primary conversion actions match this goal. Select the intended action before changing campaign bidding goals.')]);
            }
            $action = $candidates[0];
            if (empty($action['resourceName']) || ! in_array($action['origin'] ?? '', ['WEBSITE', 'GOOGLE_HOSTED', 'APP', 'CALL_FROM_ADS', 'STORE', 'YOUTUBE_HOSTED', 'LOCAL_SERVICES_ADS'], true)) {
                return $this->result($strategy, null, [new AgentIssue('conversion_goal_action_unknown', 'The intended conversion action has incomplete or unsupported provenance.')]);
            }
            $matchingPrimaries = array_filter(array_column($rows, 'conversionAction'), fn ($other) => ($other['category'] ?? '') === $category
                && ($other['origin'] ?? '') === $action['origin'] && ($other['primaryForGoal'] ?? false));
            $intent = ['mode' => ($action['primaryForGoal'] ?? false) && count($matchingPrimaries) === 1 ? 'category' : 'custom',
                'category' => $category, 'origin' => $action['origin'], 'action_resource' => $action['resourceName']];
            if ($issue = $this->validateDelivery($customerId, $action)) {
                return $this->result($strategy, $intent, [$issue]);
            }
            if ($prepare) {
                return $this->result($strategy, $intent);
            }

            $resource ??= $strategy->reusableGoogleCampaignId();
            if (! $resource) {
                return $this->result($strategy, $intent, [new AgentIssue('conversion_goal_campaign_missing', 'A deployed Google campaign is required to verify its bidding goals.')]);
            }
            if (ctype_digit($resource)) {
                $resource = "customers/{$customerId}/campaigns/{$resource}";
            }
            if (! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/\d+$#', $resource)) {
                throw new \LogicException('Google campaign resource does not belong to this customer.');
            }
            if ($mutate && ($held = $this->mutationHold($strategy, $customerId, $resource, $intent, $allowInactive))) {
                return $held;
            }
            $config = $this->configuration($customerId, $resource);
            $managedCustom = $baseline['custom_goal'] ?? $strategy->execution_result['metadata']['conversion_goal_readiness']['managed_custom_goal'] ?? null;
            if ($issue = $this->configIssue($config, $managedCustom)) {
                if ($intent['mode'] !== 'custom' || $config === []) {
                    return $this->result($strategy, $intent, [$issue]);
                }
            }
            $goals = $this->goals($customerId, $resource);
            if ($intent['mode'] === 'custom') {
                return $this->reconcileCustom($strategy, $customerId, $resource, $intent, $config, $goals, $mutate, $managedCustom, $allowInactive);
            }
            if (! empty($config['customConversionGoal'])) {
                return $this->result($strategy, $intent, [new AgentIssue('conversion_goal_custom', 'An existing custom goal requires review before switching to category bidding.')]);
            }
            $intended = array_filter($goals, fn ($goal) => $this->matches($goal, $intent));
            if (count($intended) !== 1) {
                return $this->result($strategy, $intent, [new AgentIssue('conversion_goal_missing', 'Google has not provided the campaign goal for the intended action category and origin.')]);
            }
            $updates = [];
            foreach ($goals as $goal) {
                $wanted = $this->matches($goal, $intent);
                if ((bool) ($goal['biddable'] ?? false) !== $wanted) {
                    if (empty($goal['resourceName'])) {
                        return $this->result($strategy, $intent, [new AgentIssue('conversion_goal_unknown', 'Google returned incomplete campaign goal evidence. No settings were changed.')]);
                    }
                    $updates[] = new MutateOperation(['campaign_conversion_goal_operation' => new CampaignConversionGoalOperation([
                        'update' => new CampaignConversionGoal(['resource_name' => $goal['resourceName'], 'biddable' => $wanted]),
                        'update_mask' => new FieldMask(['paths' => ['biddable']]),
                    ])]);
                }
            }
            if ($updates === []) {
                return $this->result($strategy, $intent);
            }
            if (! $mutate) {
                return $this->result($strategy, $intent, [new AgentIssue('conversion_goal_mismatch', 'Campaign bidding goals do not match the intended primary conversion action.')], repairable: true);
            }

            if ($held = $this->mutationHold($strategy, $customerId, $resource, $intent, $allowInactive)) {
                return $held;
            }
            $this->applyOperations($customerId, $updates);
            if ($this->dryRun) {
                return $this->result($strategy, $intent, [new AgentIssue('conversion_goal_validate_only', 'Google validated the goal changes; live settings have not been changed.')]);
            }
            // A successful mutation is not proof that the intended goals are effective.
            if ($this->configIssue($this->configuration($customerId, $resource))
                || ! $this->aligned($this->goals($customerId, $resource), $intent)) {
                return $this->result($strategy, $intent, [new AgentIssue('conversion_goal_unverified', 'Google has not confirmed the intended campaign goal settings. Review or retry the check.')]);
            }

            return $this->result($strategy, $intent, actions: [['type' => 'campaign_conversion_goals_updated', 'campaign_resource' => $resource, 'intent' => $intent]]);
        } catch (\Throwable $e) {
            $this->result($strategy, null, [new AgentIssue('conversion_goal_check_unavailable', 'Conversion goals could not be verified. The last successful configuration has not been cleared.')], status: 'unknown');
            throw $e;
        }
    }

    /** Re-read holds after remote work, immediately before each recurring write. */
    private function mutationHold(Strategy $strategy, string $customerId, string $resource, array $intent, bool $allowInactive): ?array
    {
        if ($allowInactive) {
            return null;
        }
        $rows = $this->readRows($customerId, "SELECT campaign.status FROM campaign WHERE campaign.resource_name = '{$resource}'");
        $remoteStatus = $rows[0]['campaign']['status'] ?? null;
        if ($remoteStatus !== 'ENABLED') {
            $unknown = ! in_array($remoteStatus, ['PAUSED', 'REMOVED'], true);

            return $this->result($strategy, $intent, [new AgentIssue('conversion_goal_campaign_inactive',
                $unknown ? 'Google campaign status is unavailable. Automatic conversion goal changes are on hold.'
                    : 'Google reports this campaign as '.$remoteStatus.'. Automatic conversion goal changes are on hold.')], status: $unknown ? 'unknown' : 'needs_review');
        }
        $fresh = Strategy::with('campaign.customer')->findOrFail($strategy->id);
        $campaign = $fresh->campaign;
        $customer = $campaign->customer;
        $currentResource = $fresh->reusableGoogleCampaignId();
        if ($currentResource && ctype_digit($currentResource)) {
            $currentResource = "customers/{$customerId}/campaigns/{$currentResource}";
        }
        // Pennant memoises values per worker; an admin can change the stored flag during API reads.
        Feature::flushCache();
        Cache::forget('enabled_platform_slugs');
        $reason = match (true) {
            ! $customer || $customer->id !== $this->customer?->id => 'customer_changed',
            $customer->cleanGoogleCustomerId() !== $customerId => 'google_account_changed',
            $currentResource !== $resource => 'google_campaign_changed',
            $fresh->conversion_goals !== $strategy->conversion_goals => 'reviewed_conversion_intent_changed',
            $customer->is_sandbox => 'sandbox',
            ! EnabledPlatform::isEnabled('google') => 'google_disabled',
            in_array($customer->google_ads_link_status, ['pending', 'refused', 'cancelled', 'failed', 'revoked'], true) => 'google_management_unavailable',
            $campaign->status->value !== 'active' => 'campaign_not_active',
            in_array(strtoupper((string) $campaign->platform_status), ['PAUSED', 'REMOVED'], true) => 'google_campaign_not_active',
            $campaign->hasPassedEndDate() => 'campaign_ended',
            $customer->service_type === 'setup_only' => 'setup_only',
            ! $fresh->signed_off_at => 'strategy_not_approved',
            ! Feature::for($customer)->active(AutoHealing::class) => 'auto_healing_disabled',
            Setting::get('managed_billing_enabled', true) && ! $customer->isSelfFundedAds()
                && ! $customer->adSpendCredit()->first()?->canRunCampaigns() => 'ad_spend_unfunded',
            default => null,
        };
        if ($reason) {
            return $this->result($strategy, $intent, [new AgentIssue('conversion_goal_campaign_inactive',
                'Automatic conversion goal changes are on hold: '.str_replace('_', ' ', $reason).'.')]);
        }

        return null;
    }

    private function matches(array $goal, array $intent): bool
    {
        return ($goal['category'] ?? '') === $intent['category'] && ($goal['origin'] ?? '') === $intent['origin'];
    }

    private function aligned(array $goals, array $intent): bool
    {
        return count(array_filter($goals, fn ($goal) => $this->matches($goal, $intent))) === 1
            && collect($goals)->every(fn ($goal) => (bool) ($goal['biddable'] ?? false) === $this->matches($goal, $intent));
    }

    private function configIssue(array $config, ?string $managedCustom = null): ?AgentIssue
    {
        if ($config === []) {
            return new AgentIssue('conversion_goal_config_unknown', 'Campaign conversion goal configuration is unavailable. No settings were changed.');
        }
        if (! empty($config['customConversionGoal']) && $config['customConversionGoal'] !== $managedCustom) {
            return new AgentIssue('conversion_goal_custom', 'This campaign uses a custom conversion goal. Review its intended actions before changing it.');
        }

        return null;
    }

    protected function validateDelivery(string $customerId, array $action): ?AgentIssue
    {
        $event = null;
        if ($customerId === (string) config('conversions.google_ads_customer_id')) {
            foreach (['paid_subscription', 'signup_import'] as $candidate) {
                if (($action['resourceName'] ?? '') === Setting::get('conversion_resource_name.'.$candidate)) {
                    $event = $candidate;
                    break;
                }
            }
        }
        if (! $event) {
            return null;
        }
        if (($action['type'] ?? '') !== 'UPLOAD_CLICKS' || ($action['category'] ?? '') !== ($event === 'paid_subscription' ? 'PURCHASE' : 'SIGNUP')) {
            return new AgentIssue('conversion_goal_delivery_type', 'The selected server-side action has an unexpected delivery type or category.');
        }
        $id = basename($action['resourceName']);
        $validation = app(DataManagerService::class)->ingestConversion(
            operatingAccountId: $customerId, conversionActionId: $id,
            adIdentifiers: ['gclid' => 'DIAGNOSTIC_FAKE_GCLID_0000'], value: 1, currency: 'USD', occurredAt: now(),
            validateOnly: true, transactionId: 'validate-campaign-conversion-goal',
        );
        if (! $validation['success']) {
            if (preg_match('/scope|access.token|MCC account|credentials|UNAUTHENTICATED|PERMISSION_DENIED/i', $validation['error'] ?? '')) {
                return new AgentIssue('conversion_goal_import_credentials', 'The management account credentials cannot validate this conversion import. Authorize Google Data Manager access before selecting this action for campaign bidding.');
            }

            return new AgentIssue('conversion_goal_delivery_not_ready', 'The selected conversion action cannot accept import validation yet. Check its upload credentials and readiness before using it for bidding.');
        }

        return null;
    }

    private function reconcileCustom(Strategy $strategy, string $customerId, string $resource, array $intent, array $config, array $goals, bool $mutate, ?string $managedCustom, bool $allowInactive): array
    {
        $campaignId = basename($resource);
        $name = 'Spectra campaign '.$campaignId.' strategy '.$strategy->id.' action '.basename($intent['action_resource']);
        $customs = $this->readRows($customerId, "SELECT custom_conversion_goal.resource_name, custom_conversion_goal.name, custom_conversion_goal.status, custom_conversion_goal.conversion_actions FROM custom_conversion_goal WHERE custom_conversion_goal.name = '{$name}' AND custom_conversion_goal.status = 'ENABLED'");
        $custom = $customs[0]['customConversionGoal'] ?? null;
        if ($custom && (($custom['conversionActions'] ?? []) !== [$intent['action_resource']] || count($customs) !== 1)) {
            return $this->result($strategy, $intent, [new AgentIssue('conversion_goal_custom_conflict', 'The managed custom goal differs from the intended action. Review it before changing bidding.')]);
        }
        $customResource = $custom['resourceName'] ?? null;
        $linkedCustom = $config['customConversionGoal'] ?? null;
        if ($linkedCustom && $linkedCustom !== $customResource && $linkedCustom !== $managedCustom) {
            return $this->result($strategy, $intent, [new AgentIssue('conversion_goal_custom', 'This campaign uses an unrelated custom goal. Review its intended actions before changing it.')]);
        }
        if ($customResource) {
            $intent['custom_goal'] = $customResource;
        }
        $aligned = $customResource && ($config['customConversionGoal'] ?? null) === $customResource
            && collect($goals)->every(fn ($goal) => ! ($goal['biddable'] ?? false));
        if ($aligned) {
            return $this->result($strategy, $intent);
        }
        if (! $mutate) {
            return $this->result($strategy, $intent, [new AgentIssue('conversion_goal_mismatch', 'The campaign does not yet bid only on its selected conversion action.')], repairable: true);
        }
        if (! $customResource) {
            if ($held = $this->mutationHold($strategy, $customerId, $resource, $intent, $allowInactive)) {
                return $held;
            }
            $customResource = $this->createCustomGoal($customerId, $name, $intent['action_resource']);
            if ($this->dryRun || ! $customResource) {
                return $this->result($strategy, $intent, [new AgentIssue('conversion_goal_custom_unverified', 'The campaign custom conversion goal has not been created and verified.')]);
            }
            $intent['custom_goal'] = $customResource;
        }
        if (! preg_match('#^customers/'.preg_quote($customerId, '#').'/customConversionGoals/\d+$#', $customResource)) {
            throw new \LogicException('The custom conversion goal does not belong to the campaign account.');
        }
        $operations = [new MutateOperation(['conversion_goal_campaign_config_operation' => new ConversionGoalCampaignConfigOperation([
            'update' => new ConversionGoalCampaignConfig(['resource_name' => $config['resourceName'] ?? "customers/{$customerId}/conversionGoalCampaignConfigs/{$campaignId}", 'custom_conversion_goal' => $customResource]),
            'update_mask' => new FieldMask(['paths' => ['custom_conversion_goal']]),
        ])])];
        foreach ($goals as $goal) {
            if ($goal['biddable'] ?? false) {
                if (empty($goal['resourceName'])) {
                    return $this->result($strategy, $intent, [new AgentIssue('conversion_goal_unknown', 'Google returned incomplete campaign goal evidence. No bidding settings were changed.')]);
                }
                $operations[] = new MutateOperation(['campaign_conversion_goal_operation' => new CampaignConversionGoalOperation([
                    'update' => new CampaignConversionGoal(['resource_name' => $goal['resourceName'], 'biddable' => false]),
                    'update_mask' => new FieldMask(['paths' => ['biddable']]),
                ])]);
            }
        }
        // Remember ownership before linking. A retry can safely recognise this custom goal.
        $this->result($strategy, $intent, [new AgentIssue('conversion_goal_update_pending', 'The selected custom goal is being applied and awaits Google verification.')]);
        if ($held = $this->mutationHold($strategy, $customerId, $resource, $intent, $allowInactive)) {
            return $held;
        }
        $this->applyOperations($customerId, $operations);
        $afterConfig = $this->configuration($customerId, $resource);
        $afterCustoms = $this->readRows($customerId, "SELECT custom_conversion_goal.resource_name, custom_conversion_goal.status, custom_conversion_goal.conversion_actions FROM custom_conversion_goal WHERE custom_conversion_goal.resource_name = '{$customResource}'");
        $afterCustom = $afterCustoms[0]['customConversionGoal'] ?? [];
        if ($this->dryRun || ($afterConfig['customConversionGoal'] ?? null) !== $customResource
            || ($afterCustom['status'] ?? '') !== 'ENABLED' || ($afterCustom['conversionActions'] ?? []) !== [$intent['action_resource']]
            || collect($this->goals($customerId, $resource))->contains(fn ($goal) => $goal['biddable'] ?? false)) {
            return $this->result($strategy, $intent, [new AgentIssue('conversion_goal_unverified', 'Google has not confirmed the campaign custom goal and its selected action. Review or retry the check.')]);
        }

        return $this->result($strategy, $intent, actions: [['type' => 'campaign_conversion_goals_updated', 'campaign_resource' => $resource, 'intent' => $intent]]);
    }

    protected function createCustomGoal(string $customerId, string $name, string $action): ?string
    {
        $this->ensureClient();
        $response = $this->client->getCustomConversionGoalServiceClient()->mutateCustomConversionGoals(new MutateCustomConversionGoalsRequest([
            'customer_id' => $customerId, 'validate_only' => $this->dryRun,
            'operations' => [new CustomConversionGoalOperation(['create' => new CustomConversionGoal([
                'name' => $name, 'conversion_actions' => [$action], 'status' => CustomConversionGoalStatus::ENABLED,
            ])])],
        ]));

        return $this->dryRun ? null : $response->getResults()[0]->getResourceName();
    }

    private function configuration(string $customerId, string $resource): array
    {
        $rows = $this->readRows($customerId, "SELECT conversion_goal_campaign_config.resource_name, conversion_goal_campaign_config.custom_conversion_goal, conversion_goal_campaign_config.goal_config_level FROM conversion_goal_campaign_config WHERE campaign.resource_name = '{$resource}'");

        return isset($rows[0]['conversionGoalCampaignConfig']) ? array_merge(['verified' => true], $rows[0]['conversionGoalCampaignConfig']) : [];
    }

    private function goals(string $customerId, string $resource): array
    {
        return array_column($this->readRows($customerId, "SELECT campaign_conversion_goal.resource_name, campaign_conversion_goal.category, campaign_conversion_goal.origin, campaign_conversion_goal.biddable FROM campaign_conversion_goal WHERE campaign.resource_name = '{$resource}'"), 'campaignConversionGoal');
    }

    protected function readRows(string $customerId, string $query): array
    {
        $this->ensureClient();
        $rows = [];
        foreach ($this->searchQuery($customerId, $query)->iterateAllElements() as $row) {
            $rows[] = json_decode($row->serializeToJsonString(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $rows;
    }

    protected function applyOperations(string $customerId, array $operations): void
    {
        $this->ensureClient();
        $this->client->getGoogleAdsServiceClient()->mutate(new MutateGoogleAdsRequest([
            'customer_id' => $customerId, 'mutate_operations' => $operations, 'partial_failure' => false, 'validate_only' => $this->dryRun,
        ]));
    }

    private function result(Strategy $strategy, ?array $intent, array $issues = [], array $actions = [], bool $repairable = false, string $status = 'needs_review'): array
    {
        $state = ['status' => $issues === [] ? 'ready' : $status, 'ready' => $issues === [], 'intent' => $intent,
            'actions' => $actions, 'issues' => $issues, 'repairable' => $repairable];
        // Other workers write readiness and deployment evidence to this same JSON column.
        // Read its latest contents under the same row lock they use, then change only our keys.
        $execution = DB::transaction(function () use ($strategy, $state, $intent, $issues, $actions, $status) {
            $locked = Strategy::whereKey($strategy->id)->lockForUpdate()->firstOrFail();
            if ($locked->campaign->customer_id !== $this->customer?->id) {
                throw new \LogicException('Conversion readiness must belong to the service customer.');
            }
            $execution = $locked->execution_result ?? [];
            $previous = $execution['metadata']['conversion_goal_readiness'] ?? [];
            $persisted = array_merge($state, ['issues' => array_map(fn (AgentIssue $issue) => $issue->toArray(), $issues), 'checked_at' => now()->toIso8601String()]);
            if (isset($intent['custom_goal']) || isset($previous['managed_custom_goal'])) {
                $persisted['managed_custom_goal'] = $intent['custom_goal'] ?? $previous['managed_custom_goal'];
            }
            if ($status === 'unknown' && isset($previous['last_ready'])) {
                $persisted['last_ready'] = $previous['last_ready'];
            } elseif ($state['ready']) {
                $persisted['last_ready'] = ['intent' => $intent, 'checked_at' => $persisted['checked_at']];
            } elseif (isset($previous['last_ready'])) {
                $persisted['last_ready'] = $previous['last_ready'];
            }
            $execution['metadata']['conversion_goal_readiness'] = $persisted;
            if ($state['ready'] && $intent && ($intent['mode'] !== 'custom' || ! empty($intent['custom_goal']))) {
                $execution['metadata']['google_search_baseline']['conversion_goal'] = $intent;
                $execution['metadata']['google_search_baseline']['conversion_category'] = $intent['category'];
            }
            $locked->forceFill(['execution_result' => $execution])->save();
            $changed = ($previous['status'] ?? null) !== $state['status'] || ($previous['intent'] ?? null) !== $intent
                || ($previous['issues'] ?? []) !== $persisted['issues'];
            if ($actions !== [] || ($issues !== [] && $changed)) {
                AgentActivity::record('conversion_tracking', $actions !== [] ? 'conversion_goals_updated' : 'conversion_goals_need_review',
                    $actions !== [] ? 'Google confirmed campaign bidding goals match the reviewed conversion intent.' : AgentIssue::toSentence($issues),
                    $locked->campaign->customer_id, $locked->campaign_id,
                    ['strategy_id' => $locked->id, 'readiness' => $persisted], $state['ready'] ? 'completed' : 'needs_review');
            }

            return $execution;
        }, 3);
        $strategy->setAttribute('execution_result', $execution)->syncOriginalAttribute('execution_result');

        return array_merge($execution['metadata']['conversion_goal_readiness'], ['issues' => $issues]);
    }
}
