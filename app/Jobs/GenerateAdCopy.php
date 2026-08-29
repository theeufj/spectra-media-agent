<?php

namespace App\Jobs;

use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Strategy;
use App\Prompts\AdCopyPrompt;
use App\Services\AdminMonitorService;
use App\Services\GeminiService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateAdCopy implements ShouldQueue
{
    /**
     * A soft-deleted customer/campaign mid-queue means the work is moot —
     * discard quietly instead of filling failed_jobs with ModelNotFound.
     */
    public $deleteWhenMissingModels = true;

    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
     */
    public function __construct(
        protected Campaign $campaign,
        protected Strategy $strategy,
        protected string $platform,
        protected ?int $personaId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("GenerateAdCopy job started for Campaign {$this->campaign->id}.");
            // Initialize services
            $geminiService = new GeminiService;
            $adminMonitorService = new AdminMonitorService($geminiService);

            // Load persona if specified
            $persona = $this->personaId ? \App\Models\Persona::find($this->personaId) : null;

            // Get brand guidelines for this customer
            $brandGuidelines = $this->campaign->customer->brandGuideline ?? null;

            if (! $brandGuidelines) {
                Log::warning("No brand guidelines found for customer {$this->campaign->customer_id}. Content may lack brand consistency.");
            }

            // Load analyzed competitors so ad copy differentiates from what they're saying
            $competitors = $this->campaign->customer->competitors()
                ->whereNotNull('messaging_analysis')
                ->get();

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
                Log::info('Found '.count($productContext).' selected product pages for ad copy generation.');
            }

            $strategyContent = $this->strategy->ad_copy_strategy;
            $maxAttempts = 10; // Increased to ensure compliance with rules
            $approvedAdCopyData = null;
            $lastFeedback = null;
            // Why each attempt bailed. Without this, a total Gemini outage and a
            // genuine compliance rejection produced the same final message.
            $attemptFailures = [];

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
                    $productContext,
                    $persona,
                    $competitors
                ))->getPrompt();
                $generatedResponse = $geminiService->generateContent(config('ai.models.default'), $adCopyPrompt);
                Log::info("Received raw response from Gemini for attempt {$attempt}.", ['response' => $generatedResponse]);

                if (is_null($generatedResponse)) {
                    Log::error("Failed to get ad copy from Gemini on attempt {$attempt}.");
                    $attemptFailures[] = 'no_response_from_gemini';

                    continue;
                }

                $generatedText = $generatedResponse['text'] ?? null;
                if (is_null($generatedText)) {
                    Log::error("Failed to get ad copy text from Gemini response on attempt {$attempt}.");
                    $attemptFailures[] = 'empty_text_from_gemini';

                    continue;
                }

                $adCopyData = [];
                try {
                    $cleanedJson = preg_replace('/^```json\s*|\s*```$/', '', trim($generatedText));
                    $adCopyData = json_decode($cleanedJson, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \Exception('JSON decode error: '.json_last_error_msg());
                    }

                    Log::info('Parsed ad copy data from Gemini.', ['ad_copy_data' => $adCopyData]);

                    // Ensure headlines and descriptions are arrays.
                    if (! is_array($adCopyData['headlines'] ?? null) || ! is_array($adCopyData['descriptions'] ?? null)) {
                        throw new \Exception('Gemini did not return a valid JSON object with headlines and descriptions arrays.');
                    }
                } catch (\Exception $e) {
                    Log::error("Failed to parse Gemini's ad copy response on attempt {$attempt}: ".$e->getMessage(), ['generated_text' => $generatedText]);
                    $attemptFailures[] = 'unparseable_response';

                    continue;
                }

                $tempAdCopy = new AdCopy(['strategy_id' => $this->strategy->id, 'platform' => $this->platform, 'headlines' => $adCopyData['headlines'], 'descriptions' => $adCopyData['descriptions']]);
                $reviewResults = $adminMonitorService->reviewAdCopy($tempAdCopy);

                if (is_null($reviewResults)) {
                    Log::warning("Ad copy review failed on attempt {$attempt}. No review results.");
                    $attemptFailures[] = 'review_returned_nothing';

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
                        'violations' => $reviewResults['programmatic_validation']['violations'] ?? [],
                    ]);
                    // Store the feedback for the next attempt.
                    $lastFeedback = $reviewResults['programmatic_validation']['feedback'] ?? [];
                    $attemptFailures[] = 'not_approved';
                }
            }

            if (is_null($approvedAdCopyData)) {
                // Summarise *why* the attempts failed. "Last violations: null"
                // previously implied the model produced non-compliant copy, when
                // the real cause could be that it never produced anything at all
                // — e.g. Vertex returning 403 on every call. Those need different
                // people to fix, so the message has to tell them apart.
                $tally = array_count_values($attemptFailures);
                arsort($tally);
                $reason = $tally ? array_key_first($tally) : 'unknown';

                $breakdown = implode(', ', array_map(
                    fn ($k, $n) => "{$k} x{$n}",
                    array_keys($tally),
                    $tally
                ));

                Log::error("Failed to generate approved ad copy after {$maxAttempts} attempts for Campaign {$this->campaign->id}, Strategy {$this->strategy->id}, Platform {$this->platform}.", [
                    'dominant_reason' => $reason,
                    'breakdown' => $tally,
                    'last_feedback' => $lastFeedback,
                ]);

                // Only 'not_approved' means the copy itself was the problem;
                // everything else is an upstream/model fault.
                $summary = $reason === 'not_approved'
                    ? 'copy repeatedly failed compliance review. Last feedback: '.json_encode($lastFeedback)
                    : "no usable copy was produced ({$reason}) — this is an upstream failure, not a compliance one";

                throw new \Exception(
                    "Failed to generate ad copy after {$maxAttempts} attempts: {$summary}. Breakdown: {$breakdown}"
                );
            }

            $uniqueKeys = ['strategy_id' => $this->strategy->id, 'platform' => $this->platform];
            if ($persona) {
                $uniqueKeys['persona_id'] = $persona->id;
            }

            AdCopy::updateOrCreate(
                $uniqueKeys,
                ['headlines' => $approvedAdCopyData['headlines'], 'descriptions' => $approvedAdCopyData['descriptions'], 'persona_id' => $persona?->id]
            );

            Log::info("Successfully generated and stored approved ad copy for Campaign {$this->campaign->id}, Strategy {$this->strategy->id}, Platform {$this->platform}");

            // Clear any prior ad_copy error so the UI doesn't show stale failures.
            $existing = $this->strategy->collateral_errors ?? [];
            unset($existing['ad_copy']);
            $this->strategy->update(['collateral_errors' => empty($existing) ? null : $existing]);

        } catch (\Exception $e) {
            Log::error("Error in GenerateAdCopy job for Campaign {$this->campaign->id}: ".$e->getMessage());
            $this->fail($e);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateAdCopy failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);

        $existing = $this->strategy->collateral_errors ?? [];
        $existing['ad_copy'] = $exception->getMessage();
        $this->strategy->update(['collateral_errors' => $existing]);
    }
}
