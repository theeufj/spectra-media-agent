<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Prompts\ImagePrompt;
use App\Prompts\ImagePromptSplitterPrompt;
use App\Services\AdminMonitorService;
use App\Services\GeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateImage implements ShouldQueue
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
     */
    public function __construct(
        protected Campaign $campaign,
        protected Strategy $strategy
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(GeminiService $geminiService, AdminMonitorService $adminMonitorService): void
    {
        Log::info("Starting image generation job for Campaign ID: {$this->campaign->id}, Strategy ID: {$this->strategy->id}");

        try {
            // Fetch brand guidelines if available
            $brandGuidelines = $this->campaign->customer->brandGuideline ?? null;
            if (!$brandGuidelines) {
                Log::warning("No brand guidelines found for customer ID: {$this->campaign->customer_id}");
            }

            $strategyPrompt = $this->strategy->imagery_strategy;
            $review = $adminMonitorService->reviewImagePrompt($strategyPrompt);

            if (!$review['is_valid']) {
                $feedback = implode(' ', $review['feedback']);
                throw new \Exception("Image prompt failed validation: {$feedback}");
            }

            // --- AI-Powered Prompt Splitting ---
            $splitterPrompt = (new ImagePromptSplitterPrompt($strategyPrompt))->getPrompt();
            $splitterResponse = $geminiService->generateContent('gemini-flash-latest', $splitterPrompt);
            
            $prompts = [];
            try {
                $cleanedJson = preg_replace('/^```json\s*|\s*```$/', '', trim($splitterResponse['text']));
                $decoded = json_decode($cleanedJson, true);
                if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['prompts']) || !is_array($decoded['prompts'])) {
                    throw new \Exception("Failed to decode prompts from the splitter model.");
                }
                $prompts = $decoded['prompts'];
            } catch (\Exception $e) {
                Log::error("Failed to parse prompts from ImagePromptSplitter: " . $e->getMessage(), ['response' => $splitterResponse['text'] ?? null]);
                // Fallback to the original strategy if splitting fails
                $prompts = [$strategyPrompt];
            }

            if (empty($prompts)) {
                // If splitting results in no prompts, fall back to the original strategy
                $prompts = [$strategyPrompt];
                Log::warning("Image prompt splitter returned no prompts. Falling back to the original strategy.");
            }
            // --- End Prompt Splitting ---

            $successfulUploads = 0;

            foreach ($prompts as $index => $prompt) {
                Log::info("Generating image " . ($index + 1) . "/" . count($prompts) . " for Strategy ID: {$this->strategy->id}");

                $imagePrompt = (new ImagePrompt($prompt, $brandGuidelines))->getPrompt();
                Log::info("Gemini Image Generation Prompt:", ['prompt' => $imagePrompt]);

                $imageData = $geminiService->generateImage($imagePrompt);

                if (!$imageData || !isset($imageData['data']) || !isset($imageData['mimeType'])) {
                    Log::warning('Failed to generate image data from Gemini on attempt ' . ($index + 1));
                    continue;
                }

                $decodedImage = base64_decode($imageData['data']);
                if ($decodedImage === false) {
                    Log::warning('Failed to decode a base64 image candidate on attempt ' . ($index + 1));
                    continue;
                }

                // Store the image in S3
                $extension = $this->getExtensionFromMimeType($imageData['mimeType']);
                $filename = uniqid('img_', true) . '.' . $extension;
                $s3Path = "collateral/images/{$this->campaign->id}/{$filename}";

                try {
                    $s3Client = Storage::disk('s3')->getClient();
                    $result = $s3Client->putObject([
                        'Bucket' => config('filesystems.disks.s3.bucket'),
                        'Key' => $s3Path,
                        'Body' => $decodedImage,
                        'ContentType' => $imageData['mimeType'],
                    ]);

                    if (!isset($result['ETag'])) {
                        throw new \Exception('S3 upload failed - no ETag in response.');
                    }
                } catch (\Exception $e) {
                    throw new \Exception("Failed to upload image to S3. AWS Error: " . $e->getMessage());
                }

                Log::info("Image uploaded to S3 at path: {$s3Path}");

                // Construct the CloudFront URL
                $cloudfrontDomain = config('filesystems.cloudfront_domain');
                if (!$cloudfrontDomain) {
                    throw new \Exception('CloudFront domain is not configured.');
                }
                $cloudFrontUrl = "https://{$cloudfrontDomain}/{$s3Path}";

                // Create the ImageCollateral record
                ImageCollateral::create([
                    'campaign_id' => $this->campaign->id,
                    'strategy_id' => $this->strategy->id,
                    'platform' => $this->strategy->platform,
                    's3_path' => $s3Path,
                    'cloudfront_url' => $cloudFrontUrl,
                ]);

                $successfulUploads++;
            }

            Log::info("Successfully generated and stored {$successfulUploads} image(s) for Strategy ID: {$this->strategy->id}");

        } catch (\Exception $e) {
            Log::error("Error in GenerateImage job for Strategy ID {$this->strategy->id}: " . $e->getMessage());
            $this->fail($e);
        }
    }

    /**
     * Get the file extension from a MIME type.
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $parts = explode('/', $mimeType);
        return end($parts) ?: 'png'; // Default to png if detection fails
    }
}
