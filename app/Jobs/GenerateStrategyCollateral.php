<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Models\VideoCollateral;
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
    private const SHAPES_PER_CONCEPT = 2;

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
        Log::info("Generating collateral for Strategy ID: {$this->strategy->id}, Platform: {$this->strategy->platform}");

        // Generate ad copy for the strategy
        GenerateAdCopy::dispatch($this->campaign, $this->strategy, $this->strategy->platform)
            ->delay(now()->addSeconds(5));
        Log::info("Dispatched ad copy generation for Strategy ID: {$this->strategy->id}");

        // Generate 3 images per strategy (respecting free-tier limit)
        for ($i = 0; $i < 3; $i++) {
            if (! ImageCollateral::canGenerateForCampaign($this->campaign)) {
                Log::info("Image limit reached for Campaign ID: {$this->campaign->id}, skipping remaining image generation");
                break;
            }
            // $i is the slot, and the slot is what picks the lens. Without it
            // every one of these three jobs generates the scene as briefed and
            // the set is one picture three times — see CreativeVariant.
            GenerateImage::dispatch($this->campaign, $this->strategy, $i)
                ->delay(now()->addSeconds(10 + ($i * 10))); // Stagger by 10 seconds
            $imageNum = $i + 1;
            Log::info("Dispatched image generation {$imageNum}/3 for Strategy ID: {$this->strategy->id}");
        }

        // Campaign-level videos: generate exactly 2 per campaign — landscape (16:9) for Google,
        // portrait (9:16) for Meta — then share both across all strategies at deployment time.
        // Only the first signed-off strategy triggers generation to avoid duplicates.
        $videoStrategy = $this->strategy->video_strategy ?? '';
        if ($this->hasActionableVideoContent($videoStrategy)) {
            $firstStrategyId = $this->campaign->strategies()
                ->whereNotNull('signed_off_at')
                ->orderBy('id')
                ->value('id');

            if ($firstStrategyId === $this->strategy->id) {
                $strategySpread = ($this->strategy->id % 8) * 45;

                /*
                   Concepts, each produced in both shapes.

                   Two videos used to go out and both omitted the variation
                   index, so both took Variation A — the problem-led script —
                   and the same visual. The B variation has existed the whole
                   time and was never once asked for. Same shape of bug as the
                   images: the machinery was there, the caller never said which
                   of the set each job was.

                   Landscape and portrait share a concept deliberately. They are
                   one ad in two shapes, for Google and for Meta; only the
                   concept index varies between concepts.
                */
                $wanted = max(1, (int) config('ai.video_concepts_per_campaign', 2));
                $allowance = VideoCollateral::remainingForCampaign($this->campaign);
                $concepts = min($wanted, intdiv($allowance, self::SHAPES_PER_CONCEPT));

                if ($concepts < 1) {
                    Log::info('Skipping video generation — plan allowance leaves no room', [
                        'campaign_id' => $this->campaign->id,
                        'remaining_videos' => $allowance,
                    ]);

                    return;
                }

                if ($concepts < $wanted) {
                    // Said out loud rather than silently trimmed: a set that is
                    // smaller than configured is a fact about the plan, and the
                    // log is where that gets answered.
                    Log::info('Video concepts trimmed to the plan allowance', [
                        'campaign_id' => $this->campaign->id,
                        'wanted' => $wanted,
                        'generating' => $concepts,
                        'remaining_videos' => $allowance,
                    ]);
                }

                for ($concept = 0; $concept < $concepts; $concept++) {
                    $offset = $concept * 480;

                    // Landscape (16:9) — Google Ads / YouTube
                    GenerateVideo::dispatch($this->campaign, $this->strategy, 'Google Ads (Performance Max)', $concept)
                        ->delay(now()->addSeconds(120 + $strategySpread + $offset));
                    // Portrait (9:16) — Meta / Facebook
                    GenerateVideo::dispatch($this->campaign, $this->strategy, 'Facebook Ads', $concept)
                        ->delay(now()->addSeconds(120 + $strategySpread + $offset + 240));
                }

                Log::info('Dispatched campaign videos', [
                    'campaign_id' => $this->campaign->id,
                    'strategy_id' => $this->strategy->id,
                    'concepts' => $concepts,
                    'videos' => $concepts * self::SHAPES_PER_CONCEPT,
                ]);
            } else {
                Log::info("Skipping video generation for Strategy ID: {$this->strategy->id} — campaign videos will be generated by Strategy ID: {$firstStrategyId}");
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
            ! preg_match('/\b(however|but|if|when|use|create|generate|show|feature|include)\b/i', $content)) {
            return false;
        }

        return true;
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
