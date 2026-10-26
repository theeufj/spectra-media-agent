<?php

namespace App\Services\GoogleAds;

use App\Enums\CampaignStatus;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\Agents\GoogleSearchReachGate;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\Campaigns\GoogleCampaignSpendSafety;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Common\MaximizeConversions;
use Google\Ads\GoogleAds\V26\Enums\AssetAutomationStatusEnum\AssetAutomationStatus;
use Google\Ads\GoogleAds\V26\Enums\AssetAutomationTypeEnum\AssetAutomationType;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus as GoogleCampaignStatus;
use Google\Ads\GoogleAds\V26\Enums\MessagingRestrictionTypeEnum\MessagingRestrictionType;
use Google\Ads\GoogleAds\V26\Resources\AdGroup;
use Google\Ads\GoogleAds\V26\Resources\AdGroup\AiMaxAdGroupSetting;
use Google\Ads\GoogleAds\V26\Resources\Campaign\AiMaxSetting;
use Google\Ads\GoogleAds\V26\Resources\Campaign as GoogleCampaign;
use Google\Ads\GoogleAds\V26\Resources\Campaign\AssetAutomationSetting;
use Google\Ads\GoogleAds\V26\Resources\Campaign\MessagingRestriction;
use Google\Ads\GoogleAds\V26\Resources\Campaign\TextGuidelines;
use Google\Ads\GoogleAds\V26\Services\AdGroupOperation;
use Google\Ads\GoogleAds\V26\Services\CampaignOperation;
use Google\Ads\GoogleAds\V26\Services\MutateGoogleAdsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateOperation;
use Google\Protobuf\FieldMask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Operator-owned two-stage setup. Preparation never starts ads; retries only read unknown outcomes. */
class ConfigureAiMaxPilot extends BaseGoogleAdsService
{
    private const PREPARATION_DIFFERENCES = [
        'The pilot requires standard Search Maximize Conversions without CPA or CPC targets.',
        'Google has not confirmed AI Max is enabled.',
        'Text customization must be explicitly enabled and final URL expansion explicitly disabled.',
        'The reviewed offer needs explicit text-generation messaging restrictions.',
        'The Google campaign end timestamp does not match the pilot end.',
    ];

    private const RESTRICTIONS = [
        'Do not describe the offer as recurring, monthly, ongoing management or a subscription.',
        'Do not quote a setup price other than US$999 one-time. Do not imply the price is AUD.',
        'Do not imply advertising spend is included, free or paid by SiteToSpend.',
        'Do not promise guaranteed results, sales, leads, rankings or approval.',
    ];

