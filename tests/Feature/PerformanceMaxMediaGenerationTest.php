<?php

namespace Tests\Feature;

use App\Jobs\GenerateImage;
use App\Jobs\GenerateVideo;
use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\Plan;
use App\Models\Strategy;
use App\Services\AdminMonitorService;
use App\Services\Campaigns\CollateralPlan;
use App\Services\GeminiService;
use App\Services\VideoGeneration\PerformanceMaxVideo;
use App\Services\VideoGeneration\VideoGenerationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
use Tests\TestCase;

class PerformanceMaxMediaGenerationTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{Campaign, Strategy} */
    private function campaign(): array
    {
        $campaign = Campaign::factory()->create();
        $plan = Plan::create([
            'slug' => 'test-performance-max-media', 'name' => 'Test', 'price_cents' => 100, 'billing_interval' => 'month',
            'creative_limits' => ['image_generations' => 6, 'video_generations' => 1],
        ]);
        $campaign->customer->forceFill(['plan_id' => $plan->id])->save();
        $strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'campaign_type' => 'performance_max',
            'platform' => 'Google Ads (Performance Max)',
            'signed_off_at' => now(),
            'generate_video' => true,
            'imagery_strategy' => 'An agent checking the campaign on a laptop, with morning light on the desk.',
            'creative_concepts' => [['selling_idea' => 'See campaigns', 'evidence' => 'A campaign dashboard', 'visual' => 'A laptop on a desk']],
        ]);
        $strategy->adCopies()->create(['platform' => $strategy->platform, 'headlines' => ['See Your Campaigns'], 'descriptions' => ['See your campaigns in one place.']]);

        return [$campaign->fresh(), $strategy];
    }

    public function test_live_portrait_draft_uses_xai_portrait_and_leaves_selected_images_in_place(): void
    {
        [$campaign, $strategy] = $this->campaign();
        $strategy->update(['deployment_status' => 'active']);
        $existing = ImageCollateral::create([
            'campaign_id' => $campaign->id, 'strategy_id' => $strategy->id, 'platform' => $strategy->platform,
            's3_path' => 'existing.png', 'cloudfront_url' => 'https://images.test/existing.png', 'format' => 'square', 'should_deploy' => true,
        ]);
        config(['ai.image_provider' => 'xai', 'services.xai.api_key' => 'test-key', 'filesystems.disks.s3.bucket' => null]);
        Cache::forget('xai:unavailable');
        Storage::fake('public');
        $base = (string) ImageManager::gd()->create(768, 1024)->fill('#e18e57')->encode(new PngEncoder);
        Http::fake(['api.x.ai/*' => Http::response(['data' => [['b64_json' => base64_encode($base)]]])]);
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->never())->method('generateImage');

        (new GenerateImage($campaign, $strategy, onlyFormat: 'portrait'))->handle($gemini, app(AdminMonitorService::class));

        Http::assertSent(fn ($request) => $request['aspect_ratio'] === '3:4');
        Http::assertSentCount(1);
        $portrait = $strategy->imageCollaterals()->where('format', 'portrait')->sole();
        $this->assertSame([960, 1200], array_slice(getimagesizefromstring(Storage::disk('public')->get($portrait->s3_path)), 0, 2));
        $this->assertFalse($portrait->should_deploy);
        $this->assertTrue($existing->fresh()->should_deploy);
    }

    public function test_performance_max_gets_a_video_when_the_plan_has_one_slot(): void
    {
        [$campaign, $strategy] = $this->campaign();

        $jobs = collect(app(CollateralPlan::class)->forStrategy($campaign, $strategy))->filter(fn ($job) => $job instanceof GenerateVideo);

        $this->assertCount(1, $jobs);
        $this->assertSame('Google Ads (Performance Max)', (new \ReflectionProperty(GenerateVideo::class, 'platform'))->getValue($jobs->first()));
    }

    public function test_openrouter_requests_enough_video_and_probe_measures_the_video_stream(): void
    {
        config(['ai.video_provider' => 'grok', 'services.openrouter.api_key' => 'test-key']);
        Http::fake(['openrouter.ai/*' => Http::response(['id' => 'video'])]);

        app(VideoGenerationService::class)->startGeneration('Show the offer', forPerformanceMax: true);
        Http::assertSent(fn ($request) => $request['duration'] >= PerformanceMaxVideo::MIN_SECONDS);

        Process::fake(['*' => Process::result('{"streams":[{"duration":"11.520"}]}')]);
        $this->assertSame(11.52, PerformanceMaxVideo::duration('test mp4'));
    }
}
