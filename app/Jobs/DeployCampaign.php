<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\DeploymentService;
use App\Services\AdSpendBillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

        // Initialize ad spend credit if this is the customer's first campaign
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

        $deploymentResults = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($this->campaign->strategies as $strategy) {
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
            // TODO: Send notification to user about complete failure
        } elseif ($failureCount > 0) {
            Log::warning("Partial deployment failure for Campaign ID: {$this->campaign->id}");
            // TODO: Send notification to user about partial failure
        } else {
            Log::info("All deployments successful for Campaign ID: {$this->campaign->id}");
            // TODO: Send success notification to user
        }
    }
}
