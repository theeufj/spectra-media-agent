<?php

namespace Tests\Feature;

use App\Jobs\UploadPMaxVideoAssets;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\Creative\CreativeRelease;
use App\Services\GoogleAds\VideoServices\UploadPMaxVideoToGoogle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PerformanceMaxVideoUploadTest extends TestCase
{
    use DatabaseTransactions;

    public function test_selected_campaign_video_reuses_pending_upload_and_persists_the_processed_id(): void
    {
        config(['services.youtube.client_id' => null]);
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'end_date' => now()->addDay()]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'campaign_type' => 'performance_max',
            'signed_off_at' => now(), 'google_ads_campaign_id' => 'customers/1234567890/campaigns/123']);
        $video = VideoCollateral::create(['campaign_id' => $campaign->id, 'strategy_id' => $strategy->id,
            'platform' => 'Google Ads (Performance Max)', 'status' => 'completed', 'is_active' => true, 'should_deploy' => false,
            'provider' => 'xai', 's3_path' => 'videos/ad.mp4', 'generation_metadata' => ['duration_seconds' => 12.0]]);
        $upload = 'customers/1234567890/youTubeVideoUploads/42';
        $google = $this->createMock(UploadPMaxVideoToGoogle::class);
        $google->expects($this->once())->method('start')->with('1234567890', 'videos/ad.mp4', $campaign->name.' - ad video '.$video->id, true)->willReturn($upload);
        $google->expects($this->once())->method('processedVideoId')->with('1234567890', $upload)->willReturn('abcdefghijk');
        $google->expects($this->never())->method('isLinked');
        $this->app->bind(UploadPMaxVideoToGoogle::class, fn () => $google);

        $job = new UploadPMaxVideoAssets($strategy->id, '1234567890', videoIds: [$video->id], prepareOnly: true);
        $job->handle();
        $this->assertUploadNotStarted($video);

        $video->update(['should_deploy' => true]);
        $job->handle();
        $this->assertUploadNotStarted($video);
        Storage::fake('public')->put('videos/ad.mp4', 'reviewed video bytes');
        $strategy->adCopies()->create(['platform' => 'Google Ads', 'headlines' => ['Reviewed copy'], 'descriptions' => ['Reviewed description.'], 'should_deploy' => true]);
        $release = app(CreativeRelease::class);
        $fingerprint = $release->selection($strategy)['fingerprint'];
        $strategy->update(['creative_release_review' => ['fingerprint' => $fingerprint, 'status' => 'passed', 'issues' => [],
            'video_hashes' => [$video->id => hash('sha256', 'reviewed video bytes')]]]);
        $release->approve($strategy, $fingerprint);
        $job->handle();
        $this->assertSame($upload, $video->fresh()->generation_metadata['google_ads_video_upload']);
        $this->assertNull($video->fresh()->youtube_video_id);

        $job->handle();
        $this->assertSame('abcdefghijk', $video->fresh()->youtube_video_id);
        $job->handle();
    }

    private function assertUploadNotStarted(VideoCollateral $video): void
    {
        $this->assertNull($video->fresh()->generation_metadata['google_ads_video_upload'] ?? null);
    }
}
