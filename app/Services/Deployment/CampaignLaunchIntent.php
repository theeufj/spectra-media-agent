<?php

namespace App\Services\Deployment;

use App\Enums\CampaignStatus;
use App\Jobs\DeployCampaign;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\MccAccount;
use App\Models\Setting;
use App\Models\Strategy;
use App\Models\User;
use App\Policies\CampaignPolicy;
use App\Services\Billing\LaunchFunding;
use App\Services\Billing\PlatformFunding;
use App\Services\Campaigns\CampaignMarketResearch;
use App\Services\Campaigns\CampaignMarketResearchWorkflow;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\Campaigns\GoogleSearchTotalBudget;
use App\Services\Conversions\CampaignOutcomeEvidence;
use App\Services\Creative\CreativeRelease;
use App\Services\DeploymentService;
use App\Services\FacebookAds\BusinessManagerService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Persist the customer's exact launch request while account setup catches up. */
class CampaignLaunchIntent
{
    public const WAITING = 'awaiting_launch';

    public function __construct(private CreativeRelease $creative, private CampaignMarketResearch $research) {}

    public static function googleReady(Customer $customer): bool
    {
        return (bool) $customer->cleanGoogleCustomerId()
            && in_array($customer->google_ads_link_status, [null, 'active', 'managed'], true);
    }

    public static function canProvisionGoogle(Customer $customer): bool
    {
        return ! $customer->cleanGoogleCustomerId() && $customer->google_ads_link_status === null;
    }

