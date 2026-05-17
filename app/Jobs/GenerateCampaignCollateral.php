<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Mail\CollateralGenerated;
use App\Jobs\GenerateAdCopy;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 3600; // 1 hour timeout for all collateral generation

    public function __construct(
        protected Campaign $campaign,
        protected int $userId
    ) {
    }

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

            // Dispatch as a batch so the email only sends after ALL jobs complete
            Bus::batch($jobs)
                ->name("Campaign {$campaignId} Collateral")
                ->allowFailures()
                ->then(function () use ($campaignId, $userId) {
                    $campaign = Campaign::find($campaignId);
                    $user = \App\Models\User::find($userId);
                    if ($campaign && $user) {
                        Mail::to($user->email)->send(new CollateralGenerated($campaign, $user));
                        Log::info("Collateral generation complete email sent to {$user->email} for Campaign ID: {$campaignId}");

                        // If user is not yet subscribed, send them the 'Ads Are Ready to Deploy' upsell email
                        if (!$user->subscribed('default') && $user->subscription_status !== 'active') {
                            $totalAssets = $campaign->strategies->sum('ad_copies_count') + 
                                           $campaign->strategies->sum('image_collaterals_count') + 
                                           $campaign->strategies->sum('video_collaterals_count');
                            Mail::to($user->email)->send(new \App\Mail\AdsReadyToDeploy($user, $campaign, $totalAssets ?? 0));
                            Log::info("Sent AdsReadyToDeploy trial upsell email to {$user->email}");
                        }
                    }
                })
                ->catch(function ($batch, $e) use ($campaignId) {
                    Log::error("Some collateral jobs failed for Campaign ID {$campaignId}: " . $e->getMessage());

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

            Log::info("Dispatched " . count($jobs) . " collateral generation jobs as batch for Campaign ID: {$campaignId}");

        } catch (\Exception $e) {
            Log::error("Error in GenerateCampaignCollateral job for Campaign ID {$this->campaign->id}: " . $e->getMessage());
            $this->fail($e);
        }
    }

    private function buildJobsForStrategy(Strategy $strategy): array
    {
        Log::info("Building collateral jobs for Strategy ID: {$strategy->id}, Platform: {$strategy->platform}");

        $jobs = [];

        // Ad copy (one job per strategy)
        $jobs[] = new GenerateAdCopy($this->campaign, $strategy, $strategy->platform);

        // 3 images per strategy
        for ($i = 0; $i < 3; $i++) {
            $jobs[] = new GenerateImage($this->campaign, $strategy);
        }

        // 2 videos per strategy — respect the AI's explicit decision when available,
        // fall back to the heuristic for strategies generated before this field existed.
        $shouldGenerateVideo = $strategy->generate_video ?? $this->hasActionableVideoContent($strategy->video_strategy);
        if ($shouldGenerateVideo) {
            for ($i = 0; $i < 2; $i++) {
                $jobs[] = new GenerateVideo($this->campaign, $strategy, $strategy->platform, $i);
            }
        } else {
            Log::info("Skipping video generation for Strategy ID: {$strategy->id} - generate_video=false");
        }

        return $jobs;
    }

    /**
     * Quick check if video strategy has actionable content.
     * Uses simpler logic than GenerateVideo job for early filtering.
     */
    private function hasActionableVideoContent(string $videoStrategy): bool
    {
        $content = trim($videoStrategy);
        
        if (empty($content)) {
            return false;
        }
        
        // Check if it's purely "N/A" or "Not Applicable"
        if (preg_match('/^(n\/a|not applicable|none)\.?$/i', $content)) {
            return false;
        }
        
        // If content is short and just says "N/A for [reason]" without alternatives
        if (strlen($content) < 100 && 
            stripos($content, 'n/a') !== false && 
            !preg_match('/\b(however|but|if|when|use|create|generate|show|feature|include)\b/i', $content)) {
            return false;
        }
        
        return true;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateCampaignCollateral failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
