<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Mail\GoogleAdsVerificationRequired;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Setting;
use App\Notifications\DeploymentCompleted;
use App\Notifications\DeploymentFailed;
use App\Services\ActivityLogger;
use App\Services\AdSpendBillingService;
use App\Services\Agents\PreLaunchComplianceAgent;
use App\Services\DeploymentService;
use App\Services\FacebookAds\CreateFacebookAdsAccount;
use App\Services\FacebookAds\PixelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DeployCampaign implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 1200;

    public $uniqueFor = 1800;

    public function uniqueId(): string
    {
        return (string) $this->campaign->id;
    }

    public function __construct(
        protected Campaign $campaign,
        protected bool $useAgents = true,
        protected ?int $strategyId = null
    ) {}

    public function handle(AdSpendBillingService $billingService): void
    {
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

        // Auto-provision Facebook ad account on first Facebook deployment.
        if (! $customer->facebook_ads_account_id) {
            $hasFacebookStrategy = $this->campaign->strategies->contains(
                fn ($s) => str_contains(strtolower($s->platform), 'facebook') || str_contains(strtolower($s->platform), 'meta')
            );
            if ($hasFacebookStrategy) {
                Log::info("DeployCampaign: No Facebook ad account — provisioning for customer {$customer->id}");
                $accountResult = (new CreateFacebookAdsAccount($customer))(
                    accountName: $customer->name.' Ads',
                    currency: $customer->billingCurrency(),
                    timezoneId: 1
                );
                if ($accountResult) {
                    $customer->update(['facebook_ads_account_id' => $accountResult['account_id']]);
                    $customer->refresh();
                    Log::info('DeployCampaign: Facebook ad account provisioned', [
                        'customer_id' => $customer->id,
                        'account_id' => $accountResult['account_id'],
                    ]);
                } else {
                    Log::warning('DeployCampaign: Could not provision Facebook ad account — compliance check may fail', [
                        'customer_id' => $customer->id,
                    ]);
                }
            }
        }

        // Auto-provision Facebook pixel before compliance check.
        // PixelService will find an existing pixel on the ad account or create a
        // new BM-owned one and share it — no manual steps required.
        if ($customer->facebook_ads_account_id && ! $customer->facebook_pixel_id) {
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
        $complianceResult = (new PreLaunchComplianceAgent)->check($this->campaign);
        if (! $complianceResult['passed']) {
            Log::warning("DeployCampaign: Campaign {$this->campaign->id} failed compliance check, aborting deployment.", [
                'failures' => $complianceResult['failures'],
            ]);
            $this->notifyUsers(new \App\Notifications\DeploymentFailed(
                $this->campaign,
                'Pre-launch compliance check failed: '.implode('; ', array_column($complianceResult['failures'], 'message'))
            ));

            return;
        }

        // Ad spend credit applies only to accounts Spectra funds.
        //
        // Where the customer granted us management of an account they already
        // owned, their own card is on it and Google charges them directly.
        // Requiring prepaid ad credit there bills them twice for the same
        // clicks. Those customers are gated on their subscription and their
        // media allowance instead, both checked below.
        $managedBillingEnabled = Setting::get('managed_billing_enabled', true) && ! $customer->isSelfFundedAds();

        if ($customer->isSelfFundedAds()) {
            if (! $customer->hasMediaCreditsRemaining()) {
                Log::warning('DeployCampaign: self-funded customer has no media allowance left', [
                    'customer_id' => $customer->id,
                ]);

                $this->notifyUsers(new \App\Notifications\DeploymentFailed(
                    $this->campaign,
                    'Your media allowance for this period is used up. Upgrade your plan or add a creative boost to publish more ads.'
                ));

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
            } catch (\Exception $e) {
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

        // Split the campaign's daily budget evenly across strategies.
        //
        // Signed-off only: sign-off is the user's approval of what runs and
        // what the budget is split across. Deploying every strategy meant an
        // unreviewed one could go live AND halve the budget of the one the
        // user actually approved.
        $strategies = $this->strategyId
            ? $this->campaign->strategies->where('id', $this->strategyId)
            : $this->campaign->strategies->whereNotNull('signed_off_at');

        // Filter strategies to only platforms the user's plan allows
        $user = $this->campaign->customer->users()->wherePivot('role', 'owner')->first()
            ?? $this->campaign->customer->users()->first();
        if ($user) {
            $allowed = $user->allowedPlatforms();
            [$strategies, $planFiltered] = $strategies->partition(function ($strategy) use ($allowed) {
                $platformStr = strtolower($strategy->platform);
                foreach ($allowed as $allow) {
                    if (str_contains($platformStr, $allow)) {
                        return true;
                    }
                }

                return false;
            });

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
        }

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

        if ($strategies->count() > 0 && $this->campaign->daily_budget) {
            DB::transaction(function () use ($strategies, $customer) {
                // Try to use cross-channel budget allocation for smart splits
                $allocation = \App\Models\PlatformBudgetAllocation::where('customer_id', $customer->id)->first();

                if ($allocation && $allocation->strategy !== 'manual') {
                    $platformMap = [
                        'google' => 'google_ads_pct',
                        'facebook' => 'facebook_ads_pct',
                        'microsoft' => 'microsoft_ads_pct',
                        'linkedin' => 'linkedin_ads_pct',
                    ];

                    // Calculate allocated budget per strategy based on platform percentages
                    $totalPct = 0;
                    $strategyPcts = [];
                    foreach ($strategies as $strategy) {
                        $platform = strtolower($strategy->platform);
                        $pctField = $platformMap[$platform] ?? null;
                        $pct = $pctField ? (float) $allocation->$pctField : 0;
                        $strategyPcts[$strategy->id] = $pct;
                        $totalPct += $pct;
                    }

                    if (abs($totalPct - 100) > 1) {
                        Log::warning("Budget allocation percentages sum to {$totalPct}% (expected ~100%) for campaign {$this->campaign->id} — falling back to even split");
                        $totalPct = 0; // Force even-split fallback below
                    }

                    foreach ($strategies as $strategy) {
                        if (! $strategy->daily_budget) {
                            if ($totalPct > 0) {
                                $share = $strategyPcts[$strategy->id] / $totalPct;
                                $budget = round($this->campaign->daily_budget * $share, 2);
                            } else {
                                $budget = round($this->campaign->daily_budget / $strategies->count(), 2);
                            }
                            if ($budget < 5.00) {
                                Log::warning("Strategy {$strategy->id} ({$strategy->platform}) allocated only \${$budget}/day for campaign {$this->campaign->id}");
                            }
                            $strategy->update(['daily_budget' => max($budget, 1.00)]);
                        }
                    }
                } else {
                    // Fallback: even split
                    $budgetPerStrategy = round($this->campaign->daily_budget / $strategies->count(), 2);
                    foreach ($strategies as $strategy) {
                        if (! $strategy->daily_budget) {
                            if ($budgetPerStrategy < 5.00) {
                                Log::warning("Strategy {$strategy->id} ({$strategy->platform}) allocated only \${$budgetPerStrategy}/day for campaign {$this->campaign->id}");
                            }
                            $strategy->update(['daily_budget' => $budgetPerStrategy]);
                        }
                    }
                }
            });
        }

        $deploymentResults = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($strategies as $strategy) {
            // Skip strategies that are already deployed/verified — prevents duplicate campaigns
            // when the user clicks Deploy more than once or the job retries.
            if (in_array($strategy->deployment_status, ['deployed', 'verified'])) {
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

        if ($successCount > 0 && $this->campaign->customer->service_type === 'setup_only') {
            // One-time setup: "everything arrives paused" is a promise in the
            // receipt email — enforce it mechanically. The customer flips the
            // switch themselves, on their own billing.
            $this->pauseForSetupOnly();
            $this->campaign->update(['status' => CampaignStatus::Paused]);
        } elseif ($successCount > 0 && $this->campaign->status !== CampaignStatus::Active) {
            $this->campaign->update(['status' => CampaignStatus::Active]);
            RecordSiteConversion::dispatch($this->campaign->customer, 'campaign_live');
        }

        // Dispatch verification job after 60s to confirm objects exist on platforms
        if ($successCount > 0) {
            VerifyDeployment::dispatch($this->campaign)->delay(now()->addSeconds(60));
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
    public function failed(\Throwable $exception): void
    {
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

    /**
     * Pause the just-deployed Google campaign for a one-time setup customer.
     * Best-effort: a pause failure must not fail the deployment — it is
     * reported so an admin sees it before the handover.
     */
    protected function pauseForSetupOnly(): void
    {
        $customer = $this->campaign->customer;
        $customerId = preg_replace('/[^0-9]/', '', (string) $customer->google_ads_customer_id);
        $campaignId = preg_replace('/[^0-9]/', '', (string) $this->campaign->google_ads_campaign_id);

        if ($customerId === '' || $campaignId === '') {
            return;
        }

        try {
            $result = $this->campaignStatusService($customer)->execute(
                $customerId,
                "customers/{$customerId}/campaigns/{$campaignId}",
                'PAUSED'
            );

            AgentActivity::record(
                'deployment',
                'setup_only_paused',
                "Paused \"{$this->campaign->name}\" after build — one-time setup customers launch it themselves.",
                $this->campaign->customer_id,
                $this->campaign->id,
                $result
            );
        } catch (\Throwable $e) {
            report($e);
            Log::error('DeployCampaign: could not pause setup-only campaign', [
                'campaign_id' => $this->campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Separated so tests can stub the Google round-trip. */
    protected function campaignStatusService(\App\Models\Customer $customer): \App\Services\GoogleAds\CommonServices\UpdateCampaignStatus
    {
        return new \App\Services\GoogleAds\CommonServices\UpdateCampaignStatus($customer);
    }
}
