<?php

namespace Tests\Feature;

use App\Jobs\ReviewCreativeSet;
use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Services\Creative\ConceptSelector;
use App\Services\Creative\ImageComposer;
use App\Services\Creative\RenderedSetReviewer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Intervention\Image\ImageManager;
use Tests\TestCase;

class CreativePlanningTest extends TestCase
{
    use DatabaseTransactions;

    private function candidates(): array
    {
        $subjects = ['rain beads on fabric', 'organised pockets holding tools', 'cyclist lifting backpack', 'bag leaning beside doorway', 'zip fastening between fingers', 'woven strap material'];

        return array_map(fn ($i) => [
            'selling_idea' => ['Weather resistance', 'Useful organisation', 'Commuter portability', 'Everyday use', 'Easy fastening', 'Comfortable straps'][$i],
            'evidence' => 'Product specification supplied by the business.',
            'subject' => $subjects[$i], 'visual' => $subjects[$i].' photographed in natural light.',
            'visual_style' => ['detail_photo', 'still_life', 'editorial_photo'][$i % 3],
            'composition' => ['close_up', 'overhead', 'environmental'][$i % 3],
            'layout' => 'editorial', 'headline' => 'Made for your day', 'supporting_copy' => 'Explore the bag and its features.', 'cta' => 'Explore the bag',
        ], range(0, 5));
    }

    public function test_selects_three_distinct_directions_with_stable_candidate_provenance(): void
    {
        $selector = new ConceptSelector;
        $selected = $selector->select($this->candidates(), 'search');
        $this->assertCount(3, $selected);
        $this->assertCount(3, array_unique(array_column($selected, 'visual_style')));
        $this->assertSame($selected, $selector->select($this->candidates(), 'search'));
        foreach ($selected as $concept) {
            $this->assertSame($this->candidates()[$concept['candidate_id'] - 1]['visual'], $concept['visual']);
        }
    }

    public function test_different_selling_words_cannot_disguise_six_desk_scenes(): void
    {
        $candidates = $this->candidates();
        foreach ($candidates as &$candidate) {
            $candidate['subject'] = 'Laptop beside papers on a desk';
        }
        $this->expectException(ValidationException::class);
        (new ConceptSelector)->select($candidates, 'search');
    }

    public function test_search_does_not_select_illustrations_or_add_copy_panels(): void
    {
        $candidates = $this->candidates();
        foreach ([0, 1, 2] as $index) {
            $candidates[$index]['visual_style'] = 'illustration';
        }
        $this->assertSame('editorial', (new ImageComposer)->layout('Google Ads', 1, 'performance_max', $candidates[3]));
        $selected = (new ConceptSelector)->select($candidates, 'search');
        foreach ($selected as $slot => $concept) {
            $this->assertNotSame('illustration', $concept['visual_style']);
            $this->assertSame('clean', (new ImageComposer)->layout('Google Ads (SEM)', $slot, 'search', $concept));
        }
    }

    public function test_composed_ad_preserves_the_picture_and_renders_a_legible_panel_in_each_size(): void
    {
        foreach ([[1024, 1024], [1200, 628], [300, 250]] as [$w, $h]) {
            $image = ImageManager::gd()->create($w, $h)->fill('#cc9955');
            (new ImageComposer)->compose($image, 'statement', 'Made for your day', 'Test Brand', 'Practical details for your daily commute.', 'Explore the bag', '#123456');
            $this->assertSame('cc9955', $image->pickColor(5, 5)->toHex());
            $this->assertSame('123456', $image->pickColor(5, $h - 5)->toHex());
        }
    }

    private function reviewStrategy(): Strategy
    {
        $strategy = Strategy::factory()->create(['creative_candidates' => $this->candidates(), 'creative_review' => [
            'run_id' => 'test-run', 'started_at' => now()->toIso8601String(), 'status' => 'pending',
        ]]);
        foreach (range(0, 2) as $slot) {
            ImageCollateral::create(['campaign_id' => $strategy->campaign_id, 'strategy_id' => $strategy->id,
                'platform' => $strategy->platform, 'format' => 'square', 's3_path' => "test/{$slot}.jpg", 'cloudfront_url' => "https://example.test/{$slot}.jpg", 'concept_key' => "00000000-0000-0000-0000-00000000000{$slot}",
                'generation_metadata' => ['creative_run_id' => 'test-run', 'slot' => $slot]]);
        }

        return $strategy;
    }

