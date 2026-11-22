<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Jobs\CheckGoogleConversionProcessing;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Billing\LaunchFunding;
use App\Services\GoogleAds\CommonServices\GetCampaignSpendSafety;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use App\Services\GoogleAds\DataManagerService;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use App\Services\GoogleAds\Support\CampaignDateTimes;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus as GoogleStatus;
use Google\Ads\GoogleAds\V26\Enums\CustomerStatusEnum\CustomerStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** An explicit restart of the site's own signup campaign after repairing its measurement. */
class RestartOwnedSignupCampaign
{
    public const HOLD = 'no_conversions_after_matured_spend';

    private const JOURNAL = 'pmax_signup_restart';

    public static function handles(Campaign $campaign): bool
    {
        return $campaign->customer?->uuid && $campaign->customer->uuid === config('conversions.advertiser_customer_uuid')
            && $campaign->customer->cleanGoogleCustomerId() === (string) config('conversions.google_ads_customer_id')
            && (($campaign->spend_safety_hold['reason'] ?? null) === self::HOLD
                || (! $campaign->spend_safety_hold && isset($campaign->spend_guardrails[self::JOURNAL])))
            && $campaign->strategies()->where('campaign_type', 'performance_max')->get()->contains(fn (Strategy $strategy) => in_array(
                $strategy->reusableGoogleCampaignId(), array_filter([$campaign->googleAdsResourceName(), $campaign->googleCampaignNumericId()]), true));
    }

    public static function verifiedBaselineDate(Campaign $campaign, string $resource, string $timezone): ?string
    {
        $entry = $campaign->spend_guardrails[self::JOURNAL] ?? [];
        if (! $campaign->customer?->uuid || $campaign->customer->uuid !== config('conversions.advertiser_customer_uuid')
            || $campaign->customer->cleanGoogleCustomerId() !== (string) config('conversions.google_ads_customer_id')
            || ($entry['status'] ?? null) !== 'verified' || ($entry['resource'] ?? null) !== $resource
            || ($entry['timezone'] ?? null) !== $timezone || empty($entry['enabled_at'])
            || empty($entry['actor']) || empty($entry['receipt_id'])) {
            return null;
        }

        return CarbonImmutable::parse($entry['enabled_at'])->setTimezone($timezone)->toDateString();
    }

