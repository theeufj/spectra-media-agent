<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\EnabledPlatform;
use App\Models\KnowledgeBase;
use App\Models\Recommendation;
use App\Notifications\StrategyGenerationFailed;
use App\Prompts\StrategyPrompt;
use App\Services\GeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateStrategy implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    /**
     * @var \App\Models\Campaign
     */
    public $campaign;

    /**
     * Create a new job instance.
     *
     * @param Campaign $campaign
     */
    public function __construct(Campaign $campaign)
    {
        $this->campaign = $campaign;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Starting strategy generation for campaign {$this->campaign->id} (customer: {$this->campaign->customer_id})");
        
        // Mark strategy generation as started
        $this->campaign->update([
            'strategy_generation_started_at' => now(),
            'strategy_generation_completed_at' => null,
            'strategy_generation_error' => null,
        ]);
        
        try {
            // Step 1: Gather all the user's knowledge base content.
            // Since campaigns belong to customers and knowledge bases belong to users,
            // we need to get all knowledge bases from all users associated with this customer
            Log::info("Fetching knowledge base content for customer {$this->campaign->customer_id}");
            
            $customerUserIds = $this->campaign->customer->users()->pluck('users.id');
            Log::info("Found " . $customerUserIds->count() . " users for customer {$this->campaign->customer_id}");
            
            $knowledgeBaseContent = KnowledgeBase::whereIn('user_id', $customerUserIds)
                ->pluck('content')
                ->implode("\n\n---\n\n");
            
            $kbLength = strlen($knowledgeBaseContent);
            Log::info("Knowledge base content length: {$kbLength} characters");

            // Warn and truncate if the KB is approaching Gemini's input limits
            if ($kbLength > 800000) {
                Log::warning("Knowledge base content is very large ({$kbLength} chars) for campaign {$this->campaign->id} — truncating to 800k chars to stay within API limits");
                $knowledgeBaseContent = substr($knowledgeBaseContent, 0, 800000);
            } elseif ($kbLength > 500000) {
                Log::warning("Knowledge base content is large ({$kbLength} chars) for campaign {$this->campaign->id}");
            }

            if (empty($knowledgeBaseContent)) {
                Log::warning("No knowledge base content found for customer {$this->campaign->customer_id} to generate strategy for campaign {$this->campaign->id}.");
                $this->failWithError('No knowledge base content found. Please add content to your knowledge base before generating strategies.');
                return;
            }

            // Step 1.25: Fetch brand guidelines if available
            Log::info("Checking for brand guidelines for customer ID: {$this->campaign->customer_id}");
            $brandGuidelines = $this->campaign->customer->brandGuideline ?? null;
            if (!$brandGuidelines) {
                Log::warning("No brand guidelines found for customer ID: {$this->campaign->customer_id}");
            } else {
                Log::info("Brand guidelines found with quality score: {$brandGuidelines->extraction_quality_score}");
            }

            // Step 1.5: Gather any pending recommendations.
            Log::info("Fetching pending recommendations for campaign {$this->campaign->id}");
            $recommendations = Recommendation::where('campaign_id', $this->campaign->id)
                ->where('status', 'pending')
                ->get();
            Log::info("Found {$recommendations->count()} pending recommendations");

            // Step 1.75: Get enabled platforms
            Log::info("Fetching enabled platforms for campaign {$this->campaign->id}");
            $enabledPlatforms = EnabledPlatform::getEnabledPlatformNames();
            Log::info("Found " . count($enabledPlatforms) . " enabled platforms: " . implode(', ', $enabledPlatforms));

            // Step 1.8: Use campaign's user-selected platforms if available, otherwise fall back to plan-allowed filter
            if (!empty($this->campaign->platforms)) {
                // Filter user selection to only include system-enabled platforms (safety check)
                $enabledPlatforms = array_values(array_filter($this->campaign->platforms, function ($p) use ($enabledPlatforms) {
                    return in_array(strtolower($p), array_map('strtolower', $enabledPlatforms), true);
                }));
                Log::info("Using campaign-selected platforms for campaign {$this->campaign->id}: " . implode(', ', $enabledPlatforms));
            } else {
                // Legacy fallback: filter by user's plan-allowed platforms
                $user = $this->campaign->customer->users()->first();
                if ($user) {
                    $allowed = $user->allowedPlatforms();
                    $enabledPlatforms = array_values(array_filter($enabledPlatforms, function ($p) use ($allowed) {
                        return in_array(strtolower($p), $allowed, true);
                    }));
                    Log::info("Plan-filtered platforms for campaign {$this->campaign->id}: " . implode(', ', $enabledPlatforms));
                }
            }

            if (empty($enabledPlatforms)) {
                Log::error("No enabled platforms found for campaign {$this->campaign->id}. Cannot generate strategy.");
                $this->failWithError('No advertising platforms are currently enabled. Please contact support.');
                return;
            }

            // Step 1.9: Load competitor intelligence if available
            $competitors = $this->campaign->customer->competitors()
                ->whereNotNull('last_analyzed_at')
                ->take(10)
                ->get();
            Log::info("Found {$competitors->count()} analyzed competitors for campaign {$this->campaign->id}");

            // Step 2: Construct the prompt using our dedicated prompt builder class.
            Log::info("Building strategy prompt for campaign {$this->campaign->id}");
            $prompt = StrategyPrompt::build($this->campaign, $knowledgeBaseContent, $recommendations->toArray(), $brandGuidelines, $enabledPlatforms, $competitors);
            Log::info("Generated prompt length: " . strlen($prompt) . " characters");

            // Step 3: Call Gemini API with Gemini 3 Pro Preview with extended thinking and Google Search.
            Log::info("Preparing API call to Gemini 3 Pro Preview for campaign {$this->campaign->id}");
            
            $gemini = app(GeminiService::class);
            $systemInstruction = StrategyPrompt::getSystemInstruction();
            
            Log::info("Making API request to Gemini with thinking and search enabled for campaign {$this->campaign->id}");
            $result = $gemini->generateWithThinkingAndSearch(
                config('ai.models.pro'),
                $systemInstruction,
                $prompt,
                [
                    'temperature' => 1,
                    'topP' => 0.95,
                    'topK' => 40,
                    'maxOutputTokens' => 65535
                ]
            );
            
            Log::info("Received API response from Vertex AI for campaign {$this->campaign->id}");
            
            if (!$result) {
                Log::error("Failed to generate strategy for campaign {$this->campaign->id}: No response from Vertex AI");
                $this->failWithError('AI service did not return a response. Please try again.');
                return;
            }

            $jsonText = $result['text'] ?? null;
            
            if (!$jsonText) {
                Log::error("Failed to generate strategy for campaign {$this->campaign->id}: No text content in response");
                $this->failWithError('AI service returned an empty response. Please try again.');
                return;
            }

            Log::info("Found JSON text content for campaign {$this->campaign->id}, length: " . strlen($jsonText));

            $strategyData = $this->extractStrategyJson($jsonText);

            if (!$strategyData) {
                Log::error("Failed to parse JSON response for campaign {$this->campaign->id}", [
                    'raw_preview' => substr($jsonText, 0, 500),
                ]);
                $this->failWithError('The AI returned a response that could not be parsed. Please try regenerating.');
                return;
            }

            if (!isset($strategyData['strategies'])) {
                Log::error("AI response parsed but missing 'strategies' key for campaign {$this->campaign->id}", [
                    'keys_present' => array_keys($strategyData),
                    'raw_preview' => substr($jsonText, 0, 500),
                ]);
                $this->failWithError('The AI response was missing expected strategy data. Please try regenerating.');
                return;
            }
            
            Log::info("Successfully parsed strategy data for campaign {$this->campaign->id}, found " . count($strategyData['strategies']) . " strategies");

            // Step 4: Parse the response and save the platform-specific strategies.
            Log::info("Creating strategy records for campaign {$this->campaign->id}");
            foreach ($strategyData['strategies'] as $index => $strategy) {
                Log::info("Creating strategy #{$index} for platform '{$strategy['platform']}' on campaign {$this->campaign->id}");
                
                // Inject landing_page_url into bidding_strategy to avoid schema changes
                if (isset($strategy['landing_page_url'])) {
                    $strategy['bidding_strategy']['landing_page_url'] = $strategy['landing_page_url'];
                }

                try {
                    $newStrategy = $this->campaign->strategies()->create([
                        'platform' => $strategy['platform'],
                        'ad_copy_strategy' => is_array($strategy['ad_copy_strategy']) ? implode("\n\n", $strategy['ad_copy_strategy']) : ($strategy['ad_copy_strategy'] ?? ''),
                        'imagery_strategy' => is_array($strategy['imagery_strategy']) ? implode("\n\n", $strategy['imagery_strategy']) : ($strategy['imagery_strategy'] ?? ''),
                        'video_strategy' => is_array($strategy['video_strategy']) ? implode("\n\n", $strategy['video_strategy']) : ($strategy['video_strategy'] ?? ''),
                        'bidding_strategy' => $strategy['bidding_strategy'],
                        'cpa_target' => $strategy['bidding_strategy']['parameters']['targetCpaMicros'] ?? null,
                        'revenue_cpa_multiple' => $strategy['revenue_cpa_multiple'],
                        'generate_video' => $strategy['generate_video'] ?? true,
                    ]);

                    // Create TargetingConfig if targeting data is present
                    if (isset($strategy['targeting'])) {
                        $targeting = $strategy['targeting'];
                        $newStrategy->targetingConfig()->create([
                            'interests' => $targeting['interests'] ?? [],
                            'behaviors' => $targeting['behaviors'] ?? [],
                            'age_min' => $targeting['age_min'] ?? 18,
                            'age_max' => $targeting['age_max'] ?? 65,
                            'genders' => $targeting['genders'] ?? ['all'],
                            'geo_locations' => $targeting['geo_locations'] ?? [],
                            'platform' => $strategy['platform'],
                        ]);
                        Log::info("Created targeting config for strategy {$newStrategy->id}");
                    }

                    Log::info("Successfully created strategy #{$index} for campaign {$this->campaign->id}");
                } catch (\Exception $e) {
                    Log::error("Failed to create strategy #{$index} for campaign {$this->campaign->id}: " . $e->getMessage());
                    throw $e;
                }
            }

            Log::info("Successfully generated and saved strategies for campaign {$this->campaign->id}");
            
            // Mark strategy generation as completed
            $this->campaign->update([
                'strategy_generation_completed_at' => now(),
            ]);

            $strategyCount = count($strategyData['strategies']);
            AgentActivity::record(
                'strategy',
                'generated_strategies',
                "Generated {$strategyCount} " . ($strategyCount === 1 ? 'strategy' : 'strategies') . " for \"{$this->campaign->name}\"",
                $this->campaign->customer_id,
                $this->campaign->id,
                ['strategy_count' => $strategyCount]
            );

        } catch (\Exception $e) {
            Log::error("Error generating strategy for campaign {$this->campaign->id}: " . $e->getMessage(), [
                'campaign_id' => $this->campaign->id,
                'customer_id' => $this->campaign->customer_id,
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Mark strategy generation as failed
            $this->campaign->update([
                'strategy_generation_completed_at' => now(),
                'strategy_generation_error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Record the error and notify the campaign's users.
     */
    /**
     * Robustly extract the strategy JSON object from the model's raw text.
     *
     * Handles three common failure modes:
     *   1. Clean JSON — returned as-is after trimming.
     *   2. Markdown fenced JSON — strips ```json ... ``` wrappers.
     *   3. JSON embedded in prose — extracts the outermost { ... } block,
     *      which occurs when the model prefixes or suffixes with commentary
     *      despite being instructed not to.
     */
    private function extractStrategyJson(string $raw): ?array
    {
        $text = trim($raw);

        // Pass 1: strip markdown fences anywhere in the string
        $text = preg_replace('/```json\s*/i', '', $text);
        $text = preg_replace('/```/', '', $text);
        $text = trim($text);

        // Pass 2: try a straight decode first (fast path for well-behaved responses)
        $data = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        // Pass 3: extract the outermost JSON object using brace counting
        // Handles prose before/after the JSON block
        $start = strpos($text, '{');
        if ($start !== false) {
            $depth = 0;
            $end   = null;
            for ($i = $start; $i < strlen($text); $i++) {
                if ($text[$i] === '{') $depth++;
                elseif ($text[$i] === '}') {
                    $depth--;
                    if ($depth === 0) { $end = $i; break; }
                }
            }

            if ($end !== null) {
                $candidate = substr($text, $start, $end - $start + 1);
                $data = json_decode($candidate, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    Log::info("GenerateStrategy: JSON extracted via brace-counting fallback for campaign {$this->campaign->id}");
                    return $data;
                }
            }
        }

        Log::error("GenerateStrategy: All JSON extraction passes failed for campaign {$this->campaign->id}", [
            'json_error' => json_last_error_msg(),
            'raw_preview' => substr($raw, 0, 300),
        ]);

        return null;
    }

    protected function failWithError(string $message): void
    {
        $this->campaign->update([
            'strategy_generation_completed_at' => now(),
            'strategy_generation_error' => $message,
        ]);

        $cacheKey = "strategy_fail_notif:{$this->campaign->id}";
        if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
            return;
        }
        \Illuminate\Support\Facades\Cache::put($cacheKey, true, now()->addHours(24));

        $customer = $this->campaign->customer;
        if ($customer) {
            foreach ($customer->users as $user) {
                $user->notify(new StrategyGenerationFailed($this->campaign, $message));
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateStrategy failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
