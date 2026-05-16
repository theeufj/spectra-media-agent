<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\ImageCollateral;
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

        // Generate 3 images per strategy (respecting free-tier limit)
        for ($i = 0; $i < 3; $i++) {
            if (!ImageCollateral::canGenerateForCampaign($this->campaign)) {
                Log::info("Image limit reached for Campaign ID: {$this->campaign->id}, skipping remaining image generation");
                break;
            }
            GenerateImage::dispatch($this->campaign, $this->strategy)
                ->delay(now()->addSeconds(10 + ($i * 10))); // Stagger by 10 seconds
            $imageNum = $i + 1;
            Log::info("Dispatched image generation {$imageNum}/3 for Strategy ID: {$this->strategy->id}");
        }

        // Generate 2 videos per strategy (only if video strategy contains actionable content)
        $videoStrategy = $this->strategy->video_strategy ?? '';
        if ($this->hasActionableVideoContent($videoStrategy)) {
            // Spread video jobs across strategies using strategy ID as an offset.
            // Veo has per-minute quota; staggering prevents concurrent requests from multiple strategies.
            $strategySpread = ($this->strategy->id % 8) * 45; // 0–315 s spread across up to 8 strategies
            for ($i = 0; $i < 2; $i++) {
                $delay = 120 + $strategySpread + ($i * 240); // 2 min base + strategy spread + 4 min between videos
                GenerateVideo::dispatch($this->campaign, $this->strategy, $this->strategy->platform)
                    ->delay(now()->addSeconds($delay));
                $videoNum = $i + 1;
                Log::info("Dispatched video generation {$videoNum}/2 for Strategy ID: {$this->strategy->id}, delay: {$delay}s");
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

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateStrategyCollateral failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
