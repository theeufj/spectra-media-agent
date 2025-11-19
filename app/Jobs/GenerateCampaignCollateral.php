<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Mail\CollateralGenerated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

            // Generate collateral for each strategy
            foreach ($strategies as $strategy) {
                $this->generateCollateralForStrategy($strategy);
            }

            // Send email notification to the user
            $user = \App\Models\User::find($this->userId);
            if ($user) {
                Mail::to($user->email)->send(new CollateralGenerated($this->campaign, $user));
                Log::info("Collateral generation complete email sent to {$user->email} for Campaign ID: {$this->campaign->id}");
            }

            Log::info("Collateral generation job completed for Campaign ID: {$this->campaign->id}");

        } catch (\Exception $e) {
            Log::error("Error in GenerateCampaignCollateral job for Campaign ID {$this->campaign->id}: " . $e->getMessage());
            $this->fail($e);
        }
    }

    private function generateCollateralForStrategy(Strategy $strategy): void
    {
        Log::info("Generating collateral for Strategy ID: {$strategy->id}, Platform: {$strategy->platform}");

        // Generate 3 images per strategy
        for ($i = 0; $i < 3; $i++) {
            GenerateImage::dispatch($this->campaign, $strategy)
                ->delay(now()->addSeconds($i * 10)); // Stagger by 10 seconds
            $imageNum = $i + 1;
            Log::info("Dispatched image generation {$imageNum}/3 for Strategy ID: {$strategy->id}");
        }

        // Generate 2 videos per strategy (only if video strategy contains actionable content)
        $videoStrategy = $strategy->video_strategy;
        if ($this->hasActionableVideoContent($videoStrategy)) {
            for ($i = 0; $i < 2; $i++) {
                GenerateVideo::dispatch($this->campaign, $strategy, $strategy->platform)
                    ->delay(now()->addSeconds(30 + ($i * 60))); // Stagger videos by 60 seconds, start after 30s
                $videoNum = $i + 1;
                Log::info("Dispatched video generation {$videoNum}/2 for Strategy ID: {$strategy->id}");
            }
        } else {
            Log::info("Skipping video generation for Strategy ID: {$strategy->id} - no actionable video content");
        }
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
}