    public function start(Campaign $campaign, User $operator): void
    {
        if (! $operator->isFullAdmin()) {
            throw new \RuntimeException('An administrator must explicitly approve the paid campaign restart.');
        }
        Cache::lock("campaign-spend-safety:{$campaign->id}", 300)->block(5, function () use ($campaign, $operator) {
            $campaign->refresh()->load('customer');
            $resource = (string) $campaign->googleAdsResourceName();
            $account = $campaign->customer->cleanGoogleCustomerId();
            $previous = $campaign->spend_guardrails[self::JOURNAL] ?? null;
            if (! $campaign->spend_safety_hold && $campaign->status === CampaignStatus::Active
                && ($previous['status'] ?? null) === 'verified' && ($previous['resource'] ?? null) === $resource
                && $this->provider($campaign)->confirmedStatus($account, $resource, 'ENABLED')) {
                return;
            }
            $snapshot = app()->makeWith(GetCampaignSpendSafety::class, ['customer' => $campaign->customer])->forCampaign($campaign);
            $context = $this->approvalContext($campaign, $snapshot);
            $heldAt = $campaign->spend_safety_hold['verified_at'];
            $strategy = Strategy::findOrFail($context['strategy_id']);
            $goals = app()->makeWith(ReconcileCampaignConversionGoals::class, ['customer' => $campaign->customer])->inspect($strategy, $resource);
            if (! ($goals['configuration_verified'] ?? false) || ! ($goals['ready'] ?? false)
                || ($goals['campaign_resource'] ?? null) !== $resource || ($goals['intent']['mode'] ?? null) !== 'custom'
                || ($goals['intent']['category'] ?? null) !== 'SIGNUP' || empty($goals['intent']['custom_goal'])
                || ($goals['intent']['action_resource'] ?? null) !== $context['action']) {
                throw new \RuntimeException('Google must verify the exact signup action selected for this Performance Max campaign.');
            }
            $event = $this->processedRegistration($context['action'], CarbonImmutable::parse($heldAt), $previous['receipt_id'] ?? null);
            $details = app(DataManagerService::class)->retrieveRequestStatus($event->google_request_id);
            $receipt = CheckGoogleConversionProcessing::diagnostics($details['destinations'] ?? [], $context['action']);
            if (! $details['success'] || $receipt['status'] !== 'SUCCESS' || $receipt['warnings'] !== []) {
                throw new \RuntimeException('Google has not confirmed successful processing of this registration at the selected action.');
            }
            $resume = ($previous['status'] ?? null) === 'requested';
            if ($previous && (! in_array($previous['status'] ?? null, ['requested', 'rejected'], true) || ($previous['context'] ?? null) != $context
                || $previous['hold'] != $campaign->spend_safety_hold || $previous['receipt_id'] !== $event->id)) {
                throw new \RuntimeException('A previous restart was already used or its approval changed. Review the spending hold.');
            }
            if ((! $resume && $snapshot['status'] !== GoogleStatus::PAUSED) || ($resume && $snapshot['status'] !== GoogleStatus::ENABLED)) {
                throw new \RuntimeException('Google must confirm the campaign is paused for a new restart. A prior unconfirmed enable requires review.');
            }
            // Commit the one-shot intent before crossing the network. After interruption another
            // request may confirm a successful enable, but cannot issue a second mutation.
            $entry = $resume ? $previous : ['status' => 'requested', 'context' => $context, 'resource' => $resource,
                'timezone' => $snapshot['account_timezone'], 'hold' => $campaign->spend_safety_hold,
                'receipt_id' => $event->id, 'actor' => $operator->id, 'requested_at' => now()->toIso8601String()];
            if (! $resume) {
                DB::transaction(function () use ($campaign, $entry, $snapshot, $context, $previous) {
                    $locked = $this->lockedCampaign($campaign, $entry, $snapshot, $context);
                    if (($locked->spend_guardrails[self::JOURNAL] ?? null) != $previous) {
                        throw new \RuntimeException('A restart has already been requested.');
                    }
                    $locked->update(['spend_guardrails' => [...($locked->spend_guardrails ?? []), self::JOURNAL => $entry]]);
                    AgentActivity::record('spend_safety', 'signup_repair_restart_requested', 'Administrator approved a restart after Google processed a completed registration.',
                        $locked->customer_id, $locked->id, $entry);
                });
            }
            $completed = DB::transaction(function () use ($campaign, $entry, $context, $resource, $account, $resume): bool {
                $snapshot = app()->makeWith(GetCampaignSpendSafety::class, ['customer' => $campaign->customer])->forCampaign($campaign);
                $locked = $this->lockedCampaign($campaign, $entry, $snapshot, $context);
                if (($locked->spend_guardrails[self::JOURNAL] ?? null) != $entry || $snapshot['status'] !== ($resume ? GoogleStatus::ENABLED : GoogleStatus::PAUSED)) {
                    throw new \RuntimeException('The campaign restart changed before activation.');
                }
                $provider = $this->provider($locked);
                // Only this transaction sees the hold released while the provider call is in flight.
                $locked->update(['spend_safety_hold' => null]);
                if (! $resume) {
                    $result = $provider->enable($account, $resource, ownerApprovedRestart: true);
                    if (! $result['success']) {
                        if (! ($result['rejected'] ?? false) || ! $provider->confirmedStatus($account, $resource, 'PAUSED')) {
                            throw new \RuntimeException('Google did not acknowledge the enable. The prior spending hold remains in force.');
                        }
                        $entry['status'] = 'rejected';
                        $locked->update(['spend_safety_hold' => $entry['hold'], 'spend_guardrails' => [...$locked->spend_guardrails, self::JOURNAL => $entry]]);
                        AgentActivity::record('spend_safety', 'signup_repair_restart_rejected', 'Google rejected the enable and confirmed the campaign remains paused.',
                            $locked->customer_id, $locked->id, $entry, 'failed');

                        return false;
                    }
                }
                if (! $provider->confirmedStatus($account, $resource, 'ENABLED')) {
                    throw new \RuntimeException('Google did not confirm this campaign was enabled. Its spending hold remains in force.');
                }
                $zone = $snapshot['account_timezone'];
                if (CarbonImmutable::parse($entry['requested_at'])->setTimezone($zone)->toDateString() !== now($zone)->toDateString()) {
                    throw new \RuntimeException('The account date changed during the activation. Review and verify the spend window.');
                }
                $entry['status'] = 'verified';
                $entry['enabled_at'] = now()->toIso8601String();
                $locked->update(['spend_guardrails' => [...$locked->spend_guardrails, self::JOURNAL => $entry]]);
                $locked->applyPlatformStatus('ENABLED');
                AgentActivity::record('spend_safety', 'signup_repair_restart_verified', 'Google confirmed the Performance Max restart. The original no-conversion stop still applies to spend from this account day.',
                    $locked->customer_id, $locked->id, $entry);

                return true;
            });
            if (! $completed) {
                throw new \RuntimeException('Google rejected the requested change. The campaign is paused. Review the cause before approving another attempt.');
            }
        });
    }