    /** @param array<int, string> $fingerprints */
    public function request(Campaign $campaign, User $user, ?int $strategyId, array $fingerprints): ?string
    {
        return DB::transaction(function () use ($campaign, $user, $strategyId, $fingerprints) {
            $current = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $customer = $current->customer;
            if (! $customer || ! app(CampaignPolicy::class)->deploy($user, $current) || ! $this->canPrepare($current, $customer)) {
                throw ValidationException::withMessages(['campaign_id' => 'This workspace cannot launch the campaign right now.']);
            }
            $pending = [self::WAITING, 'queued', 'deploying'];
            // Legacy paused handovers share one launch approval across the whole campaign.
            if (($customer->service_type === 'setup_only' || $strategyId === null && $fingerprints === [])
                && $current->strategies()->whereIn('deployment_status', $pending)->exists()) {
                return null;
            }
            $strategies = $current->strategies()->when($strategyId !== null, fn ($query) => $query->whereKey($strategyId))
                ->where(fn ($query) => $query->whereNull('deployment_status')->orWhereNotIn('deployment_status', $pending))
                ->whereNotNull('signed_off_at')->orderBy('id')->lockForUpdate()->get()
                ->reject(fn (Strategy $strategy) => $strategy->isDeployed());
            if ($strategies->contains(fn (Strategy $strategy) => isset($strategy->launch_intent['campaign_create']))) {
                throw ValidationException::withMessages(['campaign_id' => 'Site to Spend is checking an existing platform submission for this strategy.']);
            }
            if ($strategies->isEmpty()) {
                return null;
            }
            // A request cannot accidentally include a signed strategy created after the review page loaded.
            if ($strategies->contains(fn (Strategy $strategy) => ! isset($fingerprints[$strategy->id]))) {
                throw ValidationException::withMessages(['creative_fingerprints' => 'Reload the campaign and review the current ads before launching.']);
            }
            if ($strategies->contains(fn (Strategy $strategy) => ! $this->platformAllowed($customer, $strategy))) {
                throw ValidationException::withMessages(['campaign_id' => 'Choose enabled ad platforms that are included in your current plan.']);
            }
            foreach ($strategies as $strategy) {
                $cap = GoogleSearchTotalBudget::forStrategy($strategy->setRelation('campaign', $current));
                if ($cap !== null && $current->strategies()->count() !== 1) {
                    throw ValidationException::withMessages(['daily_budget' => 'A fixed Google Search budget requires a dedicated campaign with one strategy.']);
                }
            }
            $needsResearch = $strategies->contains(function (Strategy $strategy) use ($current): bool {
                $strategy->setRelation('campaign', $current);

                return $strategy->requiresInitialMarketResearch();
            });
            if ($needsResearch) {
                app(CampaignMarketResearchWorkflow::class)->assertApproved($current);
            }
            $budget = CampaignSpendGuardrails::moneyToMicros($current->daily_budget);
            if ($budget <= 0 || $budget !== CampaignSpendGuardrails::moneyToMicros($current->approved_daily_budget)
                || $current->budget_confirmed_at === null || $strategies->contains(fn (Strategy $strategy) => CampaignSpendGuardrails::moneyToMicros($strategy->daily_budget) <= 0)) {
                throw ValidationException::withMessages(['daily_budget' => 'Approve the daily budget and platform allocations before launching ads.']);
            }
            $committed = $current->strategies()->get()->filter(fn (Strategy $s) => $strategies->contains('id', $s->id) || $s->reservesDeploymentBudget())
                ->sum(fn (Strategy $s) => CampaignSpendGuardrails::moneyToMicros($s->daily_budget));
            if ($committed > $budget) {
                throw ValidationException::withMessages(['daily_budget' => 'The selected channel allocations exceed your approved total budget.']);
            }
            $platforms = $strategies->map(fn (Strategy $strategy) => DeploymentService::normalizePlatform($strategy->platform))->unique()->values()->all();
            $researchApproval = $needsResearch ? ($current->search_delivery_state['market_research'] ?? []) : [];
            $id = (string) Str::uuid();
            $intent = [
                'id' => $id,
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'requested_at' => now()->toIso8601String(),
                'budget_approved_at' => $current->budget_confirmed_at->toIso8601String(),
                'proposal' => $this->research->approvalFingerprint($current),
                'research_approval' => $researchApproval['approved_fingerprint'] ?? null,
                'research_request_id' => $researchApproval['approved_request_id'] ?? null,
                'accounts' => $customer->only(CampaignMarketResearch::APPROVAL_ACCOUNT_FIELDS),
                'platforms' => $platforms,
                'google_manager' => $customer->google_ads_manager_customer_id ?: MccAccount::getActive()?->google_customer_id,
                'staged_accounts' => null,
            ];
            $independent = $customer->service_type !== 'setup_only';
            $payer = $independent ? $customer->adSpendPayer(allowWithoutPaymentMethod: true) : null;
            foreach ($strategies as $index => $strategy) {
                $channel = $independent ? [
                    'version' => 2, 'approval_id' => $id, 'id' => $index === $strategies->keys()->first() ? $id : (string) Str::uuid(),
                    'platforms' => [DeploymentService::normalizePlatform($strategy->platform)],
                    'daily_cents' => LaunchFunding::cents($strategy->daily_budget),
                    'max_reserve_cents' => LaunchFunding::cents($strategy->daily_budget) * LaunchFunding::runwayDays($current), 'payer_user_id' => $payer?->id,
                ] : [];
                $this->creative->approve($strategy, $fingerprints[$strategy->id]);
                $strategy->forceFill(['launch_intent' => [...$intent, ...$channel,
                    'signed_off_at' => $strategy->signed_off_at?->toIso8601String(),
                    'creative_fingerprint' => $strategy->creative_approval['fingerprint'] ?? null,
                ], 'deployment_status' => self::WAITING, 'deployment_error' => null])->save();
            }
            if ($current->status === CampaignStatus::Draft) {
                $current->update(['status' => CampaignStatus::PendingAdminDeployment, 'pending_admin_deployment_at' => now()]);
            }

            return $id;
        });
    }

