<?php

namespace Tests\Feature;

use App\Jobs\GenerateImage;
use App\Jobs\GenerateVideo;
use App\Models\Campaign;
use App\Models\Plan;
use App\Models\Strategy;
use App\Models\User;
use App\Services\AdminMonitorService;
use App\Services\Creative\MetaOfferArt;
use App\Services\GeminiService;
use App\Services\VideoGeneration\MetaOfferVideo;
use App\Services\VideoGeneration\VideoGenerationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\TestCase;

class MetaOfferCreativeTest extends TestCase
{
    use DatabaseTransactions;

    private function offer(): array
    {
        Storage::fake('public');
        config(['filesystems.disks.s3.bucket' => null]);
        Mail::fake();
        $campaign = Campaign::factory()->create(['product_focus' => 'AI agents that document advertising decisions.']);
        $customer = $campaign->customer;
        $customer->users()->attach(User::factory()->create()->id, ['role' => 'owner']);
        $plan = Plan::create(['slug' => 'test-meta-offer', 'name' => 'Test Meta', 'price_cents' => 0, 'billing_interval' => 'month', 'creative_limits' => ['image_generations' => 3, 'video_generations' => 1]]);
        $customer->forceFill(['name' => 'Offer Studio', 'website' => 'https://offer.example', 'plan_id' => $plan->id])->save();
        $customer->brandGuideline()->create([
            'brand_voice' => [], 'tone_attributes' => [], 'color_palette' => ['primary_colors' => ['#FF4D00']],
            'typography' => [], 'visual_style' => [], 'messaging_themes' => [], 'unique_selling_propositions' => [],
            'target_audience' => [], 'brand_personality' => [], 'extracted_at' => now(),
        ]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Facebook Ads', 'campaign_type' => 'display',
            'imagery_strategy' => 'Create a pretend dashboard above a laptop keyboard.', 'video_strategy' => 'N/A',
            'signed_off_at' => now(), 'deployed_at' => now()]);
        $selected = $strategy->adCopies()->create(['platform' => 'Facebook Ads', 'headlines' => ['Account activity in one place'], 'descriptions' => ['See the reasons behind each change.'], 'should_deploy' => true]);
        $strategy->adCopies()->create(['platform' => 'Facebook Ads', 'headlines' => ['Unselected draft'], 'descriptions' => [], 'should_deploy' => false]);

