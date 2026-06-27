<?php

namespace App\Services;

use App\Models\AiCost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Google\Auth\CredentialsLoader;

/**
 * Currently Available Models (Verified March 2026 from ai.google.dev/gemini-api/docs/models):
 *
 * --- GEMINI 3 SERIES (Preview) ---
 * - gemini-3.1-pro-preview        | Advanced intelligence, agentic & vibe coding (replaces shut-down gemini-3-pro-preview)
 * - gemini-3.5-flash              | Latest stable flash — best price-performance (confirmed on Vertex AI global)
 * - gemini-3-flash-preview        | Earlier flash preview
 * - gemini-3.1-flash-lite-preview | Budget / Ultra-low latency (confirmed on Vertex AI global)
 * - gemini-3-pro-image-preview    | Nano Banana Pro - Studio-quality 4K image generation
 * - gemini-3.1-flash-image-preview| Nano Banana 2 - High-efficiency image generation (confirmed on Vertex AI global)
 *
 * --- GEMINI 2.5 SERIES (Stable) ---
 * - gemini-2.5-pro              | Deep reasoning & coding (stable)
 * - gemini-2.5-flash            | Best price-performance with reasoning (stable)
 * - gemini-2.5-flash-lite       | Fastest, most budget-friendly multimodal
 * - gemini-2.5-flash-image      | Nano Banana - Native image generation & editing
 *
 * --- EMBEDDING ---
 * - gemini-embedding-2-preview   | Multimodal embeddings (text/image/video/audio/PDF) - 3072 dims
 * - gemini-embedding-001         | Text embeddings for semantic search & RAG - 768 dims (legacy)
 *
 * --- VIDEO ---
 * - veo-3.1-generate-preview     | State-of-the-art cinematic video with synced audio
 *
 * --- DEPRECATED (migrate away) ---
 * - gemini-2.0-flash             | Deprecated - use gemini-2.5-flash
 * - gemini-2.0-flash-lite        | Deprecated - use gemini-2.5-flash-lite
 * - gemini-3-pro-preview         | Shut down March 9, 2026 - use gemini-3.1-pro-preview or gemini-2.5-pro
 * - text-embedding-004           | Legacy - use gemini-embedding-2-preview
 */

class GeminiService
{
    private string $project;
    private string $location;
    // Global endpoint — required for Gemini 3.x / 2.5 text and image models on Vertex AI
    private string $vertexBaseUrl;
    // Regional endpoint — Veo video models require a specific region (not global)
    private string $videoBaseUrl;
    // Gemini Files API — used for video upload/extend (no Vertex AI equivalent yet)
    private string $geminiBaseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private int $maxRetries = 3;
    private int $initialRetryDelayMs = 1000;

    public function __construct()
    {
        $this->project  = config('services.google.project_id');
        $this->location = config('services.google.location', 'us-central1');

        $this->vertexBaseUrl = "https://aiplatform.googleapis.com/v1/projects/{$this->project}/locations/global/publishers/google/models/";
        $this->videoBaseUrl  = "https://aiplatform.googleapis.com/v1/projects/{$this->project}/locations/{$this->location}/publishers/google/models/";
    }

    // ─── Auth ────────────────────────────────────────────────────────────────

    private function getAccessToken(): string
    {
        // Tokens expire after 60 min; cache for 50 to ensure we never send a stale one.
        return Cache::remember('gcp_vertex_access_token', 3000, function () {
            // Laravel 12 does not call putenv() for .env values, so google/auth's
            // getenv('GOOGLE_APPLICATION_CREDENTIALS') check always returns false.
            // Load the credentials file explicitly via the Laravel config instead.
            $credentialsPath = config('services.google.credentials_path');
            $keyData         = json_decode(file_get_contents($credentialsPath), true);
            $credentials     = CredentialsLoader::makeCredentials(
                ['https://www.googleapis.com/auth/cloud-platform'],
                $keyData
            );
            $token = $credentials->fetchAuthToken();
            return $token['access_token'];
        });
    }

