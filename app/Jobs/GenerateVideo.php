<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\VideoGeneration\VideoGenerationService;
use App\Services\GeminiService;
use App\Prompts\VideoScriptPrompt;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 900;

    public function __construct(
        protected Campaign $campaign,
        protected Strategy $strategy,
        protected string $platform
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
        
        // Check if it's purely "N/A" or "Not Applicable" with no additional content
        if (preg_match('/^(n\/a|not applicable|none)\.?$/i', $content)) {
            return null;
        }
        
        // If content starts with "N/A" but contains conditional statements (however, if, when, for, etc.)
        // extract everything after the conditional indicator
        if (preg_match('/^n\/a[^.]*\.\s*(however|but|if|when|for|in cases where|alternatively)/i', $content, $matches)) {
            // Find the position of the conditional word
            $pos = stripos($content, $matches[1]);
            if ($pos !== false) {
                $actionableContent = substr($content, $pos);
                Log::info("Extracted conditional video content after N/A: {$actionableContent}");
                return trim($actionableContent);
            }
        }
        
        // If the content mentions both "N/A" and actionable scenarios, extract the actionable part
        if (stripos($lowerContent, 'n/a') !== false && 
            (stripos($lowerContent, 'however') !== false || 
             stripos($lowerContent, 'but') !== false || 
             stripos($lowerContent, 'if') !== false)) {
            // Split by common transition words and take the actionable part
            $transitions = ['however', 'but', 'if', 'when', 'for', 'in cases where', 'alternatively'];
            foreach ($transitions as $transition) {
                $pos = stripos($content, $transition);
                if ($pos !== false) {
                    $actionableContent = substr($content, $pos);
                    Log::info("Extracted actionable content after '{$transition}': {$actionableContent}");
                    return trim($actionableContent);
                }
            }
        }
        
        // If "N/A" is mentioned but there's substantial content (more than just "N/A for X"),
        // check if there's actionable content in the rest of the text
        if (stripos($lowerContent, 'n/a') !== false && strlen($content) > 50) {
            // The content is long enough that it likely contains actionable information
            Log::info("Video strategy mentions N/A but contains substantial content ({strlen($content)} chars). Using full content.");
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
        Log::info("Starting video generation job for Strategy ID: {$this->strategy->id} on platform {$this->platform}");

        $videoCollateral = null;
        try {
            // Fetch brand guidelines if available
            $brandGuidelines = $this->campaign->customer->brandGuideline ?? null;
            if (!$brandGuidelines) {
                Log::warning("No brand guidelines found for customer ID: {$this->campaign->customer_id}");
            }

            // Check if video strategy contains actionable content
            $videoStrategy = $this->strategy->video_strategy;
            $actionableContent = $this->extractActionableVideoContent($videoStrategy);
            
            if (!$actionableContent) {
                Log::info("No actionable video content found for Strategy ID: {$this->strategy->id}. Skipping video generation.");
                return;
            }
            
            Log::info("Using actionable video strategy: {$actionableContent}");

            // 1. Generate the video script using the actionable content
            $scriptPrompt = (new VideoScriptPrompt($actionableContent, $brandGuidelines))->getPrompt();
            $scriptResponse = $geminiService->generateContent('gemini-flash-latest', $scriptPrompt);
            $script = $scriptResponse['text'] ?? 'No script generated.';
            Log::info("Generated video script: {$script}");

            // 2. Create a placeholder VideoCollateral record
            $videoCollateral = VideoCollateral::create([
                'campaign_id' => $this->campaign->id,
                'strategy_id' => $this->strategy->id,
                'platform' => $this->platform,
                'status' => 'pending',
                'is_active' => true,
            ]);

            // 3. Generate the final video prompt using the dedicated prompt class with actionable content
            $videoPrompt = (new VideoFromScriptPrompt($actionableContent, $script))->getPrompt();
            Log::info("Combined video prompt: {$videoPrompt}");

            // 4. Start the video generation and get the operation name
            $operationName = $videoGenerationService->startGeneration($videoPrompt);

            if (!$operationName) {
                $videoCollateral->update(['status' => 'failed']);
                throw new \Exception('Failed to start video generation.');
            }

            // 5. Update the record with the operation name and set status to 'generating'
            $videoCollateral->update([
                'operation_name' => $operationName,
                'status' => 'generating',
            ]);

            Log::info("Video generation initiated for Strategy ID: {$this->strategy->id}. Operation Name: {$operationName}");

            // 6. Dispatch the job to check the video status.
            CheckVideoStatus::dispatch($videoCollateral)->delay(now()->addMinutes(1));

        } catch (\Exception $e) {
            if ($videoCollateral) {
                $videoCollateral->update(['status' => 'failed']);
            }
            Log::error("Error in GenerateVideo job for Strategy ID {$this->strategy->id}: " . $e->getMessage());
            $this->fail($e);
        }
    }
}
