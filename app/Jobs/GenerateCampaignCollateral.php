<?php

namespace App\Jobs;

use App\Mail\CollateralGenerated;
use App\Models\Campaign;
use App\Models\Strategy;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GenerateCampaignCollateral implements ShouldQueue
{
    /**
     * A soft-deleted customer/campaign mid-queue means the work is moot —
     * discard quietly instead of filling failed_jobs with ModelNotFound.
     */
    public $deleteWhenMissingModels = true;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 3600; // 1 hour timeout for all collateral generation

    public function __construct(
        protected Campaign $campaign,
        protected int $userId
    ) {}

    public function handle(): void
    {
        Log::info("Starting collateral generation for Campaign ID: {$this->campaign->id}");

        try {
            $strategies = $this->campaign->strategies()->whereNotNull('signed_off_at')->get();

            if ($strategies->isEmpty()) {
                Log::warning("No signed-off strategies found for Campaign ID: {$this->campaign->id}");

                return;
            }

            // Collect all generation jobs
            $jobs = [];
            foreach ($strategies as $strategy) {
                $jobs = array_merge($jobs, $this->buildJobsForStrategy($strategy));
            }

            if (empty($jobs)) {
                Log::warning("No collateral jobs to dispatch for Campaign ID: {$this->campaign->id}");

                return;
            }

            $campaignId = $this->campaign->id;
            $userId = $this->userId;

            // Dispatch as a batch so the email only sends after every job has run.
            //
            // finally(), not then(): then() fires only when pending_jobs reaches
            // zero, and DatabaseBatchRepository::incrementFailedJobs() writes
            // pending_jobs back unchanged — so one permanently failed member pins
            // it above zero forever. The members do fail permanently: GenerateAdCopy,
            // GenerateImage and GenerateVideo all end handle() with fail(), which
            // bypasses $tries. A single Gemini/Veo error therefore meant the
            // customer never got CollateralGenerated and an unsubscribed trial user
            // never got the AdsReadyToDeploy upsell, though the other eleven assets
            // generated fine. finally() is the callback an allowFailures() batch
            // actually reaches — it fires once pending_jobs - failed_jobs === 0.
            Bus::batch($jobs)
                ->name("Campaign {$campaignId} Collateral")
                ->allowFailures()
                ->finally(function (Batch $batch) use ($campaignId, $userId) {
                    if ($batch->failedJobs >= $batch->totalJobs) {
                        // Nothing generated at all. catch() has already stamped the
                        // error onto the strategies for the polling endpoint, and
                        // telling the customer their ads are ready would be a lie.
                        Log::error("All {$batch->totalJobs} collateral jobs failed for Campaign ID: {$campaignId} — no completion email sent");

                        return;
                    }

                    if ($batch->hasFailures()) {
                        Log::warning("Collateral generation for Campaign ID {$campaignId} finished with {$batch->failedJobs} of {$batch->totalJobs} jobs failed — emailing what did generate");
                    }

                    // ad_copies_count & friends are withCount() virtuals — a
                    // plain find() leaves them null and the upsell email told
                    // every trial user we'd generated "0 assets" for them.
                    $campaign = Campaign::with(['strategies' => function ($query) {
                        $query->withCount(['adCopies', 'imageCollaterals', 'videoCollaterals']);
                    }])->find($campaignId);
                    $user = \App\Models\User::find($userId);
                    if ($campaign && $user) {
                        Mail::to($user->email)->send(new CollateralGenerated($campaign, $user));
                        Log::info("Collateral generation complete email sent to {$user->email} for Campaign ID: {$campaignId}");

                        // If user is not yet subscribed, send them the 'Ads Are Ready to Deploy' upsell email
                        if (! $user->subscribed('default') && $user->subscription_status !== 'active') {
                            $totalAssets = $campaign->strategies->sum('ad_copies_count') +
                                           $campaign->strategies->sum('image_collaterals_count') +
                                           $campaign->strategies->sum('video_collaterals_count');
                            Mail::to($user->email)->send(new \App\Mail\AdsReadyToDeploy($user, $campaign, $totalAssets));
                            Log::info("Sent AdsReadyToDeploy trial upsell email to {$user->email}");
                        }
                    }
                })
                ->catch(function ($batch, $e) use ($campaignId) {
                    Log::error("Some collateral jobs failed for Campaign ID {$campaignId}: ".$e->getMessage());

                    // Stamp the error onto any strategy for this campaign that has no collateral yet,
                    // so the polling endpoint can surface it to the UI.
                    \App\Models\Strategy::where('campaign_id', $campaignId)
                        ->whereNotNull('signed_off_at')
                        ->each(function ($strategy) use ($e) {
                            $errors = $strategy->collateral_errors ?? [];
                            $errors[] = ['message' => $e->getMessage(), 'failed_at' => now()->toIso8601String()];
                            $strategy->update(['collateral_errors' => $errors]);
                        });
                })
                ->dispatch();

            Log::info('Dispatched '.count($jobs)." collateral generation jobs as batch for Campaign ID: {$campaignId}");

        } catch (\Throwable $e) {
            Log::error("Error in GenerateCampaignCollateral job for Campaign ID {$this->campaign->id}: ".$e->getMessage());
            $this->fail($e);
        }
    }

    private function buildJobsForStrategy(Strategy $strategy): array
    {
        return app(\App\Services\Campaigns\CollateralPlan::class)->forStrategy($this->campaign, $strategy);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateCampaignCollateral failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