    public function prepare(Campaign $campaign, array $pilot): array
    {
        $lock = Cache::lock('google-ai-max-setup:'.$campaign->id, 600);
        if (! $lock->get()) {
            return ['status' => 'busy', 'ready' => false];
        }
        $attempted = false;
        $journal = [];
        try {
            $fresh = $this->authorized($campaign, CampaignStatus::Paused);
            unset($pilot['settings_fingerprint']);
            $this->validatePolicy($fresh, $pilot);
            $this->assertOwnerRequest($fresh, $pilot, CampaignSpendGuardrails::aiMaxPilot($fresh) === null ? ['preparing'] : ['preparing', 'prepared', 'activating']);
            $journal = $fresh->search_delivery_state['ai_max_setup'] ?? [];
            $existing = CampaignSpendGuardrails::aiMaxPilot($fresh);
            if ($journal !== []) {
                $this->assertJournal($fresh, $pilot, $journal);
                if (! empty($journal['prepare_started_at'])) {
                    return $this->finishPreparation($fresh, $pilot, $journal);
                }
            } elseif ($existing !== null) {
                throw new \RuntimeException('An AI Max approval already exists without its setup journal.');
            }
            $snapshot = $this->pausedSnapshot($fresh, $pilot);
            if (($snapshot['reported_lifetime_cost_micros'] ?? null) !== $pilot['baseline_cost_micros']) {
                throw new \RuntimeException('The fresh Google reported-spend baseline differs from this approval.');
            }
            $before = $this->inspection($fresh, $pilot);
            if (empty($before['observed']) || ! is_string($before['fingerprint'] ?? null)
                || array_diff($before['issues'] ?? [], self::PREPARATION_DIFFERENCES) !== []) {
                throw new \RuntimeException('The paused campaign differs from the reviewed AI Max proposal.');
            }
            $operations = $this->operations($fresh, $pilot, $before['observed']);
            $this->mutate($fresh, $operations, true);
            if ($this->dryRun) {
                return ['status' => 'validated', 'ready' => false, 'validate_only' => true, 'operation_count' => count($operations)];
            }
            // Validation and inspection can take time. Recheck both local authority and Google configuration.
            $fresh = $this->authorized($fresh, CampaignStatus::Paused);
            $again = $this->inspection($fresh, $pilot);
            $lastSnapshot = $this->pausedSnapshot($fresh, $pilot);
            if (($again['fingerprint'] ?? null) !== $before['fingerprint']
                || ($lastSnapshot['reported_lifetime_cost_micros'] ?? null) !== $pilot['baseline_cost_micros']) {
                throw new \RuntimeException('The paused campaign changed during AI Max validation.');
            }
            $journal = ['status' => 'preparing', 'customer_id' => $fresh->customer->cleanGoogleCustomerId(),
                'campaign_resource' => $fresh->googleAdsResourceName(), 'pilot_hash' => $this->pilotHash($pilot),
                'preserved_fingerprint' => $this->preservedFingerprint($lastSnapshot),
                'prepare_started_at' => now()->toIso8601String(), 'automatic_replay_allowed' => false];
            $this->save($fresh, $journal, $pilot, install: true);
            $fresh = $this->authorized($fresh, CampaignStatus::Paused, $pilot);
            $attempted = true;
            $this->mutate($fresh, $operations, false);

            return $this->finishPreparation($fresh, $pilot, $journal);
        } catch (\Throwable $e) {
            report($e);
            // A response error does not prove the atomic request failed. Read it back once; never send it again.
            if ($attempted) {
                try {
                    return $this->finishPreparation($campaign, $pilot, $journal);
                } catch (\Throwable $readbackError) {
                    report($readbackError);
                }
            }

            return ['status' => 'needs_review', 'ready' => false, 'mutation_attempted' => $attempted,
                'automatic_replay_allowed' => false, 'error' => 'AI Max preparation could not be verified. Keep the campaign paused and review its durable setup journal.'];
        } finally {
            $lock->release();
        }
    }

    public function activate(Campaign $campaign): array
    {
        $lock = Cache::lock('google-ai-max-setup:'.$campaign->id, 600);
        if (! $lock->get()) {
            return ['status' => 'busy', 'ready' => false];
        }
        $attempted = false;
        $journal = [];
        $pilot = [];
        try {
            $fresh = $this->authorized($campaign);
            $pilot = CampaignSpendGuardrails::aiMaxPilot($fresh);
            if (! $pilot) {
                throw new \RuntimeException('There is no approved AI Max pilot to activate.');
            }
            $this->validatePolicy($fresh, $pilot);
            $journal = $fresh->search_delivery_state['ai_max_setup'] ?? [];
            $this->assertJournal($fresh, $pilot, $journal);
            if (! empty($journal['activation_started_at'])) {
                $attempted = true; // An earlier process may have enabled Google before dying.

                return $this->finishActivation($fresh, $pilot, $journal);
            }
            if (($journal['status'] ?? null) !== 'prepared' || empty($journal['prepared_at'])
                || ! is_string($pilot['settings_fingerprint'] ?? null)
                || ! CampaignSpendGuardrails::canEnable($fresh, true)) {
                throw new \RuntimeException('The pilot has not completed preparation or is outside its approved window.');
            }
            $fresh = $this->authorized($fresh, CampaignStatus::Paused, $pilot);
            $this->pausedSnapshot($fresh, $pilot);
            $this->verifyReadinessAndSpend($fresh, $pilot);
            $fresh = $this->authorized($fresh, CampaignStatus::Paused, $pilot);
            $journal = [...$journal, 'status' => 'activation_pending', 'activation_started_at' => now()->toIso8601String()];
            $this->save($fresh, $journal, $pilot);
            $fresh = $this->authorized($fresh, CampaignStatus::Paused, $pilot);
            $attempted = true;
            $this->enable($fresh);

            return $this->finishActivation($fresh, $pilot, $journal);
        } catch (\Throwable $e) {
            report($e);
            if ($attempted) {
                try {
                    return $this->finishActivation($campaign, $pilot, $journal);
                } catch (\Throwable $readbackError) {
                    report($readbackError);
                }
                $this->holdAndPause($campaign);
            }

            return ['status' => 'needs_review', 'ready' => false, 'mutation_attempted' => $attempted,
                'automatic_replay_allowed' => false, 'error' => 'AI Max activation is unverified. Review the Google status and setup journal before any restart.'];
        } finally {
            $lock->release();
        }
    }

