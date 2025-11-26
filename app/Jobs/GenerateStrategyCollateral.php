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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 1800; // 30 minutes timeout

    public function __construct(
        protected Campaign $campaign,
        protected Strategy $strategy,
        protected int $userId
    ) {
    }

    public function handle(): void
    {
        Log::info("Starting collateral generation for Strategy ID: {$this->strategy->id}, Campaign ID: {$this->campaign->id}");

        try {
            // Verify strategy is signed off
            if (is_null($this->strategy->signed_off_at)) {
                Log::warning("Strategy ID {$this->strategy->id} is not signed off, skipping collateral generation");
                return;
            }

            // Check if collateral already exists for this strategy
            $existingImages = $this->strategy->imageCollaterals()->count();
            $existingAdCopies = $this->strategy->adCopies()->count();
            
            if ($existingImages > 0 || $existingAdCopies > 0) {
                Log::info("Strategy ID {$this->strategy->id} already has collateral (images: {$existingImages}, ad copies: {$existingAdCopies}), skipping");
                return;
            }

            $this->generateCollateral();

            Log::info("Collateral generation dispatched for Strategy ID: {$this->strategy->id}");

        } catch (\Exception $e) {
            Log::error("Error in GenerateStrategyCollateral job for Strategy ID {$this->strategy->id}: " . $e->getMessage());
            $this->fail($e);
        }
    }

    private function generateCollateral(): void
    {
        Log::info("Generating collateral for Strategy ID: {$this->strategy->id}, Platform: {$this->strategy->platform}");

        // Generate ad copy for the strategy
        GenerateAdCopy::dispatch($this->campaign, $this->strategy, $this->strategy->platform)
            ->delay(now()->addSeconds(5));
        Log::info("Dispatched ad copy generation for Strategy ID: {$this->strategy->id}");

        // Generate 3 images per strategy
        for ($i = 0; $i < 3; $i++) {
            GenerateImage::dispatch($this->campaign, $this->strategy)
                ->delay(now()->addSeconds(10 + ($i * 10))); // Stagger by 10 seconds
            $imageNum = $i + 1;
            Log::info("Dispatched image generation {$imageNum}/3 for Strategy ID: {$this->strategy->id}");
        }

        // Generate 2 videos per strategy (only if video strategy contains actionable content)
        $videoStrategy = $this->strategy->video_strategy ?? '';
        if ($this->hasActionableVideoContent($videoStrategy)) {
            for ($i = 0; $i < 2; $i++) {
                GenerateVideo::dispatch($this->campaign, $this->strategy, $this->strategy->platform)
                    ->delay(now()->addSeconds(40 + ($i * 60))); // Stagger videos by 60 seconds
                $videoNum = $i + 1;
                Log::info("Dispatched video generation {$videoNum}/2 for Strategy ID: {$this->strategy->id}");
            }
        } else {
            Log::info("Skipping video generation for Strategy ID: {$this->strategy->id} - no actionable video content");
        }
    }

    /**
     * Quick check if video strategy has actionable content.
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