    private function authHeaders(): array
    {
        return [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];
    }

    private function flushTokenCache(): void
    {
        Cache::forget('gcp_vertex_access_token');
    }

    // ─── Text generation ─────────────────────────────────────────────────────

    /**
     * Generates content using a specified Gemini model with retry and fallback.
     *
     * @param string      $model            The Gemini model to use.
     * @param string      $prompt           The user prompt.
     * @param array       $config           Generation config overrides (temperature, maxOutputTokens, etc.).
     * @param string|null $systemInstruction Optional system instruction.
     * @param bool        $enableThinking   Enable extended thinking (HIGH level).
     * @param bool        $enableGoogleSearch Enable Google Search grounding tool.
     * @param int|null    $maxRetries       Override default retry count.
     * @param string|null $imageBase64      Optional base64-encoded image for multimodal input.
     * @param string      $imageMimeType    MIME type of the image.
     * @param array       $context          Tracking context: campaign_id, customer_id, operation, task_type.
     *                                      task_type applies a preset temperature/topP when not overridden in $config.
     */
    public function generateContent(
        string $model,
        string $prompt,
        array $config = [],
        ?string $systemInstruction = null,
        bool $enableThinking = false,
        bool $enableGoogleSearch = false,
        ?int $maxRetries = null,
        ?string $imageBase64 = null,
        string $imageMimeType = 'image/jpeg',
        array $context = []
    ): ?array {
        $maxRetries = $maxRetries ?? $this->maxRetries;
        $startTime  = hrtime(true);

        $result = $this->attemptGenerate(
            $model, $prompt, $config, $systemInstruction,
            $enableThinking, $enableGoogleSearch, $maxRetries,
            $imageBase64, $imageMimeType, $context, $startTime
        );

        // Model fallback: if primary fails, try the next model in the chain.
        // Use array access — dot notation breaks for model names that contain dots.
        if ($result === null) {
            $fallback = (config('ai.fallback_chain') ?? [])[$model] ?? null;
            if ($fallback) {
                Log::warning("GeminiService: Primary model {$model} failed. Falling back to {$fallback}.");
                $result = $this->attemptGenerate(
                    $fallback, $prompt, $config, $systemInstruction,
                    $enableThinking, $enableGoogleSearch, $maxRetries,
                    $imageBase64, $imageMimeType,
                    array_merge($context, ['fallback_from' => $model]),
                    hrtime(true)
                );
            }
        }

        return $result;
    }

