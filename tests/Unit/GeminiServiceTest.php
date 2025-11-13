<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiServiceTest extends TestCase
{
    private GeminiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set a mock API key for testing
        config(['app.GEMINI_API_KEY' => 'test-api-key']);
        $this->service = new GeminiService();
    }

    /**
     * Test generateContent returns array with successful response
     */
    public function test_generateContent_returns_array_on_success(): void
    {
        $mockResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Generated text response']
                        ]
                    ]
                ]
            ]
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($mockResponse, 200)
        ]);

        $result = $this->service->generateContent('gemini-2.5-pro', 'Test prompt');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('text', $result);
        $this->assertEquals('Generated text response', $result['text']);
    }

    /**
     * Test generateContent returns null on API failure
     */
    public function test_generateContent_returns_null_on_api_failure(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(
                ['error' => ['message' => 'API error']],
                500
            )
        ]);

        Log::spy();

        $result = $this->service->generateContent('gemini-2.5-pro', 'Test prompt');

        $this->assertNull($result);
        Log::shouldHaveReceived('error')->once();
    }

    /**
     * Test generateContent returns null when response structure is incomplete
     */
    public function test_generateContent_returns_null_when_candidates_missing(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(['data' => 'incomplete'], 200)
        ]);

        $result = $this->service->generateContent('gemini-2.5-pro', 'Test prompt');

        $this->assertNull($result);
    }

    /**
     * Test generateContent handles exceptions gracefully
     */
    public function test_generateContent_handles_exceptions(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([], 500)
        ]);

        Log::spy();

        // Since the actual exception occurs inside the try-catch, we'll test with a failed response
        $result = $this->service->generateContent('gemini-2.5-pro', 'Test prompt');

        $this->assertNull($result);
        Log::shouldHaveReceived('error')->once();
    }

    /**
     * Test embedContent returns array on success
     */
    public function test_embedContent_returns_array_on_success(): void
    {
        $mockResponse = [
            'embedding' => [
                'values' => [0.1, 0.2, 0.3, 0.4]
            ]
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($mockResponse, 200)
        ]);

        $result = $this->service->embedContent('text-embedding-004', 'Test text');

        $this->assertIsArray($result);
        $this->assertEquals([0.1, 0.2, 0.3, 0.4], $result);
    }

    /**
     * Test embedContent returns null on API failure
     */
    public function test_embedContent_returns_null_on_api_failure(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(
                ['error' => ['message' => 'API error']],
                500
            )
        ]);

        Log::spy();

        $result = $this->service->embedContent('text-embedding-004', 'Test text');

        $this->assertNull($result);
        Log::shouldHaveReceived('error')->once();
    }

    /**
     * Test embedContent returns null when embedding values missing
     */
    public function test_embedContent_returns_null_when_values_missing(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(['embedding' => []], 200)
        ]);

        $result = $this->service->embedContent('text-embedding-004', 'Test text');

        $this->assertNull($result);
    }

    /**
     * Test embedContent handles exceptions gracefully
     */
    public function test_embedContent_handles_exceptions(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([], 500)
        ]);

        Log::spy();

        // Since the actual exception occurs inside the try-catch, we'll test with a failed response
        $result = $this->service->embedContent('text-embedding-004', 'Test text');

        $this->assertNull($result);
        Log::shouldHaveReceived('error')->once();
    }

    /**
     * Test generateImage returns array with image data on success
     */
    public function test_generateImage_returns_array_on_success(): void
    {
        $mockResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'inlineData' => [
                                    'data' => 'base64encodedimage',
                                    'mimeType' => 'image/png'
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($mockResponse, 200)
        ]);

        $result = $this->service->generateImage('Generate an image of a cat');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('mimeType', $result);
        $this->assertEquals('base64encodedimage', $result['data']);
        $this->assertEquals('image/png', $result['mimeType']);
    }

    /**
     * Test generateImage returns null when inlineData is missing
     */
    public function test_generateImage_returns_null_when_inlinedata_missing(): void
    {
        $mockResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Some response']
                        ]
                    ]
                ]
            ]
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($mockResponse, 200)
        ]);

        Log::spy();

        $result = $this->service->generateImage('Generate an image');

        $this->assertNull($result);
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * Test generateImage returns null on API failure
     */
    public function test_generateImage_returns_null_on_api_failure(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(
                ['error' => ['message' => 'API error']],
                500
            )
        ]);

        Log::spy();

        $result = $this->service->generateImage('Generate an image');

        $this->assertNull($result);
        Log::shouldHaveReceived('error')->once();
    }

    /**
     * Test generateImage handles exceptions gracefully
     */
    public function test_generateImage_handles_exceptions(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([], 500)
        ]);

        Log::spy();

        // Since the actual exception occurs inside the try-catch, we'll test with a failed response
        $result = $this->service->generateImage('Generate an image');

        $this->assertNull($result);
        Log::shouldHaveReceived('error')->once();
    }

    /**
     * Test startVideoGeneration returns operation name on success
     */
    public function test_startVideoGeneration_returns_operation_name_on_success(): void
    {
        $mockResponse = [
            'name' => 'projects/123/locations/us-central1/operations/abc123'
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($mockResponse, 200)
        ]);

        $result = $this->service->startVideoGeneration('Generate a video of a sunset');

        $this->assertIsString($result);
        $this->assertEquals('projects/123/locations/us-central1/operations/abc123', $result);
    }

    /**
     * Test startVideoGeneration returns null on API failure
     */
    public function test_startVideoGeneration_returns_null_on_api_failure(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(
                ['error' => ['message' => 'API error']],
                500
            )
        ]);

        Log::spy();

        $result = $this->service->startVideoGeneration('Generate a video');

        $this->assertNull($result);
        Log::shouldHaveReceived('error')->once();
    }

    /**
     * Test startVideoGeneration accepts custom parameters
     */
    public function test_startVideoGeneration_accepts_custom_parameters(): void
    {
        $mockResponse = [
            'name' => 'projects/123/locations/us-central1/operations/abc123'
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($mockResponse, 200)
        ]);

        $customParams = [
            'aspectRatio' => '9:16',
            'durationSeconds' => 15,
        ];

        $result = $this->service->startVideoGeneration('Generate a video', 'veo-2.0-generate-001', $customParams);

        $this->assertIsString($result);
        Http::assertSent(function ($request) use ($customParams) {
            $body = json_decode($request->body(), true);
            return $body['parameters']['aspectRatio'] === '9:16' &&
                   $body['parameters']['durationSeconds'] === 15;
        });
    }

    /**
     * Test startVideoGeneration handles exceptions gracefully
     */
    public function test_startVideoGeneration_handles_exceptions(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([], 500)
        ]);

        Log::spy();

        // Since the actual exception occurs inside the try-catch, we'll test with a failed response
        $result = $this->service->startVideoGeneration('Generate a video');

        $this->assertNull($result);
        Log::shouldHaveReceived('error')->once();
    }

    /**
     * Test checkVideoGenerationStatus returns operation when done
     */
    public function test_checkVideoGenerationStatus_returns_operation_when_done(): void
    {
        $mockResponse = [
            'done' => true,
            'response' => [
                'predictions' => [
                    [
                        'bytesBase64Encoded' => 'base64video',
                        'mimeType' => 'video/mp4'
                    ]
                ]
            ]
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($mockResponse, 200)
        ]);

        $result = $this->service->checkVideoGenerationStatus('projects/123/locations/us-central1/operations/abc123');

        $this->assertIsArray($result);
        $this->assertTrue($result['done']);
    }

    /**
     * Test checkVideoGenerationStatus returns null when not done
     */
    public function test_checkVideoGenerationStatus_returns_null_when_not_done(): void
    {
        $mockResponse = [
            'done' => false,
            'name' => 'projects/123/locations/us-central1/operations/abc123'
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($mockResponse, 200)
        ]);

        $result = $this->service->checkVideoGenerationStatus('projects/123/locations/us-central1/operations/abc123');

        $this->assertNull($result);
    }

    /**
     * Test checkVideoGenerationStatus returns null on API failure
     */
    public function test_checkVideoGenerationStatus_returns_null_on_api_failure(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(
                ['error' => ['message' => 'API error']],
                500
            )
        ]);

        Log::spy();

        $result = $this->service->checkVideoGenerationStatus('projects/123/locations/us-central1/operations/abc123');

        $this->assertNull($result);
        Log::shouldHaveReceived('error')->once();
    }

    /**
     * Test checkVideoGenerationStatus handles exceptions gracefully
     */
    public function test_checkVideoGenerationStatus_handles_exceptions(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([], 500)
        ]);

        Log::spy();

        // Since the actual exception occurs inside the try-catch, we'll test with a failed response
        $result = $this->service->checkVideoGenerationStatus('projects/123/locations/us-central1/operations/abc123');

        $this->assertNull($result);
        Log::shouldHaveReceived('error')->once();
    }

    /**
     * Test generateContent retries on 503 error
     */
    public function test_generateContent_retries_on_503(): void
    {
        $successResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Generated text']
                        ]
                    ]
                ]
            ]
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([], 503)  // First attempt fails with 503
                ->push($successResponse, 200)  // Second attempt succeeds
        ]);

        Log::spy();

        $result = $this->service->generateContent('gemini-2.5-pro', 'Test prompt', 3);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('text', $result);
        $this->assertEquals('Generated text', $result['text']);
        Log::shouldHaveReceived('warning')->once(); // One warning for the retry
    }

    /**
     * Test generateContent retries on 429 (rate limit) error
     */
    public function test_generateContent_retries_on_429(): void
    {
        $successResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Generated text']
                        ]
                    ]
                ]
            ]
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([], 429)  // First attempt fails with 429 (rate limit)
                ->push($successResponse, 200)  // Second attempt succeeds
        ]);

        Log::spy();

        $result = $this->service->generateContent('gemini-2.5-pro', 'Test prompt', 3);

        $this->assertIsArray($result);
        $this->assertEquals('Generated text', $result['text']);
        Log::shouldHaveReceived('warning')->once(); // One warning for the retry
    }

    /**
     * Test generateContent retries multiple times before succeeding
     */
    public function test_generateContent_retries_multiple_times(): void
    {
        $successResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Generated text']
                        ]
                    ]
                ]
            ]
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([], 503)  // First attempt fails
                ->push([], 503)  // Second attempt fails
                ->push($successResponse, 200)  // Third attempt succeeds
        ]);

        Log::spy();

        $result = $this->service->generateContent('gemini-2.5-pro', 'Test prompt', 4);

        $this->assertIsArray($result);
        $this->assertEquals('Generated text', $result['text']);
        Log::shouldHaveReceived('warning')->twice(); // Two warnings for the two retries
    }

    /**
     * Test generateContent fails after max retries exhausted
     */
    public function test_generateContent_fails_after_max_retries(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([], 503)  // First attempt fails
                ->push([], 503)  // Second attempt fails
                ->push([], 503)  // Third attempt fails (max retries reached)
        ]);

        Log::spy();

        $result = $this->service->generateContent('gemini-2.5-pro', 'Test prompt', 3);

        $this->assertNull($result);
        Log::shouldHaveReceived('warning')->twice(); // Two warnings for retries
        Log::shouldHaveReceived('error')->once(); // One error when all retries exhausted
    }

    /**
     * Test generateContent does not retry on 4xx errors (except 429)
     */
    public function test_generateContent_does_not_retry_on_400(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(['error' => 'Bad request'], 400)
        ]);

        Log::spy();

        $result = $this->service->generateContent('gemini-2.5-pro', 'Test prompt', 3);

        $this->assertNull($result);
        Log::shouldHaveReceived('error')->once();
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Test generateContent does not retry on 401 unauthorized
     */
    public function test_generateContent_does_not_retry_on_401(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(['error' => 'Unauthorized'], 401)
        ]);

        Log::spy();

        $result = $this->service->generateContent('gemini-2.5-pro', 'Test prompt', 3);

        $this->assertNull($result);
        Log::shouldHaveReceived('error')->once();
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Test generateContent respects custom max retries parameter
     */
    public function test_generateContent_respects_custom_max_retries(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([], 503)  // First attempt fails
                ->push([], 503)  // Second attempt fails (max retries of 2 reached)
        ]);

        Log::spy();

        $result = $this->service->generateContent('gemini-2.5-pro', 'Test prompt', 2);

        $this->assertNull($result);
        Log::shouldHaveReceived('warning')->once(); // One warning for the one retry
        Log::shouldHaveReceived('error')->once();
    }
}

