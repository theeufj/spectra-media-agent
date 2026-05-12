<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Prompts\ImagePrompt;
use App\Prompts\ImagePromptSplitterPrompt;
use App\Services\AdminMonitorService;
use App\Services\GeminiService;
use App\Services\StorageHelper;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

class GenerateImage implements ShouldQueue
{
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
            // Check free-tier image limit before generating
            if (!ImageCollateral::canGenerateForCampaign($this->campaign)) {
                Log::info("Image limit reached for Campaign ID: {$this->campaign->id}, skipping generation");
                return;
            }

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
                        'image_url' => $page->metadata['image'] ?? null, // Pass image URL if available
                    ];
                })->toArray();
            }

            $strategyPrompt = $this->strategy->imagery_strategy;

            // Skip generation if the strategy is explicitly "N/A" or similar, without treating it as an error
            if (strlen(trim($strategyPrompt)) < 50 && (stripos($strategyPrompt, 'N/A') !== false || stripos($strategyPrompt, 'Not Applicable') !== false)) {
                Log::info("Skipping image generation for Strategy ID: {$this->strategy->id} due to N/A strategy.");
                return;
            }

            $review = $adminMonitorService->reviewImagePrompt($strategyPrompt);

            if (!$review['is_valid']) {
                $feedback = implode(' ', $review['feedback']);
                throw new \Exception("Image prompt failed validation: {$feedback}");
            }

            // --- AI-Powered Prompt Splitting ---
            $splitterPrompt = (new ImagePromptSplitterPrompt($strategyPrompt))->getPrompt();
            $splitterResponse = $geminiService->generateContent(config('ai.models.default'), $splitterPrompt);
            
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

                $imagePrompt = (new ImagePrompt($prompt, $brandGuidelines, $productContext))->getPrompt();
                Log::info("Gemini Image Generation Prompt:", ['prompt' => $imagePrompt]);

                // Retry logic with exponential backoff
                $maxRetries = 3;
                $imageData = null;
                
                for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                    if ($attempt > 1) {
                        $waitTime = pow(2, $attempt - 1); // Exponential backoff: 2, 4, 8 seconds
                        Log::info("Retrying image generation after {$waitTime} seconds (attempt {$attempt}/{$maxRetries})");
                        sleep($waitTime);
                    }
                    
                    $imageData = $geminiService->generateImage($imagePrompt);
                    
                    if ($imageData && isset($imageData['data']) && isset($imageData['mimeType'])) {
                        Log::info("Successfully generated image on attempt {$attempt}");
                        break;
                    }
                    
                    Log::warning("Failed to generate image data from Gemini on attempt {$attempt}/{$maxRetries}");
                }

                if (!$imageData || !isset($imageData['data']) || !isset($imageData['mimeType'])) {
                    Log::error("Failed to generate image after {$maxRetries} attempts for prompt index " . ($index + 1));
                    continue;
                }

                $decodedImage = base64_decode($imageData['data']);
                if ($decodedImage === false) {
                    Log::warning('Failed to decode a base64 image candidate on attempt ' . ($index + 1));
                    continue;
                }

                // Apply brand name + tagline overlay (all tiers), or preview watermark (free tier)
                $customer = $this->campaign->customer;
                $user = $customer->users()->first();
                $isSubscribed = $user && ($user->subscribed('default') || $user->subscription_status === 'active')
                    || $customer->subscription_status === 'active';

                try {
                    $image = Image::read($decodedImage);
                    $w = $image->width();
                    $h = $image->height();

                    if ($isSubscribed) {
                        // Brand name + tagline banner at bottom
                        $brandName = $customer->name ?? '';
                        $tagline = null;
                        if ($brandGuidelines) {
                            $usps = $brandGuidelines->unique_selling_propositions ?? [];
                            $themes = $brandGuidelines->messaging_themes ?? [];
                            $raw = $usps[0] ?? $themes[0] ?? null;
                            if ($raw) {
                                // Strip "First USP: " style prefixes and truncate
                                $raw = preg_replace('/^[^:]+:\s*/', '', $raw);
                                $tagline = mb_strlen($raw) > 45 ? mb_substr($raw, 0, 42) . '…' : $raw;
                            }
                        }

                        // Semi-transparent dark banner covering bottom 18% of image
                        $bannerH = (int) ($h * 0.18);
                        $image->drawRectangle(0, $h - $bannerH, function ($draw) use ($w, $h, $bannerH) {
                            $draw->size($w, $bannerH);
                            $draw->background('rgba(0, 0, 0, 0.65)');
                        });

                        // Resolve a font path (server system font fallback chain)
                        $fontPath = $this->resolveFont();

                        if ($brandName) {
                            $image->text($brandName, (int) ($w / 2), $h - $bannerH + (int) ($bannerH * 0.38), function ($font) use ($fontPath, $bannerH) {
                                if ($fontPath) $font->filename($fontPath);
                                $font->size((int) ($bannerH * 0.38));
                                $font->color('ffffff');
                                $font->align('center');
                                $font->valign('middle');
                            });
                        }

                        if ($tagline) {
                            $image->text($tagline, (int) ($w / 2), $h - $bannerH + (int) ($bannerH * 0.72), function ($font) use ($fontPath, $bannerH) {
                                if ($fontPath) $font->filename($fontPath);
                                $font->size((int) ($bannerH * 0.22));
                                $font->color('rgba(220, 220, 220, 1)');
                                $font->align('center');
                                $font->valign('middle');
                            });
                        }
                    } else {
                        // Free tier: "Preview" watermark bottom-right
                        $fontPath = $this->resolveFont();
                        $image->text('Preview', $w - 20, $h - 20, function ($font) use ($fontPath) {
                            if ($fontPath) $font->filename($fontPath);
                            $font->size(24);
                            $font->color('ffffff');
                            $font->align('right');
                            $font->valign('bottom');
                        });
                    }

                    $decodedImage = (string) $image->encode();
                } catch (\Exception $e) {
                    Log::warning("Failed to apply image overlay: " . $e->getMessage());
                }

                // Store the image in S3
                $extension = $this->getExtensionFromMimeType($imageData['mimeType']);
                $filename = uniqid('img_', true) . '.' . $extension;
                $storagePath = "collateral/images/{$this->campaign->id}/{$filename}";

                [$s3Path, $cloudFrontUrl] = StorageHelper::put($storagePath, $decodedImage, $imageData['mimeType']);

                Log::info("Image uploaded at path: {$s3Path}");

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
     * Resolve a usable font path for Intervention Image text rendering.
     * Falls back through a chain of common system font locations.
     */
    private function resolveFont(): ?string
    {
        $candidates = [
            public_path('fonts/Arial.ttf'),
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Get the file extension from a MIME type.
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $parts = explode('/', $mimeType);
        return end($parts) ?: 'png'; // Default to png if detection fails
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateImage failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