    private function attemptGenerate(
        string $model,
        string $prompt,
        array $config,
        ?string $systemInstruction,
        bool $enableThinking,
        bool $enableGoogleSearch,
        int $maxRetries,
        ?string $imageBase64,
        string $imageMimeType,
        array $context,
        int $startTime
    ): ?array {
        $attempt = 0;

        // Apply task-type preset for temperature/topP, unless caller explicitly set them.
        // Use array access — task types could theoretically contain dots.
        $taskType    = $context['task_type'] ?? null;
        $taskPreset  = $taskType ? ((config('ai.task_config') ?? [])[$taskType] ?? []) : [];
        $defaultConfig = array_merge(
            ['temperature' => 1, 'topP' => 0.95, 'topK' => 40, 'maxOutputTokens' => 8192],
            $taskPreset,
            $config  // caller values always win
        );

        while ($attempt < $maxRetries) {
            try {
                $parts = [['text' => $prompt]];

                if ($imageBase64) {
                    $cleanBase64 = preg_replace('#^data:image/\w+;base64,#i', '', $imageBase64);
                    $parts[] = [
                        'inlineData' => [
                            'mimeType' => $imageMimeType,
                            'data'     => $cleanBase64,
                        ]
                    ];
                }

                $payload = [
                    'contents'         => [['role' => 'user', 'parts' => $parts]],
                    'generationConfig' => $defaultConfig,
                ];

                if ($enableThinking) {
                    $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => 'HIGH'];
                }

                if ($systemInstruction) {
                    $payload['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
                }

                if ($enableGoogleSearch) {
                    $payload['tools'] = [['googleSearch' => (object)[]]];
                }

                $response = Http::withHeaders($this->authHeaders())
                    ->timeout(300)
                    ->post("{$this->vertexBaseUrl}{$model}:streamGenerateContent", $payload);

                if ($response->successful()) {
                    $responseData = $response->json();

                    if (is_array($responseData)) {
                        $textContent   = null;
                        $usageMetadata = [];

                        foreach ($responseData as $chunk) {
                            $chunkParts = $chunk['candidates'][0]['content']['parts'] ?? [];
                            foreach ($chunkParts as $part) {
                                if (isset($part['text']) && !isset($part['thought'])) {
                                    $textContent = ($textContent ?? '') . $part['text'];
                                }
                            }
                            // usageMetadata appears in the final chunk
                            if (!empty($chunk['usageMetadata'])) {
                                $usageMetadata = $chunk['usageMetadata'];
                            }
                        }

                        if ($textContent) {
                            $durationMs = (int) ((hrtime(true) - $startTime) / 1e6);
                            $this->recordCost($model, 'generateContent', $usageMetadata, $durationMs, $context);
                            return ['text' => $textContent];
                        }
                    }

                    Log::error("GeminiService: No text content found in response", [
                        'model'    => $model,
                        'response' => $responseData,
                    ]);
                    return null;
                }

                $statusCode = $response->status();

                if ($statusCode === 401) {
                    $this->flushTokenCache();
                }

                if ($this->isRetryableError($statusCode)) {
                    $attempt++;
                    if ($attempt < $maxRetries) {
                        $delayMs = $this->calculateBackoffDelay($attempt);
                        Log::warning("GeminiService: Retryable error ({$statusCode}) on attempt {$attempt}/{$maxRetries}. Retrying in {$delayMs}ms...", [
                            'model'    => $model,
                            'response' => $response->body(),
                        ]);
                        usleep($delayMs * 1000);
                        continue;
                    }
                }

                Log::error("GeminiService: Failed to generate content from model {$model} (Status: {$statusCode}): " . $response->body(), [
                    'model'       => $model,
                    'attempt'     => $attempt + 1,
                    'max_retries' => $maxRetries,
                ]);
                return null;

            } catch (\Exception $e) {
                $attempt++;
                if ($attempt < $maxRetries) {
                    $delayMs = $this->calculateBackoffDelay($attempt);
                    Log::warning("GeminiService: Exception on attempt {$attempt}/{$maxRetries}: " . $e->getMessage() . ". Retrying in {$delayMs}ms...", [
                        'model'     => $model,
                        'exception' => get_class($e),
                    ]);
                    usleep($delayMs * 1000);
                    continue;
                }
                Log::error("GeminiService: Exception during content generation from model {$model} (Max retries reached): " . $e->getMessage(), [
                    'model'       => $model,
                    'attempt'     => $attempt,
                    'max_retries' => $maxRetries,
                    'exception'   => $e,
                ]);
                return null;
            }
        }

        return null;
    }

    /**
     * Resolve a model key ('default', 'pro', 'lite') or a task type to the
     * configured model string. Pass context['task_type'] to also apply the
     * right temperature preset automatically.
     *
     * Usage in agents:
     *   $model = $this->gemini->resolveModel('strategy');
     *   $this->gemini->generateContent($model, $prompt, [], null, false, false, null, null, 'image/jpeg',
     *       ['task_type' => 'strategy', 'customer_id' => $customer->id]);
     */
    public function resolveModel(string $taskTypeOrKey): string
    {
        // Direct model key ('default', 'pro', 'lite', 'image', 'video', 'embedding')
        $fromModels = config("ai.models.{$taskTypeOrKey}");
        if ($fromModels) {
            return $fromModels;
        }

        // Task type → model tier → model string (task types don't contain dots, safe to use dot-notation)
        $tier = config("ai.task_models.{$taskTypeOrKey}");
        if ($tier) {
            return config("ai.models.{$tier}", config('ai.models.default'));
        }

        // Assume it's already a raw model string
        return $taskTypeOrKey;
    }

