<?php

namespace Tests\Feature;

use App\Jobs\CheckVideoStatus;
use App\Jobs\ExtendPMaxVideo;
use App\Jobs\ExtendVideoForScript;
use App\Jobs\FinalizeVideoNarration;
use App\Models\AiCost;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\VideoCollateral;
use App\Services\GeminiService;
use App\Services\VideoGeneration\VideoGenerationService;
use App\Services\XaiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class XaiVideoProviderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.video_provider' => 'xai',
            'ai.models.video_xai' => 'grok-imagine-video-1.5',
            'services.xai.api_key' => 'test-xai-key',
            'services.openrouter.api_key' => 'test-openrouter-key',
        ]);
        Cache::forget('xai:unavailable');
    }

    public function test_direct_grok_uses_the_full_script_and_records_the_720p_cost(): void
    {
        Http::fake(['api.x.ai/v1/videos/generations' => Http::response(['request_id' => 'request-1'])]);
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        $result = app(VideoGenerationService::class)->startGeneration(
            'initial prompt',
            ['aspectRatio' => '9:16'],
            voiceoverScript: str_repeat('Narrated words ', 30),
            promptForProvider: fn (string $provider) => "Prompt for {$provider}",
            context: ['campaign_id' => $campaign->id, 'customer_id' => $customer->id, 'task_type' => 'video_generation'],
        );

        $this->assertSame(['provider' => 'xai', 'operation_name' => 'request-1'], $result);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.x.ai/v1/videos/generations'
            && $request->hasHeader('Authorization', 'Bearer test-xai-key')
            && $request['model'] === config('ai.models.video_xai')
            && $request['prompt'] === 'Prompt for xai'
            && $request['duration'] === 15
            && $request['aspect_ratio'] === '9:16'
            && $request['resolution'] === '720p');
        Http::assertSentCount(1);

        $cost = AiCost::where('campaign_id', $campaign->id)->where('operation', 'startVideoGeneration')->sole();
        $this->assertSame('xAI', $cost->service);
        $this->assertSame($customer->id, $cost->customer_id);
        $this->assertSame(config('ai.models.video_xai'), $cost->model);
        $this->assertSame('2.100000', $cost->cost);
        $this->assertSame(['duration_seconds' => 15], $cost->metadata);
    }

    public function test_direct_failure_can_fall_back_to_openrouter(): void
    {
        Http::fake([
            'api.x.ai/v1/videos/generations' => Http::response(null, 503),
            'openrouter.ai/api/v1/videos' => Http::response(['id' => 'router-1']),
        ]);

        $this->assertSame(
            ['provider' => 'openrouter', 'operation_name' => 'router-1'],
            app(VideoGenerationService::class)->startGeneration('a prompt'),
        );
        Http::assertSentCount(2);
    }

    public function test_direct_failure_without_openrouter_falls_back_to_a_veo_prompt(): void
    {
        config(['services.openrouter.api_key' => null]);
        Http::fake(['api.x.ai/v1/videos/generations' => Http::response(null, 503)]);
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->once())->method('startVideoGeneration')
            ->with('Prompt for veo', config('ai.models.video'), [], [])->willReturn('veo-1');
        $this->instance(GeminiService::class, $gemini);

        $this->assertSame(
            ['provider' => 'veo', 'operation_name' => 'veo-1'],
            app(VideoGenerationService::class)->startGeneration('prompt', promptForProvider: fn (string $provider) => "Prompt for {$provider}"),
        );
        Http::assertSentCount(1);
    }

    public function test_polling_completes_an_xai_video_after_provider_configuration_changes(): void
    {
        config([
            'ai.video_provider' => 'veo',
            'filesystems.disks.s3.bucket' => null,
        ]);
        Storage::fake('public');
        Http::fake([
            'api.x.ai/v1/videos/request-1' => Http::sequence()
                ->push(['status' => 'pending'])
                ->push(['status' => 'done', 'video' => ['url' => 'https://vidgen.x.ai/video.mp4', 'respect_moderation' => true]]),
            'vidgen.x.ai/video.mp4' => Http::response('raw-mp4-bytes'),
        ]);
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => '123456']);
        $video = VideoCollateral::create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads (Performance Max)',
            'status' => 'generating',
            'provider' => 'xai',
            'operation_name' => 'request-1',
            'script' => str_repeat('Narrated words ', 30),
        ]);

        $job = new CheckVideoStatus($video);
        $job->handle(app(GeminiService::class));
        $this->assertSame('generating', $video->fresh()->status);
        $job->handle(app(GeminiService::class));

        $video->refresh();
        $this->assertSame('completed', $video->status);
        $this->assertSame('raw-mp4-bytes', Storage::disk('public')->get($video->s3_path));
        Http::assertSent(fn ($request) => $request->url() === 'https://vidgen.x.ai/video.mp4' && ! $request->hasHeader('Authorization'));
        Queue::assertNotPushed(ExtendVideoForScript::class);
        Queue::assertNotPushed(FinalizeVideoNarration::class);
        Queue::assertNotPushed(ExtendPMaxVideo::class);
    }

    public function test_pmax_requests_at_least_ten_seconds_on_xai(): void
    {
        Http::fake(['api.x.ai/v1/videos/generations' => Http::response(['request_id' => 'request-1'])]);

        app(VideoGenerationService::class)->startGeneration('a short narration', forPerformanceMax: true);

        Http::assertSent(fn ($request) => $request['duration'] === 10 && $request['aspect_ratio'] === '16:9');
    }

    public function test_polling_does_not_download_a_moderated_result(): void
    {
        Http::fake([
            'api.x.ai/v1/videos/request-1' => Http::response([
                'status' => 'done', 'video' => ['url' => 'https://vidgen.x.ai/video.mp4', 'respect_moderation' => false],
            ]),
        ]);

        $this->assertSame(['error' => 'video did not pass moderation'], app(XaiService::class)->checkVideoStatus('request-1'));
        Http::assertSentCount(1);
    }
}
