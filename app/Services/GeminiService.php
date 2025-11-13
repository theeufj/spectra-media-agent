<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Currently AvaliABle Models:
 * - gemini-flash-latest | Modalities Text 
 * - gemini-2.5-pro | Modalities Text
 * - gemini-2.5-flash-image | Modalities: Images and text
 * - text-embedding-004
 * - veo-2.0-generate-001 | Modalities: Video and text
 * 
 */

class GeminiService
{
    private string $apiKey;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private int $maxRetries = 3;
    private int $initialRetryDelayMs = 1000;

    public function __construct()
    {
        $this->apiKey = env('GEMINI_API_KEY');
    }

    /**
     * Generates content using a specified Gemini model with retry mechanism.
     *
     * @param string $model The Gemini model to use (e.g., 'gemini-2.5-pro').
     * @param string $prompt The prompt to send to the model.
     * @param int $maxRetries Maximum number of retry attempts (default: 3).
     * @return array|null The generated content as an array, or null on failure.
     */
    public function generateContent(string $model, string $prompt, int $maxRetries = null): ?array
    {
        $maxRetries = $maxRetries ?? $this->maxRetries;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                ])->timeout(300)->post("{$this->baseUrl}{$model}:generateContent?key={$this->apiKey}", [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ]
                ]);

                // Check if response was successful
                if ($response->successful()) {
                    $responseData = $response->json();
                    return $responseData['candidates'][0]['content']['parts'][0] ?? null;
                }

                // Handle specific error codes
                $statusCode = $response->status();
                
                // Retry on server errors (5xx) and specific client errors
                if ($this->isRetryableError($statusCode)) {
                    $attempt++;
                    
                    if ($attempt < $maxRetries) {
                        $delayMs = $this->calculateBackoffDelay($attempt);
                        Log::warning("GeminiService: Retryable error ({$statusCode}) on attempt {$attempt}/{$maxRetries}. Retrying in {$delayMs}ms...", [
                            'model' => $model,
                            'response' => $response->body(),
                        ]);
                        usleep($delayMs * 1000); // Convert ms to microseconds
                        continue;
                    }
                }

                // Non-retryable error or max retries reached
                Log::error("GeminiService: Failed to generate content from model {$model} (Status: {$statusCode}): " . $response->body(), [
                    'model' => $model,
                    'attempt' => $attempt + 1,
                    'max_retries' => $maxRetries,
                ]);
                return null;

            } catch (\Exception $e) {
                $attempt++;
                
                if ($attempt < $maxRetries) {
                    $delayMs = $this->calculateBackoffDelay($attempt);
                    Log::warning("GeminiService: Exception on attempt {$attempt}/{$maxRetries}: " . $e->getMessage() . ". Retrying in {$delayMs}ms...", [
                        'model' => $model,
                        'exception' => get_class($e),
                    ]);
                    usleep($delayMs * 1000);
                    continue;
                }

                Log::error("GeminiService: Exception during content generation from model {$model} (Max retries reached): " . $e->getMessage(), [
                    'model' => $model,
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'exception' => $e,
                ]);
                return null;
            }
        }

        return null;
    }

    /**
     * Determines if an HTTP status code warrants a retry.
     * Retries on 5xx errors and specific 4xx errors like 429 (too many requests).
     *
     * @param int $statusCode
     * @return bool
     */
    private function isRetryableError(int $statusCode): bool
    {
        // Retry on server errors (5xx)
        if ($statusCode >= 500 && $statusCode < 600) {
            return true;
        }

        // Retry on rate limiting (429) and some other transient client errors
        if ($statusCode === 429) {
            return true;
        }

        return false;
    }

    /**
     * Calculates exponential backoff delay with jitter.
     * Formula: baseDelay * (2 ^ (attempt - 1)) + random jitter
     *
     * @param int $attempt The current attempt number (1-indexed)
     * @return int Delay in milliseconds
     */
    private function calculateBackoffDelay(int $attempt): int
    {
        // Exponential backoff: 1s, 2s, 4s, 8s, etc.
        $delay = $this->initialRetryDelayMs * (2 ** ($attempt - 1));
        
        // Add jitter (±10% of delay) to prevent thundering herd
        $jitter = rand(0, (int)($delay * 0.1 * 2)) - ($delay * 0.1);
        $finalDelay = max(100, $delay + $jitter); // Ensure minimum 100ms delay
        
        return (int)$finalDelay;
    }

    /**
     * Generates embeddings for a given text using a specified Gemini embedding model.
     *
     * @param string $model The Gemini embedding model to use (e.g., 'text-embedding-004').
     * @param string $text The text to embed.
     * @return array|null The embedding values as an array, or null on failure.
     */
    public function embedContent(string $model, string $text): ?array
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(300)->post("{$this->baseUrl}{$model}:embedContent?key={$this->apiKey}", [
                'model' => "models/{$model}",
                'content' => [
                    'parts' => [
                        ['text' => $text]
                    ]
                ]
            ]);

            if ($response->failed()) {
                Log::error("GeminiService: Failed to get embedding from model {$model}: " . $response->body());
                return null;
            }

            return $response->json()['embedding']['values'] ?? null;
        } catch (\Exception $e) {
            Log::error("GeminiService: Exception during embedding generation from model {$model}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Generates an image based on a prompt using a specified Gemini image generation model.
     *
     * @param string $prompt The prompt for image generation.
     * @param string $model The Gemini image generation model to use (e.g., 'gemini-2.5-flash-image').
     * @param int $candidateCount The number of images to generate.
     * @return array|null An array of image data arrays, or null on failure.
     */
    public function generateImage(string $prompt, string $model = 'gemini-2.5-flash-image', string $imageSize = '1K', int $candidateCount = 1): ?array
    {
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $prompt]],
                ]
            ],
            'generationConfig' => [
                'responseModalities' => ['IMAGE', 'TEXT'],
                'imageConfig' => ['imageSize' => $imageSize],
                'candidateCount' => $candidateCount,
            ],
        ];

        return $this->sendImageRequest($model, $payload);
    }

    /**
     * Refines an existing image based on a new prompt and context images.
     *
     * @param string $prompt The refinement prompt.
     * @param array $contextImages An array of context images, each with 'mime_type' and 'data' (base64).
     * @param string $model The image generation model.
     * @param string $imageSize The desired image size.
     * @return array|null A single generated image data array, or null on failure.
     */
    public function refineImage(string $prompt, array $contextImages, string $model = 'gemini-2.5-flash-image', string $imageSize = '1K'): ?array
    {
        $parts = [['text' => $prompt]];
        foreach ($contextImages as $image) {
            if (isset($image['mime_type']) && isset($image['data'])) {
                $parts[] = ['inline_data' => ['mime_type' => $image['mime_type'], 'data' => $image['data']]];
            }
        }

        $payload = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'responseModalities' => ['IMAGE', 'TEXT'],
                'imageConfig' => ['imageSize' => $imageSize],
                'candidateCount' => 1, // Refinement should produce one image
            ],
        ];

        $result = $this->sendImageRequest($model, $payload);
        return $result ? $result[0] : null; // Return the first (and only) image
    }

    /**
     * Private helper method to send requests to the image generation endpoint.
     */
    private function sendImageRequest(string $model, array $payload): ?array
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(300)->post("{$this->baseUrl}{$model}:streamGenerateContent?key={$this->apiKey}", $payload);

            if ($response->failed()) {
                Log::error("GeminiService: Failed to generate image from model {$model}: " . $response->body());
                return null;
            }

            $responseData = $response->json();
            $images = [];

            if (isset($responseData['candidates']) && is_array($responseData['candidates'])) {
                foreach ($responseData['candidates'] as $candidate) {
                    if (isset($candidate['content']['parts'][0]['inlineData'])) {
                        $inlineData = $candidate['content']['parts'][0]['inlineData'];
                        $images[] = [
                            'data' => $inlineData['data'] ?? null,
                            'mimeType' => $inlineData['mimeType'] ?? null,
                        ];
                    }
                }
            }

            if (empty($images)) {
                Log::warning("GeminiService: No inlineData found in image generation response.", ['response' => $responseData]);
                return null;
            }

            return $images;

        } catch (\Exception $e) {
            Log::error("GeminiService: Exception during image generation from model {$model}: " . $e->getMessage(), ['exception' => $e]);
            return null;
        }
    }

    /**
     * Starts a long-running video generation operation using a specified Gemini model.
     *
// ... existing code ...
        try {
            $parts = [['text' => $prompt]];

            // Add context images to the prompt if provided
            foreach ($contextImages as $image) {
                if (isset($image['mime_type']) && isset($image['data'])) {
                    $parts[] = [
                        'inline_data' => [
                            'mime_type' => $image['mime_type'],
                            'data' => $image['data'],
                        ]
                    ];
                }
            }

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(300)->post("{$this->baseUrl}{$model}:streamGenerateContent?key={$this->apiKey}", [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => $parts,
                    ]
                ],
                'generationConfig' => [
                    'responseModalities' => ['IMAGE', 'TEXT'],
                    'imageConfig' => [
                        'imageSize' => $imageSize,
                    ],
                    'candidateCount' => $candidateCount,
                ],
            ]);

            if ($response->failed()) {
                Log::error("GeminiService: Failed to generate image from model {$model}: " . $response->body());
                return null;
            }

            $responseData = $response->json();
            $images = [];

            if (isset($responseData['candidates']) && is_array($responseData['candidates'])) {
                foreach ($responseData['candidates'] as $candidate) {
                    if (isset($candidate['content']['parts'][0]['inlineData'])) {
                        $inlineData = $candidate['content']['parts'][0]['inlineData'];
                        $images[] = [
                            'data' => $inlineData['data'] ?? null,
                            'mimeType' => $inlineData['mimeType'] ?? null,
                        ];
                    }
                }
            }

            if (empty($images)) {
                Log::warning("GeminiService: No inlineData found in image generation response.", [
                    'response' => $responseData,
                ]);
                return null;
            }

            return $images;

        } catch (\Exception $e) {
            Log::error("GeminiService: Exception during image generation from model {$model}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Starts a long-running video generation operation using a specified Gemini model.
     *
     * @param string $prompt The prompt for video generation.
     * @param string $model The Gemini video generation model to use (e.g., 'veo-2.0-generate-001').
     * @param array $parameters Additional generation parameters (e.g., aspectRatio, durationSeconds).
     * @return string|null The operation name if successful, or null on failure.
     */
    public function startVideoGeneration(string $prompt, string $model = 'veo-2.0-generate-001', array $parameters = []): ?string
    {
        try {
            $requestBody = [
                'instances' => [
                    [
                        'prompt' => $prompt,
                    ]
                ],
                'parameters' => array_merge([
                    'aspectRatio' => '16:9',
                    'sampleCount' => 1,
                    'durationSeconds' => 8,
                    'personGeneration' => 'ALLOW_ALL',
                ], $parameters),
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(300)->post("{$this->baseUrl}{$model}:predictLongRunning?key={$this->apiKey}", $requestBody);

            if ($response->failed()) {
                Log::error("GeminiService: Failed to start video generation from model {$model}: " . $response->body());
                return null;
            }

            return $response->json()['name'] ?? null;
        } catch (\Exception $e) {
            Log::error("GeminiService: Exception during video generation start from model {$model}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Checks the status of a long-running Gemini operation.
     *
     * @param string $operationName The name of the operation to check.
     * @return array|null The operation response if available, or null if not done or on failure.
     */
    public function checkVideoGenerationStatus(string $operationName): ?array
    {
        try {
            $response = Http::timeout(300)->get("{$this->baseUrl}{$operationName}?key={$this->apiKey}");

            if ($response->failed()) {
                Log::error("GeminiService: Failed to check operation status for {$operationName}: " . $response->body());
                return null;
            }

            $responseData = $response->json();

            if (isset($responseData['done']) && $responseData['done'] === true) {
                return $responseData;
            }

            return null; // Operation not yet done
        } catch (\Exception $e) {
            Log::error("GeminiService: Exception during operation status check for {$operationName}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return null;
        }
    }
}
