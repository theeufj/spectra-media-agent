<?php

namespace Tests\Feature;

use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Services\Creative\CreativeRelease;
use App\Services\Creative\GoogleDisplayBannerRenderer;
use App\Services\Creative\GoogleDisplayBannerSet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoogleDisplayBannerReleaseTest extends TestCase
{
    use DatabaseTransactions;

    public function test_quality_review_sees_all_twenty_rendered_banner_sizes_and_rejects_a_single_unreadable_cta(): void
    {
        config(['filesystems.disks.s3.bucket' => null]);
        $disk = Storage::fake('public');
        $strategy = Strategy::factory()->create(['platform' => 'Google Ads', 'campaign_type' => 'display', 'signed_off_at' => now()]);
        $strategy->adCopies()->create(['platform' => 'Google Ads', 'headlines' => ['See the range'], 'descriptions' => ['View our products.'], 'should_deploy' => true]);
        ob_start();
        imagepng(imagecreatetruecolor(330, 260));
        $bytes = (string) ob_get_clean();
        $path = "collateral/images/{$strategy->campaign_id}/tested.png";
        $disk->put($path, $bytes);
        $set = (new GoogleDisplayBannerSet)->generate($bytes, $path, 'Customer', 'See the range', 'Learn More');
        $image = ImageCollateral::create(['strategy_id' => $strategy->id, 'campaign_id' => $strategy->campaign_id, 's3_path' => $path,
            'cloudfront_url' => '/tested.png', 'platform' => 'Google Ads', 'is_active' => true, 'should_deploy' => true,
            'format' => 'square', 'google_display_banners' => $set]);
        $copy = $this->createMock(\App\Services\AdminMonitorService::class);
        $copy->method('reviewAdCopy')->willReturn(['programmatic_validation' => ['is_valid' => true],
            'gemini_feedback' => ['factual_accuracy' => true, 'intent_relevance' => true, 'blocking_issues' => []]]);
        $gemini = $this->createMock(\App\Services\GeminiService::class);
        $gemini->expects($this->exactly(2))->method('generateContent')->willReturnCallback(function ($model, $prompt, $config, $instruction, $think, $search, $retries, $payload) use ($image) {
            $render = base64_decode($payload);
            $this->assertNotFalse(getimagesizefromstring($render));
            if (str_contains($prompt, 'fixed-size display advertisements')) {
                $this->assertSame(2048, getimagesizefromstring($render)[0]);
                $assets = array_map(fn ($id) => ['id' => $id, 'passed' => $id !== 2, 'feedback' => $id === 2 ? 'The CTA is clipped.' : 'Readable layout.'], range(1, 20));
            } else {
                $assets = [['id' => $image->id, 'passed' => true, 'feedback' => 'Readable source art.']];
            }

            return ['text' => json_encode(['assets' => $assets], JSON_THROW_ON_ERROR)];
        });
        $reviewer = new \App\Services\Creative\CreativeReleaseReviewer($copy, $gemini, app(\App\Services\Campaigns\AdvertisingEvidence::class));
        $verdict = $reviewer->review($strategy, app(CreativeRelease::class)->selection($strategy));
        $this->assertSame('needs_review', $verdict['status']);
        $this->assertCount(20, $verdict['google_display_banner_hashes'][$image->id]);
        $this->assertCount(1, $verdict['issues']);
        $this->assertMatchesRegularExpression('/Image #\d+, \d+x\d+: The CTA is clipped/', $verdict['issues'][0]);
    }

    public function test_approval_pins_the_individual_display_files_and_the_native_button(): void
    {
        config(['filesystems.disks.s3.bucket' => null]);
        Storage::fake('public');
        $strategy = Strategy::factory()->create(['platform' => 'Google Ads', 'campaign_type' => 'display', 'signed_off_at' => now(), 'conversion_goals' => ['primary_goal' => 'signup']]);
        $strategy->adCopies()->create(['platform' => 'Google Ads', 'headlines' => ['Book a visit'], 'descriptions' => ['Find your next appointment.'], 'should_deploy' => true]);
        $photo = imagecreatetruecolor(200, 180);
        imagefill($photo, 0, 0, 0x778899);
        ob_start();
        imagepng($photo);
        $bytes = (string) ob_get_clean();
        $path = "collateral/images/{$strategy->campaign_id}/source.jpg";
        $sets = new GoogleDisplayBannerSet;
        $set = $sets->generate($bytes, $path, 'Sample Business', 'Book a visit', 'Sign Up');
        $image = ImageCollateral::create(['strategy_id' => $strategy->id, 'campaign_id' => $strategy->campaign_id,
            's3_path' => $path, 'cloudfront_url' => 'https://images.example.test/source.jpg', 'is_active' => true, 'should_deploy' => true, 'platform' => 'Google Ads', 'format' => 'square',
            'google_display_banners' => $set]);
        $hashes = $sets->verifiedHashes($image);
        $this->assertSame(array_keys(GoogleDisplayBannerRenderer::SIZES), array_keys($hashes));
        $release = app(CreativeRelease::class);
        $selection = $release->selection($strategy);
        $strategy->update(['creative_release_review' => ['fingerprint' => $selection['fingerprint'], 'status' => 'passed', 'issues' => [], 'google_display_banner_hashes' => [$image->id => $hashes]]]);
        $release->approve($strategy, $selection['fingerprint']);
        $this->assertSame('Sign Up', $strategy->creative_approval['google_native_cta']);
        $this->assertEquals($hashes, $strategy->creative_approval['google_display_banner_hashes'][$image->id]);
        $this->assertTrue($release->isApproved($strategy));
        $image->update(['google_display_banners' => [...$set, 'headline' => 'A different offer']]);
        $this->assertFalse($release->isApproved($strategy));
        Storage::disk('public')->put($set['sizes']['300x250'], 'replaced after approval');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('300x250');
        $sets->verifiedHashes($image);
    }
}
