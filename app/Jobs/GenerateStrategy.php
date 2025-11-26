<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\EnabledPlatform;
use App\Models\KnowledgeBase;
use App\Models\Recommendation;
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
            
            Log::info("Knowledge base content length: " . strlen($knowledgeBaseContent) . " characters");

            if (empty($knowledgeBaseContent)) {
                Log::warning("No knowledge base content found for customer {$this->campaign->customer_id} to generate strategy for campaign {$this->campaign->id}.");
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

            if (empty($enabledPlatforms)) {
                Log::error("No enabled platforms found for campaign {$this->campaign->id}. Cannot generate strategy.");
                return;
            }

            // Step 2: Construct the prompt using our dedicated prompt builder class.
            Log::info("Building strategy prompt for campaign {$this->campaign->id}");
            $prompt = StrategyPrompt::build($this->campaign, $knowledgeBaseContent, $recommendations->toArray(), $brandGuidelines, $enabledPlatforms);
            Log::info("Generated prompt length: " . strlen($prompt) . " characters");

            // Step 3: Call Gemini API with Gemini 3 Pro Preview with extended thinking and Google Search.
            Log::info("Preparing API call to Gemini 3 Pro Preview for campaign {$this->campaign->id}");
            
            $gemini = app(GeminiService::class);
            $systemInstruction = StrategyPrompt::getSystemInstruction();
            
            Log::info("Making API request to Gemini with thinking and search enabled for campaign {$this->campaign->id}");
            $result = $gemini->generateWithThinkingAndSearch(
                'gemini-3-pro-preview',
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
                return;
            }

            $jsonText = $result['text'] ?? null;
            
            if (!$jsonText) {
                Log::error("Failed to generate strategy for campaign {$this->campaign->id}: No text content in response");
                return;
            }

            Log::info("Found JSON text content for campaign {$this->campaign->id}, length: " . strlen($jsonText));
            
            // Clean up the JSON response (remove markdown code blocks if present)
            $cleanedJson = trim($jsonText);
            $cleanedJson = preg_replace('/^```json\s*/', '', $cleanedJson);
            $cleanedJson = preg_replace('/\s*```$/', '', $cleanedJson);
            $cleanedJson = trim($cleanedJson);
            
            Log::info("Attempting to decode JSON for campaign {$this->campaign->id}");
            $strategyData = json_decode($cleanedJson, true);

            if (json_last_error() !== JSON_ERROR_NONE || !isset($strategyData['strategies'])) {
                Log::error("Failed to parse JSON response for campaign {$this->campaign->id}: " . json_last_error_msg());
                Log::debug("Raw JSON for campaign {$this->campaign->id}: " . substr($cleanedJson, 0, 500) . '...');
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
                        'ad_copy_strategy' => $strategy['ad_copy_strategy'],
                        'imagery_strategy' => $strategy['imagery_strategy'],
                        'video_strategy' => $strategy['video_strategy'],
                        'bidding_strategy' => $strategy['bidding_strategy'],
                        'cpa_target' => $strategy['bidding_strategy']['parameters']['targetCpaMicros'] ?? null,
                        'revenue_cpa_multiple' => $strategy['revenue_cpa_multiple'],
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
}
