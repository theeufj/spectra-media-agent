<?php

namespace Tests\Feature;

use App\Jobs\ReviewCreativeRelease;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\AdminMonitorService;
use App\Services\Campaigns\AdvertisingEvidence;
use App\Services\Creative\CreativeRelease;
use App\Services\Creative\CreativeReleaseReviewer;
use App\Services\Creative\VideoReviewInput;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreativeReleaseTest extends TestCase
{
    use DatabaseTransactions;

    public function test_large_video_review_uses_a_complete_encode_with_sound_and_preserves_short_inputs(): void
    {
        Process::fake([
            '*ffprobe*' => Process::result('{"streams":[{"duration":"11.2"}]}'),
            '*ffmpeg*' => Process::result('smaller complete mp4'),
        ]);
        $this->assertSame('tiny', VideoReviewInput::forInlineReview('tiny'));
        $this->assertSame('smaller complete mp4'.PHP_EOL, VideoReviewInput::forInlineReview(str_repeat('x', 800_000)));
        Process::assertRan(function ($process) {
            $command = $process->command;

            return is_array($command) && $command[0] === 'ffmpeg'
                && in_array('0:a:0?', $command, true) && in_array('0:v:0', $command, true)
                && ! array_intersect(['-t', '-shortest', '-an'], $command);
        });
    }

    private function strategy(): Strategy
    {
        $strategy = Strategy::factory()->create(['platform' => 'Facebook Ads', 'campaign_type' => 'display', 'signed_off_at' => now()]);
        $strategy->adCopies()->create(['platform' => 'Facebook Ads', 'headlines' => ['Manage Your Campaign'], 'descriptions' => ['Find the plan for your business.'], 'should_deploy' => true]);

        return $strategy;
    }

    public function test_the_saved_approval_is_exact_and_the_executor_cannot_read_unapproved_copy(): void
    {
        $strategy = $this->strategy();
        $release = app(CreativeRelease::class);
        $fingerprint = $release->selection($strategy)['fingerprint'];
        $strategy->update(['creative_release_review' => ['fingerprint' => $fingerprint, 'status' => 'passed', 'issues' => []]]);
        $release->approve($strategy, $fingerprint);
        $this->assertTrue($release->isApproved($strategy));

        $newCopy = $strategy->adCopies()->create(['platform' => 'Facebook Ads', 'headlines' => ['Unauthorised Changes'], 'descriptions' => ['Something else.'], 'should_deploy' => true]);
        $this->assertFalse($release->isApproved($strategy));
        $this->assertFalse($strategy->deployableAdCopies()->whereKey($newCopy->id)->exists());
        $this->expectException(ValidationException::class);
        $release->approve($strategy, $fingerprint);
    }

    public function test_reviewer_reads_the_rendered_image_and_video_and_blocks_an_unsupported_spoken_claim(): void
    {
        $strategy = $this->strategy();
        $image = ImageCollateral::create(['campaign_id' => $strategy->campaign_id, 'strategy_id' => $strategy->id,
            'platform' => 'Facebook Ads', 's3_path' => 'creative.png', 'cloudfront_url' => '/creative.png',
            'is_active' => true, 'should_deploy' => true]);
        $video = VideoCollateral::create(['campaign_id' => $strategy->campaign_id, 'strategy_id' => $strategy->id,
            'platform' => 'Facebook Ads', 's3_path' => 'creative.mp4', 'status' => 'completed',
            'is_active' => true, 'should_deploy' => true, 'script' => 'Manage your campaign.']);
        $disk = Storage::fake('public');
        ob_start();
        imagepng(imagecreatetruecolor(300, 300));
        $disk->put('creative.png', (string) ob_get_clean());
        $disk->put('creative.mp4', 'final clip for test');
        $copyReview = $this->createMock(AdminMonitorService::class);
        $copyReview->method('reviewAdCopy')->willReturn(['programmatic_validation' => ['is_valid' => true],
            'gemini_feedback' => ['factual_accuracy' => true, 'intent_relevance' => true, 'blocking_issues' => []]]);
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->exactly(2))->method('generateContent')->willReturnCallback(
            function (string $model, string $prompt, array $config, ?string $instruction, bool $thinking, bool $search, ?int $retries, ?string $data, string $mime) use ($image, $video) {
                if ($mime === 'image/jpeg') {
                    $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring(base64_decode($data))[2]);

                    return ['text' => json_encode(['assets' => [['id' => $image->id, 'passed' => true, 'feedback' => 'Clear composition.']]])];
                }
                $this->assertSame('video/mp4', $mime);
                $this->assertSame('final clip for test', base64_decode($data));
                $this->assertStringContainsString('spoken claims', $prompt);

                return ['text' => json_encode(['assets' => [['id' => $video->id, 'passed' => false, 'feedback' => 'Unsupported spoken pricing claim.']]])];
            }
        );
        $release = app(CreativeRelease::class);
        $fingerprint = $release->selection($strategy)['fingerprint'];
        (new ReviewCreativeRelease($strategy->id, $fingerprint))->handle(
            $release, new CreativeReleaseReviewer($copyReview, $gemini, app(AdvertisingEvidence::class))
        );
        $strategy->refresh();
        $this->assertSame('needs_review', $release->state($strategy)['status']);
        $this->assertStringContainsString('Unsupported spoken pricing claim', $release->issues($strategy)[0]);
        $this->expectException(ValidationException::class);
        $release->approve($strategy, $fingerprint);
    }
}
