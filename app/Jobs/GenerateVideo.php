<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\VideoGeneration\VideoGenerationService;
use App\Services\GeminiService;
use App\Prompts\VideoScriptPrompt;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Prompts\VideoFromScriptPrompt;

class GenerateVideo implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 900;

    public function __construct(
        protected Campaign $campaign,
        protected Strategy $strategy,
        protected string $platform,
        protected int $variationIndex = 0,
        protected bool $force = false
    ) {
    }

    /**
     * Extract actionable video content from strategy text.
     * Handles cases where strategy mentions "N/A" but provides alternative scenarios.
     * 
     * @param string $videoStrategy
     * @return string|null Returns actionable content or null if no video should be generated
     */
    private function extractActionableVideoContent(string $videoStrategy): ?string
    {
        // Trim whitespace
        $content = trim($videoStrategy);
        
        // If empty, return null
        if (empty($content)) {
            return null;
        }
        
        // Convert to lowercase for case-insensitive checking
        $lowerContent = strtolower($content);

        // Verbs indicating the strategy actually describes a video to produce. Used to
        // decide whether an "N/A" strategy still carries an actionable instruction.
        $actionVerbs = '/\b(use|create|generate|produce|show|showcase|feature|include|display|depict|film|shoot|capture|animate|scene|video should|footage|b-?roll)\b/i';

        // Check if it's purely "N/A" or "Not Applicable" with no additional content
        if (preg_match('/^(n\/a|not applicable|none)\.?$/i', $content)) {
            return null;
        }
        
        // If content starts with "N/A" but contains conditional statements (however, if, when, for, etc.)
        // extract everything after the conditional indicator
        if (preg_match('/^n\/a[^.]*\.\s*(however|but|if|when|for|in cases where|alternatively)/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
            // Slice from the exact matched position — not stripos(), which could land
            // on an earlier substring (e.g. "if" inside "specific").
            $actionableContent = trim(substr($content, $matches[1][1]));
            Log::info("Extracted conditional video content after N/A: {$actionableContent}");
            return $actionableContent;
        }
        
        // If the content is flagged N/A and a genuine actionable instruction follows a
        // transition word, extract that part. Transition words are matched with WORD
        // BOUNDARIES so "if" inside "specific", "for" inside "platform", etc. never
        // false-match — that bug turned "...for this specific setup." into "ific setup.".
        if (stripos($lowerContent, 'n/a') !== false && preg_match($actionVerbs, $content)) {
            $transitions = ['however', 'but', 'if', 'when', 'for', 'in cases where', 'alternatively'];
            foreach ($transitions as $transition) {
                if (preg_match('/\b' . preg_quote($transition, '/') . '\b/i', $content, $m, PREG_OFFSET_CAPTURE)) {
                    $actionableContent = trim(substr($content, $m[0][1]));
                    if (preg_match($actionVerbs, $actionableContent)) {
                        Log::info("Extracted actionable content after '{$transition}': {$actionableContent}");
                        return $actionableContent;
                    }
                }
            }
        }
        
        // "N/A" is mentioned: only generate a video if the text actually instructs one.
        // Otherwise it's just an explanation of why video is N/A (e.g. "video formats
        // inapplicable for this specific setup") and no video should be produced.
        if (stripos($lowerContent, 'n/a') !== false) {
            if (!preg_match($actionVerbs, $content)) {
                Log::info("Video strategy is N/A without an actionable instruction — skipping video: {$content}");
                return null;
            }
            return $content;
        }
        
        // If content doesn't start with "N/A", use the full content
        if (!preg_match('/^n\/a/i', $content)) {
            return $content;
        }
        
        // If we get here, content is likely just "N/A for [reason]" with no alternatives
        if (strlen($content) < 100 && !preg_match('/\b(use|create|generate|show|feature|include|video should)\b/i', $content)) {
            Log::info("Video strategy appears to be N/A without actionable alternatives: {$content}");
            return null;
        }
        
        // Default: use the full content if we're unsure
        return $content;
    }

    public function handle(VideoGenerationService $videoGenerationService, GeminiService $geminiService): void
    {
        Log::info("Starting video generation job for Campaign ID: {$this->campaign->id}, Strategy ID: {$this->strategy->id}");

        // Idempotency: if a non-failed collateral already exists for this strategy+platform
        // (e.g. from a previous attempt that survived a retry), skip creation.
        $alreadyStarted = VideoCollateral::where('campaign_id', $this->campaign->id)
            ->where('strategy_id', $this->strategy->id)
            ->where('platform', $this->platform)
            ->whereIn('status', ['pending', 'generating', 'completed'])
            ->exists();

        if ($alreadyStarted) {
            Log::info("GenerateVideo: idempotency guard — collateral already exists, skipping", [
                'campaign_id' => $this->campaign->id,
                'strategy_id' => $this->strategy->id,
                'platform'    => $this->platform,
            ]);
            return;
        }

        $videoCollateral = null;
        try {
            // Fetch brand guidelines if available
            $brandGuidelines = $this->campaign->customer->brandGuideline ?? null;
            if (!$brandGuidelines) {
                Log::warning("No brand guidelines found for customer ID: {$this->campaign->customer_id}");
            }

            // Fetch selected product pages
            $productContext = [];
            $selectedPages = $this->campaign->pages; // Assuming relationship is defined
            if ($selectedPages->isNotEmpty()) {
                $productContext = $selectedPages->map(function ($page) {
                    return [
                        'title' => $page->title,
                        'description' => $page->meta_description,
                        'features' => $page->metadata['features'] ?? [],
                    ];
                })->toArray();
            }

            $videoStrategy = $this->strategy->video_strategy;
            
            // Extract actionable content from strategy
            $actionableContent = $this->extractActionableVideoContent($videoStrategy);

            if (!$actionableContent) {
                if (!$this->force) {
                    Log::info("Skipping video generation for Strategy ID: {$this->strategy->id} - no actionable video content found. Original strategy: '{$videoStrategy}'");
                    return;
                }
                // Forced (e.g. PMax ad-strength healing): PMax needs a video asset even when
                // the written strategy said video was N/A. Fall back to a generic brief.
                $brand = $this->campaign->customer->name ?? 'the brand';
                $focus = $this->campaign->product_focus ?: 'the product/service';
                $actionableContent = "Short, modern product-showcase video for {$brand}. Highlight {$focus} "
                    . "with clean, high-quality visuals and upbeat pacing. No on-screen text overlays. "
                    . "Suitable as a Performance Max video asset.";
                Log::info("GenerateVideo: forced generation with fallback brief for Strategy ID: {$this->strategy->id}");
            }

            // Step 1: Generate Video Script using Gemini
            Log::info("Generating video script for Strategy ID: {$this->strategy->id}, variation: {$this->variationIndex}");
            $scriptPrompt = (new VideoScriptPrompt($actionableContent, $brandGuidelines, $productContext, $this->variationIndex))->getPrompt();
            $scriptResponse = $geminiService->generateContent(config('ai.models.default'), $scriptPrompt);
            
            $script = $scriptResponse['text'] ?? null;
            if (empty($script)) {
                throw new \Exception("Failed to generate video script from Gemini.");
            }
            
            Log::info("Generated video script: {$script}");

            // Step 2: Create a placeholder VideoCollateral record
            $videoCollateral = VideoCollateral::create([
                'campaign_id' => $this->campaign->id,
                'strategy_id' => $this->strategy->id,
                'platform' => $this->platform,
                'script' => $script,
                'status' => 'pending',
                'is_active' => true,
            ]);

            // Step 3: Generate the final video prompt using the dedicated prompt class with actionable content
            // Note: VideoFromScriptPrompt might need update if we want to pass product context there too, 
            // but usually the script is enough.
            $videoPrompt = (new VideoFromScriptPrompt($actionableContent, $script))->getPrompt();
            Log::info("Combined video prompt: {$videoPrompt}");

            // Step 4: Start the video generation and get the operation name + provider
            // Meta/Facebook ads are consumed on mobile in portrait (Stories/Reels), so use 9:16.
            // All other platforms default to 16:9 landscape.
            $isMobilePlatform = in_array(strtolower($this->platform), ['facebook', 'meta', 'instagram', 'facebook ads']);
            $videoParams = $isMobilePlatform ? ['aspectRatio' => '9:16'] : [];
            $result = $videoGenerationService->startGeneration($videoPrompt, $videoParams, null, $script);

            if (!$result) {
                // Don't hard-fail immediately — retry the job after a backoff delay
                // to handle Veo quota limits when multiple strategies fire simultaneously
                if ($this->attempts() < 3) {
                    Log::warning("Video generation failed to start for Strategy ID {$this->strategy->id}, attempt {$this->attempts()}/3 — retrying in 5 minutes");
                    $this->release(300);
                    return;
                }
                $videoCollateral->update(['status' => 'failed']);
                throw new \Exception('Failed to start video generation after 3 attempts.');
            }

            // Step 5: Update the record with the operation name, provider, and set status to 'generating'
            $videoCollateral->update([
                'operation_name' => $result['operation_name'],
                'provider'       => $result['provider'],
                'status'         => 'generating',
            ]);

            Log::info("Video generation initiated for Strategy ID: {$this->strategy->id}.", [
                'provider'       => $result['provider'],
                'operation_name' => $result['operation_name'],
            ]);

            $existing = $this->strategy->collateral_errors ?? [];
            unset($existing['video']);
            $this->strategy->update(['collateral_errors' => empty($existing) ? null : $existing]);

            // Step 6: Dispatch the job to check the video status.
            CheckVideoStatus::dispatch($videoCollateral)->delay(now()->addMinutes(1));

        } catch (\Exception $e) {
            if ($videoCollateral) {
                $videoCollateral->update(['status' => 'failed']);
            }
            Log::error("Error in GenerateVideo job for Campaign ID {$this->campaign->id}: " . $e->getMessage());
            $this->fail($e);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateVideo failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);

        $existing = $this->strategy->collateral_errors ?? [];
        $existing['video'] = $exception->getMessage();
        $this->strategy->update(['collateral_errors' => $existing]);
    }
}