    /** Queue exactly the still-current approved strategies once prerequisites are available. */
    public function resume(int $campaignId, string $id): bool
    {
        $legs = Strategy::where('campaign_id', $campaignId)->where('launch_intent->version', 2)
            ->where(fn ($q) => $q->where('launch_intent->approval_id', $id)->orWhere('launch_intent->id', $id))
            ->where('deployment_status', self::WAITING)->get();
        if ($legs->isNotEmpty()) {
            $queued = false;
            foreach ($legs as $leg) {
                try {
                    $lock = Cache::lock('launch-leg:'.$leg->launch_intent['id'], 180);
                    if ($lock->get()) {
                        try {
                            $queued = $this->resumeLeg($leg) || $queued;
                        } finally {
                            $lock->release();
                        }
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return $queued;
        }
        $waitingMeta = Strategy::query()->where('campaign_id', $campaignId)->where('launch_intent->id', $id)
            ->where('deployment_status', self::WAITING)->whereNotNull('launch_intent->meta_account_issue')
            ->with('campaign.customer')->first();
        $checkedCustomer = $waitingMeta?->campaign?->customer;
        $checkedMetaAccount = $checkedCustomer?->facebook_ads_account_id;
        $checkedMetaPage = $checkedCustomer?->facebook_page_id;
        $metaIssue = $checkedCustomer ? $this->metaAccountIssue($checkedCustomer,
            allowProvisioning: $waitingMeta->launch_intent['meta_account_issue'] !== 'facebook_account_provisioning_failed') : null;

        $current = DB::transaction(function () use ($campaignId, $id, $checkedCustomer, $checkedMetaAccount, $checkedMetaPage, $metaIssue) {
            $campaign = Campaign::whereKey($campaignId)->lockForUpdate()->first();
            $customer = $campaign?->customer;
            if (! $campaign || ! $customer) {
                return null;
            }
            $strategies = $this->strategies($campaign, $id, self::WAITING)->lockForUpdate()->get();
            if ($strategies->isEmpty()) {
                return null;
            }
            $intent = $strategies->first()->launch_intent;
            $waitingForMeta = filled($intent['meta_account_issue'] ?? null);
            if ($strategies->contains(fn (Strategy $strategy) => ! $this->isCurrent($campaign, $customer, $strategy, attachingMeta: $waitingForMeta))) {
                $this->invalidate($campaign, $strategies);

                return null;
            }
            if (! $this->accountsReady($customer, $intent['platforms']) || ! CampaignSpendGuardrails::hasLaunchFunding($customer)) {
                return null;
            }
            if ($waitingForMeta && ($checkedCustomer?->id !== $customer->id || $checkedMetaAccount !== $customer->facebook_ads_account_id
                || $checkedMetaPage !== $customer->facebook_page_id || $metaIssue !== null)) {
                return null;
            }
            $this->bindAccounts($campaign, $customer, $strategies, status: 'queued');
            if ($campaign->status === CampaignStatus::PendingAdminDeployment) {
                $campaign->update(['status' => CampaignStatus::Draft, 'pending_admin_deployment_at' => null]);
            }

            return $campaign;
        });
        if (! $current) {
            return false;
        }
        try {
            DeployCampaign::dispatch($current, launchId: $id);
        } catch (\Throwable $e) {
            $this->strategies($current, $id, 'queued')->update(['deployment_status' => self::WAITING]);
            report($e);

            return false;
        }

        return true;
    }

    private function resumeLeg(Strategy $leg): bool
    {
        $leg->refresh()->load('campaign.customer');
        $campaign = $leg->campaign;
        $customer = $campaign->customer;
        $intent = $leg->launch_intent;
        if ($leg->deployment_status !== self::WAITING) {
            return false;
        }
        if (! $this->isCurrent($campaign, $customer, $leg)) {
            $this->invalidate($campaign, new Collection([$leg]));

            return false;
        }
        $platform = $intent['platforms'][0];
        $proof = app(PlatformFunding::class)->assess($customer, $platform);
        $evidence = $proof['ready'] ? app(CampaignOutcomeEvidence::class)->forStrategy($leg) : [];
        $funding = [];
        if (! $proof['ready']) {
            $message = $proof['message'];
            $code = $proof['code'];
        } elseif (($evidence['requires_verification'] ?? true) && ! ($evidence['verified_for_launch'] ?? false)) {
            $code = 'conversion_test_required';
            $message = 'Verify a test conversion at the approved destination before funding this channel.';
        } else {
            $funding = app(LaunchFunding::class)->reserve($leg, $proof);
            $message = $funding['ready'] ? null : $funding['message'];
            $code = $funding['ready'] ? 'funded' : ($funding['code'] ?? 'funding_pending');
        }
        $queued = DB::transaction(function () use ($leg, $intent, $code, $message, $proof, $evidence, $funding) {
            $campaign = Campaign::whereKey($leg->campaign_id)->lockForUpdate()->firstOrFail();
            $current = Strategy::whereKey($leg->id)->lockForUpdate()->firstOrFail();
            if ($current->deployment_status !== self::WAITING || ($current->launch_intent['id'] ?? null) !== $intent['id']) {
                return false;
            }
            $customer = $campaign->customer;
            if (! $this->isCurrent($campaign, $customer, $current)) {
                $this->invalidate($campaign, new Collection([$current]));

                return false;
            }
            $current->forceFill(['launch_intent' => [...$current->launch_intent, 'funding' => [
                'code' => $code, 'payer' => $proof['payer'], 'account' => $proof['account'], 'currency' => $proof['currency'],
                'target_cents' => LaunchFunding::runwayDays($campaign) * $intent['daily_cents'],
                'owner' => $code === 'conversion_test_required' ? 'Website owner' : ($funding['owner'] ?? null),
                'setup_url' => $funding['setup_url'] ?? ($code === 'conversion_test_required' ? ($evidence['setup_url'] ?? null) : null),
            ]], 'deployment_error' => $message])->save();
            if ($message !== null || ! app(LaunchFunding::class)->funded($current)) {
                return false;
            }
            $this->bindAccounts($campaign, $customer, new Collection([$current]), 'queued');
            if ($campaign->status === CampaignStatus::PendingAdminDeployment) {
                $campaign->update(['status' => CampaignStatus::Draft, 'pending_admin_deployment_at' => null]);
            }

            return true;
        });
        if ($queued) {
            try {
                DeployCampaign::dispatch($campaign, strategyId: $leg->id, launchId: $intent['id']);
            } catch (\Throwable $e) {
                $this->strategies($campaign, $intent['id'], 'queued')->update(['deployment_status' => self::WAITING]);
                throw $e;
            }
        }

        return $queued;
    }

    /** @return Collection<int, Strategy> */
    public function claim(Campaign $campaign, string $id): Collection
    {
        return DB::transaction(function () use ($campaign, $id) {
            $current = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $customer = $current->customer;
            $strategies = $this->strategies($current, $id, 'queued')->whereNull('launch_intent->started_at')->orderBy('id')->lockForUpdate()->get();
            if ($strategies->isEmpty()) {
                return $strategies;
            }
            if (! $customer || $strategies->contains(fn (Strategy $strategy) => ! $this->isCurrent($current, $customer, $strategy))) {
                $this->invalidate($current, $strategies);

                return new Collection;
            }
            if (! $this->accountsReady($customer, $strategies->first()->launch_intent['platforms']) || ! $this->funded($customer, $strategies->first())) {
                $this->strategies($current, $id, 'queued')->update(['deployment_status' => self::WAITING]);

                return new Collection;
            }
            foreach ($strategies as $strategy) {
                $strategy->forceFill(['launch_intent' => [...$strategy->launch_intent, 'started_at' => now()->toIso8601String()]])->save();
            }

            return $strategies;
        });
    }

    public function approvalCurrent(Strategy $strategy): bool
    {
        $current = $strategy->fresh(['campaign.customer']);

        return $current && $current->campaign?->customer && $this->isCurrent($current->campaign, $current->campaign->customer, $current);
    }

    public function canPublish(Strategy $strategy, bool $ownerApprovedRestart = false): bool
    {
        $current = $strategy->fresh(['campaign.customer']);
        if (! $current || ! $current->launch_intent) {
            return $current !== null && ! $ownerApprovedRestart; // Existing deployment/repair paths predate customer launch intents.
        }
        $campaign = $current->campaign;
        $customer = $campaign?->customer;
        if ($ownerApprovedRestart) {
            $activation = $current->execution_result['metadata']['search_reach_activation'] ?? [];
            if (! $campaign || ! CampaignSpendGuardrails::hasGoogleTotalBudget($campaign)
                || ($activation['enabled_verified'] ?? null) !== true || ($activation['status'] ?? null) !== 'enabled_verified'
                || ! is_string($current->launch_intent['id'] ?? null)
                || ($activation['launch_id'] ?? null) !== $current->launch_intent['id']) {
                return false;
            }
        }

        return $campaign && $customer && $this->isCurrent($campaign, $customer, $current, ownerApprovedRestart: $ownerApprovedRestart)
            && $this->accountsReady($customer, $current->launch_intent['platforms'])
            && $this->funded($customer, $current) && $this->publicationReady($customer, $current);
    }

    private function funded(Customer $customer, Strategy $strategy): bool
    {
        return ($strategy->launch_intent['version'] ?? 1) === 2
            ? app(LaunchFunding::class)->funded($strategy) : CampaignSpendGuardrails::hasLaunchFunding($customer);
    }

    private function publicationReady(Customer $customer, Strategy $strategy): bool
    {
        if (($strategy->launch_intent['version'] ?? 1) !== 2) {
            return true;
        }
        $approved = $strategy->launch_intent['funding'] ?? [];
        $proof = app(PlatformFunding::class)->assess($customer, $strategy->launch_intent['platforms'][0]);
        $events = app(CampaignOutcomeEvidence::class)->forStrategy($strategy);

        return $proof['ready'] && $proof['account'] === ($approved['account'] ?? null)
            && $proof['payer'] === ($approved['payer'] ?? null)
            && $proof['currency'] === ($approved['currency'] ?? null)
            && (! ($events['requires_verification'] ?? true) || ($events['verified_for_launch'] ?? false));
    }

    public function attachProvisionedMetaAccount(Campaign $campaign, string $id): void
    {
        DB::transaction(function () use ($campaign, $id) {
            $current = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $customer = $current->customer;
            $strategies = $this->strategies($current, $id, 'queued')->lockForUpdate()->get();
            if (! $customer || $strategies->isEmpty() || $strategies->contains(fn (Strategy $strategy) => ! $this->isCurrent($current, $customer, $strategy, attachingMeta: true))) {
                throw new \RuntimeException('The approved campaign changed while creating the Meta account.');
            }
            $this->bindAccounts($current, $customer, $strategies, status: 'queued');
        });
    }

    /** @return array{code: string, message: string}|null */
    public function metaAccountIssue(Customer $customer, bool $allowProvisioning = false): ?array
    {
        if (! $customer->facebook_page_id) {
            return ['code' => 'facebook_page_not_connected', 'message' => 'Link this workspace’s Facebook Page before launching Meta ads.'];
        }
        if (! $customer->facebook_ads_account_id && $allowProvisioning) {
            return null;
        }
        if (! $customer->facebook_ads_account_id || ! $customer->facebook_bm_owned) {
            return ['code' => 'facebook_account_provisioning_failed', 'message' => 'Link a Site to Spend business portfolio ad account before launching Meta ads.'];
        }

        return app(BusinessManagerService::class)->launchAccountIssue($customer->facebook_ads_account_id);
    }

    /** @param array{code: string, message: string} $issue */
    public function deferMetaAccount(Campaign $campaign, string $id, array $issue): void
    {
        DB::transaction(function () use ($campaign, $id, $issue) {
            $current = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            foreach ($this->strategies($current, $id, 'queued')->lockForUpdate()->get() as $strategy) {
                $intent = $strategy->launch_intent;
                unset($intent['started_at']);
                $strategy->forceFill(['launch_intent' => [...$intent, 'meta_account_issue' => $issue['code']],
                    'deployment_status' => self::WAITING, 'deployment_error' => $issue['message']])->save();
            }
            if ($current->status === CampaignStatus::Draft) {
                $current->update(['status' => CampaignStatus::PendingAdminDeployment, 'pending_admin_deployment_at' => now()]);
            }
        });
    }

    /** @return HasMany<Strategy, Campaign> */
    public function strategies(Campaign $campaign, string $id, string $status): HasMany
    {
        return $campaign->strategies()->where('launch_intent->id', $id)->where('deployment_status', $status);
    }

    /** @param Collection<int, Strategy> $strategies */
    private function bindAccounts(Campaign $campaign, Customer $customer, Collection $strategies, string $status): void
    {
        $intent = $strategies->first()->launch_intent;
        $currentAccounts = $customer->only(CampaignMarketResearch::APPROVAL_ACCOUNT_FIELDS);
        if ($intent['research_approval'] !== null) {
            app(CampaignMarketResearchWorkflow::class)->bindApprovedAccounts($campaign,
                $intent['research_accounts'] ?? $intent['staged_accounts'] ?? $intent['accounts'], $intent['research_approval']);
            if (($intent['version'] ?? 1) === 2) {
                $newApproval = $this->research->approvalFingerprint($campaign);
                foreach ($campaign->strategies()->where('launch_intent->approval_id', $intent['approval_id'])->lockForUpdate()->get() as $sibling) {
                    $sibling->forceFill(['launch_intent' => [...$sibling->launch_intent,
                        'research_accounts' => $currentAccounts, 'research_approval' => $newApproval]])->save();
                }
            }
        }
        foreach ($strategies as $strategy) {
            $intentForStrategy = $strategy->refresh()->launch_intent;
            unset($intentForStrategy['meta_account_issue']);
            $strategy->forceFill(['launch_intent' => [...$intentForStrategy,
                'staged_accounts' => $currentAccounts,
                'research_approval' => $intent['research_approval'] === null ? null : $this->research->approvalFingerprint($campaign),
            ], 'deployment_status' => $status, 'deployment_error' => null])->save();
        }
    }

    private function isCurrent(Campaign $campaign, Customer $customer, Strategy $strategy, bool $attachingMeta = false, bool $ownerApprovedRestart = false): bool
    {
        $intent = $strategy->launch_intent;
        $approver = User::find($intent['user_id']);
        // Require workspace membership as on the customer action. The admin
        // portal's global policy override does not authorize a deferred launch.
        if ($intent['customer_id'] !== $customer->id || ! $approver || ! app(CampaignPolicy::class)->deploy($approver, $campaign)
            || ! $this->canPrepare($campaign, $customer, $ownerApprovedRestart) || ! $this->platformAllowed($customer, $strategy)
            || $campaign->budget_confirmed_at?->toIso8601String() !== $intent['budget_approved_at']
            || $strategy->signed_off_at?->toIso8601String() !== $intent['signed_off_at']
            || ($strategy->creative_approval['fingerprint'] ?? null) !== $intent['creative_fingerprint']
            || ! $this->accountsMatch($customer, $intent, $attachingMeta)
            || ! $this->creative->isApproved($strategy)) {
            return false;
        }

        try {
            $fixed = GoogleSearchTotalBudget::forStrategy($strategy->setRelation('campaign', $campaign));
        } catch (ValidationException) {
            return false;
        }
        if ($fixed !== null && ! $strategy->google_ads_campaign_id
            && $fixed['start_date'] < now($customer->timezone ?: config('app.timezone'))->toDateString()) {
            return false;
        }

        $research = $campaign->search_delivery_state['market_research'] ?? [];

        return hash_equals($intent['proposal'], $this->research->approvalFingerprint($campaign, $intent['accounts']))
            && ($intent['research_approval'] === null || ($research['status'] ?? null) === 'ready'
                && ($research['result']['status'] ?? null) === 'ready'
                && ($research['approved_request_id'] ?? null) === $intent['research_request_id']
                && ($research['request_id'] ?? null) === $intent['research_request_id']
                && ($research['approved_fingerprint'] ?? null) === $intent['research_approval']);
    }

    private function canPrepare(Campaign $campaign, Customer $customer, bool $ownerApprovedRestart = false): bool
    {
        $brand = $customer->brandGuideline;

        return ! $customer->is_sandbox && $customer->hasPaidDeploymentAccess() && Setting::get('deployment_enabled', true)
            && $brand?->user_verified && ($brand->approved_version === null || $brand->approved_version === $brand->profile_version)
            && ! $campaign->hasPassedEndDate() && ! $campaign->spend_safety_hold
            && ! in_array($campaign->status, [CampaignStatus::Completed, CampaignStatus::Ended], true)
            && ($ownerApprovedRestart || $campaign->status !== CampaignStatus::Paused);
    }

    private function platformAllowed(Customer $customer, Strategy $strategy): bool
    {
        $platform = DeploymentService::normalizePlatform($strategy->platform);

        return array_key_exists($platform, config('platform_architecture.platforms'))
            && EnabledPlatform::isEnabled($platform) && $customer->allowsAdPlatform($platform);
    }

    private function accountsReady(Customer $customer, array $platforms): bool
    {
        return (! in_array('google', $platforms, true) || self::googleReady($customer))
            && (! in_array('microsoft', $platforms, true) || ($customer->microsoft_ads_customer_id && $customer->microsoft_ads_account_id))
            && (! in_array('linkedin', $platforms, true) || $customer->linkedin_ads_account_id);
    }

    private function accountsMatch(Customer $customer, array $intent, bool $attachingMeta): bool
    {
        $prior = $intent['staged_accounts'] ?? $intent['accounts'];
        $staged = $intent['staged_accounts'] !== null;
        $current = $customer->only(CampaignMarketResearch::APPROVAL_ACCOUNT_FIELDS);
        $accountPlatforms = ['google_ads_customer_id' => 'google', 'facebook_ads_account_id' => 'facebook',
            'microsoft_ads_customer_id' => 'microsoft', 'microsoft_ads_account_id' => 'microsoft', 'linkedin_ads_account_id' => 'linkedin'];
        foreach ($accountPlatforms as $field => $platform) {
            if ((($intent['version'] ?? 1) === 2 && ! in_array($platform, $intent['platforms'], true)) || $prior[$field] === $current[$field]) {
                continue;
            }
            if ($prior[$field] || ! $current[$field] || ! in_array($platform, $intent['platforms'], true)
                || ($staged && ! ($attachingMeta && $platform === 'facebook'))) {
                return false;
            }
        }
        $previousLink = $prior['google_ads_link_status'];
        $link = $current['google_ads_link_status'];
        if ($previousLink !== $link && (($intent['version'] ?? 1) !== 2 || in_array('google', $intent['platforms'], true))) {
            $acceptedLink = $previousLink === 'pending' && $link === 'active' && $prior['google_ads_customer_id'];
            $provisionedAccount = $previousLink === null && $link === 'managed'
                && ! $prior['google_ads_customer_id'] && $current['google_ads_customer_id'];
            if ($staged || ! in_array('google', $intent['platforms'], true) || (! $acceptedLink && ! $provisionedAccount)) {
                return false;
            }
        }
        if (in_array('google', $intent['platforms'], true) && $current['google_ads_customer_id']
            && ($staged || $prior['google_ads_customer_id'] !== $current['google_ads_customer_id'] || $previousLink !== $link)
            && ($customer->google_ads_manager_customer_id || $prior['google_ads_customer_id'] !== $current['google_ads_customer_id'] || $previousLink !== $link)
            && (! $intent['google_manager'] || preg_replace('/\D/', '', (string) $intent['google_manager']) !== preg_replace('/\D/', '', (string) $customer->google_ads_manager_customer_id))) {
            return false;
        }

        return true;
    }

    /** @param Collection<int, Strategy> $strategies */
    private function invalidate(Campaign $campaign, Collection $strategies): void
    {
        foreach ($strategies as $strategy) {
            if (($strategy->launch_intent['version'] ?? 1) === 2 && ! $strategy->hasDeploymentFootprint()) {
                app(LaunchFunding::class)->release($strategy);
            }
            $strategy->update(['deployment_status' => 'failed',
                'deployment_error' => 'Launch approval changed while setup was pending. Review your current campaign, budget, and ads before trying again.']);
        }
        if ($campaign->status === CampaignStatus::PendingAdminDeployment) {
            $campaign->update(['status' => CampaignStatus::Draft, 'pending_admin_deployment_at' => null]);
        }
    }
}