    private function finishPreparation(Campaign $campaign, array $pilot, array $journal): array
    {
        $fresh = $this->authorized($campaign, CampaignStatus::Paused, $pilot);
        $this->assertJournal($fresh, $pilot, $journal);
        $snapshot = $this->pausedSnapshot($fresh, $pilot);
        if ($this->preservedFingerprint($snapshot) !== ($journal['preserved_fingerprint'] ?? null)) {
            throw new \RuntimeException('Budget, targeting, keywords or other preserved settings changed during preparation.');
        }
        $readiness = $this->inspection($fresh, $pilot);
        if (($readiness['ready'] ?? null) !== true || ! is_string($readiness['fingerprint'] ?? null)) {
            throw new \RuntimeException('Google has not confirmed the complete AI Max pilot configuration.');
        }
        $pilot['settings_fingerprint'] = $readiness['fingerprint'];
        $journal = [...$journal, 'status' => 'prepared', 'prepared_at' => now()->toIso8601String(),
            'settings_fingerprint' => $readiness['fingerprint']];
        $this->save($fresh, $journal, $pilot, prepared: true);

        return ['status' => 'prepared', 'ready' => true, 'campaign_status' => 'PAUSED',
            'pilot' => $pilot, 'fingerprint' => $readiness['fingerprint'], 'automatic_replay_allowed' => false];
    }

    private function finishActivation(Campaign $campaign, array $pilot, array $journal): array
    {
        $fresh = $this->authorized($campaign, expectedPilot: $pilot);
        $this->assertJournal($fresh, $pilot, $journal);
        $this->verifyReadinessAndSpend($fresh, $pilot);
        $snapshot = $this->snapshot($fresh);
        if (! $snapshot || ($snapshot['status'] ?? null) !== 2
            || $this->preservedFingerprint($snapshot) !== ($journal['preserved_fingerprint'] ?? null)) {
            throw new \RuntimeException('Google has not confirmed the enabled pilot and its preserved settings.');
        }
        $already = ! empty($journal['activated_at']);
        $journal = [...$journal, 'status' => 'active', 'activated_at' => $journal['activated_at'] ?? now()->toIso8601String(),
            'configuration_verified_at' => now()->toIso8601String()];
        $this->save($fresh, $journal, $pilot, active: true);
        if (! $already) {
            AgentActivity::record('google_ai_max', 'ai_max_pilot_activated',
                'Google confirmed the owner-approved AI Max purchase pilot. Waiting for actual purchase results.',
                $fresh->customer_id, $fresh->id, ['daily_budget_micros' => $pilot['max_daily_budget_micros'],
                    'review_spend_micros' => $pilot['review_spend_micros'], 'ends_at' => $pilot['ends_at'],
                    'selected_conversion_action' => $pilot['selected_conversion_action'], 'conversions_verified' => false]);
        }

        return ['status' => 'active', 'ready' => true, 'campaign_status' => 'ENABLED', 'pilot' => $pilot,
            'actual_conversions_verified' => false, 'automatic_replay_allowed' => false];
    }

    private function validatePolicy(Campaign $campaign, array $pilot): void
    {
        $candidate = clone $campaign;
        $candidate->spend_guardrails = [...($campaign->spend_guardrails ?? []), 'ai_max_pilot' => $pilot];
        if (! CampaignSpendGuardrails::validAiMaxPilot($candidate)
            || CarbonImmutable::parse($pilot['ends_at'])->lte(now())
            || CarbonImmutable::parse($pilot['ends_at'])->setTimezone($pilot['account_timezone'])->format('H:i:s') !== '23:59:59') {
            throw new \InvalidArgumentException('A complete current owner-approved AI Max policy is required.');
        }
    }

