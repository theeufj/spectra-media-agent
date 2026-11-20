<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CreativeUsage;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Plan;
use App\Models\User;
use App\Models\VideoCollateral;
use App\Services\Creative\StarterCreativeQuota;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StarterCreativeQuotaTest extends TestCase
{
    use DatabaseTransactions;

    private function account(): Customer
    {
        $plan = Plan::updateOrCreate(['slug' => 'starter'], ['name' => 'Starter', 'price_cents' => 9900, 'creative_limits' => ['image_generations' => 1,
            'video_generations' => 1, 'refinements' => 2, 'max_refinements_per_item' => 2, 'max_extensions_per_video' => 1]]);
        $customer = Customer::factory()->create(['plan_id' => $plan->id]);
        $customer->users()->attach(User::factory()->create(), ['role' => 'owner']);

        return $customer;
    }

    public function test_monthly_units_are_shared_across_campaigns_and_formats_and_only_consumed_once(): void
    {
        $customer = $this->account();
        $quota = app(StarterCreativeQuota::class);
        $one = Campaign::factory()->create(['customer_id' => $customer->id]);
        $two = Campaign::factory()->create(['customer_id' => $customer->id]);
        $key = $quota->reserve($one->customer, 'image', 'strategy:1:slot:0');
        $this->assertIsString($key);
        $this->assertSame($key, $quota->reserve($one->customer, 'image', 'strategy:1:slot:0'));
        $this->assertFalse($quota->reserve($two->customer, 'image', 'strategy:2:slot:0'));
        $this->assertSame(0, $quota->remaining($customer, 'image'));
        $row = ['campaign_id' => $one->id, 'platform' => 'Google Ads', 's3_path' => 'one.jpg', 'cloudfront_url' => '/one.jpg'];
        $this->assertIsString(ImageCollateral::createConcept($one, [$row + ['format' => 'square'], $row + ['format' => 'landscape']], quotaReservation: $key));
        $quota->release($key);
        $this->assertFalse($quota->reserve($one->customer, 'image', 'strategy:1:slot:0'));
        $this->assertSame(1, CreativeUsage::where('customer_id', $customer->id)->sole()->image_generations_used);
        $this->assertNull(ImageCollateral::createConcept($one, [$row + ['format' => 'square']], quotaReservation: $key));
        $this->assertSame(2, $one->imageCollaterals()->count());
    }

    public function test_workers_check_monthly_limits_before_generating_and_never_spend_starter_units_on_meta(): void
    {
        $customer = $this->account();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = \App\Models\Strategy::factory()->create(['campaign_id' => $campaign->id, 'campaign_type' => 'performance_max',
            'platform' => 'Google Ads', 'signed_off_at' => now(), 'generate_video' => true,
            'imagery_strategy' => 'Show the camera on a wooden desk in natural light.', 'video_strategy' => 'Show the camera in use.']);
        $strategy->adCopies()->create(['platform' => 'Google Ads', 'headlines' => ['Try the camera'], 'descriptions' => ['See it in action.']]);
        $quota = app(StarterCreativeQuota::class);
        $imageKey = $quota->reserve($customer, 'image', 'prior-image');
        $this->assertIsString($imageKey);
        $quota->finish($imageKey);
        $gemini = $this->createMock(\App\Services\GeminiService::class);
        $gemini->expects($this->never())->method('generateContent');
        $monitor = $this->createMock(\App\Services\AdminMonitorService::class);
        $monitor->expects($this->never())->method('reviewImagePrompt');
        $videoGenerator = $this->createMock(\App\Services\VideoGeneration\VideoGenerationService::class);
        $videoGenerator->expects($this->never())->method('startGeneration');
        (new \App\Jobs\GenerateImage($campaign, $strategy))->handle($gemini, $monitor);
        (new \App\Jobs\GenerateVideo($campaign, $strategy, 'Facebook Ads', force: true))->handle($videoGenerator, $gemini);
        $this->assertSame(1, $quota->remaining($customer, 'video'));
        $videoKey = $quota->reserve($customer, 'video', 'prior-video');
        $this->assertIsString($videoKey);
        $quota->finish($videoKey);
        (new \App\Jobs\GenerateVideo($campaign, $strategy, 'Google', force: true))->handle($videoGenerator, $gemini);
        $this->assertSame(0, $campaign->videoCollaterals()->count());
        $this->assertSame(0, $campaign->imageCollaterals()->count());
    }

    public function test_refinements_work_at_the_campaign_cap_and_finish_on_the_second_revision(): void
    {
        $customer = $this->account();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = \App\Models\Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads']);
        config(['filesystems.disks.s3.bucket' => null]);
        $disk = \Illuminate\Support\Facades\Storage::fake('public');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aO8sAAAAASUVORK5CYII=');
        $disk->put('example.png', $png);
        $id = ImageCollateral::createConcept($campaign, [['campaign_id' => $campaign->id, 'strategy_id' => $strategy->id, 'platform' => 'Google Ads', 'format' => 'square',
            's3_path' => 'example.png', 'cloudfront_url' => '/example.png']]);
        $original = ImageCollateral::where('concept_key', $id)->sole();
        $this->assertFalse(ImageCollateral::canGenerateForCampaign($campaign));
        $gemini = $this->createMock(\App\Services\GeminiService::class);
        $gemini->expects($this->exactly(2))->method('refineImage')->willReturn(['data' => base64_encode($png), 'mimeType' => 'image/png']);
        (new \App\Jobs\RefineImage($original, 'Brighten the image'))->handle($gemini);
        $second = ImageCollateral::where('parent_id', $original->id)->sole();
        (new \App\Jobs\RefineImage($second, 'Use a warmer tone'))->handle($gemini);
        $third = ImageCollateral::where('parent_id', $second->id)->sole();
        (new \App\Jobs\RefineImage($third, 'A third revision'))->handle($gemini);
        (new \App\Jobs\RefineImage($original, 'A new branch'))->handle($gemini);
        $this->assertSame(1, ImageCollateral::conceptsForCampaign($campaign));
        $this->assertSame(3, ImageCollateral::where('concept_key', $id)->count());
        $this->assertSame(2, CreativeUsage::where('customer_id', $customer->id)->sole()->refinements_used);
    }

    public function test_failed_video_returns_its_allowance_to_the_original_period_and_can_be_retried(): void
    {
        $this->travelTo(now()->startOfMonth());
        $customer = $this->account();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $quota = app(StarterCreativeQuota::class);
        $key = $quota->reserve($customer, 'video', 'video:slot');
        $this->assertIsString($key);
        $video = VideoCollateral::reserve($campaign, ['platform' => 'Google Ads (Performance Max)', 'variation_index' => 0, 'status' => 'pending', 'script' => 'Demo',
            'is_active' => true, 'generation_metadata' => ['starter_quota_key' => $key]]);
        $this->assertNotNull($video);
        $oldUsage = CreativeUsage::where('customer_id', $customer->id)->sole();
        $this->travelTo(now()->addMonth());
        $video->update(['status' => 'failed']);
        $this->assertSame(0, $oldUsage->fresh()->video_generations_used);
        $this->assertSame($key, $quota->reserve($customer, 'video', 'video:slot'));
        $retry = VideoCollateral::reserve($campaign, ['platform' => 'Google Ads (Performance Max)', 'variation_index' => 0, 'status' => 'generating', 'script' => 'Demo',
            'is_active' => true, 'generation_metadata' => ['starter_quota_key' => $key]]);
        $this->assertNotNull($retry);
        $retry->update(['status' => 'completed']);
        $quota->release($key);
        $this->assertSame(0, $quota->remaining($customer, 'video'));
        $this->assertSame(1, (int) CreativeUsage::where('customer_id', $customer->id)->sum('video_generations_used'));
    }
}
