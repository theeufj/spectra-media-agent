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

        foreach ($this->campaign->strategies as $strategy) {
            $connection = $user->connections()->where('platform', $strategy->platform)->first();

            if (!$connection) {
                Log::error("No active connection found for user {$user->id} and platform {$strategy->platform}. Skipping deployment.");
                continue;
            }

            // Here, you would add logic to refresh the access token if it has expired.
            // For now, we'll assume it's valid.

            $credentials = [
                'access_token' => $connection->access_token,
                'developer_token' => config('services.google_ads.developer_token'),
                'customer_id' => $connection->account_id,
                'login_customer_id' => config('services.google_ads.login_customer_id'),
            ];

            $deploymentStrategy = DeploymentService::getStrategy($strategy->platform, $credentials);

            if ($deploymentStrategy) {
                $success = $deploymentStrategy->deploy($this->campaign, $strategy, $connection);
                if (!$success) {
                    Log::error("Deployment failed for Strategy ID: {$strategy->id} on platform: {$strategy->platform}");
                    // Optionally, you could add more specific error handling here
                }
            }
        }

        Log::info("Finished deployment job for Campaign ID: {$this->campaign->id}");
    }
}
