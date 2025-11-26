<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Vertex AI Service for Google Cloud Platform
 * 
 * Available Gemini Models (Generally Available):
 * - gemini-3-pro-preview | Most intelligent model, best in the world for multimodal understanding
 * - gemini-2.5-pro | Powerful reasoning model, excels at coding and complex reasoning
 * - gemini-2.5-flash | Most balanced model with 1M token context window
 * - gemini-2.5-flash-lite | Fastest and most cost-efficient for high-frequency tasks
 * - gemini-2.5-flash-image | State-of-the-art image generation and editing (Nano Banana)
 * - gemini-2.0-flash | Price-performance model
 * - gemini-2.0-flash-lite | Optimized for cost and low latency
 * 
 * Available Imagen Models:
 * - imagen-4.0-generate-001 | Highest quality image generation
 * - imagen-4.0-fast-generate-001 | Higher quality with lower latency
 * - imagen-4.0-ultra-generate-001 | Highest quality with best prompt adherence
 * - imagen-3.0-generate-002 | Previous generation
 * - imagen-3.0-generate-001 | Previous generation
 * - imagen-3.0-fast-generate-001 | Lower latency generation
 * 
 * Available Veo Models (Video):
 * - veo-3.1-generate-001 | State-of-the-art video generation with native audio
 * - veo-3.1-fast-generate-001 | Higher quality with lower latency
 * - veo-3.1-generate-preview | Previous generation
 * - veo-3.0-fast-generate-001 | Previous generation with lower latency
 * - veo-2.0-generate-001 | Earlier generation
 * 
 * Note: Model availability varies by region. Use us-central1 for widest selection.
 */
class VertexAIService
{
    private string $projectId;
    private string $location;
    private string $baseUrl;
    private int $maxRetries = 3;
    private int $initialRetryDelayMs = 1000;

    public function __construct()
    {
        $this->projectId = config('services.google.project_id') ?? env('GOOGLE_CLOUD_PROJECT');
        $this->location = config('services.google.location', 'us-central1');
        // Use v1beta1 for access to preview models like gemini-3-pro-preview
        $this->baseUrl = "https://{$this->location}-aiplatform.googleapis.com/v1beta1/projects/{$this->projectId}/locations/{$this->location}/publishers/google/models/";
    }