    private function authorized(Campaign $campaign, ?CampaignStatus $status = null, ?array $expectedPilot = null): Campaign
    {
        $fresh = $campaign->fresh(['customer']);
        if (! $fresh || ! $this->customer || $fresh->customer_id !== $this->customer->id
            || $fresh->customer->cleanGoogleCustomerId() !== $this->customer->cleanGoogleCustomerId()
            || ! $fresh->googleCampaignNumericId() || $fresh->spend_safety_hold
            || $fresh->customer->service_type !== 'managed' || $fresh->customer->google_ads_link_status === 'revoked'
            || ($status !== null && $fresh->status !== $status)
            || (auth()->check() && ! auth()->user()->hasRole('admin'))
            || ($expectedPilot !== null && ($this->pilotHash(CampaignSpendGuardrails::aiMaxPilot($fresh) ?? []) !== $this->pilotHash($expectedPilot)
                || (isset($expectedPilot['settings_fingerprint']) && ($fresh->spend_guardrails['ai_max_pilot']['settings_fingerprint'] ?? null) !== $expectedPilot['settings_fingerprint'])))) {
            throw new \RuntimeException('The campaign scope, owner authorization or pause/hold state changed.');
        }

        $policy = $expectedPilot ?? CampaignSpendGuardrails::aiMaxPilot($fresh);
        if ($policy !== null) {
            $this->validatePolicy($fresh, $policy);
            $this->assertOwnerRequest($fresh, $policy);
        }

        return $fresh;
    }

    private function assertOwnerRequest(Campaign $campaign, array $pilot, array $statuses = ['preparing', 'prepared', 'activating']): void
    {
        if (! array_key_exists('owner_request_nonce', $pilot)) {
            return;
        }
        $request = $campaign->search_delivery_state['ai_max_request'] ?? [];
        if (! is_string($pilot['owner_request_nonce']) || $pilot['owner_request_nonce'] === ''
            || ($request['enabled'] ?? null) !== true || ($request['nonce'] ?? null) !== $pilot['owner_request_nonce']
            || ($request['customer_id'] ?? null) !== $campaign->customer_id
            || ($request['google_customer_id'] ?? null) !== $campaign->customer->cleanGoogleCustomerId()
            || ($request['campaign_resource'] ?? null) !== $campaign->googleAdsResourceName()
            || ($request['strategy_id'] ?? null) !== ($pilot['strategy_id'] ?? null)
            || ! in_array($request['status'] ?? null, $statuses, true)
            || ! is_string($request['expires_at'] ?? null) || CarbonImmutable::parse($request['expires_at'])->lte(now())) {
            throw new \RuntimeException('The deferred owner request expired, changed scope or was revoked.');
        }
    }

    private function assertJournal(Campaign $campaign, array $pilot, array $journal): void
    {
        if (($journal['customer_id'] ?? null) !== $campaign->customer->cleanGoogleCustomerId()
            || ($journal['campaign_resource'] ?? null) !== $campaign->googleAdsResourceName()
            || ($journal['pilot_hash'] ?? null) !== $this->pilotHash($pilot)) {
            throw new \RuntimeException('The AI Max setup journal does not match this campaign and approval.');
        }
    }

    private function pausedSnapshot(Campaign $campaign, array $pilot): array
    {
        $snapshot = $this->snapshot($campaign);
        if (! $snapshot || ($snapshot['status'] ?? null) !== 3
            || ($snapshot['daily_budget_micros'] ?? null) !== $pilot['max_daily_budget_micros']
            || ! is_int($snapshot['reported_lifetime_cost_micros'] ?? null)
            || ($snapshot['currency_code'] ?? null) !== $pilot['currency_code']
            || ($snapshot['time_zone'] ?? null) !== $pilot['account_timezone']) {
            throw new \RuntimeException('A fresh paused Google campaign, fixed budget and reported spend are required.');
        }

        return $snapshot;
    }

    private function verifyReadinessAndSpend(Campaign $campaign, array $pilot): void
    {
        $this->spendCheck($campaign);
        $readiness = $this->inspection($campaign, $pilot);
        $snapshot = $this->snapshot($campaign);
        $cost = $snapshot['reported_lifetime_cost_micros'] ?? null;
        if (($readiness['ready'] ?? null) !== true || ($readiness['fingerprint'] ?? null) !== ($pilot['settings_fingerprint'] ?? null)
            || ! is_int($cost) || $cost < $pilot['baseline_cost_micros']
            || $cost - $pilot['baseline_cost_micros'] >= $pilot['review_spend_micros']) {
            throw new \RuntimeException('Fresh pilot configuration and remaining reported-spend allowance must be verified.');
        }
        $this->authorized($campaign, expectedPilot: $pilot);
    }

