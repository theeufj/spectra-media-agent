<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\DeploymentService;
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

    public function handle(): void
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