    private function provider(Campaign $campaign): UpdateCampaignStatus
    {
        return app()->makeWith(UpdateCampaignStatus::class, ['customer' => $campaign->customer]);
    }

    private function lockedCampaign(Campaign $campaign, array $entry, array $snapshot, array $context): Campaign
    {
        $locked = Campaign::withoutCustomerScope()->lockForUpdate()->findOrFail($campaign->id);
        if (! $locked instanceof Campaign) {
            throw new \RuntimeException('Campaign is no longer available for restart.');
        }
        $locked->setRelation('customer', Customer::lockForUpdate()->findOrFail($locked->customer_id));
        if ($locked->spend_safety_hold != $entry['hold'] || $context != $this->approvalContext($locked, $snapshot)) {
            throw new \RuntimeException('Campaign approval, funding or ownership changed. The restart was stopped.');
        }

        return $locked;
    }

    private function approvalContext(Campaign $campaign, array $snapshot): array
    {
        $customer = $campaign->customer;
        $account = $customer->cleanGoogleCustomerId();
        $resource = $campaign->googleAdsResourceName();
        if (! $account || $account !== (string) config('conversions.google_ads_customer_id')
            || ! $customer->uuid || $customer->uuid !== config('conversions.advertiser_customer_uuid')
            || ! in_array(strtolower((string) parse_url($customer->website, PHP_URL_HOST)), ['sitetospend.com', 'www.sitetospend.com'], true)
            || $customer->service_type !== 'managed' || $customer->is_sandbox || $customer->google_ads_link_status === 'revoked'
            || $campaign->status !== CampaignStatus::Paused || ($campaign->spend_safety_hold['reason'] ?? null) !== self::HOLD
            || empty($campaign->spend_safety_hold['verified_at']) || CampaignSpendGuardrails::activeTrial($campaign)
            || CampaignSpendGuardrails::aiMaxPilot($campaign) || CampaignSpendGuardrails::pendingAiMaxRequest($campaign)
            || $snapshot['campaign_type'] !== AdvertisingChannelType::PERFORMANCE_MAX
            || $snapshot['customer_id'] !== $account || $snapshot['customer_status'] !== CustomerStatus::ENABLED
            || $snapshot['manager_or_test'] || $snapshot['resource'] !== $resource
            || Customer::whereKeyNot($customer->id)->whereRaw("replace(google_ads_customer_id, '-', '') = ?", [$account])->exists()
            || CampaignSpendGuardrails::forGoogleResource($account, $resource)?->id !== $campaign->id) {
            throw new \RuntimeException('This restart is limited to the dedicated Site to Spend Performance Max signup campaign with a verified no-conversion hold.');
        }
        $strategies = $campaign->strategies()->where('campaign_type', 'performance_max')->lockForUpdate()->get()
            ->filter(fn (Strategy $strategy) => $strategy->signed_off_at && in_array($strategy->reusableGoogleCampaignId(), [$resource, basename($resource)], true));
        $budget = CampaignSpendGuardrails::moneyToMicros($campaign->approved_daily_budget);
        $strategy = $strategies->sole();
        $credit = $customer->adSpendCredit()->lockForUpdate()->first();
        $clock = now($snapshot['account_timezone']);
        $today = $clock->toDateString();
        $start = CampaignDateTimes::calendarDate((string) $campaign->getRawOriginal('start_date'));
        $end = CampaignDateTimes::calendarDate((string) $campaign->getRawOriginal('end_date'));
        $action = Setting::get('conversion_resource_name.signup_import');
        $committed = DB::table('launch_funding_reservations')->where('customer_id', $customer->id)->where('currency', $snapshot['currency_code'])
            ->whereIn('status', ['collecting', 'held'])->sum('held_cents');
        $days = $end === null ? 0 : min(7, max(0, (int) $clock->copy()->startOfDay()->diffInDays(CarbonImmutable::parse($end, $clock->timezone), false) + 1));
        if (! $campaign->budget_confirmed_at || $budget <= 0 || $budget !== $snapshot['daily_budget_micros']
            || ($campaign->getRawOriginal('total_budget') !== null && CampaignSpendGuardrails::moneyToMicros($campaign->total_budget) < $snapshot['cost_micros'] + $budget)
            || CampaignSpendGuardrails::moneyToMicros($strategy->daily_budget) !== $budget
            || ! CampaignSpendGuardrails::permitsDailyBudget($campaign, $budget) || ! CampaignSpendGuardrails::hasLaunchFunding($customer)
            || ! $credit?->canRunCampaigns() || $credit->campaigns_paused_at || $credit->currency !== $snapshot['currency_code']
            || LaunchFunding::cents($credit->current_balance) - (int) $committed < LaunchFunding::cents($strategy->daily_budget) * $days
            || $snapshot['currency_code'] !== $customer->billingCurrency() || ! $start || $today < $start || ! $end || $today > $end
            || ! $snapshot['campaign_start_date'] || $today < $snapshot['campaign_start_date'] || ! $snapshot['campaign_end_date']
            || $today > $snapshot['campaign_end_date'] || $end < $snapshot['campaign_end_date']
            || $clock->toDateTimeString() < $snapshot['starts_at_local'] || $clock->toDateTimeString() > $snapshot['ends_at_local']
            || ! is_string($action) || ! preg_match("~^customers/{$account}/conversionActions/\d+$~D", $action)) {
            throw new \RuntimeException('The approved dates, daily budget, currency and funded wallet must match the dedicated serving account.');
        }

        return ['resource' => $resource, 'strategy_id' => $strategy->id, 'action' => $action, 'budget_micros' => $budget,
            'currency' => $snapshot['currency_code'], 'start' => $start, 'end' => $end,
            'signed_off_at' => $strategy->signed_off_at->toIso8601String(), 'budget_confirmed_at' => $campaign->budget_confirmed_at->toIso8601String()];
    }