    /**
     * Generate content with system instruction, thinking, and Google Search.
     */
    public function generateWithThinkingAndSearch(
        string $model,
        string $systemInstruction,
        string $prompt,
        array $config = [],
        array $context = []
    ): ?array {
        return $this->generateContent(
            $model,
            $prompt,
            $config,
            $systemInstruction,
            true,
            true,
            null,
            null,
            'image/jpeg',
            $context
        );
    }

    // ─── Embeddings ──────────────────────────────────────────────────────────

    /**
     * Generates embeddings for a given text using a specified Gemini embedding model.
     */
    public function embedContent(string $model, string $text, array $context = []): ?array
    {
        $startTime = hrtime(true);

        // gemini-embedding-2-preview uses embedContent on the regional endpoint.
        // Older models (gemini-embedding-001, text-embedding-*) use predict on the global endpoint.
        $isGeminiEmbedding2 = str_starts_with($model, 'gemini-embedding-2');

        try {
            if ($isGeminiEmbedding2) {
                $url      = "{$this->videoBaseUrl}{$model}:embedContent";
                $payload  = ['content' => ['parts' => [['text' => $text]]]];
            } else {
                $url     = "{$this->vertexBaseUrl}{$model}:predict";
                $payload = ['instances' => [['content' => $text]]];
            }

            $response = Http::withHeaders($this->authHeaders())
                ->timeout(300)
                ->post($url, $payload);

            if ($response->failed()) {
                Log::error("GeminiService: Failed to get embedding from model {$model}: " . $response->body());
                return null;
            }

            $durationMs = (int) ((hrtime(true) - $startTime) / 1e6);
            $approxTokens = (int) (strlen($text) / 4);
            $this->recordCost($model, 'embedContent', ['promptTokenCount' => $approxTokens], $durationMs, $context);

            if ($isGeminiEmbedding2) {
                // embedContent response: embedding.values
                return $response->json()['embedding']['values'] ?? null;
            }

            // predict response: predictions[0].embeddings.values
            return $response->json()['predictions'][0]['embeddings']['values'] ?? null;
        } catch (\Exception $e) {
            Log::error("GeminiService: Exception during embedding generation from model {$model}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    // ─── Image generation ────────────────────────────────────────────────────

    /**
     * Generates an image based on a prompt using a specified Gemini image generation model.
     */
    public function generateImage(string $prompt, string $model = 'gemini-3.1-flash-image-preview', string $imageSize = '1K', array $context = []): ?array
    {
        $payload = [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $prompt]],
                ]
            ],
            'generationConfig' => [
                'responseModalities' => ['IMAGE', 'TEXT'],
                'imageConfig'        => ['image_size' => $imageSize],
            ],
        ];

