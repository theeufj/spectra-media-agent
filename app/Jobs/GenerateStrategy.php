<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\KnowledgeBase;
use App\Models\Recommendation;
use App\Prompts\StrategyPrompt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
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
        try {
            // Step 1: Gather all the user's knowledge base content.
            // We retrieve all entries for the user who owns the campaign and concatenate them.
            $knowledgeBaseContent = KnowledgeBase::where('user_id', $this->campaign->user_id)
                ->pluck('content')
                ->implode("\n\n---\n\n");

            if (empty($knowledgeBaseContent)) {
                Log::warning("No knowledge base content found for user {$this->campaign->user_id} to generate strategy for campaign {$this->campaign->id}.");
                return;
            }

            // Step 1.5: Gather any pending recommendations.
            $recommendations = Recommendation::where('campaign_id', $this->campaign->id)
                ->where('status', 'pending')
                ->get();

            // Step 2: Construct the prompt using our dedicated prompt builder class.
            $prompt = StrategyPrompt::build($this->campaign, $knowledgeBaseContent, $recommendations->toArray());

            // Step 3: Call the Google Gemini API.
            $apiKey = config('services.google.gemini_api_key');
            //fallback to env variable if config is not set
            if (empty($apiKey)) {
                $apiKey = env('GEMINI_API_KEY');
            }
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(300)->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent?key=AIzaSyCZDOaie5UJ6Aj5ehTBsiJdiWnD7uJaX74", [
                'systemInstruction' => [
                    'parts' => [
                        [
                            'text' => 'You are an expert digital marketing strategist with deep knowledge of multi-platform marketing campaigns. Use your extended thinking capabilities to reason through complex marketing scenarios, and ground your strategies in real-world search data and market trends.'
                        ]
                    ]
                ],
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 1,
                    'topP' => 0.95,
                    'topK' => 40,
                    'maxOutputTokens' => 16000,
                    'thinkingConfig' => [
                        'includeThoughts' => true,
                        'thinkingBudget' => 5000
                    ]
                ]
            ]);
            if ($response->failed()) {
                Log::error("Failed to generate strategy for campaign {$this->campaign->id}: " . $response->body());
                return;
            }

            // The response from Gemini may contain multiple parts:
            // - thoughts (from extended thinking)
            // - text with the actual JSON strategy
            // We need to find the text part that contains the JSON, not the thinking part
            $responseData = $response->json();
            $candidates = $responseData['candidates'] ?? [];
            
            if (empty($candidates)) {
                Log::error("Failed to generate strategy for campaign {$this->campaign->id}: No candidates in response");
                return;
            }

            $parts = $candidates[0]['content']['parts'] ?? [];
            $jsonText = null;
            
            // Find the text part that contains JSON (skip thinking parts)
            foreach ($parts as $part) {
                if (isset($part['text']) && !isset($part['thought'])) {
                    $jsonText = $part['text'];
                    break;
                }
            }
            
            if (!$jsonText) {
                Log::error("Failed to generate strategy for campaign {$this->campaign->id}: No JSON text content in response parts");
                return;
            }

            // Clean up the JSON response (remove markdown code blocks if present)
            $cleanedJson = trim($jsonText);
            $cleanedJson = preg_replace('/^```json\s*/', '', $cleanedJson);
            $cleanedJson = preg_replace('/\s*```$/', '', $cleanedJson);
            $cleanedJson = trim($cleanedJson);
            
            $strategyData = json_decode($cleanedJson, true);

            if (json_last_error() !== JSON_ERROR_NONE || !isset($strategyData['strategies'])) {
                Log::error("Failed to parse JSON response for campaign {$this->campaign->id}: " . json_last_error_msg());
                return;
            }

            // Step 4: Parse the response and save the platform-specific strategies.
            foreach ($strategyData['strategies'] as $strategy) {
                $this->campaign->strategies()->create([
                    'platform' => $strategy['platform'],
                    'ad_copy_strategy' => $strategy['ad_copy_strategy'],
                    'imagery_strategy' => $strategy['imagery_strategy'],
                    'video_strategy' => $strategy['video_strategy'],
                    'bidding_strategy' => $strategy['bidding_strategy'],
                    'cpa_target' => $strategy['bidding_strategy']['parameters']['targetCpaMicros'] ?? null,
                    'revenue_cpa_multiple' => $strategy['revenue_cpa_multiple'],
                ]);
            }

            Log::info("Successfully generated and saved strategies for campaign {$this->campaign->id}");

        } catch (\Exception $e) {
            Log::error("Error generating strategy for campaign {$this->campaign->id}: " . $e->getMessage());
        }
    }
}