    public function test_only_the_weak_concept_is_revised_once_and_a_duplicate_review_spends_nothing(): void
    {
        $strategy = $this->reviewStrategy();
        $first = array_map(fn ($slot) => ['slot' => $slot, 'passed' => $slot !== 1, 'feedback' => 'Use the approved close detail instead of another desk.'], range(0, 2));
        $last = array_map(fn ($slot) => ['slot' => $slot, 'passed' => true, 'feedback' => 'Clear and distinct.'], range(0, 2));
        $reviewer = $this->createMock(RenderedSetReviewer::class);
        $reviewer->expects($this->exactly(2))->method('review')->willReturnOnConsecutiveCalls($first, $last);
        $reviewer->expects($this->once())->method('revise')->with($strategy, 1, 'test-run', $this->anything(), $this->anything());
        (new ReviewCreativeSet($strategy, 'test-run'))->handle($reviewer);
        $this->assertSame('passed', $strategy->fresh()->creative_review['status']);
        $this->assertTrue($strategy->fresh()->creative_review['retried_slots'][1]);
        (new ReviewCreativeSet($strategy, 'test-run'))->handle($reviewer);
    }

    public function test_review_failure_preserves_assets_and_exposes_manual_review(): void
    {
        $strategy = $this->reviewStrategy();
        $reviewer = $this->createMock(RenderedSetReviewer::class);
        $reviewer->expects($this->once())->method('review')->willThrowException(new \RuntimeException('Provider unavailable'));
        $reviewer->expects($this->never())->method('revise');
        (new ReviewCreativeSet($strategy, 'test-run'))->handle($reviewer);
        $this->assertSame('needs_review', $strategy->fresh()->creative_review['status']);
        $this->assertSame(3, $strategy->imageCollaterals()->count());
    }

    public function test_a_failed_final_review_stops_after_one_correction(): void
    {
        $strategy = $this->reviewStrategy();
        $results = array_map(fn ($slot) => ['slot' => $slot, 'passed' => $slot !== 0, 'feedback' => 'The subject remains unrelated to the offer.'], range(0, 2));
        $reviewer = $this->createMock(RenderedSetReviewer::class);
        $reviewer->expects($this->exactly(2))->method('review')->willReturn($results);
        $reviewer->expects($this->once())->method('revise');
        (new ReviewCreativeSet($strategy, 'test-run'))->handle($reviewer);
        (new ReviewCreativeSet($strategy, 'test-run'))->handle($reviewer);
        $this->assertSame('needs_review', $strategy->fresh()->creative_review['status']);
    }

    public function test_a_stale_review_cannot_modify_a_new_generation(): void
    {
        $strategy = $this->reviewStrategy();
        $reviewer = $this->createMock(RenderedSetReviewer::class);
        $reviewer->expects($this->never())->method('review');
        (new ReviewCreativeSet($strategy, 'old-run'))->handle($reviewer);
        $this->assertSame('pending', $strategy->fresh()->creative_review['status']);
    }

    public function test_manual_generation_recovers_the_missing_slot_instead_of_repeating_the_first(): void
    {
        $strategy = $this->reviewStrategy();
        $user = \App\Models\User::factory()->create(['email_verified_at' => now()]);
        $strategy->campaign->customer->users()->attach($user->id, ['role' => 'owner']);
        session(['active_customer_id' => $strategy->campaign->customer_id]);
        $strategy->imageCollaterals()->where('generation_metadata->slot', 1)->delete();
        $this->actingAs($user)->post(route('campaigns.collateral.image.store', [
            'campaign' => $strategy->campaign, 'strategy' => $strategy,
        ]))->assertRedirect();
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\GenerateImage::class, function ($job) {
            return (new \ReflectionProperty($job, 'slot'))->getValue($job) === 1
                && (new \ReflectionProperty($job, 'creativeRunId'))->getValue($job) === 'test-run';
        });
    }

    public function test_incomplete_replacement_preserves_original_sizes_and_cross_strategy_replacement_is_refused(): void
    {
        $strategy = $this->reviewStrategy();
        $original = $strategy->imageCollaterals()->first();
        $row = $original->only(['campaign_id', 'strategy_id', 'platform', 's3_path']);
        $row['format'] = 'landscape';
        $this->assertNull(ImageCollateral::createConcept($strategy->campaign, [$row], $original->concept_key, $strategy->id, 'test-run'));
        $this->assertNull(ImageCollateral::createConcept(Campaign::factory()->create(), [$row], $original->concept_key, $strategy->id, 'test-run'));
        $this->assertNotNull($original->fresh());
    }
}
