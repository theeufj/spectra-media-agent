<?php

namespace Tests\Feature;

use App\Jobs\ExtendVideoForScript;
use App\Jobs\FinalizeVideoNarration;
use App\Models\AiCost;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\VideoCollateral;
use App\Services\OpenRouterService;
use App\Services\VideoGeneration\VideoGenerationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Grok via OpenRouter is the default creative provider (2026-08-24
 * shootout). These pin the API shapes, the cost ledger entries, and the
 * provider gating that keeps Veo-only machinery (extension chains, TTS
 * re-voice) off Grok's single-pass videos.
 */
class OpenRouterProviderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openrouter.api_key' => 'test-key']);
    }

    public function test_image_generation_returns_gemini_shaped_payload_and_ledgers_cost(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode('fake-jpeg-bytes')]],
            ]),
        ]);

        $result = app(OpenRouterService::class)->generateImage('a designed ad', ['task_type' => 'image_generation']);

        $this->assertSame(base64_encode('fake-jpeg-bytes'), $result['data']);
        $this->assertSame('image/jpeg', $result['mimeType']);

        $cost = AiCost::latest()->first();
        $this->assertSame('OpenRouter', $cost->service);
        $this->assertEqualsWithDelta(0.04, (float) $cost->cost, 0.001);
    }

    public function test_video_start_caps_duration_and_ledgers_per_second_cost(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/videos' => Http::response(['id' => 'job-123', 'status' => 'pending']),
        ]);

        $jobId = app(OpenRouterService::class)->startVideoGeneration('a video', 40);

        $this->assertSame('job-123', $jobId);
        Http::assertSent(fn ($request) => $request['duration'] === 15 && $request['generate_audio'] === true);

        $cost = AiCost::latest()->first();
        $this->assertEqualsWithDelta(15 * 0.15, (float) $cost->cost, 0.001);
    }

    public function test_video_polling_returns_bytes_on_completion(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/videos/job-123/content*' => Http::response('raw-video-bytes'),
            'openrouter.ai/api/v1/videos/job-123' => Http::response(['status' => 'completed']),
        ]);

        $result = app(OpenRouterService::class)->checkVideoStatus('job-123');

        $this->assertSame('raw-video-bytes', $result['bytes']);
    }

    public function test_grok_is_preferred_for_video_and_records_the_provider(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/videos' => Http::response(['id' => 'job-9', 'status' => 'pending']),
        ]);

        $result = app(VideoGenerationService::class)->startGeneration(
            'full prompt',
            [],
            null,
            'A voiceover script of around fifteen words to size the duration from.'
        );

        $this->assertSame('openrouter', $result['provider']);
        $this->assertSame('job-9', $result['operation_name']);
    }

    public function test_credit_balance_parses(): void
    {
        \Illuminate\Support\Facades\Cache::forget('openrouter_credits');
        Http::fake([
            'openrouter.ai/api/v1/credits' => Http::response(['data' => ['total_credits' => 25, 'total_usage' => 12.9]]),
        ]);

        $balance = app(OpenRouterService::class)->creditBalance();

        $this->assertSame(25.0, $balance['total']);
        $this->assertSame(12.1, $balance['remaining']);
    }

    public function test_credit_balance_fails_soft(): void
    {
        \Illuminate\Support\Facades\Cache::forget('openrouter_credits');
        Http::fake(['openrouter.ai/api/v1/credits' => Http::response(null, 500)]);

        $this->assertNull(app(OpenRouterService::class)->creditBalance());
    }

    /**
     * The prompt has to be chosen from the provider that actually ran, not
     * from config. Reading config('ai.video_provider') meant a Grok start
     * failure handed Veo the full 15s script for an 8s clip, and the
     * extension chain then resumed at segment 1 — so segment 0 was rushed
     * and every segment after it was misaligned.
     */
    public function test_a_grok_failure_falls_back_to_veo_with_a_veo_sized_prompt(): void
    {
        $this->fakeVertexAuth();

        Http::fake([
            'openrouter.ai/api/v1/videos' => Http::response(null, 500),
            'aiplatform.googleapis.com/*' => Http::response(['name' => 'projects/p/operations/op-1']),
        ]);

        $result = app(VideoGenerationService::class)->startGeneration(
            'PROMPT_FOR_openrouter',
            [],
            null,
            'A voiceover script of about fifteen words used only to size the clip duration.',
            promptForProvider: fn (string $provider) => "PROMPT_FOR_{$provider}",
        );

        $this->assertSame('veo', $result['provider']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'predictLongRunning')
            && $request['instances'][0]['prompt'] === 'PROMPT_FOR_veo');
    }

    public function test_video_spend_is_attributed_to_the_customer_that_incurred_it(): void
    {
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        Http::fake([
            'openrouter.ai/api/v1/videos' => Http::response(['id' => 'job-42', 'status' => 'pending']),
        ]);

        app(VideoGenerationService::class)->startGeneration(
            'a prompt',
            [],
            null,
            'A voiceover script of about fifteen words used only to size the clip duration.',
            context: ['campaign_id' => $campaign->id, 'customer_id' => $customer->id],
        );

        $cost = AiCost::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame($customer->id, $cost->customer_id);
        $this->assertSame($campaign->id, $cost->campaign_id);
    }

    private function fakeVertexAuth(): void
    {
        config([
            'services.google.project_id' => 'test-project',
            'services.google.location' => 'us-central1',
            'services.google.credentials_path' => '/dev/null',
        ]);
        \Illuminate\Support\Facades\Cache::put('gcp_vertex_access_token', 'test-token', 3000);
    }

    public function test_grok_videos_skip_the_veo_only_extension_and_revoice_steps(): void
    {
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $video = VideoCollateral::create([
            'campaign_id' => $campaign->id,
            'platform' => 'Facebook Ads',
            'status' => 'completed',
            'provider' => 'openrouter',
            's3_path' => 'collateral/videos/x.mp4',
            'script' => str_repeat('A long script that would need many Veo segments to narrate fully. ', 5),
            'is_active' => true,
            'extension_count' => 0,
        ]);

        $this->assertFalse(ExtendVideoForScript::needsExtension($video));
        $this->assertFalse(FinalizeVideoNarration::shouldRun($video));
    }
}
