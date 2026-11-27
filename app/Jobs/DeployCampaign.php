<?php

namespace App\Jobs;

use App\Mail\GoogleAdsVerificationRequired;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Setting;
use App\Notifications\DeploymentCompleted;
use App\Notifications\DeploymentFailed;
use App\Services\ActivityLogger;
use App\Services\AdSpendBillingService;
use App\Services\Agents\PreLaunchComplianceAgent;
use App\Services\Campaigns\CampaignMarketResearchWorkflow;
use App\Services\Campaigns\SettleDeployedCampaign;
use App\Services\Customers\HandOverAccount;
use App\Services\Deployment\CampaignLaunchIntent;
use App\Services\Deployment\StrategyBudgetAllocator;
use App\Services\DeploymentService;
use App\Services\FacebookAds\CreateFacebookAdsAccount;
use App\Services\FacebookAds\PixelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class DeployCampaign implements ShouldBeUnique, ShouldQueue
{
    /**
     * A soft-deleted customer/campaign mid-queue means the work is moot —
     * discard quietly instead of filling failed_jobs with ModelNotFound.
     */
    public $deleteWhenMissingModels = true;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 1200;

    public $uniqueFor = 1800;

    protected ?string $launchId = null;

    public function uniqueId(): string
    {
        return $this->strategyId !== null && $this->launchId !== null ? $this->campaign->id.':'.$this->launchId : (string) $this->campaign->id;
    }

    public function __construct(
        protected Campaign $campaign,
        protected bool $useAgents = true,
        protected ?int $strategyId = null,
        ?string $launchId = null
    ) {
        $this->launchId = $launchId;
    }

    public function handle(AdSpendBillingService $billingService): void
    {
        $this->campaign->refresh()->load(['customer', 'strategies']);
        Log::info("Starting deployment job for Campaign ID: {$this->campaign->id}", [
            'campaign_name' => $this->campaign->name,
            'total_budget' => $this->campaign->total_budget,
            'use_agents' => $this->useAgents,
        ]);

        $customer = $this->campaign->customer;

        if (! $customer) {
            Log::error("No customer found for campaign {$this->campaign->id}. Skipping deployment.");

            return;
        }

        $launch = $this->launchId === null ? null : app(CampaignLaunchIntent::class);
        if ($launch) {
            $selected = $launch->claim($this->campaign, $this->launchId);
            if ($selected->isEmpty()) {
                return;
            }
            $this->campaign->refresh()->load('customer');
            $customer = $this->campaign->customer;
        } else {
            $selected = $this->campaign->strategies->filter(fn ($strategy) => $strategy->signed_off_at !== null
                && $strategy->launch_intent === null && ($this->strategyId === null || $strategy->id === $this->strategyId));
        }
        if ($selected->isEmpty()) {
            $message = 'Approve a researched strategy before creating or funding new ads.';
            $this->failPendingStrategies($message);
            $this->notifyUsers(new DeploymentFailed($this->campaign, $message));

            return;
        }
        $hasNewStrategy = $selected->contains(function ($strategy): bool {
            $strategy->setRelation('campaign', $this->campaign);

            return $strategy->requiresInitialMarketResearch();
        });
        if ($hasNewStrategy) {
            try {
                app(CampaignMarketResearchWorkflow::class)->assertApproved($this->campaign);
            } catch (ValidationException $e) {
                $message = $e->validator->errors()->first();
                $this->failPendingStrategies($message);
                $this->notifyUsers(new DeploymentFailed($this->campaign, $message));

                return;
            }
        }

        if (! $customer || ! $customer->hasPaidDeploymentAccess()) {
            $message = 'Choose a paid campaign plan before launching ads.';
            $this->failPendingStrategies($message);
            $this->notifyUsers(new DeploymentFailed($this->campaign, $message));

            return;
        }

        // Split the campaign's daily budget evenly across strategies.
        //
        // Signed-off only: sign-off is the user's approval of what runs and
        // what the budget is split across. Deploying every strategy meant an
        // unreviewed one could go live AND halve the budget of the one the
        // user actually approved.
        $strategies = $selected;

        /*
           Filter strategies to the platforms this account's plan allows.
           Was read off a user picked from the customer — the owner if one
           existed, otherwise whoever came first — so on an account with several
           people the platforms a campaign deployed to depended on row order.
           The plan belongs to the business, so the business answers.
        */
        [$strategies, $planFiltered] = $strategies->partition(fn ($strategy) => $customer->allowsAdPlatform($strategy->platform));

        // A dropped strategy must reach a terminal state the status page
        // can display — leaving it at null read as "pending" and kept the
        // page polling forever with no explanation.
        foreach ($planFiltered as $skipped) {
            $skipped->update([
                'deployment_status' => 'skipped_plan',
                'deployment_error' => "{$skipped->platform} isn't included in your current plan, so this strategy was not deployed. Upgrade your plan to run it.",
            ]);
        }

        Log::info('Plan-filtered strategies for deployment: '.$strategies->pluck('platform')->implode(', '));

        // Nothing left to deploy is a failure the user must hear about, not a
        // success with zero platforms. Falling through used to email
        // "deployment completed" for a campaign where the plan filter had
        // dropped every strategy, while the campaign never went Active.
        if ($strategies->isEmpty()) {
            Log::warning("DeployCampaign: no deployable strategies for Campaign ID: {$this->campaign->id}", [
                'strategy_id_filter' => $this->strategyId,
            ]);

            $this->notifyUsers(new DeploymentFailed(
                $this->campaign,
                'None of this campaign\'s strategies can be deployed on your current plan. Upgrade your plan or adjust the campaign\'s platforms, then deploy again.'
            ));

            return;
        }

        $hasFacebookStrategy = $strategies->contains(fn ($strategy) => DeploymentService::normalizePlatform($strategy->platform) === 'facebook');

        // Auto-provision the portfolio's ad account after the tenant's Page is linked.
        if ($hasFacebookStrategy && ! $customer->facebook_ads_account_id && $customer->facebook_page_id) {
            Log::info("DeployCampaign: No Facebook ad account — provisioning for customer {$customer->id}");
            if ($accountId = app(CreateFacebookAdsAccount::class, ['customer' => $customer])->provision()) {
                if ($launch) {
                    $launch->attachProvisionedMetaAccount($this->campaign, $this->launchId);
                }
                $customer->refresh();
                Log::info('DeployCampaign: Facebook ad account provisioned', [
                    'customer_id' => $customer->id,
                    'account_id' => $accountId,
                ]);
            } else {
                Log::warning('DeployCampaign: Could not provision Facebook ad account — compliance check may fail', [
                    'customer_id' => $customer->id,
                ]);
            }
        }

        if ($hasFacebookStrategy && $launch && ($issue = $launch->metaAccountIssue($customer))) {
            $launch->deferMetaAccount($this->campaign, $this->launchId, $issue);

            return;
        }

        // Auto-provision Facebook pixel before compliance check.
        // PixelService will find an existing pixel on the ad account or create a
        // new BM-owned one and share it — no manual steps required.
        if ($hasFacebookStrategy && $customer->facebook_ads_account_id && ! $customer->facebook_pixel_id) {
            Log::info("DeployCampaign: No pixel on record — attempting auto-provision for customer {$customer->id}");
            $pixelId = (new PixelService($customer))->resolvePixelId();
            if ($pixelId) {
                Log::info('DeployCampaign: Facebook pixel provisioned', [
                    'customer_id' => $customer->id,
                    'pixel_id' => $pixelId,
                ]);
                $customer->refresh(); // ensure compliance check sees the updated pixel_id
            } else {
                Log::warning('DeployCampaign: Could not auto-provision Facebook pixel — compliance check may fail', [
                    'customer_id' => $customer->id,
                ]);
            }
        }

        // Pre-launch compliance check — abort if any check fails
        $leg = ($strategies->first()?->launch_intent['version'] ?? 1) === 2 ? $strategies->first() : null;
        $complianceCampaign = clone $this->campaign;
        if ($leg) {
            $complianceCampaign->setRelation('strategies', new \Illuminate\Database\Eloquent\Collection([$leg]));
        }
        $complianceResult = app(PreLaunchComplianceAgent::class)->check($complianceCampaign);
        if (! $complianceResult['passed']) {
            Log::warning("DeployCampaign: Campaign {$this->campaign->id} failed compliance check, aborting deployment.", [
                'failures' => $complianceResult['failures'],
            ]);
            $message = 'Pre-launch compliance check failed: '.implode('; ', array_column($complianceResult['failures'], 'message'));
            $this->failPendingStrategies($message);
            $this->notifyUsers(new \App\Notifications\DeploymentFailed($this->campaign, $message));

            return;
        }

        // Ad spend credit applies only to accounts Spectra funds.
        //
        // Where the customer granted us management of an account they already
        // owned, their own card is on it and Google charges them directly.
        // Requiring prepaid ad credit there bills them twice for the same
        // clicks. Those customers are gated on their subscription and their
        // media allowance instead, both checked below.
        $selfFunded = $leg ? ($leg->launch_intent['funding']['payer'] ?? null) === 'customer' : $customer->isSelfFundedAds();
        $managedBillingEnabled = ! $selfFunded && ($leg !== null || Setting::get('managed_billing_enabled', true));
        if ($leg && ! $launch->canPublish($leg)) {
            $this->failPendingStrategies('Funding, tracking or the approved account changed; review this channel before publishing.');

            return;
        }

        if ($selfFunded) {
            /*
               The media allowance is a subscription concept, and a one-time
               setup customer has no subscription. They paid US$999 for exactly
               these ads; refusing to publish them because a plan they were
               never sold has no quota left is the platform charging twice and
               delivering once.
            */
            $approvedStarterAds = \App\Services\Creative\StarterCreativeQuota::applies($customer)
                && $selected->every(fn ($strategy) => app(\App\Services\Creative\CreativeRelease::class)->isApproved($strategy));
            if ($customer->service_type !== 'setup_only' && ! $approvedStarterAds && ! $customer->hasMediaCreditsRemaining()) {
                Log::warning('DeployCampaign: self-funded customer has no media allowance left', [
                    'customer_id' => $customer->id,
                ]);

                $message = 'Your media allowance for this period is used up. Check Creative Usage before trying again.';
                $this->failPendingStrategies($message);
                $this->notifyUsers(new \App\Notifications\DeploymentFailed($this->campaign, $message));

                return;
            }

            Log::info('DeployCampaign: self-funded account, skipping ad spend credit', [
                'customer_id' => $customer->id,
                'google_ads_customer_id' => $customer->google_ads_customer_id,
            ]);
        }

        if ($managedBillingEnabled) {
            try {
                $credit = $customer->adSpendCredit;

                if (! $credit) {
                    if ($this->launchId !== null) {
                        throw new \RuntimeException('Cannot deploy campaign: Payment issue. The approved launch is waiting for prepaid ad spend.');
                    }

                    Log::info('Initializing ad spend credit for customer', [
                        'customer_id' => $customer->id,
                        'daily_budget' => $this->campaign->daily_budget,
                    ]);

                    $credit = $billingService->initializeCreditAccount(
                        $customer,
                        $this->campaign->daily_budget ?? 50 // Default $50/day if not set
                    );

                    Log::info('Ad spend credit initialized', [
                        'customer_id' => $customer->id,
                        'initial_credit' => $credit->initial_credit_amount,
                    ]);
                }

                // Check if customer can run campaigns (payment status OK)
                if (! $credit->canRunCampaigns()) {
                    Log::warning('Customer cannot run campaigns - payment issue', [
                        'customer_id' => $customer->id,
                        'status' => $credit->status,
                        'payment_status' => $credit->payment_status,
                    ]);

                    // Fail the job with a message
                    throw new \Exception("Cannot deploy campaign: Payment issue. Status: {$credit->payment_status}");
                }
            } catch (\Throwable $e) {
                Log::error('Ad spend credit initialization failed', [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
                throw $e; // Re-throw to fail the job
            }
        } else {
            Log::info('Managed billing is disabled - skipping ad spend credit initialization', [
                'customer_id' => $customer->id,
            ]);
        }

        app(StrategyBudgetAllocator::class)->allocate($this->campaign, $strategies);

        $deploymentResults = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($strategies as $strategy) {
            // Skip strategies that are already deployed/verified — prevents duplicate campaigns
            // when the user clicks Deploy more than once or the job retries.
            if ($strategy->isDeployed()) {
                Log::info('Skipping already-deployed strategy', [
                    'campaign_id' => $this->campaign->id,
                    'strategy_id' => $strategy->id,
                    'platform' => $strategy->platform,
                    'deployment_status' => $strategy->deployment_status,
                ]);
                $successCount++;

                continue;
            }

            Log::info("Deploying strategy for platform: {$strategy->platform}", [
                'campaign_id' => $this->campaign->id,
                'strategy_id' => $strategy->id,
                'platform' => $strategy->platform,
            ]);

            if (($strategy->launch_intent['version'] ?? 1) === 2) {
                $strategy->refresh();
                $strategy->forceFill(['launch_intent' => [...$strategy->launch_intent, 'platform_submission_started_at' => now()->toIso8601String(),
                    'campaign_create_checkpoint' => $this->useAgents && config('app.use_execution_agents', true)
                        && in_array(DeploymentService::normalizePlatform($strategy->platform), ['google', 'facebook'], true)]])->save();
            }
            $result = DeploymentService::deploy(
                $this->campaign,
                $strategy,
                $customer,
                $this->useAgents
            );

            $deploymentResults[$strategy->platform] = $result;

            if ($result['success']) {
                $successCount++;
                Log::info('Successfully deployed strategy', [
                    'campaign_id' => $this->campaign->id,
                    'strategy_id' => $strategy->id,
                    'platform' => $strategy->platform,
                ]);
            } else {
                $failureCount++;
                Log::error('Failed to deploy strategy', [
                    'campaign_id' => $this->campaign->id,
                    'strategy_id' => $strategy->id,
                    'platform' => $strategy->platform,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }
        }

        Log::info("Finished deployment job for Campaign ID: {$this->campaign->id}", [
            'total_strategies' => count($this->campaign->strategies),
            'successful' => $successCount,
            'failed' => $failureCount,
            'results' => $deploymentResults,
        ]);

        // If a Google Ads deployment failed with ACTION_NOT_PERMITTED, the account likely
        // needs identity verification. Notify the customer so they can complete it.
        foreach ($deploymentResults as $platform => $result) {
            if (! $result['success'] && str_contains(strtolower($platform), 'google')) {
                $errorText = is_string($result['error'] ?? null) ? $result['error'] : json_encode($result['error'] ?? '');
                if (str_contains($errorText, 'ACTION_NOT_PERMITTED') || str_contains($errorText, 'PERMISSION_DENIED')) {
                    foreach ($customer->users as $user) {
                        Mail::to($user->email)->queue(
                            new GoogleAdsVerificationRequired($user, $customer, $this->campaign)
                        );
                    }
                    break;
                }
            }
        }

        // Optionally: Update campaign status or send notifications based on results
        if ($failureCount > 0 && $successCount === 0) {
            Log::critical("All deployments failed for Campaign ID: {$this->campaign->id}");
            $this->notifyUsers(new DeploymentFailed($this->campaign, 'All platform deployments failed.'));
        } elseif ($failureCount > 0) {
            Log::warning("Partial deployment failure for Campaign ID: {$this->campaign->id}");
            $this->notifyUsers(new DeploymentCompleted($this->campaign, $successCount, $failureCount, $strategies->toArray()));
        } else {
            Log::info("All deployments successful for Campaign ID: {$this->campaign->id}");
            $this->notifyUsers(new DeploymentCompleted($this->campaign, $successCount, $failureCount, $strategies->toArray()));
        }

        // AgentActivity is the per-customer feed; the activity log is the
        // platform-wide one an admin reads. A deployment is the single most
        // consequential thing that happens on this platform and appeared in
        // neither of the admin's views before.
        ActivityLogger::campaign(
            $failureCount === 0 ? 'deployed' : 'deploy_blocked',
            $this->campaign,
            ['successful' => $successCount, 'failed' => $failureCount],
        );

        AgentActivity::record(
            'deployment',
            $failureCount === 0 ? 'deployed_campaign' : 'deployment_partial',
            $failureCount === 0
                ? "Successfully deployed \"{$this->campaign->name}\" to Google Ads"
                : "Deployed \"{$this->campaign->name}\" with {$failureCount} failure(s)",
            $this->campaign->customer_id,
            $this->campaign->id,
            ['successful' => $successCount, 'failed' => $failureCount],
            $failureCount === 0 ? 'completed' : 'failed'
        );

        if ($successCount > 0) {
            // Moved to SettleDeployedCampaign because this was the only copy,
            // and it only ran when this job reached its final lines. A worker
            // restart part-way through four platforms left the strategies
            // mid-flight and the campaign at draft, and nothing downstream
            // could finish the job — see that class for what that costs.
            app(SettleDeployedCampaign::class)->settle($this->campaign);
        }

        /*
           The last step of a one-time setup, and the only one that was manual.

           A customer paying US$999 buys an account, tracking, a campaign and
           ads — and then the keys. Every part of that was automatic except the
           handover, which waited on an admin noticing. Nothing else in the
           engagement waits on a person, so neither does this.

           Only on a clean sweep. A partial deploy means some of what they paid
           for is not there, and handing over mid-build both closes the
           engagement in our records and hands them a half-built account. Those
           stay for an admin, flagged on the customer page.
        */
        $customer = $this->campaign->customer;

        if ($customer
            && $customer->service_type === 'setup_only'
            && $successCount > 0
            && $failureCount === 0
        ) {
            try {
                app(HandOverAccount::class)->handOver($customer);
            } catch (\Throwable $e) {
                // The ads are built and live-but-paused either way; a failed
                // handover must not fail the deploy and undo that. It surfaces
                // in the dashboard and the admin button finishes the job.
                report($e);
                Log::error('DeployCampaign: automatic handover failed', [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Dispatch verification job after 60s to confirm objects exist on platforms
        if ($successCount > 0) {
            VerifyDeployment::dispatch($this->campaign)->delay(now()->addSeconds(60));
            CheckGoogleCampaignReadiness::dispatch($this->campaign->id)->delay(now()->addMinutes(5));
            CheckGoogleSearchDelivery::dispatch($this->campaign->id)->delay(now()->addMinutes(10));
        }
    }

    /**
     * Notify all users associated with this campaign's customer.
     */
    protected function notifyUsers($notification): void
    {
        $customer = $this->campaign->customer;

        if ($customer) {
            foreach ($customer->users as $user) {
                $user->notify($notification);
            }
        }
    }

    /**
     * Handle a job failure.
     *
     * With $tries = 1 this is the end of the line — nothing retries, so if the
     * user isn't told here they are never told. The most common throw for a
     * new self-serve account is the card charge in handle(), which is exactly
     * the failure the user can actually fix themselves.
     */
    private function failPendingStrategies(string $message): void
    {
        $this->campaign->strategies()
            ->when($this->strategyId !== null, fn ($query) => $query->whereKey($this->strategyId))
            ->when($this->launchId !== null, fn ($query) => $query->where('launch_intent->id', $this->launchId),
                fn ($query) => $query->whereNull('launch_intent'))
            ->whereIn('deployment_status', ['queued', 'deploying'])
            ->update(['deployment_status' => 'failed', 'deployment_error' => $message]);
    }

    public function failed(\Throwable $exception): void
    {
        $this->failPendingStrategies('Deployment could not finish. Review the issue below and try again.');
        Log::error('DeployCampaign failed: '.$exception->getMessage(), [
            'campaign_id' => $this->campaign->id ?? null,
            'exception' => $exception->getTraceAsString(),
        ]);

        $isPaymentIssue = str_contains($exception->getMessage(), 'Payment issue');

        try {
            $this->notifyUsers(new DeploymentFailed(
                $this->campaign,
                $isPaymentIssue
                    ? 'We couldn\'t charge your payment method for the ad spend prepayment. Update your card under Billing → Ad Spend, then deploy again.'
                    : 'Something went wrong while deploying. Our team has been notified — you can retry from the campaign page.'
            ));
        } catch (\Throwable $e) {
            report($e);
            Log::error('DeployCampaign::failed could not notify users: '.$e->getMessage());
        }
    }
}