        $result = $this->sendImageRequest($model, $payload, $context);
        return $result ? $result[0] : null;
    }

    /**
     * Refines an existing image based on a new prompt and context images.
     */
    public function refineImage(string $prompt, array $contextImages, string $model = 'gemini-3.1-flash-image-preview', string $imageSize = '1K', array $context = []): ?array
    {
        $parts = [['text' => $prompt]];
        foreach ($contextImages as $image) {
            if (isset($image['mime_type']) && isset($image['data'])) {
                $parts[] = ['inline_data' => ['mime_type' => $image['mime_type'], 'data' => $image['data']]];
            }
        }

        $payload = [
            'contents'         => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'responseModalities' => ['IMAGE', 'TEXT'],
                'imageConfig'        => ['image_size' => $imageSize],
                'candidateCount'     => 1,
            ],
        ];

        $result = $this->sendImageRequest($model, $payload, $context);
        return $result ? $result[0] : null;
    }

    private function sendImageRequest(string $model, array $payload, array $context = []): ?array
    {
        $startTime = hrtime(true);

        try {
            $response = Http::withHeaders($this->authHeaders())
                ->timeout(600)
                ->post("{$this->vertexBaseUrl}{$model}:streamGenerateContent", $payload);

            if ($response->failed()) {
                $errorBody    = $response->json();
                $errorCode    = $errorBody[0]['error']['code']    ?? $response->status();
                $errorMessage = $errorBody[0]['error']['message'] ?? $response->body();
                $errorStatus  = $errorBody[0]['error']['status']  ?? 'UNKNOWN';

                Log::error("GeminiService: Failed to generate image from model {$model}", [
                    'status_code'   => $response->status(),
                    'error_code'    => $errorCode,
                    'error_status'  => $errorStatus,
                    'error_message' => $errorMessage,
                ]);
                return null;
            }

            $responseData  = $response->json();
            $images        = [];
            $usageMetadata = [];

            if (is_array($responseData)) {
                foreach ($responseData as $chunk) {
                    if (isset($chunk['candidates'][0]['content']['parts'][0]['inlineData'])) {
                        $inlineData = $chunk['candidates'][0]['content']['parts'][0]['inlineData'];
                        $images[] = [
                            'data'     => $inlineData['data']     ?? null,
                            'mimeType' => $inlineData['mimeType'] ?? null,
                        ];
                    }
                    if (!empty($chunk['usageMetadata'])) {
                        $usageMetadata = $chunk['usageMetadata'];
                    }
                }
            }

            if (empty($images)) {
                Log::warning("GeminiService: No inlineData found in image generation response.", ['response' => $responseData]);
                return null;
            }

            $durationMs = (int) ((hrtime(true) - $startTime) / 1e6);
            $this->recordCost($model, 'generateImage', $usageMetadata, $durationMs, $context);

            return $images;

        } catch (\Exception $e) {
            Log::error("GeminiService: Exception during image generation from model {$model}: " . $e->getMessage(), ['exception' => $e]);
            return null;
        }
    }

    // ─── Video generation (Vertex AI) ────────────────────────────────────────

    /**
     * Starts a long-running Veo video generation operation via Vertex AI.
     *
     * @return string|null The Vertex AI operation name, or null on failure.
     */
    public function startVideoGeneration(string $prompt, string $model = 'veo-3.1-generate-preview', array $parameters = [], array $context = []): ?string
    {
        try {
            $requestBody = [
                'instances' => [['prompt' => $prompt]],
                'parameters' => array_merge([
                    'aspectRatio'      => '16:9',
                    'sampleCount'      => 1,
                    'durationSeconds'  => 8,
                    'personGeneration' => 'ALLOW_ALL',
                ], $parameters),
            ];

            $response = Http::withHeaders($this->authHeaders())
                ->timeout(300)
                ->post("{$this->videoBaseUrl}{$model}:predictLongRunning", $requestBody);

            if ($response->failed()) {
                Log::error("GeminiService: Failed to start video generation from model {$model}", [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                    'model'  => $model,
                ]);
                return null;
            }

            $json = $response->json();
            Log::info("GeminiService: Veo response keys: " . implode(', ', array_keys($json ?? [])));

            // Record a nominal cost entry for video generation dispatch (billed per second by Google)
            $this->recordCost($model, 'startVideoGeneration', [], 0, array_merge($context, [
                'duration_seconds' => $parameters['durationSeconds'] ?? 8,
            ]));

            return $json['name'] ?? null;
        } catch (\Exception $e) {
            Log::error("GeminiService: Exception during video generation start from model {$model}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Checks the status of a Vertex AI long-running operation.
     *
     * @param string $operationName Full Vertex AI operation name
     *                              (e.g. projects/{p}/locations/{l}/operations/{id})
     * @return array|null The operation response if done, or null if still running or on failure.
     */
    public function checkVideoGenerationStatus(string $operationName): ?array
    {
        try {
            $url = "https://aiplatform.googleapis.com/v1/{$operationName}";

            $response = Http::withHeaders($this->authHeaders())
                ->timeout(300)
                ->get($url);

            if ($response->failed()) {
                Log::error("GeminiService: Failed to check operation status for {$operationName}: " . $response->body());
                return null;
            }

            $responseData = $response->json();

            if (isset($responseData['done']) && $responseData['done'] === true) {
                return $responseData;
            }

            return null;
        } catch (\Exception $e) {
            Log::error("GeminiService: Exception during operation status check for {$operationName}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Downloads video data from a given URI.
     */
    public function downloadVideo(string $uri): ?string
    {
        try {
            $response = Http::withHeaders($this->authHeaders())
                ->timeout(300)
                ->get($uri);

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

    // ─── Video Files API (Gemini endpoint — no Vertex AI equivalent yet) ─────

    /**
     * Upload a video file to the Gemini Files API and return a fresh persistent URI.
     *
     * NOTE: The Files API has no Vertex AI equivalent. A future migration should
     * replace this with a Cloud Storage upload and pass gs:// URIs to Veo instead.
     */
    public function uploadVideoToFilesApi(string $videoData, string $mimeType = 'video/mp4', string $displayName = 'source_video.mp4'): ?string
    {
        try {
            $numBytes = strlen($videoData);

            $initResponse = Http::withHeaders(array_merge($this->authHeaders(), [
                'X-Goog-Upload-Protocol'              => 'resumable',
                'X-Goog-Upload-Command'               => 'start',
                'X-Goog-Upload-Header-Content-Length' => $numBytes,
                'X-Goog-Upload-Header-Content-Type'   => $mimeType,
            ]))->post('https://generativelanguage.googleapis.com/upload/v1beta/files', [
                'file' => ['display_name' => $displayName],
            ]);

            if ($initResponse->failed()) {
                Log::error('GeminiService: Failed to initiate Files API upload: ' . $initResponse->body());
                return null;
            }

            $uploadUrl = $initResponse->header('X-Goog-Upload-URL');
            if (!$uploadUrl) {
                Log::error('GeminiService: No upload URL returned from Files API initiation');
                return null;
            }

            $uploadResponse = Http::withHeaders([
                'Content-Length'        => $numBytes,
                'X-Goog-Upload-Offset'  => 0,
                'X-Goog-Upload-Command' => 'upload, finalize',
            ])->withBody($videoData, $mimeType)->put($uploadUrl);

            if ($uploadResponse->failed()) {
                Log::error('GeminiService: Failed to upload video bytes to Files API: ' . $uploadResponse->body());
                return null;
            }

            $fileUri = $uploadResponse->json()['file']['uri'] ?? null;
            Log::info('GeminiService: Video uploaded to Files API', ['uri' => $fileUri]);
            return $fileUri;
        } catch (\Exception $e) {
            Log::error('GeminiService: Exception during Files API upload: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extend a Veo-generated video by up to 7 seconds.
     */
    public function extendVideo(string $videoUri, string $prompt, array $parameters = [], array $context = []): ?string
    {
        $requestBody = [
            'instances' => [
                [
                    'prompt' => $prompt,
                    'video'  => ['uri' => $videoUri],
                ]
            ],
            'parameters' => array_merge([
                'aspectRatio'      => '16:9',
                'sampleCount'      => 1,
                'durationSeconds'  => 8,
                'personGeneration' => 'ALLOW_ALL',
                'resolution'       => '720p',
            ], $parameters),
        ];

        Log::info("GeminiService: Extending video with URI: {$videoUri}");

        $attempt    = 0;
        $maxRetries = 3;

        while ($attempt < $maxRetries) {
            try {
                $response = Http::withHeaders($this->authHeaders())
                    ->timeout(300)
                    ->post("{$this->videoBaseUrl}veo-3.1-generate-preview:predictLongRunning", $requestBody);

                if ($response->successful()) {
                    $operationName = $response->json()['name'] ?? null;
                    Log::info("GeminiService: Video extension started successfully. Operation: {$operationName}");
                    $this->recordCost('veo-3.1-generate-preview', 'extendVideo', [], 0, $context);
                    return $operationName;
                }

                $statusCode = $response->status();

                if ($statusCode === 401) {
                    $this->flushTokenCache();
                }

                if ($this->isRetryableError($statusCode)) {
                    $attempt++;
                    if ($attempt < $maxRetries) {
                        $delayMs = $this->calculateBackoffDelay($attempt);
                        Log::warning("GeminiService: Retryable error ({$statusCode}) on extension attempt {$attempt}/{$maxRetries}. Retrying in {$delayMs}ms...", [
                            'response' => $response->body(),
                        ]);
                        usleep($delayMs * 1000);
                        continue;
                    }
                }

                Log::error("GeminiService: Failed to extend video: " . $response->body());
                return null;

            } catch (\Exception $e) {
                $attempt++;
                if ($attempt < $maxRetries) {
                    $delayMs = $this->calculateBackoffDelay($attempt);
                    Log::warning("GeminiService: Exception on extension attempt {$attempt}/{$maxRetries}: " . $e->getMessage() . ". Retrying in {$delayMs}ms...");
                    usleep($delayMs * 1000);
                    continue;
                }
                Log::error("GeminiService: Exception during video extension: " . $e->getMessage(), ['exception' => $e]);
                return null;
            }
        }

        return null;
    }

    // ─── Cost tracking ───────────────────────────────────────────────────────

    /**
     * Calculate cost in USD from token counts and model pricing config.
     */
    public function calculateCost(string $model, int $inputTokens, int $outputTokens, int $cachedTokens = 0): float
    {
        // Array access required — model names contain dots which break Laravel's config dot-notation.
        $pricing = (config('ai.pricing') ?? [])[$model] ?? null;
        if (!$pricing) {
            return 0.0;
        }

        return round(
            ($inputTokens  * $pricing['input']  / 1_000_000) +
            ($outputTokens * $pricing['output'] / 1_000_000) +
            ($cachedTokens * $pricing['cached'] / 1_000_000),
            6
        );
    }

    /**
     * Record an AI API call to the ai_costs table.
     * Fire-and-forget — exceptions are swallowed so they never break a generation call.
     */
    private function recordCost(string $model, string $operation, array $usageMetadata, int $durationMs, array $context = []): void
    {
        try {
            $inputTokens  = (int) ($usageMetadata['promptTokenCount']     ?? 0);
            $outputTokens = (int) ($usageMetadata['candidatesTokenCount'] ?? 0);
            $cachedTokens = (int) ($usageMetadata['cachedContentTokenCount'] ?? 0);
            $cost         = $this->calculateCost($model, $inputTokens, $outputTokens, $cachedTokens);

            AiCost::create([
                'campaign_id'  => $context['campaign_id']  ?? null,
                'customer_id'  => $context['customer_id']  ?? null,
                'service'      => 'Gemini',
                'operation'    => $context['operation']    ?? $operation,
                'model'        => $model,
                'input_tokens' => $inputTokens,
                'output_tokens'=> $outputTokens,
                'cached_tokens'=> $cachedTokens,
                'cost'         => $cost,
                'duration_ms'  => $durationMs,
                'task_type'    => $context['task_type']    ?? null,
                'metadata'     => array_filter([
                    'fallback_from'    => $context['fallback_from']    ?? null,
                    'duration_seconds' => $context['duration_seconds'] ?? null,
                ]),
            ]);
        } catch (\Throwable $e) {
            // Never let cost tracking break a generation call
            Log::warning("GeminiService: Failed to record AI cost: " . $e->getMessage());
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function isRetryableError(int $statusCode): bool
    {
        return ($statusCode >= 500 && $statusCode < 600) || $statusCode === 429 || $statusCode === 401;
    }

    private function calculateBackoffDelay(int $attempt): int
    {
        $delay  = $this->initialRetryDelayMs * (2 ** ($attempt - 1));
        $jitter = rand(0, (int)($delay * 0.1 * 2)) - ($delay * 0.1);
        return (int) max(100, $delay + $jitter);
    }
}
