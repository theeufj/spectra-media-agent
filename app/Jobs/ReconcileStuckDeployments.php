<?php

namespace App\Jobs;

use App\Models\Strategy;
use App\Services\Deployment\DeploymentVerifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Rescues strategies stranded in 'deploying'.
 *
 * DeploymentService marks a strategy 'deploying' before handing off to the
 * execution agent, then writes 'deployed' or 'failed' once the agent returns.
 * If the agent never returns — worker timeout, OOM, a deploy restarting Horizon
 * mid-run — no catch block ever executes and the row keeps 'deploying' forever.
 * Nothing else reconciles it: VerifyDeployment only considers 'deployed'.
 *
 * Two strategies sat in that state for weeks (one since May), so the campaign
 * looked permanently mid-deploy in the UI while its objects were already live on
 * the platform.
 *
 * Truth comes from the platform, not from our own record of intent: if the
 * objects exist the deployment did in fact succeed, whatever our row says.
 */
class ReconcileStuckDeployments implements ShouldQueue
{
    use \App\Jobs\Concerns\RecordsAgentRun, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 600;

    /**
     * How long a deployment may legitimately stay in flight. Deployments take
     * minutes, not hours — anything past this had its worker die under it.
     */
    private const STUCK_AFTER_MINUTES = 30;

    public function handle(DeploymentVerifier $verifier): void
    {
        $runStart = $this->startRun();

        $stuck = Strategy::with('campaign.customer')
            ->where('deployment_status', 'deploying')
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->get();

        $resolved = 0;
        $errors = 0;

        foreach ($stuck as $strategy) {
            try {
                $customer = $strategy->campaign?->customer;

                // Microsoft and LinkedIn have no verification path, so a false
                // from verify() means "unknown", not "absent". Calling those
                // failed would assert something we never checked.
                if (! $verifier->supports($strategy->platform)) {
                    $strategy->update([
                        'deployment_status' => 'deploy_unverified',
                        'deployment_error' => 'Deployment did not complete and '.$strategy->platform.' has no verification path, so whether it reached the platform is unknown. Check the platform directly before redeploying.',
                    ]);
                    Log::warning("ReconcileStuckDeployments: strategy {$strategy->id} ({$strategy->platform}) cannot be verified — marked deploy_unverified");
                    $resolved++;

                    continue;
                }

                $exists = $verifier->verify($strategy, $customer);

                if ($exists) {
                    // Objects are live. Hand to VerifyDeployment's normal path by
                    // marking 'deployed'; it promotes to 'verified' on its next pass.
                    $strategy->update([
                        'deployment_status' => 'deployed',
                        'deployed_at' => $strategy->deployed_at ?? $strategy->updated_at,
                        'deployment_error' => null,
                    ]);
                    Log::info("ReconcileStuckDeployments: strategy {$strategy->id} ({$strategy->platform}) verified live — marked deployed");
                } else {
                    // Not proven live. Mark failed so it stops looking in-flight and
                    // becomes eligible for redeployment, which 'deploying' blocks.
                    $strategy->update([
                        'deployment_status' => 'failed',
                        'deployment_error' => 'Deployment did not complete — worker stopped before the agent returned, and no platform objects could be verified.',
                    ]);
                    Log::warning("ReconcileStuckDeployments: strategy {$strategy->id} ({$strategy->platform}) unverifiable — marked failed");

                    // The user was told "deployment initiated" up to an hour
                    // ago and has heard nothing since. Silently flipping the
                    // strategy to failed left them to discover it themselves.
                    $stuckCampaign = $strategy->campaign;
                    if ($customer && $stuckCampaign instanceof \App\Models\Campaign) {
                        foreach ($customer->users as $user) {
                            $user->notify(new \App\Notifications\DeploymentFailed(
                                $stuckCampaign,
                                "Your {$strategy->platform} deployment didn't complete. You can redeploy it from the campaign's collateral page — nothing was charged for ads that never ran."
                            ));
                        }
                    }
                }

                $resolved++;
            } catch (\Throwable $e) {
                report($e);
                $errors++;
                Log::error("ReconcileStuckDeployments: strategy {$strategy->id}: ".$e->getMessage());
            }
        }

        $this->finishRun($runStart, actions: $resolved, errors: $errors, scope: $stuck->count().' stuck strategies');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ReconcileStuckDeployments failed: '.$exception->getMessage());
        $this->recordRunFailure($exception);
    }
}
