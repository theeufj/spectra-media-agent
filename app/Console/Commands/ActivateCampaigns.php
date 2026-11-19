<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Jobs\RecordSiteConversion;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Setting;
use App\Models\Strategy;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\CampaignStatusHelper;
use App\Services\Deployment\CampaignLaunchIntent;
use App\Services\FacebookAds\AdService;
use App\Services\FacebookAds\AdSetService;
use App\Services\FacebookAds\BusinessManagerService;
use App\Services\FacebookAds\CampaignService;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use App\Services\LinkedInAds\CampaignService as LinkedInCampaignService;
use App\Services\MicrosoftAds\CampaignService as MicrosoftCampaignService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ActivateCampaigns extends Command
{
    protected $signature = 'campaigns:activate
        {campaign? : Campaign ID to activate (all deployed if omitted)}
        {--dry-run : Show what would be activated without making changes}';

    protected $description = 'Transition deployed campaigns from PAUSED to ACTIVE/ENABLED on their platforms';

    /**
     * How long a freshly deployed strategy is left alone on an unattended run.
     *
     * VerifyDeployment only selects deployment_status = 'deployed'; flipping a
     * strategy to 'active' the moment it lands would take it out from under the
     * verifier and the deployment would never be checked. An operator naming a
     * campaign explicitly is not held to this.
     */
    private const SETTLE_MINUTES = 15;

    public function handle(): int
    {
        if (CampaignStatusHelper::isTestingMode()) {
            $this->warn('Campaign testing mode is ON — campaigns are deployed as PAUSED.');
            $this->warn('Disable testing mode in Settings before activating.');

            return self::FAILURE;
        }

        $campaignId = $this->argument('campaign');

        $query = Strategy::with('campaign.customer')
            ->whereIn('deployment_status', Strategy::ACTIVATABLE_STATUSES);

        if ($campaignId) {
            $query->where('campaign_id', $campaignId);
        } else {
            $query->where(fn ($scope) => $scope
                ->where('updated_at', '<', now()->subMinutes(self::SETTLE_MINUTES))
                ->orWhere(fn ($verified) => $verified->where('deployment_status', 'verified')
                    ->where('execution_result->metadata->facebook_activation_pending', true)));
        }

        $strategies = $query->get();

        if ($strategies->isEmpty()) {
            $this->info('No deployed strategies found to activate.');

            return self::SUCCESS;
        }

        $this->info("Found {$strategies->count()} strategy(ies) to activate.");

        $successCount = 0;
        $failCount = 0;
        $plannedCount = 0;

        foreach ($strategies as $strategy) {
            $campaign = $strategy->campaign;
            $customer = $campaign?->customer;

            // instanceof rather than a truthiness check so the rest of the loop
            // has a Campaign and a Customer, not an untyped relation result.
            if (! $campaign instanceof Campaign || ! $customer instanceof Customer) {
                $this->warn("Strategy {$strategy->id}: No customer found, skipping.");

                continue;
            }

            $label = "Strategy {$strategy->id} ({$strategy->platform}) - Campaign \"{$campaign->name}\"";

            // A campaign Spectra has deliberately stood down — unpaid ad-spend
            // credit, a policy violation, a deactivated customer, an owner who
            // paused it — must never be switched back on by a scheduled sweep.
            // Its strategies keep deployment_status 'deployed', so without this
            // the sweep resumes spend nobody authorised.
            if (in_array($campaign->status, CampaignStatus::nonDelivering(), true)) {
                $this->warn("    {$label}: campaign is {$campaign->status->label()}, leaving it alone.");

                continue;
            }

            $platform = $this->platformKey($strategy->platform);

            if ($platform === null) {
                $this->warn("    Unknown platform '{$strategy->platform}', skipping.");

                continue;
            }

            $metadata = $strategy->execution_result['metadata'] ?? [];
            if (! CampaignSpendGuardrails::canEnable($campaign)
                || ! CampaignSpendGuardrails::hasLaunchFunding($customer)
                || $strategy->launch_intent !== null && ($strategy->deployment_status !== 'verified'
                    || ! app(CampaignLaunchIntent::class)->canPublish($strategy))
                || $platform === 'google' && isset($metadata['google_search_staging'])
                    && ($metadata['search_reach_activation']['enabled_verified'] ?? false) !== true
                || $platform === 'facebook' && ! $this->canActivateFacebook($strategy, $campaign)) {
                $this->warn("    {$label}: activation requirements are pending.");

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  [DRY RUN] Would activate: {$label}");
                $plannedCount++;

                continue;
            }

            $this->line("  Activating: {$label}");

            // One try/catch per strategy: a platform that throws must not abort
            // the rest of the run, and report() is what puts it on the admin
            // exception dashboard — a log line alone never reaches it.
            try {
                $activated = match ($platform) {
                    'google' => $this->activateGoogleAds($strategy, $campaign, $customer),
                    'facebook' => $this->activateFacebook($strategy, $campaign, $customer),
                    'microsoft' => $this->activateMicrosoft($strategy, $campaign, $customer),
                    'linkedin' => $this->activateLinkedIn($strategy, $campaign, $customer),
                };
            } catch (\Throwable $e) {
                report($e);
                Log::error('ActivateCampaigns: activation threw', [
                    'strategy_id' => $strategy->id,
                    'platform' => $strategy->platform,
                    'error' => $e->getMessage(),
                ]);
                $this->error('    Activation failed: '.$e->getMessage());
                $failCount++;

                continue;
            }

            if ($activated) {
                $strategy->update(['deployment_status' => 'active']);
                // Keep platform_status in step with what we just told the
                // platform. Without it MonitorCampaignStatus reads ENABLED an
                // hour later, sees a stale local row and records a spurious
                // status_reconciled activity on every cycle.
                if ($platform !== 'facebook' || ! $campaign->google_ads_campaign_id) {
                    $campaign->applyPlatformStatus('ENABLED');
                }
                if (($platform === 'facebook' || $strategy->campaign_type === 'performance_max')
                    && ! $campaign->strategies()->deployed()->whereKeyNot($strategy->id)->get()
                        ->contains(fn (Strategy $other) => $other->deployment_status === 'active'
                            || ! array_key_exists('facebook_activation_pending', $other->execution_result['metadata'] ?? [])
                                && ! isset($other->execution_result['metadata']['google_pmax_staging']))) {
                    RecordSiteConversion::dispatch($customer, 'campaign_live');
                }
                $this->info('    Activated successfully.');
                $successCount++;
            } else {
                $this->error('    Activation failed.');
                $failCount++;
            }
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run complete. {$plannedCount} strategies would be activated.");
        } else {
            $this->info("Done. {$successCount} activated, {$failCount} failed.");
        }

        return $failCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Canonical platform key for a stored strategy platform string, which ranges
     * from 'google' to 'Google Ads (SEM)'. Null when nothing here can activate it.
     */
    private function platformKey(?string $platform): ?string
    {
        $platform = strtolower(trim($platform ?? ''));

        return match (true) {
            str_contains($platform, 'google') => 'google',
            str_contains($platform, 'facebook'), str_contains($platform, 'meta') => 'facebook',
            str_contains($platform, 'microsoft'), str_contains($platform, 'bing') => 'microsoft',
            str_contains($platform, 'linkedin') => 'linkedin',
            default => null,
        };
    }

    /**
     * The campaign identifier the execution agent recorded on the result.
     *
     * ExecutionResult::addPlatformId() is called with 'campaign' at every call
     * site in the repo; 'campaign_id' is written nowhere. Reading only that key
     * meant the platform ids were never consulted at all — Google limped along
     * on the campaign column and Facebook reported "Missing Facebook campaign
     * ID" for every strategy.
     */
    private function platformCampaignId(Strategy $strategy): ?string
    {
        $ids = $strategy->execution_result['platform_ids'] ?? [];
        $id = $ids['campaign'] ?? $ids['campaign_id'] ?? null;

        return $id === null || $id === '' ? null : (string) $id;
    }

    private function activateGoogleAds(Strategy $strategy, Campaign $campaign, Customer $customer): bool
    {
        if (! $customer->google_ads_customer_id) {
            $this->warn('    Missing Google Ads customer ID.');

            return false;
        }

        $customerId = $customer->cleanGoogleCustomerId();

        // Both sources have been seen holding a bare numeric ID *and* a full
        // resource name. Interpolating a resource name into the template built
        // customers/X/campaigns/customers/X/campaigns/Y, which Google rejects —
        // so activation failed for every campaign whose strategy carried no
        // recorded platform id and fell back to the campaign's own column.
        $googleCampaignId = $this->platformCampaignId($strategy);

        $resourceName = match (true) {
            $googleCampaignId === null => $campaign->googleAdsResourceName(),
            str_starts_with($googleCampaignId, 'customers/') => $googleCampaignId,
            default => "customers/{$customerId}/campaigns/{$googleCampaignId}",
        };

        if (! $resourceName) {
            $this->warn('    Missing Google Ads campaign ID.');

            return false;
        }

        // Staged Search has its own approved reach check and readback during
        // settlement. The scheduled sweep must never issue a second enable.
        if (isset($strategy->execution_result['metadata']['google_search_staging'])) {
            return true;
        }

        if ($strategy->campaign_type === 'performance_max'
            && ! app(\App\Services\GoogleAds\PerformanceMaxServices\PerformanceMaxLaunchCheck::class)->ready($strategy, $resourceName)) {
            $this->warn('    Performance Max is waiting for a verified budget, asset group and conversion goal.');

            return false;
        }

        if ($strategy->launch_intent !== null && ! app(CampaignLaunchIntent::class)->canPublish($strategy)) {
            return false;
        }
        $service = new UpdateCampaignStatus($customer);
        $result = $service->enable($customerId, $resourceName);
        if (($result['success'] ?? false) && $strategy->launch_intent !== null
            && ! app(CampaignLaunchIntent::class)->canPublish($strategy)) {
            $this->verifySafeGooglePause($service, $customer, $customerId, $resourceName);

            return false;
        }

        if ($strategy->campaign_type === 'performance_max' && ($result['success'] ?? false)) {
            return app(\App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration::class, ['customer' => $customer])
                ->campaignStatus($customerId, $resourceName) === 'ENABLED';
        }

        return $result['success'] ?? false;
    }

    private function verifySafeGooglePause(UpdateCampaignStatus $service, Customer $customer, string $customerId, string $resource): void
    {
        if (! ($service->pause($customerId, $resource)['success'] ?? false)
            || app(\App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration::class, ['customer' => $customer])
                ->campaignStatus($customerId, $resource) !== 'PAUSED') {
            throw new \RuntimeException('The Google launch approval changed and the safety pause could not be verified.');
        }
    }

    private function canActivateFacebook(Strategy $strategy, Campaign $campaign): bool
    {
        $customer = $campaign->customer;
        $budget = CampaignSpendGuardrails::moneyToMicros($strategy->daily_budget);
        $today = now($customer?->timezone ?: config('app.timezone'))->toDateString();

        return $strategy->deployment_status === 'verified'
            && ($strategy->execution_result['metadata']['facebook_activation_pending'] ?? false) === true
            && ($strategy->launch_intent === null || app(CampaignLaunchIntent::class)->canPublish($strategy))
            && $strategy->signed_off_at !== null
            && $customer !== null
            && ! $customer->is_sandbox
            && CampaignSpendGuardrails::hasLaunchFunding($customer)
            && $campaign->budget_confirmed_at !== null
            && $budget > 0
            && $budget <= CampaignSpendGuardrails::moneyToMicros($campaign->approved_daily_budget)
            && CampaignSpendGuardrails::permitsDailyBudget($campaign, $budget)
            && $campaign->start_date->toDateString() <= $today
            && EnabledPlatform::isEnabled('facebook')
            && Setting::get('deployment_enabled', true)
            && CampaignStatusHelper::getFacebookAdsStatus() === 'ACTIVE';
    }

    private function activateFacebook(Strategy $strategy, Campaign $campaign, Customer $customer): bool
    {
        $ids = $strategy->execution_result['platform_ids'] ?? [];
        $id = $this->platformCampaignId($strategy);
        $adSetId = $ids['ad_set'] ?? null;
        $accountId = str_replace('act_', '', (string) $customer->facebook_ads_account_id);
        $adIds = array_filter($ids, fn ($key) => str_starts_with($key, 'image_ad_') || str_starts_with($key, 'video_ad_'), ARRAY_FILTER_USE_KEY);
        if (! $id || ! $adSetId || ! $accountId || $adIds === [] || $id !== (string) $strategy->facebook_campaign_id
            || (string) $adSetId !== (string) $strategy->facebook_adset_id) {
            return false;
        }

        $service = new CampaignService($customer);
        $remote = $service->getCampaign($id);
        $budgetCents = intdiv(CampaignSpendGuardrails::moneyToMicros($strategy->daily_budget), 10_000);
        if (($remote['id'] ?? null) !== $id || ($remote['account_id'] ?? null) !== $accountId
            || (int) ($remote['daily_budget'] ?? 0) !== $budgetCents
            || ! in_array($remote['status'] ?? null, ['PAUSED', 'ACTIVE'], true)) {
            return false;
        }

        $sets = (new AdSetService($customer))->listAdSets($id);
        $activeSet = collect($sets)->first(fn ($set) => ($set['id'] ?? null) === (string) $adSetId && ($set['status'] ?? null) === 'ACTIVE');
        if ($activeSet === null) {
            return false;
        }
        $ads = (new AdService($customer))->listAds((string) $adSetId);
        $activeAd = collect($ads)->first(fn ($ad) => ($ad['status'] ?? null) === 'ACTIVE' && in_array($ad['id'] ?? null, $adIds, true));
        if ($activeAd === null) {
            return false;
        }

        $current = $campaign->fresh();
        $latest = $strategy->fresh();
        if (! $current || ! $latest || ! CampaignSpendGuardrails::canEnable($current)
            || ! $this->canActivateFacebook($latest, $current)
            || $latest->execution_result !== $strategy->execution_result
            || $latest->daily_budget !== $strategy->daily_budget
            || $current->customer?->facebook_ads_account_id !== $customer->facebook_ads_account_id) {
            return false;
        }

        $enabledHere = $remote['status'] === 'PAUSED';
        if ($enabledHere && $strategy->launch_intent !== null) {
            $issue = app(BusinessManagerService::class)->launchAccountIssue($accountId);
            if ($issue) {
                $this->warn('    '.$issue['message']);

                return false;
            }
        }
        if ($enabledHere && ! $service->updateCampaign($id, ['status' => 'ACTIVE'])) {
            return false;
        }

        $readback = $service->getCampaign($id);
        $current = $current->fresh();
        $latest = $latest->fresh();
        if ($current && $latest && CampaignSpendGuardrails::canEnable($current) && $this->canActivateFacebook($latest, $current)
            && $latest->execution_result === $strategy->execution_result && $latest->daily_budget === $strategy->daily_budget) {
            return ($readback['status'] ?? null) === 'ACTIVE';
        }

        if ($enabledHere && (! $service->updateCampaign($id, ['status' => 'PAUSED'])
            || ($service->getCampaign($id)['status'] ?? null) !== 'PAUSED')) {
            throw new \RuntimeException('The Meta launch authorization changed and the safety pause could not be verified.');
        }

        return false;
    }

    /**
     * A Microsoft campaign that deploys paused — testing mode, a PAUSED
     * `campaigns.default_status`, or CampaignService's own 'Paused' fallback —
     * had no way back on: this command dispatched for google and facebook and
     * `continue`d otherwise, so the deploy reported success and never served.
     */
    private function activateMicrosoft(Strategy $strategy, Campaign $campaign, Customer $customer): bool
    {
        $msCampaignId = $this->platformCampaignId($strategy)
            ?? $campaign->microsoft_ads_campaign_id
            ?? null;

        if (! $msCampaignId || ! $customer->microsoft_ads_account_id) {
            $this->warn('    Missing Microsoft campaign ID or account ID.');

            return false;
        }

        $service = app(MicrosoftCampaignService::class, ['customer' => $customer]);
        $result = $service->updateStatus(
            (string) $msCampaignId,
            CampaignStatusHelper::getMicrosoftAdsStatus('ACTIVE'),
        );
        if (! $result || $strategy->launch_intent === null) {
            return $result;
        }
        if (! app(CampaignLaunchIntent::class)->canPublish($strategy)) {
            if (! $service->updateStatus((string) $msCampaignId, 'Paused') || $service->getCampaignStatus((string) $msCampaignId) !== 'Paused') {
                throw new \RuntimeException('The Microsoft launch approval changed and the safety pause could not be verified.');
            }

            return false;
        }

        return $service->getCampaignStatus((string) $msCampaignId) === 'Active';
    }

    /**
     * Same gap on LinkedIn. updateStatus() answers with the PATCH response body
     * (or null on failure), so null is the only failure signal available.
     */
    private function activateLinkedIn(Strategy $strategy, Campaign $campaign, Customer $customer): bool
    {
        $liCampaignId = $this->platformCampaignId($strategy)
            ?? $campaign->linkedin_campaign_id
            ?? null;

        if (! $liCampaignId || ! $customer->linkedin_ads_account_id) {
            $this->warn('    Missing LinkedIn campaign ID or account ID.');

            return false;
        }

        $service = app(LinkedInCampaignService::class, ['customer' => $customer]);
        $result = $service->updateStatus(
            (string) $liCampaignId,
            CampaignStatusHelper::getLinkedInAdsStatus('ACTIVE'),
        );
        if ($result && $strategy->launch_intent !== null) {
            if (! app(CampaignLaunchIntent::class)->canPublish($strategy)) {
                if ($service->updateStatus((string) $liCampaignId, 'PAUSED') === null || ($service->getCampaign((string) $liCampaignId)['status'] ?? null) !== 'PAUSED') {
                    throw new \RuntimeException('The LinkedIn launch approval changed and the safety pause could not be verified.');
                }

                return false;
            }

            return ($service->getCampaign((string) $liCampaignId)['status'] ?? null) === 'ACTIVE';
        }

        return $result !== null;
    }
}
