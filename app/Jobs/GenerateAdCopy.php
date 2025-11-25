<?php

namespace App\Jobs;

use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Strategy;
use App\Prompts\AdCopyPrompt;
use App\Services\AdminMonitorService;
use App\Services\GeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateAdCopy implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 900; // 15 minutes

    /**
     * Create a new job instance.
     *
     * @param Campaign $campaign
     * @param Strategy $strategy
     * @param string $platform
     */
    public function __construct(
        protected Campaign $campaign,
        protected Strategy $strategy,
        protected string $platform
    ) {
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            Log::info("GenerateAdCopy job started for Campaign {$this->campaign->id}.");
            // Initialize services
            $geminiService = new GeminiService();
            $adminMonitorService = new AdminMonitorService($geminiService);

            // Get brand guidelines for this customer
            $brandGuidelines = $this->campaign->customer->brandGuideline ?? null;
            
            if (!$brandGuidelines) {
                Log::warning("No brand guidelines found for customer {$this->campaign->customer_id}. Content may lack brand consistency.");
            }

            // Fetch selected product pages
            $productContext = [];
            $selectedPages = $this->campaign->pages; // Assuming relationship is defined
            if ($selectedPages->isNotEmpty()) {
                $productContext = $selectedPages->map(function ($page) {
                    return [
                        'title' => $page->title,
                        'url' => $page->url,
                        'price' => $page->metadata['price'] ?? null,
                        'description' => $page->meta_description,
                        'features' => $page->metadata['features'] ?? [], // Assuming features might be in metadata
                    ];
                })->toArray();
                Log::info("Found " . count($productContext) . " selected product pages for ad copy generation.");
            }

            $strategyContent = $this->strategy->ad_copy_strategy;
            $maxAttempts = 10; // Increased to ensure compliance with rules
            $approvedAdCopyData = null;
            $lastFeedback = null;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                Log::info("Attempting to generate and review ad copy (Attempt {$attempt}/{$maxAttempts}) for Campaign {$this->campaign->id}, Strategy {$this->strategy->id}, Platform {$this->platform}");

                // Get the platform rules to provide context to the model.
                $rules = AdminMonitorService::getRulesForPlatform($this->platform);
                Log::info("Fetched platform rules for {$this->platform}.", ['rules' => $rules]);

                // Pass feedback, rules, and brand guidelines into the prompt.
                $adCopyPrompt = (new AdCopyPrompt(
                    $strategyContent,
                    $this->platform,
                    $rules,
                    $lastFeedback,
                    $brandGuidelines,
                    $productContext
                ))->getPrompt();
                $generatedResponse = $geminiService->generateContent('gemini-2.5-pro', $adCopyPrompt);
                Log::info("Received raw response from Gemini for attempt {$attempt}.", ['response' => $generatedResponse]);

                if (is_null($generatedResponse)) {
                    Log::error("Failed to get ad copy from Gemini on attempt {$attempt}.");
                    continue;
                }

                $generatedText = $generatedResponse['text'] ?? null;
                if (is_null($generatedText)) {
                    Log::error("Failed to get ad copy text from Gemini response on attempt {$attempt}.");
                    continue;
                }

                $adCopyData = [];
                try {
                    $cleanedJson = preg_replace('/^```json\s*|\s*```$/', '', trim($generatedText));
                    $adCopyData = json_decode($cleanedJson, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \Exception("JSON decode error: " . json_last_error_msg());
                    }

                    Log::info("Parsed ad copy data from Gemini.", ['ad_copy_data' => $adCopyData]);

                    // Ensure headlines and descriptions are arrays.
                    if (!is_array($adCopyData['headlines'] ?? null) || !is_array($adCopyData['descriptions'] ?? null)) {
                        throw new \Exception("Gemini did not return a valid JSON object with headlines and descriptions arrays.");
                    }
                } catch (\Exception $e) {
                    Log::error("Failed to parse Gemini's ad copy response on attempt {$attempt}: " . $e->getMessage(), ['generated_text' => $generatedText]);
                    continue;
                }

                $tempAdCopy = new AdCopy(['strategy_id' => $this->strategy->id, 'platform' => $this->platform, 'headlines' => $adCopyData['headlines'], 'descriptions' => $adCopyData['descriptions']]);
                $reviewResults = $adminMonitorService->reviewAdCopy($tempAdCopy);

                if (is_null($reviewResults)) {
                    Log::warning("Ad copy review failed on attempt {$attempt}. No review results.");
                    continue;
                }

                if (($reviewResults['overall_status'] ?? 'needs_revision') === 'approved') {
                    Log::info("Ad copy approved on attempt {$attempt}.", $reviewResults);
                    $approvedAdCopyData = $adCopyData;
                    break;
                } else {
                    Log::warning("Ad copy not approved on attempt {$attempt}.", [
                        'overall_status' => $reviewResults['overall_status'] ?? 'unknown',
                        'feedback' => $reviewResults['programmatic_validation']['feedback'] ?? [],
                        'violations' => $reviewResults['programmatic_validation']['violations'] ?? []
                    ]);
                    // Store the feedback for the next attempt.
                    $lastFeedback = $reviewResults['programmatic_validation']['feedback'] ?? [];
                }
            }

            if (is_null($approvedAdCopyData)) {
                Log::error("Failed to generate approved ad copy after {$maxAttempts} attempts for Campaign {$this->campaign->id}, Strategy {$this->strategy->id}, Platform {$this->platform}. Last feedback: ", ['feedback' => $lastFeedback]);
                throw new \Exception("Failed to generate approved ad copy after {$maxAttempts} attempts. Last violations: " . json_encode($lastFeedback));
            }

            AdCopy::updateOrCreate(
                ['strategy_id' => $this->strategy->id, 'platform' => $this->platform],
                ['headlines' => $approvedAdCopyData['headlines'], 'descriptions' => $approvedAdCopyData['descriptions']]
            );

            Log::info("Successfully generated and stored approved ad copy for Campaign {$this->campaign->id}, Strategy {$this->strategy->id}, Platform {$this->platform}");

        } catch (\Exception $e) {
            Log::error("Error in GenerateAdCopy job for Campaign {$this->campaign->id}: " . $e->getMessage());
            $this->fail($e);
        }
    }
}
