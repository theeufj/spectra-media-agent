<?php

namespace Tests\Feature\Services\AI;

use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group ai
 */
class GeminiServiceIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected GeminiService $gemini;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_INTEGRATION_TESTS=true to run.');
        }

        $this->gemini = app(GeminiService::class);
    }

    public function test_generates_text_content(): void
    {
        $response = $this->gemini->generateContent(
            model:  config('ai.models.default'),
            prompt: 'Say hello in exactly one word.',
        );

        $this->assertNotNull($response);
        $this->assertArrayHasKey('text', $response);
        $this->assertNotEmpty($response['text']);
    }

    public function test_generates_google_ads_headline_within_character_limit(): void
    {
        $response = $this->gemini->generateContent(
            model:  config('ai.models.default'),
            prompt: 'Write a single Google Ads headline for an AI-powered ad management tool. Return only the headline text, max 30 characters.',
        );

        $headline = trim($response['text'] ?? '');

        $this->assertNotEmpty($headline);
        $this->assertLessThanOrEqual(30, mb_strlen($headline), "Headline too long: \"{$headline}\"");
    }

    public function test_generates_json_structured_output(): void
    {
        $response = $this->gemini->generateContent(
            model:  config('ai.models.default'),
            prompt: 'Return ONLY valid JSON with exactly two keys: {"headline": "short title under 30 chars", "description": "one sentence under 90 chars"}. No markdown, no explanation.',
            config: ['temperature' => 0.1],
        );

        $text = trim(preg_replace('/^```json\s*|\s*```$/m', '', $response['text'] ?? ''));
        $data = json_decode($text, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('headline', $data);
        $this->assertArrayHasKey('description', $data);
    }

    public function test_generates_meta_ad_copy_within_limits(): void
    {
        $response = $this->gemini->generateContent(
            model:  config('ai.models.default'),
            prompt: 'Return ONLY valid JSON: {"primary_text": "Facebook ad body max 125 chars", "headline": "max 40 chars"}. For a Google Ads management SaaS.',
            config: ['temperature' => 0.7],
        );

        $text = trim(preg_replace('/^```json\s*|\s*```$/m', '', $response['text'] ?? ''));
        $data = json_decode($text, true);

        $this->assertIsArray($data);
        $this->assertLessThanOrEqual(125, mb_strlen($data['primary_text'] ?? ''));
        $this->assertLessThanOrEqual(40,  mb_strlen($data['headline'] ?? ''));
    }

    public function test_generates_image(): void
    {
        $response = $this->gemini->generateImage(
            'Abstract blue geometric pattern, clean minimal design',
        );

        $this->assertNotNull($response);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('mimeType', $response);
        $this->assertNotEmpty($response['data']);

        // Verify it's valid base64
        $decoded = base64_decode($response['data'], true);
        $this->assertNotFalse($decoded);
        $this->assertGreaterThan(100, strlen($decoded)); // at least 100 bytes
    }

    public function test_generates_content_with_system_instruction(): void
    {
        $response = $this->gemini->generateContent(
            model:             config('ai.models.default'),
            prompt:            'What platform are you?',
            systemInstruction: 'You are a Google Ads expert assistant. Always mention Google Ads in your response.',
        );

        $this->assertNotNull($response);
        $this->assertNotEmpty($response['text'] ?? '');
    }

    public function test_default_model_config_is_set(): void
    {
        $model = config('ai.models.default');

        $this->assertNotEmpty($model);
        $this->assertIsString($model);
    }

    public function test_generates_search_themes_for_audience_signals(): void
    {
        $response = $this->gemini->generateContent(
            model:  config('ai.models.default'),
            prompt: 'List 5 Google Ads search themes (short keyword phrases) for a SaaS Google Ads management tool. Return as JSON array: ["theme1", "theme2", ...]',
            config: ['temperature' => 0.3],
        );

        $text   = trim(preg_replace('/^```json\s*|\s*```$/m', '', $response['text'] ?? ''));
        $themes = json_decode($text, true);

        $this->assertIsArray($themes);
        $this->assertGreaterThanOrEqual(3, count($themes));
        foreach ($themes as $theme) {
            $this->assertIsString($theme);
            $this->assertNotEmpty($theme);
        }
    }
}
