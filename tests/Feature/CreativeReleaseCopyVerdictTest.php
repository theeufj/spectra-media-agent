<?php

namespace Tests\Feature;

use App\Jobs\ReviewCreativeRelease;
use App\Models\Strategy;
use App\Services\AdminMonitorService;
use App\Services\Campaigns\AdvertisingEvidence;
use App\Services\Creative\CreativeRelease;
use App\Services\Creative\CreativeReleaseReviewer;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CreativeReleaseCopyVerdictTest extends TestCase
{
    use DatabaseTransactions;

    private function strategy(): Strategy
    {
        $strategy = Strategy::factory()->create(['platform' => 'Facebook Ads', 'campaign_type' => 'display', 'signed_off_at' => now()]);
        $strategy->adCopies()->create(['platform' => 'Facebook Ads', 'headlines' => ['Bicycle Repairs', 'Book a Bike Service', 'Repairs for Commuters'],
            'descriptions' => ['Book a service for your bicycle.', 'See workshop times and book your visit.'], 'should_deploy' => true]);

        return $strategy;
    }

    private function reviewer(GeminiService $model): CreativeReleaseReviewer
    {
        return new CreativeReleaseReviewer(new AdminMonitorService($model), $model, app(AdvertisingEvidence::class));
    }

    public static function unavailableResponses(): array
    {
        return [['null'], ['invalid_json'], ['timeout']];
    }

    #[DataProvider('unavailableResponses')]
    public function test_an_outage_takes_the_existing_bounded_job_retry_path(string $failure): void
    {
        $strategy = $this->strategy();
        $release = app(CreativeRelease::class);
        $fingerprint = $release->selection($strategy)['fingerprint'];
        $model = $this->createMock(GeminiService::class);
        $model->expects($this->once())->method('generateContent')->willReturnCallback(fn () => match ($failure) {
            'null' => null,
            'invalid_json' => ['text' => '{not valid JSON'],
            'timeout' => throw new RuntimeException('private network error'),
            default => throw new \InvalidArgumentException('Unknown reviewer failure fixture: '.$failure),
        });

        $job = new ReviewCreativeRelease($strategy->id, $fingerprint);
        $this->assertSame(2, $job->tries);
        try {
            $job->handle($release, $this->reviewer($model));
            $this->fail('An unavailable model must not be saved as a copy verdict.');
        } catch (RuntimeException $e) {
            $this->assertSame('The creative reviewer could not return an ad-copy verdict.', $e->getMessage());
            $this->assertNull($strategy->fresh()->creative_release_review);
            $job->failed($e);
        }

        $fresh = $strategy->fresh();
        $this->assertSame('unavailable', $release->state($fresh)['status']);
        $this->assertFalse($release->isApproved($fresh));
        $this->travel(6)->minutes();
        $this->assertSame('pending', $release->state($fresh, true)['status']);
        Queue::assertPushed(ReviewCreativeRelease::class);
    }

    public function test_deterministic_placement_errors_are_returned_without_waiting_on_the_model(): void
    {
        $strategy = $this->strategy();
        $strategy->adCopies()->firstOrFail()->update(['headlines' => ['Headline only']]);
        $release = app(CreativeRelease::class);
        $model = $this->createMock(GeminiService::class);
        $model->expects($this->never())->method('generateContent');
        $result = $this->reviewer($model)->review($strategy, $release->selection($strategy));

        $this->assertSame('needs_review', $result['status']);
        $this->assertStringContainsString('Expected 3 headlines, but got 1.', implode(' ', $result['issues']));
    }

    public function test_complete_verdict_surfaces_specific_plain_text_reasons_and_blocks_on_missing_safety_flags(): void
    {
        $strategy = $this->strategy();
        $release = app(CreativeRelease::class);
        $model = $this->createMock(GeminiService::class);
        $model->expects($this->once())->method('generateContent')->willReturn(['text' => json_encode([
            'overall_score' => 99, 'feedback' => [], 'factual_accuracy' => false,
            'blocking_issues' => ['<b>Headline 2</b> promises a two-hour turnaround absent from the workshop prices.'],
        ])]);
        $result = $this->reviewer($model)->review($strategy, $release->selection($strategy));
        $issues = implode(' ', $result['issues']);

        $this->assertSame('needs_review', $result['status']);
        $this->assertStringContainsString('Headline 2 promises a two-hour turnaround absent from the workshop prices.', $issues);
        $this->assertStringNotContainsString('<b>', $issues);
        $this->assertStringContainsString('claims against the campaign evidence', $issues);
        $this->assertStringContainsString('match the offer to the intended customer', $issues);
    }

    public function test_only_legacy_generic_only_rejections_get_one_fresh_full_review(): void
    {
        $strategy = $this->strategy();
        $release = app(CreativeRelease::class);
        $fingerprint = $release->selection($strategy)['fingerprint'];
        $generic = 'Ad copy #123 did not pass the factual and placement checks. Regenerate or deselect it.';
        $legacy = ['fingerprint' => $fingerprint, 'status' => 'needs_review', 'issues' => [$generic]];
        $strategy->update(['creative_release_review' => [...$legacy, 'issues' => [$generic, 'Image #13: Incorrect service shown.']]]);
        $this->assertSame('needs_review', $release->state($strategy, true)['status']);
        Queue::assertNotPushed(ReviewCreativeRelease::class);
        $strategy->update(['creative_release_review' => $legacy]);
        $this->assertSame('pending', $release->state($strategy, true)['status']);
        Queue::assertPushed(ReviewCreativeRelease::class);

        $model = $this->createMock(GeminiService::class);
        $model->expects($this->once())->method('generateContent')->willReturn(['text' => json_encode([
            'overall_score' => 89, 'feedback' => [], 'factual_accuracy' => true, 'intent_relevance' => true,
            // Missing required blocker list must never pass.
        ])]);
        $job = new ReviewCreativeRelease($strategy->id, $fingerprint);
        $job->handle($release, $this->reviewer($model));
        $job->handle($release, $this->reviewer($model));

        $fresh = $strategy->fresh();
        $this->assertSame('needs_review', $release->state($fresh)['status']);
        $this->assertSame(2, $fresh->creative_release_review['copy_review_version']);
        $this->assertFalse($release->isApproved($fresh));
        $this->assertStringContainsString('whether there were blocking issues', implode(' ', $release->issues($fresh)));
    }
}