    private function processedRegistration(string $action, CarbonImmutable $heldAt, ?int $receiptId): SpectraConversionEvent
    {
        $events = SpectraConversionEvent::with('user')->where('event', 'signup')->where('mode', 'server_google')
            ->where('google_conversion_resource', $action)->where('google_processing_status', 'SUCCESS')->where('uploaded_to_google', true)
            ->when($receiptId, fn ($query) => $query->whereKey($receiptId))
            ->whereNotNull('google_request_id')->where('occurred_at', '>', $heldAt->format('Y-m-d H:i:sP'))
            ->where('occurred_at', '<=', now()->format('Y-m-d H:i:sP'))->latest('id')->lazy(100);
        foreach ($events as $event) {
            $user = $event->user;
            if ($user && $user->tenant_key === 'sitetospend' && $user->ad_measurement_consent === true && $user->email_verified_at
                && $event->hasSignupEmailMatch() && ! empty($event->verified_source['email_verified_at'])
                && empty($event->verified_source['measurement_withdrawn']) && ($event->verified_source['user_id'] ?? null) === $user->id
                && ($event->verified_source['google_email_hash'] ?? null) === DataManagerService::hashEmail($user->email)
                && $event->occurred_at->equalTo($user->created_at)
                && $event->deduplication_key === "signup:{$user->id}:{$user->created_at->timestamp}"
                && $event->google_accepted_at?->gte($event->occurred_at) && $event->google_processing_checked_at?->gte($event->google_accepted_at)
                && $event->googleDeliveryStatus() === 'processed') {
                return $event;
            }
        }
        throw new \RuntimeException('A verified registration from the repaired signup flow must finish processing in Google before restarting.');
    }
}
