<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\Strategy;
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
            // 1. Review the image prompt for quality and safety
            $prompt = $this->strategy->imagery_strategy;
            $review = $adminMonitorService->reviewImagePrompt($prompt);

            if (!$review['is_valid']) {
                $feedback = implode(' ', $review['feedback']);
                throw new \Exception("Image prompt failed validation: {$feedback}");
            }

            // 2. Generate the image using the Gemini Service (requesting 3 candidates)
            $imageDataArray = $geminiService->generateImage($prompt, 'gemini-2.5-flash-image', '1K', 3);

            if (empty($imageDataArray)) {
                throw new \Exception('Failed to generate any image data from Gemini service.');
            }

            foreach ($imageDataArray as $imageData) {
                if (!isset($imageData['data']) || !isset($imageData['mimeType'])) {
                    Log::warning('Skipping an invalid image candidate from Gemini.');
                    continue;
                }

                // 3. Decode the base64 image data
                $decodedImage = base64_decode($imageData['data']);
                if ($decodedImage === false) {
                    Log::warning('Failed to decode a base64 image candidate.');
                    continue;
                }

                // 4. Store the image in S3
                $extension = $this->getExtensionFromMimeType($imageData['mimeType']);
                $filename = uniqid('img_', true) . '.' . $extension;
                $s3Path = "collateral/images/{$this->campaign->id}/{$filename}";

                Storage::disk('s3')->put($s3Path, $decodedImage, 'public');
                Log::info("Image uploaded to S3 at path: {$s3Path}");

                // 5. Construct the CloudFront URL
                $cloudfrontDomain = config('filesystems.cloudfront_domain', env('CLOUDFRONT_DOMAIN'));
                if (!$cloudfrontDomain) {
                    throw new \Exception('CloudFront domain is not configured.');
                }
                $cloudFrontUrl = "{$cloudfrontDomain}/{$s3Path}";

                // 6. Create the ImageCollateral record
                ImageCollateral::create([
                    'campaign_id' => $this->campaign->id,
                    'strategy_id' => $this->strategy->id,
                    'platform' => $this->strategy->platform,
                    's3_path' => $s3Path,
                    'cloudfront_url' => $cloudFrontUrl,
                ]);
            }

            Log::info("Successfully generated and stored images for Strategy ID: {$this->strategy->id}");

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
