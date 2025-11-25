<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Currently AvaliABle Models:
 * - gemini-flash-latest | Modalities Text 
 * - gemini-2.5-pro | Modalities Text
 * - gemini-3-pro-image-preview | Modalities: Images and text
 * - text-embedding-004
 * - veo-2.0-generate-001 | Modalities: Video and text
 * - gemini-3-pro-preview | Modalities: Text
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
        $this->apiKey = config('services.gemini.api_key');
    }

    /**
     * Generates content using a specified Gemini model with retry mechanism.
     *
     * @param string $model The Gemini model to use (e.g., 'gemini-2.5-pro').
     * @param string $prompt The prompt to send to the model.
     * @param array $config Generation configuration (temperature, maxOutputTokens, etc.).
     * @param string|null $systemInstruction Optional system instruction.
     * @param bool $enableThinking Enable extended thinking (HIGH level).
     * @param bool $enableGoogleSearch Enable Google Search tool.
     * @param int $maxRetries Maximum number of retry attempts (default: 3).
     * @return array|null The generated content with 'text' key, or null on failure.
     */
    public function generateContent(
        string $model, 
        string $prompt, 
        array $config = [],
        ?string $systemInstruction = null,
        bool $enableThinking = false,
        bool $enableGoogleSearch = false,
        int $maxRetries = null,
        ?string $imageBase64 = null,
        string $imageMimeType = 'image/jpeg'
    ): ?array
    {
        $maxRetries = $maxRetries ?? $this->maxRetries;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $parts = [['text' => $prompt]];

                if ($imageBase64) {
                    // Ensure base64 string is clean
                    $cleanBase64 = preg_replace('#^data:image/\w+;base64,#i', '', $imageBase64);
                    
                    $parts[] = [
                        'inlineData' => [
                            'mimeType' => $imageMimeType,
                            'data' => $cleanBase64
                        ]
                    ];
                }

                $payload = [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => $parts
                        ]
                    ],
                    'generationConfig' => array_merge([
                        'temperature' => 1,
                        'topP' => 0.95,
                        'topK' => 40,
                        'maxOutputTokens' => 8192,
                    ], $config)
                ];

                // Add thinking config if enabled
                if ($enableThinking) {
                    $payload['generationConfig']['thinkingConfig'] = [
                        'thinkingLevel' => 'HIGH'
                    ];
                }

                // Add system instruction if provided
                if ($systemInstruction) {
                    $payload['systemInstruction'] = [
                        'parts' => [
                            ['text' => $systemInstruction]
                        ]
                    ];
                }

                // Add Google Search tool if enabled
                if ($enableGoogleSearch) {
                    $payload['tools'] = [
                        ['googleSearch' => (object)[]]
                    ];
                }

                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ])->timeout(300)->post("{$this->baseUrl}{$model}:streamGenerateContent", $payload);

                // Check if response was successful
                if ($response->successful()) {
                    $responseData = $response->json();
                    
                    // Handle streaming response (array of chunks)
                    if (is_array($responseData)) {
                        $textContent = null;
                        
                        // Find the last chunk with actual text content (not thoughts)
                        foreach ($responseData as $chunk) {
                            $parts = $chunk['candidates'][0]['content']['parts'] ?? [];
                            foreach ($parts as $part) {
                                if (isset($part['text']) && !isset($part['thought'])) {
                                    $textContent = ($textContent ?? '') . $part['text'];
                                }
                            }
                        }
                        
                        if ($textContent) {
                            return ['text' => $textContent];
                        }
                    }
                    
                    Log::error("GeminiService: No text content found in response", [
                        'model' => $model,
                        'response' => $responseData
                    ]);
                    return null;
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
     * Generate content with system instruction, thinking, and Google Search
     *
     * @param string $model
     * @param string $systemInstruction
     * @param string $prompt
     * @param array $config
     * @return array|null
     */
    public function generateWithThinkingAndSearch(
        string $model,
        string $systemInstruction,
        string $prompt,
        array $config = []
    ): ?array {
        return $this->generateContent(
            $model, 
            $prompt, 
            $config, 
            $systemInstruction, 
            true,  // Enable thinking
            true   // Enable Google Search
        );
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
                'x-goog-api-key' => $this->apiKey,
            ])->timeout(300)->post("{$this->baseUrl}{$model}:embedContent", [
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
    public function generateImage(string $prompt, string $model = 'gemini-3-pro-image-preview', string $imageSize = '1K'): ?array
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
                'imageConfig' => ['image_size' => $imageSize], // Corrected to snake_case
            ],
        ];

        $result = $this->sendImageRequest($model, $payload);
        return $result ? $result[0] : null; // Return the first image
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
    public function refineImage(string $prompt, array $contextImages, string $model = 'gemini-3-pro-image-preview', string $imageSize = '1K'): ?array
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
                'imageConfig' => ['image_size' => $imageSize], // Corrected to snake_case
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
            // Use streamGenerateContent endpoint and correct the image_size key
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->apiKey,
            ])->timeout(300)->post("{$this->baseUrl}{$model}:streamGenerateContent", $payload);

            if ($response->failed()) {
                $errorBody = $response->json();
                $errorCode = $errorBody[0]['error']['code'] ?? $response->status();
                $errorMessage = $errorBody[0]['error']['message'] ?? $response->body();
                $errorStatus = $errorBody[0]['error']['status'] ?? 'UNKNOWN';
                
                Log::error("GeminiService: Failed to generate image from model {$model}", [
                    'status_code' => $response->status(),
                    'error_code' => $errorCode,
                    'error_status' => $errorStatus,
                    'error_message' => $errorMessage,
                    'full_response' => $response->body()
                ]);
                
                // Check if this is a retryable error (500, 503, etc.)
                if (in_array($response->status(), [500, 502, 503, 504]) || $errorStatus === 'INTERNAL') {
                    Log::warning("GeminiService: Retryable error detected for model {$model}");
                }
                
                return null;
            }

            // The response is a stream (an array of JSON objects). We need to find the part with the image data.
            $responseData = $response->json();
            $images = [];

            if (is_array($responseData)) {
                foreach ($responseData as $chunk) {
                    if (isset($chunk['candidates'][0]['content']['parts'][0]['inlineData'])) {
                        $inlineData = $chunk['candidates'][0]['content']['parts'][0]['inlineData'];
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
     * Starts a long-running video generation operation using a specified Gemini model.
     *
     * @param string $prompt The prompt for video generation.
     * @param string $model The Gemini video generation model to use (e.g., 'veo-2.0-generate-001').
     * @param array $parameters Additional generation parameters (e.g., aspectRatio, durationSeconds).
     * @return string|null The operation name if successful, or null on failure.
     */
    public function startVideoGeneration(string $prompt, string $model = 'veo-3.1-generate-preview', array $parameters = []): ?string
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
                'x-goog-api-key' => $this->apiKey,
            ])->timeout(300)->post("{$this->baseUrl}{$model}:predictLongRunning", $requestBody);

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
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->apiKey,
            ])->timeout(300)->get("https://generativelanguage.googleapis.com/v1beta/{$operationName}");

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

    /**
     * Downloads video data from a given URI.
     *
     * @param string $uri The URI to download the video from.
     * @return string|null The video data as a string, or null on failure.
     */
    public function downloadVideo(string $uri): ?string
    {
        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $this->apiKey,
            ])->timeout(300)->get($uri);

            if ($response->failed()) {
                Log::error("GeminiService: Failed to download video from {$uri}: " . $response->body());
                return null;
            }

            return $response->body();
        } catch (\Exception $e) {
            Log::error("GeminiService: Exception during video download from {$uri}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Extend a Veo-generated video by up to 7 seconds using Veo 3.1.
     * 
     * Requirements:
     * - Input video must be Veo-generated (from a previous operation response)
     * - Video must be 141 seconds or less
     * - Aspect ratio: 9:16 or 16:9
     * - Resolution: 720p
     * - Can extend up to 20 times (cumulative 148 seconds max)
     * 
     * @param string $videoUri The URI of the Veo-generated video to extend (e.g., from operation.response.generated_videos[0].video)
     * @param string $prompt The prompt describing how to extend the video
     * @param array $parameters Additional parameters (aspectRatio, durationSeconds, etc.)
     * @return string|null The operation name if successful, or null on failure.
     */
    public function extendVideo(string $videoUri, string $prompt, array $parameters = []): ?string
    {
        try {
            // Extract file name from URI for the video parameter
            // The videoUri should be in the format returned by Gemini (e.g., files/xyz)
            $videoFileName = $this->extractFileNameFromUri($videoUri);
            
            if (!$videoFileName) {
                Log::error("GeminiService: Invalid video URI format for extension: {$videoUri}");
                return null;
            }

            $requestBody = [
                'instances' => [
                    [
                        'prompt' => $prompt,
                        'video' => [
                            'fileUri' => $videoFileName
                        ]
                    ]
                ],
                'parameters' => array_merge([
                    'aspectRatio' => '16:9',
                    'sampleCount' => 1,
                    'durationSeconds' => 7, // Extension duration (up to 7 seconds)
                    'personGeneration' => 'ALLOW_ALL',
                ], $parameters),
            ];

            Log::info("GeminiService: Extending video with URI: {$videoFileName}");
            Log::info("GeminiService: Extension prompt: {$prompt}");

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->apiKey,
            ])->timeout(300)->post("{$this->baseUrl}veo-3.1-generate-preview:predictLongRunning", $requestBody);

            if ($response->failed()) {
                Log::error("GeminiService: Failed to extend video: " . $response->body());
                return null;
            }

            $operationName = $response->json()['name'] ?? null;
            Log::info("GeminiService: Video extension started successfully. Operation: {$operationName}");

            return $operationName;
        } catch (\Exception $e) {
            Log::error("GeminiService: Exception during video extension: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Extract the file name/identifier from a Gemini video URI.
     * Handles both full URIs and file identifiers.
     * 
     * @param string $uri The video URI
     * @return string|null The extracted file identifier
     */
    private function extractFileNameFromUri(string $uri): ?string
    {
        // If already in correct format (files/xyz or just the ID)
        if (preg_match('#^files/[a-zA-Z0-9]+$#', $uri)) {
            return $uri;
        }

        // Extract from full download URI (e.g., https://generativelanguage.googleapis.com/v1beta/files/xyz:download?alt=media)
        if (preg_match('#/files/([a-zA-Z0-9]+)(?::|/)#', $uri, $matches)) {
            return 'files/' . $matches[1];
        }

        // If it's just an ID
        if (preg_match('#^[a-zA-Z0-9]+$#', $uri)) {
            return 'files/' . $uri;
        }

        return null;
    }
}