    /**
     * Get access token for Vertex AI API
     * Priority: 1) GCE Metadata (VM), 2) Service Account File, 3) gcloud CLI (local dev)
     * Caches the token until it expires
     *
     * @return string|null
     */
    private function getAccessToken(): ?string
    {
        // Check if token is cached
        $cachedToken = Cache::get('vertex_ai_access_token');
        if ($cachedToken) {
            return $cachedToken;
        }

        try {
            // 1. Try GCE Metadata Server first (for Google Cloud VMs)
            $metadataToken = $this->getAccessTokenFromMetadata();
            if ($metadataToken) {
                return $metadataToken;
            }
            
            // 2. Check for service account credentials file
            $credentialsPath = config('services.google.credentials_path') ?? env('GOOGLE_APPLICATION_CREDENTIALS');
            
            if ($credentialsPath && file_exists($credentialsPath)) {
                return $this->getAccessTokenFromServiceAccount($credentialsPath);
            }
            
            // 3. Fall back to gcloud CLI (local development)
            $accessToken = trim(shell_exec('gcloud auth application-default print-access-token 2>&1'));
            
            if (empty($accessToken) || str_contains($accessToken, 'ERROR')) {
                Log::error("VertexAIService: Failed to get access token", ['output' => $accessToken]);
                return null;
            }

            // Cache for 55 minutes (tokens typically valid for 1 hour)
            Cache::put('vertex_ai_access_token', $accessToken, now()->addMinutes(55));
            
            return $accessToken;
        } catch (\Exception $e) {
            Log::error("VertexAIService: Exception getting access token: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get access token from GCE Metadata Server (for Google Cloud VMs)
     *
     * @return string|null
     */
    private function getAccessTokenFromMetadata(): ?string
    {
        try {
            $response = Http::timeout(2)
                ->withHeaders(['Metadata-Flavor' => 'Google'])
                ->get('http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token');
            
            if ($response->successful()) {
                $tokenData = $response->json();
                $accessToken = $tokenData['access_token'] ?? null;
                
                if ($accessToken) {
                    // Cache based on expires_in (usually 3600s), minus buffer
                    $expiresIn = ($tokenData['expires_in'] ?? 3600) - 300;
                    Cache::put('vertex_ai_access_token', $accessToken, now()->addSeconds($expiresIn));
                    Log::debug("VertexAIService: Got token from GCE metadata server");
                    return $accessToken;
                }
            }
        } catch (\Exception $e) {
            // Not on GCE, silently continue to other methods
        }
        
        return null;
    }
    
    /**
     * Get access token using a Service Account JSON key file
     *
     * @param string $credentialsPath Path to the service account JSON key file
     * @return string|null
     */
    private function getAccessTokenFromServiceAccount(string $credentialsPath): ?string
    {
        try {
            $credentials = json_decode(file_get_contents($credentialsPath), true);
            
            if (!$credentials) {
                Log::error("VertexAIService: Invalid credentials file");
                return null;
            }
            
            // Create JWT for token request
            $now = time();
            $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $payload = base64_encode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/cloud-platform',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            
            // Sign the JWT
            $signatureInput = $header . '.' . $payload;
            openssl_sign($signatureInput, $signature, $credentials['private_key'], 'SHA256');
            $jwt = $signatureInput . '.' . base64_encode($signature);
            
            // Exchange JWT for access token
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            
            if ($response->successful()) {
                $tokenData = $response->json();
                $accessToken = $tokenData['access_token'] ?? null;
                
                if ($accessToken) {
                    // Cache for 55 minutes
                    Cache::put('vertex_ai_access_token', $accessToken, now()->addMinutes(55));
                    return $accessToken;
                }
            }
            
            Log::error("VertexAIService: Failed to get token from service account", [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return null;
            
        } catch (\Exception $e) {
            Log::error("VertexAIService: Service account token error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generates content using a specified Vertex AI model with retry mechanism.
     *
     * @param string $model The Vertex AI model to use (e.g., 'gemini-3-pro').
     * @param string $prompt The prompt to send to the model.
     * @param array $config Additional generation configuration.
     * @param string|null $systemInstruction Optional system instruction.
     * @param bool $enableThinking Enable extended thinking (for models that support it).
     * @param bool $enableGoogleSearch Enable Google Search grounding.
     * @param array $safetySettings Custom safety settings.
     * @param int|null $maxRetries Maximum number of retry attempts.
     * @return array|null The generated content as an array, or null on failure.
     */
    public function generateContent(
        string $model, 
        string $prompt, 
        array $config = [], 
        ?string $systemInstruction = null,
        bool $enableThinking = false,
        bool $enableGoogleSearch = false,
        array $safetySettings = [],
        ?int $maxRetries = null
    ): ?array {
        $maxRetries = $maxRetries ?? $this->maxRetries;
        $attempt = 0;

        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            Log::error("VertexAIService: No access token available");
            return null;
        }

        $endpoint = "{$this->baseUrl}{$model}:generateContent";

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => array_merge([
                'temperature' => 1,
                'topP' => 0.95,
                'topK' => 40,
                'maxOutputTokens' => 65535,
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

        // Add safety settings (default to OFF for all categories if not specified)
        if (empty($safetySettings)) {
            $payload['safetySettings'] = [
                ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'OFF'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'OFF'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'OFF'],
                ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'OFF']
            ];
        } else {
            $payload['safetySettings'] = $safetySettings;
        }

        while ($attempt < $maxRetries) {
            try {
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->timeout(300)->post($endpoint, $payload);

                // Check if response was successful
                if ($response->successful()) {
                    $responseData = $response->json();
                    
                    // Extract text from response (skip thinking parts if present)
                    $candidates = $responseData['candidates'] ?? [];
                    if (empty($candidates)) {
                        Log::error("VertexAIService: No candidates in response", ['model' => $model]);
                        return null;
                    }

                    $parts = $candidates[0]['content']['parts'] ?? [];
                    $textContent = null;
                    
                    // Find the text part that's not a thought
                    foreach ($parts as $part) {
                        if (isset($part['text']) && !isset($part['thought'])) {
                            $textContent = $part['text'];
                            break;
                        }
                    }

                    if (!$textContent) {
                        Log::error("VertexAIService: No text content found in response", [
                            'model' => $model,
                            'parts' => $parts
                        ]);
                        return null;
                    }

                    return ['text' => $textContent];
                }

                // Handle specific error codes
                $statusCode = $response->status();
                
                // If unauthorized, try to refresh token
                if ($statusCode === 401) {
                    Cache::forget('vertex_ai_access_token');
                    $accessToken = $this->getAccessToken();
                    if (!$accessToken) {
                        Log::error("VertexAIService: Failed to refresh access token");
                        return null;
                    }
                    $attempt++;
                    continue;
                }
                
                // Retry on server errors (5xx) and specific client errors
                if ($this->isRetryableError($statusCode)) {
                    $attempt++;
                    
                    if ($attempt < $maxRetries) {
                        $delayMs = $this->calculateBackoffDelay($attempt);
                        Log::warning("VertexAIService: Retryable error ({$statusCode}) on attempt {$attempt}/{$maxRetries}. Retrying in {$delayMs}ms...", [
                            'model' => $model,
                            'response' => $response->body(),
                        ]);
                        usleep($delayMs * 1000);
                        continue;
                    }
                }

                // Non-retryable error or max retries reached
                Log::error("VertexAIService: Failed to generate content from model {$model} (Status: {$statusCode}): " . $response->body(), [
                    'model' => $model,
                    'attempt' => $attempt + 1,
                    'max_retries' => $maxRetries,
                ]);
                return null;

            } catch (\Exception $e) {
                $attempt++;
                
                if ($attempt < $maxRetries) {
                    $delayMs = $this->calculateBackoffDelay($attempt);
                    Log::warning("VertexAIService: Exception on attempt {$attempt}/{$maxRetries}: " . $e->getMessage() . ". Retrying in {$delayMs}ms...", [
                        'model' => $model,
                        'exception' => get_class($e),
                    ]);
                    usleep($delayMs * 1000);
                    continue;
                }

                Log::error("VertexAIService: Failed after {$maxRetries} attempts: " . $e->getMessage(), [
                    'model' => $model,
                    'exception' => get_class($e),
                ]);
                return null;
            }
        }

        return null;
    }

    /**
     * Determines if an HTTP status code is retryable.
     *
     * @param int $statusCode
     * @return bool
     */
    private function isRetryableError(int $statusCode): bool
    {
        return in_array($statusCode, [
            429, // Too Many Requests
            500, // Internal Server Error
            502, // Bad Gateway
            503, // Service Unavailable
            504, // Gateway Timeout
        ]);
    }

    /**
     * Calculates exponential backoff delay.
     *
     * @param int $attempt
     * @return int Delay in milliseconds
     */
    private function calculateBackoffDelay(int $attempt): int
    {
        return $this->initialRetryDelayMs * pow(2, $attempt - 1);
    }

    /**
     * Generate text content with simplified interface
     *
     * @param string $model
     * @param string $prompt
     * @param array $config
     * @return string|null
     */
    public function generateText(string $model, string $prompt, array $config = []): ?string
    {
        $result = $this->generateContent($model, $prompt, $config);
        return $result['text'] ?? null;
    }

    /**
     * Generate content with system instruction
     *
     * @param string $model
     * @param string $systemInstruction
     * @param string $prompt
     * @param array $config
     * @param bool $enableThinking
     * @param bool $enableGoogleSearch
     * @return array|null
     */
    public function generateWithSystemInstruction(
        string $model,
        string $systemInstruction,
        string $prompt,
        array $config = [],
        bool $enableThinking = false,
        bool $enableGoogleSearch = false
    ): ?array {
        return $this->generateContent(
            $model, 
            $prompt, 
            $config, 
            $systemInstruction, 
            $enableThinking, 
            $enableGoogleSearch
        );
    }

    /**
     * Generate content with extended thinking and Google Search enabled
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
}
