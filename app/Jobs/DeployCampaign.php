<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Notifications\DeploymentCompleted;
use App\Notifications\DeploymentFailed;
use App\Services\DeploymentService;
use App\Services\AdSpendBillingService;
use App\Jobs\VerifyDeployment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeployCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 1200;

    public function __construct(
        protected Campaign $campaign,
        protected bool $useAgents = true
    ) {
    }

    public function handle(AdSpendBillingService $billingService): void
    {
        Log::info("Starting deployment job for Campaign ID: {$this->campaign->id}", [
            'campaign_name' => $this->campaign->name,
            'total_budget' => $this->campaign->total_budget,
            'use_agents' => $this->useAgents
        ]);

        $customer = $this->campaign->customer;

        if (!$customer) {
            Log::error("No customer found for campaign {$this->campaign->id}. Skipping deployment.");
            return;
        }

        // Initialize ad spend credit if managed billing is enabled
        $managedBillingEnabled = Setting::get('managed_billing_enabled', true);
        
        if ($managedBillingEnabled) {
            try {
                $credit = $customer->adSpendCredit;
                
                if (!$credit) {
                    Log::info("Initializing ad spend credit for customer", [
                        'customer_id' => $customer->id,
                        'daily_budget' => $this->campaign->daily_budget,
                    ]);
                    
                    $credit = $billingService->initializeCreditAccount(
                        $customer, 
                        $this->campaign->daily_budget ?? 50 // Default $50/day if not set
                    );
                    
                    Log::info("Ad spend credit initialized", [
                        'customer_id' => $customer->id,
                        'initial_credit' => $credit->initial_credit_amount,
                    ]);
                }

                // Check if customer can run campaigns (payment status OK)
                if (!$credit->canRunCampaigns()) {
                    Log::warning("Customer cannot run campaigns - payment issue", [
                        'customer_id' => $customer->id,
                        'status' => $credit->status,
                        'payment_status' => $credit->payment_status,
                    ]);
                    
                    // Fail the job with a message
                    throw new \Exception("Cannot deploy campaign: Payment issue. Status: {$credit->payment_status}");
                }
            } catch (\Exception $e) {
                Log::error("Ad spend credit initialization failed", [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
                throw $e; // Re-throw to fail the job
            }
        } else {
            Log::info("Managed billing is disabled - skipping ad spend credit initialization", [
                'customer_id' => $customer->id,
            ]);
        }

        // Split the campaign's daily budget evenly across strategies
        $strategies = $this->campaign->strategies;

        // Filter strategies to only platforms the user's plan allows
        $user = $this->campaign->customer->users()->first();
        if ($user) {
            $allowed = $user->allowedPlatforms();
            $strategies = $strategies->filter(function ($strategy) use ($allowed) {
                return in_array(strtolower($strategy->platform), $allowed, true);
            });
            Log::info("Plan-filtered strategies for deployment: " . $strategies->pluck('platform')->implode(', '));
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

                    foreach ($strategies as $strategy) {
                        if (!$strategy->daily_budget) {
                            if ($totalPct > 0) {
                                $share = $strategyPcts[$strategy->id] / $totalPct;
                                $budget = round($this->campaign->daily_budget * $share, 2);
                            } else {
                                $budget = round($this->campaign->daily_budget / $strategies->count(), 2);
                            }
                            $strategy->update(['daily_budget' => max($budget, 1.00)]);
                        }
                    }
                } else {
                    // Fallback: even split
                    $budgetPerStrategy = round($this->campaign->daily_budget / $strategies->count(), 2);
                    foreach ($strategies as $strategy) {
                        if (!$strategy->daily_budget) {
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
            Log::info("Deploying strategy for platform: {$strategy->platform}", [
                'campaign_id' => $this->campaign->id,
                'strategy_id' => $strategy->id,
                'platform' => $strategy->platform
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
                Log::info("Successfully deployed strategy", [
                    'campaign_id' => $this->campaign->id,
                    'strategy_id' => $strategy->id,
                    'platform' => $strategy->platform
                ]);
            } else {
                $failureCount++;
                Log::error("Failed to deploy strategy", [
                    'campaign_id' => $this->campaign->id,
                    'strategy_id' => $strategy->id,
                    'platform' => $strategy->platform,
                    'error' => $result['error'] ?? 'Unknown error'
                ]);
            }
        }

        Log::info("Finished deployment job for Campaign ID: {$this->campaign->id}", [
            'total_strategies' => count($this->campaign->strategies),
            'successful' => $successCount,
            'failed' => $failureCount,
            'results' => $deploymentResults
        ]);

        // Optionally: Update campaign status or send notifications based on results
        if ($failureCount > 0 && $successCount === 0) {
            Log::critical("All deployments failed for Campaign ID: {$this->campaign->id}");
            $this->notifyUsers(new DeploymentFailed($this->campaign, 'All platform deployments failed.'));
        } elseif ($failureCount > 0) {
            Log::warning("Partial deployment failure for Campaign ID: {$this->campaign->id}");
            $this->notifyUsers(new DeploymentCompleted($this->campaign, $successCount, $failureCount));
        } else {
            Log::info("All deployments successful for Campaign ID: {$this->campaign->id}");
            $this->notifyUsers(new DeploymentCompleted($this->campaign, $successCount, $failureCount));
        }

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
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('DeployCampaign failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
