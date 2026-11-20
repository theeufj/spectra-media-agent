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
