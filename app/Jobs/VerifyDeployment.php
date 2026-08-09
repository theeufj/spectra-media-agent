<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Deployment\DeploymentVerifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs after deployment to verify that platform objects actually exist,
 * then transitions deployment_status from 'deployed' → 'verified'.
 *
 * Dispatched by DeployCampaign after a successful deployment.
 * Delayed by 60 seconds to allow platform propagation.
 */
class VerifyDeployment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [60, 120, 300]; // retry after 1m, 2m, 5m

    private DeploymentVerifier $verifier;

    public function __construct(
        protected Campaign $campaign
    ) {}

    public function handle(DeploymentVerifier $verifier): void
    {
        $this->verifier = $verifier;

        $strategies = $this->campaign->strategies()
            ->where('deployment_status', 'deployed')
            ->get();

        if ($strategies->isEmpty()) {
            Log::info("VerifyDeployment: No deployed strategies to verify for campaign {$this->campaign->id}");

            return;
        }

        $customer = $this->campaign->customer;

        foreach ($strategies as $strategy) {
            try {
                $verified = $this->verifier->verify($strategy, $customer);

                $strategy->update([
                    'deployment_status' => $verified ? 'verified' : 'deploy_unverified',
                ]);

                Log::info("VerifyDeployment: Strategy {$strategy->id} ({$strategy->platform}): ".($verified ? 'verified' : 'unverified'));
            } catch (\Exception $e) {
                // Surface in the admin exception dashboard; the batch continues.
                report($e);
                Log::error("VerifyDeployment: Failed to verify strategy {$strategy->id}: ".$e->getMessage());
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('VerifyDeployment failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
