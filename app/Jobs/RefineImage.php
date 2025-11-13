<?php

namespace App\Jobs;

use App\Models\ImageCollateral;
use App\Services\GeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RefineImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 900;

    public function __construct(
        protected ImageCollateral $originalImage,
        protected string $prompt,
        protected ?string $contextImagePath = null
    ) {
    }

    public function handle(GeminiService $geminiService): void
    {
        Log::info("Starting image refinement job for ImageCollateral ID: {$this->originalImage->id}");

        try {
            $contextImages = [];

            // Add the original image as the first context
            $originalImageData = Storage::disk('s3')->get($this->originalImage->s3_path);
            $contextImages[] = [
                'mime_type' => 'image/png', // Assuming png, adjust if you store different types
                'data' => base64_encode($originalImageData),
            ];

            // Add the user-uploaded context image, if provided
            if ($this->contextImagePath) {
                $uploadedImageData = Storage::disk('local')->get($this->contextImagePath);
                $contextImages[] = [
                    'mime_type' => mime_content_type(storage_path('app/' . $this->contextImagePath)),
                    'data' => base64_encode($uploadedImageData),
                ];
            }

            // Generate a single new image based on the prompt and context
            $imageData = $geminiService->refineImage($this->prompt, $contextImages);

            if (empty($imageData) || !isset($imageData['data'])) {
                throw new \Exception('Failed to generate refined image from Gemini.');
            }

            // Store the new image in S3
            $decodedImage = base64_decode($imageData['data']);
            $extension = $this->getExtensionFromMimeType($imageData['mimeType']);
            $filename = uniqid('img_refined_', true) . '.' . $extension;
            $s3Path = "collateral/images/{$this->originalImage->campaign_id}/{$filename}";
            Storage::disk('s3')->put($s3Path, $decodedImage, 'public');

            // Create the new ImageCollateral record
            $cloudfrontDomain = config('filesystems.cloudfront_domain', env('CLOUDFRONT_DOMAIN'));
            $cloudFrontUrl = "{$cloudfrontDomain}/{$s3Path}";

            ImageCollateral::create([
                'campaign_id' => $this->originalImage->campaign_id,
                'strategy_id' => $this->originalImage->strategy_id,
                'platform' => $this->originalImage->platform,
                's3_path' => $s3Path,
                'cloudfront_url' => $cloudFrontUrl,
                'parent_id' => $this->originalImage->id,
            ]);

            Log::info("Successfully refined image for parent ImageCollateral ID: {$this->originalImage->id}");

            // Clean up the temporary context image if it was uploaded
            if ($this->contextImagePath) {
                Storage::disk('local')->delete($this->contextImagePath);
            }

        } catch (\Exception $e) {
            Log::error("Error in RefineImage job for ImageCollateral ID {$this->originalImage->id}: " . $e->getMessage());
            $this->fail($e);
        }
    }

    private function getExtensionFromMimeType(string $mimeType): string
    {
        $parts = explode('/', $mimeType);
        return end($parts) ?: 'png';
    }
}