        return [$campaign->fresh(), $strategy, $selected];
    }

    public function test_native_images_use_selected_copy_brand_and_all_meta_placements(): void
    {
        [$campaign, $strategy, $selected] = $this->offer();
        $ai = $this->createMock(GeminiService::class);
        $ai->expects($this->never())->method('generateContent');
        (new GenerateImage($campaign, $strategy))->handle($ai, app(AdminMonitorService::class));
        $images = $strategy->imageCollaterals()->get()->keyBy('format');
        $this->assertEqualsCanonicalizing(['square', 'landscape', 'portrait'], $images->keys()->all());
        foreach (['square' => [1024, 1024], 'landscape' => [1200, 628], 'portrait' => [960, 1200]] as $format => $dimensions) {
            $image = $images[$format];
            $this->assertFalse($image->should_deploy);
            $this->assertSame('brand_offer', $image->layout);
            $this->assertSame($selected->id, $image->generation_metadata['ad_copy_id']);
            $this->assertSame('brand_and_copy', $image->generation_metadata['source']);
            $png = Storage::disk('public')->get($image->s3_path);
            $this->assertSame($dimensions, array_slice(getimagesizefromstring($png), 0, 2));
            $decoded = imagecreatefromstring($png);
            $this->assertSame(0xFF4D00, imagecolorat($decoded, 5, 5) & 0xFFFFFF);
        }
    }

    public function test_offer_layout_places_the_supplied_picture_without_redrawing_it(): void
    {
        [$campaign, $strategy] = $this->offer();
        $source = (string) ImageManager::gd()->create(150, 90)->fill('#2a9912')->toPng();
        $png = app(MetaOfferArt::class)->render($campaign, 'Keep the original', null, 1200, 628, 0, $source);
        $image = imagecreatefromstring($png);
        $this->assertSame(0x2A9912, imagecolorat($image, 1050, 307) & 0xFFFFFF);
        $this->assertFalse(MetaOfferArt::applies($campaign, $strategy, 'Google Ads'));
        $campaign->product_focus = 'Handmade ceramic mugs';
        $this->assertFalse(MetaOfferArt::applies($campaign, $strategy, 'Facebook Ads'));
    }

    public function test_meta_video_revises_the_missing_brand_then_generates_a_narrated_portrait_candidate(): void
    {
        [$campaign, $strategy] = $this->offer();
        $script = 'Offer Studio documents your account changes. Review the reasons in one place.';
        $ai = $this->createMock(GeminiService::class);
        $this->app->instance(GeminiService::class, $ai);
        $ai->expects($this->exactly(2))->method('generateContent')->willReturnOnConsecutiveCalls(['text' => 'Look at the dashboard. Click now.'], ['text' => $script]);
        $ai->expects($this->once())->method('synthesizeSpeech')->with($script)->willReturn(str_repeat("\0", 48_000 * 2));
        $testEncoder = getenv('META_TEST_FFMPEG');
        if ($testEncoder) {
            config(['services.ffmpeg.path' => $testEncoder]);
        } else {
            Process::fake(function ($pending) {
                $this->assertContains('libx264', $pending->command);
                $this->assertContains('aac', $pending->command);
                $this->assertStringContainsString('slide1.png', file_get_contents($pending->path.'/frames.txt'));
                $this->assertSame([720, 1280], array_slice(getimagesize($pending->path.'/slide0.png'), 0, 2));
                file_put_contents($pending->path.'/finished.mp4', pack('N', 12).'ftypisom');

                return Process::result();
            });
        }

        (new GenerateVideo($campaign, $strategy, 'Facebook Ads', 1, true))->handle(app(VideoGenerationService::class), $ai);
        $video = $strategy->videoCollaterals()->firstOrFail();
        $this->assertSame('completed', $video->status);
        $this->assertFalse($video->should_deploy);
        $this->assertSame($script, $video->script);
        $this->assertSame('site_to_spend', $video->provider);
        $this->assertSame('9:16', $video->generation_metadata['aspect_ratio']);
        $this->assertSame(2.5, $video->generation_metadata['duration_seconds']);
        $mp4 = Storage::disk('public')->get($video->s3_path);
        $this->assertSame('ftyp', substr($mp4, 4, 4));
        if ($testEncoder) {
            $this->assertStringContainsString('avc1', $mp4);
            $this->assertStringContainsString('mp4a', $mp4);
        }
    }

    public function test_voiceover_rejects_scripts_that_omit_brand_or_overrun_the_budget(): void
    {
        $this->assertSame([], MetaOfferVideo::scenes('Offer Studio', 'Watch your ads. Compare the costs.'));
        $this->assertSame([], MetaOfferVideo::scenes('Offer Studio', 'Offer Studio. '.str_repeat('More words ', 20).'.'));
        $this->assertCount(2, MetaOfferVideo::scenes('Offer Studio', 'See OfferStudio in action. Review your account.'));
    }

    public function test_square_video_renders_its_text_in_a_square_frame(): void
    {
        [$campaign] = $this->offer();
        $script = 'See Offer Studio in action. Review your account.';
        $ai = $this->createMock(GeminiService::class);
        $ai->expects($this->once())->method('synthesizeSpeech')->with($script, $this->anything())
            ->willReturn(str_repeat("\0", 48_000 * 2));
        $this->app->instance(GeminiService::class, $ai);
        Process::fake(function ($pending) {
            $this->assertSame([720, 720], array_slice(getimagesize($pending->path.'/slide0.png'), 0, 2));
            $filter = $pending->command[array_search('-vf', $pending->command, true) + 1];
            $this->assertStringContainsString('s=720x720', $filter);
            file_put_contents($pending->path.'/finished.mp4', pack('N', 12).'ftypisom');

            return Process::result();
        });
        $render = app(MetaOfferVideo::class)->render($campaign, $script, 0, '1:1');
        $this->assertSame('1:1', $render['aspect_ratio']);
        $this->assertSame(2.5, $render['duration_seconds']);
    }

    public function test_video_references_are_scoped_before_paying_for_narration(): void
    {
        [$campaign] = $this->offer();
        $foreign = \App\Models\ImageCollateral::create(['campaign_id' => Campaign::factory()->create()->id, 'is_active' => true,
            'platform' => 'Facebook Ads', 's3_path' => 'foreign.png', 'cloudfront_url' => '/foreign.png']);
        $ai = $this->createMock(GeminiService::class);
        $ai->expects($this->never())->method('synthesizeSpeech');
        $this->app->instance(GeminiService::class, $ai);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(MetaOfferVideo::class)->render($campaign, 'See Offer Studio in action. Review your account.', 0, '1:1', $foreign->id);
    }
}