    private function save(Campaign $campaign, array $journal, array $pilot, bool $install = false, bool $prepared = false, bool $active = false): void
    {
        DB::transaction(function () use ($campaign, $journal, $pilot, $install, $prepared, $active): void {
            $locked = Campaign::whereKey($campaign->id)->where('customer_id', $this->customer->id)->lockForUpdate()->firstOrFail();
            $locked->load('customer');
            $this->validatePolicy($locked, $pilot);
            $this->assertOwnerRequest($locked, $pilot);
            if ($locked->googleAdsResourceName() !== ($journal['campaign_resource'] ?? null)
                || $locked->customer->cleanGoogleCustomerId() !== ($journal['customer_id'] ?? null)
                || $locked->customer->service_type !== 'managed' || $locked->customer->google_ads_link_status === 'revoked'
                || ($active && ! CampaignSpendGuardrails::canEnable($locked, true))
                || $locked->spend_safety_hold || ($install && $locked->status !== CampaignStatus::Paused)
                || (! $install && $this->pilotHash(CampaignSpendGuardrails::aiMaxPilot($locked) ?? []) !== $this->pilotHash($pilot))
                || ($install && CampaignSpendGuardrails::aiMaxPilot($locked) !== null)) {
                throw new \RuntimeException('The campaign approval changed before its AI Max state could be saved.');
            }
            $values = ['spend_guardrails' => [...($locked->spend_guardrails ?? []), 'ai_max_pilot' => $pilot],
                'search_delivery_state' => [...($locked->search_delivery_state ?? []), 'ai_max_setup' => $journal]];
            if ($install) {
                $values['end_date'] = CarbonImmutable::parse($pilot['ends_at'])->setTimezone($pilot['account_timezone'])->toDateString();
            }
            if ($prepared) {
                if ($locked->status !== CampaignStatus::Paused) {
                    throw new \RuntimeException('Local pause changed during AI Max preparation.');
                }
                $values['last_bidding_changed_at'] = now();
                $values['platform_status'] = 'PAUSED';
                $strategy = $locked->strategies()->whereKey($pilot['strategy_id'])->lockForUpdate()->firstOrFail();
                $bidding = $strategy->bidding_strategy ?? [];
                foreach (['target_cpa', 'target_roas', 'cpc_bid_ceiling_micros', 'initial_cpc_bid_micros'] as $key) {
                    unset($bidding[$key]);
                }
                unset($bidding['parameters']['cpcBidCeilingMicros']);
                $strategy->update(['bidding_strategy' => [...$bidding, 'name' => 'MaximizeConversions',
                    'bid_strategy' => 'MAXIMIZE_CONVERSIONS', 'ai_max_enabled' => true]]);
            }
            if ($active) {
                $values['status'] = CampaignStatus::Active;
                $values['platform_status'] = 'ENABLED';
            }
            $locked->update($values);
        });
    }

