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

    public function __construct(protected Campaign $campaign)
    {
    }

    public function handle(): void
    {
        Log::info("Starting deployment job for Campaign ID: {$this->campaign->id}");

        $user = $this->campaign->user;
        $customer = $user->customer;

        if (!$customer) {
            Log::error("No customer found for user {$user->id}. Skipping deployment.");
            return;
        }

        foreach ($this->campaign->strategies as $strategy) {
            $deploymentStrategy = DeploymentService::getStrategy($strategy->platform, $customer);

            if ($deploymentStrategy) {
                $success = $deploymentStrategy->deploy($this->campaign, $strategy);
                if (!$success) {
                    Log::error("Deployment failed for Strategy ID: {$strategy->id} on platform: {$strategy->platform}");
                    // Optionally, you could add more specific error handling here
                }
            }
        }

        Log::info("Finished deployment job for Campaign ID: {$this->campaign->id}");
    }
}
