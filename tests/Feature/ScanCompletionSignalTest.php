<?php

namespace Tests\Feature;

use App\Jobs\ExtractBrandGuidelines;
use App\Models\BrandGuideline;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\SiteScanCompleted;
use App\Notifications\SiteScanFailed;
use App\Prompts\FirstCampaignPrompt;
use App\Services\BrandGuidelineExtractorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The customer must always hear that their scan finished — and never hear
 * that it failed when it didn't. Both happened in production: the extractor
 * stores structured arrays that FirstCampaignPrompt interpolated raw (killing
 * the job that was supposed to send the completion email), and duplicate
 * ExtractBrandGuidelines dispatches hit the extractor's rate-limit guard,
 * got null back, and sent a scan-FAILED email minutes after success.
 */
class ScanCompletionSignalTest extends TestCase
{
    use DatabaseTransactions;

    private function structuredGuideline(Customer $customer): BrandGuideline
    {
        return BrandGuideline::create([
            'customer_id' => $customer->id,
            'brand_voice' => ['primary_tone' => 'trustworthy', 'description' => 'Direct and grounded.', 'examples' => ['We finish to a standard.']],
            'tone_attributes' => ['trustworthy', 'direct'],
            'target_audience' => ['primary' => 'Melbourne homeowners', 'pain_points' => ['Weathered decks']],
            'competitor_differentiation' => ['One dedicated specialist end to end.'],
            'messaging_themes' => ['Full lifecycle deck care'],
            'unique_selling_propositions' => ['Zero subcontractors'],
            'do_not_use' => ['Bureaucratic language'],
            'color_palette' => ['primary_colors' => ['#F29913', '#1A1D20']],
            'typography' => ['heading_style' => 'Bold sans-serif'],
            'visual_style' => ['overall_aesthetic' => 'modern'],
            'writing_patterns' => ['sentence_length' => 'varied'],
            'brand_personality' => ['archetype' => 'Everyman'],
            'service_lines' => [['name' => 'New Deck Construction']],
            'extraction_quality_score' => 92,
            'extracted_at' => now(),
        ]);
    }

    public function test_first_campaign_prompt_survives_structured_guideline_fields(): void
    {
        $customer = Customer::factory()->create();
        $prompt = FirstCampaignPrompt::generate($customer, $this->structuredGuideline($customer), ['Home']);

        $this->assertStringContainsString('Direct and grounded.', $prompt);
        $this->assertStringContainsString('Melbourne homeowners', $prompt);
        $this->assertStringContainsString('One dedicated specialist end to end.', $prompt);
    }

    public function test_a_duplicate_extraction_run_skips_when_a_fresh_guideline_exists(): void
    {
        Notification::fake();
        Queue::fake();
        $customer = Customer::factory()->create();
        $this->structuredGuideline($customer);

        /** @var BrandGuidelineExtractorService&\Mockery\MockInterface $extractor */
        $extractor = \Mockery::mock(BrandGuidelineExtractorService::class);
        $extractor->shouldNotReceive('extractGuidelines');

        (new ExtractBrandGuidelines($customer))->handle($extractor);

        Notification::assertNothingSent();
    }

    public function test_null_extraction_with_an_existing_guideline_is_not_reported_as_failure(): void
    {
        Notification::fake();
        Queue::fake();
        $customer = Customer::factory()->create();
        $guideline = $this->structuredGuideline($customer);
        // Old enough to pass the freshness skip, so extraction actually runs.
        $guideline->created_at = now()->subDays(2);
        $guideline->save();

        $user = User::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);

        /** @var BrandGuidelineExtractorService&\Mockery\MockInterface $extractor */
        $extractor = \Mockery::mock(BrandGuidelineExtractorService::class);
        $extractor->shouldReceive('extractGuidelines')->andReturn(null);

        (new ExtractBrandGuidelines($customer->fresh()))->handle($extractor);

        Notification::assertNotSentTo($user, SiteScanFailed::class);
    }

    public function test_successful_extraction_notifies_bell_and_mail(): void
    {
        Notification::fake();
        Queue::fake();
        config(['first_campaign.enabled' => false]);

        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);
        $guideline = $this->structuredGuideline($customer);
        // Backdated so the duplicate-run freshness skip doesn't trigger and
        // extraction genuinely executes.
        $guideline->created_at = now()->subDays(2);
        $guideline->save();

        /** @var BrandGuidelineExtractorService&\Mockery\MockInterface $extractor */
        $extractor = \Mockery::mock(BrandGuidelineExtractorService::class);
        $extractor->shouldReceive('extractGuidelines')->andReturn($guideline);

        (new ExtractBrandGuidelines($customer))->handle($extractor);

        Notification::assertSentTo($user, SiteScanCompleted::class, function ($n) use ($user) {
            return in_array('database', $n->via($user)) && in_array('mail', $n->via($user));
        });
    }
}