    private function holdAndPause(Campaign $campaign): void
    {
        try {
            DB::transaction(function () use ($campaign): void {
                $locked = Campaign::whereKey($campaign->id)->where('customer_id', $this->customer->id)->lockForUpdate()->firstOrFail();
                $state = $locked->search_delivery_state ?? [];
                $state['ai_max_setup'] = [...($state['ai_max_setup'] ?? []), 'status' => 'activation_unverified',
                    'pause_started_at' => $state['ai_max_setup']['pause_started_at'] ?? now()->toIso8601String()];
                $locked->update(['search_delivery_state' => $state, 'status' => CampaignStatus::Paused,
                    'spend_safety_hold' => $locked->spend_safety_hold ?: ['reason' => 'ai_max_activation_unverified', 'held_at' => now()->toIso8601String()]]);
            });
            $this->pause($campaign);
            if (($this->snapshot($campaign)['status'] ?? null) !== 3) {
                throw new \RuntimeException('Google has not confirmed the protective AI Max pause.');
            }
            DB::transaction(function () use ($campaign): void {
                $locked = Campaign::whereKey($campaign->id)->where('customer_id', $this->customer->id)->lockForUpdate()->firstOrFail();
                $state = $locked->search_delivery_state ?? [];
                $state['ai_max_setup']['pause_verified_at'] = now()->toIso8601String();
                $locked->update(['search_delivery_state' => $state, 'platform_status' => 'PAUSED']);
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function preservedFingerprint(array $snapshot): string
    {
        // These are the only serving settings this explicit owner operation changes.
        foreach (['status', 'end_date', 'end_date_time', 'target_cpa_micros', 'actual_cpc_ceiling_micros', 'cpc_bid_ceiling_micros'] as $field) {
            unset($snapshot[$field]);
        }
        $snapshot['bidding_strategy'] = BiddingStrategyType::MAXIMIZE_CONVERSIONS;

        return GoogleSearchReachGate::settingsFingerprint($snapshot);
    }

    private function pilotHash(array $pilot): string
    {
        unset($pilot['settings_fingerprint']);
        ksort($pilot);

        return hash('sha256', json_encode($pilot, JSON_THROW_ON_ERROR));
    }

    protected function snapshot(Campaign $campaign): ?array
    {
        $snapshot = $this->deliverySnapshot($campaign);
        if ($snapshot === null) {
            return null;
        }
        // The ordinary delivery inspector only reads lifetime spend for legacy
        // fixed trials. A new AI Max approval needs its own fresh baseline.
        $snapshot['reported_lifetime_cost_micros'] = $this->reportedLifetimeCost($campaign);
        $snapshot['reported_spend_checked_at'] = now()->toIso8601String();

        return $snapshot;
    }

    protected function deliverySnapshot(Campaign $campaign): ?array
    {
        return (new InspectSearchDelivery($campaign->customer))->inspect($campaign);
    }

    protected function reportedLifetimeCost(Campaign $campaign): int
    {
        $customerId = $campaign->customer->cleanGoogleCustomerId();
        $resource = $campaign->googleAdsResourceName();
        if (! $this->customer || $campaign->customer_id !== $this->customer->id
            || $customerId !== $this->customer->cleanGoogleCustomerId()
            || $resource !== "customers/{$customerId}/campaigns/{$campaign->googleCampaignNumericId()}") {
            throw new \RuntimeException('Reported spend requires the exact campaign customer account.');
        }
        $costs = [];
        foreach ($this->lifetimeRows($customerId, $resource) as $row) {
            $metrics = $row->getMetrics();
            if ($row->getCampaign()?->getResourceName() === $resource && $metrics?->hasCostMicros()) {
                $costs[] = (int) $metrics->getCostMicros();
            }
        }
        if (count($costs) !== 1 || $costs[0] < 0) {
            throw new \RuntimeException('Fresh Google reported lifetime spend is unavailable.');
        }

        return $costs[0];
    }

    /** @return iterable<\Google\Ads\GoogleAds\V26\Services\GoogleAdsRow> */
    protected function lifetimeRows(string $customerId, string $resource): iterable
    {
        $this->ensureClient();

        return $this->searchQuery($customerId, "SELECT campaign.resource_name, metrics.cost_micros FROM campaign WHERE campaign.resource_name = '{$resource}'")->iterateAllElements();
    }

    protected function inspection(Campaign $campaign, array $pilot): array
    {
        return (new InspectAiMaxPilot($campaign->customer))->inspect($campaign, $pilot);
    }

    protected function spendCheck(Campaign $campaign): void
    {
        app(GoogleCampaignSpendSafety::class)->check($campaign);
    }

    protected function enable(Campaign $campaign): void
    {
        $result = (new UpdateCampaignStatus($campaign->customer))->enable($campaign->customer->cleanGoogleCustomerId(), $campaign->googleAdsResourceName(), true);
        if (($result['success'] ?? null) !== true) {
            throw new \RuntimeException('Google did not confirm the activation request.');
        }
    }

    protected function pause(Campaign $campaign): void
    {
        $result = (new UpdateCampaignStatus($campaign->customer))->pause($campaign->customer->cleanGoogleCustomerId(), $campaign->googleAdsResourceName());
        if (($result['success'] ?? null) !== true) {
            throw new \RuntimeException('The protective AI Max pause request is unverified.');
        }
    }

    protected function operations(Campaign $campaign, array $pilot, array $observed): array
    {
        $settings = [];
        foreach ($observed['campaign']['assetAutomationSettings'] ?? [] as $data) {
            $setting = new AssetAutomationSetting;
            $setting->mergeFromJsonString(json_encode($data, JSON_THROW_ON_ERROR));
            $settings[$setting->getAssetAutomationType()] = $setting;
        }
        $settings[AssetAutomationType::TEXT_ASSET_AUTOMATION] = new AssetAutomationSetting([
            'asset_automation_type' => AssetAutomationType::TEXT_ASSET_AUTOMATION, 'asset_automation_status' => AssetAutomationStatus::OPTED_IN]);
        $settings[AssetAutomationType::FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION] = new AssetAutomationSetting([
            'asset_automation_type' => AssetAutomationType::FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION, 'asset_automation_status' => AssetAutomationStatus::OPTED_OUT]);
        $guidelines = new TextGuidelines;
        $guidelines->mergeFromJsonString(json_encode($observed['campaign']['textGuidelines'] ?? new \stdClass, JSON_THROW_ON_ERROR));
        $restrictions = iterator_to_array($guidelines->getMessagingRestrictions());
        foreach (self::RESTRICTIONS as $text) {
            $restrictions[] = new MessagingRestriction(['restriction_text' => $text,
                'restriction_type' => MessagingRestrictionType::RESTRICTION_BASED_EXCLUSION]);
        }
        if (count($restrictions) > 40) {
            throw new \RuntimeException('The reviewed text restrictions exceed Google’s limit.');
        }
        $guidelines->setMessagingRestrictions($restrictions);
        $remote = new GoogleCampaign(['resource_name' => $campaign->googleAdsResourceName(), 'status' => GoogleCampaignStatus::PAUSED,
            'ai_max_setting' => new AiMaxSetting(['enable_ai_max' => true]),
            'maximize_conversions' => new MaximizeConversions(['target_cpa_micros' => 0]),
            'end_date_time' => CarbonImmutable::parse($pilot['ends_at'])->setTimezone($pilot['account_timezone'])->format('Y-m-d H:i:s'),
            'asset_automation_settings' => array_values($settings), 'text_guidelines' => $guidelines]);
        $operations = [new MutateOperation(['campaign_operation' => new CampaignOperation(['update' => $remote,
            'update_mask' => new FieldMask(['paths' => ['status', 'ai_max_setting.enable_ai_max', 'maximize_conversions.target_cpa_micros',
                'end_date_time', 'asset_automation_settings', 'text_guidelines.term_exclusions',
                'text_guidelines.messaging_restrictions']])])])];
        $groups = 0;
        foreach ($observed['ad_groups'] ?? [] as $row) {
            $group = $row['adGroup'] ?? [];
            if (($group['status'] ?? null) !== 'ENABLED') {
                continue;
            }
            if (($group['type'] ?? null) !== 'SEARCH_STANDARD'
                || ! preg_match('#^customers/'.preg_quote($campaign->customer->cleanGoogleCustomerId(), '#').'/adGroups/[0-9]+$#D', $group['resourceName'] ?? '')) {
                throw new \RuntimeException('The pilot requires a verified standard Search ad group.');
            }
            $operations[] = new MutateOperation(['ad_group_operation' => new AdGroupOperation([
                'update' => new AdGroup(['resource_name' => $group['resourceName'], 'ai_max_ad_group_setting' => new AiMaxAdGroupSetting(['disable_search_term_matching' => false])]),
                'update_mask' => new FieldMask(['paths' => ['ai_max_ad_group_setting.disable_search_term_matching']])])]);
            $groups++;
        }
        if ($groups === 0) {
            throw new \RuntimeException('No enabled Search ad group was available for the AI Max pilot.');
        }

        return $operations;
    }

    protected function mutate(Campaign $campaign, array $operations, bool $validateOnly): void
    {
        $this->ensureClient();
        $previous = $this->dryRun;
        $this->dryRun = $previous || $validateOnly;
        try {
            $result = $this->client->getGoogleAdsServiceClient()->mutate(new MutateGoogleAdsRequest([
                'validate_only' => $this->dryRun,
                'customer_id' => $campaign->customer->cleanGoogleCustomerId(), 'mutate_operations' => $operations,
                'partial_failure' => false]));
            if (! $this->dryRun && count($result->getMutateOperationResponses()) !== count($operations)) {
                throw new \RuntimeException('The atomic AI Max configuration outcome requires readback.');
            }
        } finally {
            $this->dryRun = $previous;
        }
    }
}
