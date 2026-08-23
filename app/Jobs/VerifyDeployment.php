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

        $unverified = [];

        foreach ($strategies as $strategy) {
            try {
                // "Can't verify this platform" is not "unverified".
                // ReconcileStuckDeployments checks supports() for exactly this
                // reason; skipping it here downgraded every successful
                // Microsoft/LinkedIn deploy to ⚠️ deploy_unverified 60 seconds
                // after it went live.
                if (! $this->verifier->supports($strategy->platform)) {
                    Log::info("VerifyDeployment: Strategy {$strategy->id} ({$strategy->platform}) has no verification path — leaving as deployed");

                    continue;
                }

                $verified = $this->verifier->verify($strategy, $customer);

                $strategy->update([
                    'deployment_status' => $verified ? 'verified' : 'deploy_unverified',
                ]);

                if (! $verified) {
                    $unverified[] = $strategy->platform;
                }

                Log::info("VerifyDeployment: Strategy {$strategy->id} ({$strategy->platform}): ".($verified ? 'verified' : 'unverified'));
            } catch (\Exception $e) {
                // Surface in the admin exception dashboard; the batch continues.
                report($e);
                Log::error("VerifyDeployment: Failed to verify strategy {$strategy->id}: ".$e->getMessage());
            }
        }

        // A supported platform whose objects can't be found is a real problem
        // the user was told nothing about — the deploy had already reported
        // success by the time this ran.
        if (! empty($unverified) && $customer) {
            foreach ($customer->users as $user) {
                $user->notify(new \App\Notifications\DeploymentFailed(
                    $this->campaign,
                    'We deployed your ads to '.implode(', ', $unverified).' but could not confirm they exist on the platform. Our team has been alerted and is checking — no action needed from you yet.'
                ));
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
