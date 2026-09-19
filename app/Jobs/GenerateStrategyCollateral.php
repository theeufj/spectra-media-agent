<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Strategy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to generate collateral (ad copy, images, videos) for a single strategy.
 * This is dispatched when a user signs off on an individual strategy.
 */
class GenerateStrategyCollateral implements ShouldQueue
{
    /**
     * A soft-deleted customer/campaign mid-queue means the work is moot —
     * discard quietly instead of filling failed_jobs with ModelNotFound.
     */
    public $deleteWhenMissingModels = true;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 1800; // 30 minutes timeout

    /**
     * Every concept is produced twice — 16:9 for Google and YouTube, 9:16 for
     * Meta — so a concept costs two of the plan's video allowance.
     */
    public function __construct(
        protected Campaign $campaign,
        protected Strategy $strategy,
        protected int $userId
    ) {}

    public function handle(): void
    {
        Log::info("Starting collateral generation for Strategy ID: {$this->strategy->id}, Campaign ID: {$this->campaign->id}");

        try {
            // Verify strategy is signed off
            if (is_null($this->strategy->signed_off_at)) {
                Log::warning("Strategy ID {$this->strategy->id} is not signed off, skipping collateral generation");

                return;
            }

            // Guard against double-dispatch: skip only if collateral was generated very recently
            // (within 5 minutes). Stale collateral from a previous run should not block fresh generation.
            $existingImages = $this->strategy->imageCollaterals()->count();
            $existingAdCopies = $this->strategy->adCopies()->count();
            $recentCutoff = now()->subMinutes(5);
            $recentlyGenerated = ($existingImages > 0 || $existingAdCopies > 0)
                && $this->strategy->updated_at >= $recentCutoff;

            if ($recentlyGenerated) {
                Log::info("Strategy ID {$this->strategy->id} collateral was just generated, skipping duplicate dispatch");

                return;
            }

            $this->generateCollateral();

            Log::info("Collateral generation dispatched for Strategy ID: {$this->strategy->id}");

        } catch (\Throwable $e) {
            Log::error("Error in GenerateStrategyCollateral job for Strategy ID {$this->strategy->id}: ".$e->getMessage());
            $this->fail($e);
        }
    }

    private function generateCollateral(): void
    {
        foreach (app(\App\Services\Campaigns\CollateralPlan::class)->forStrategy($this->campaign, $this->strategy) as $job) {
            dispatch($job);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateStrategyCollateral failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
