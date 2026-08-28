<?php

namespace Tests\Feature;

use App\Jobs\ExtractBrandGuidelines;
use App\Models\BrandGuideline;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\SiteScanFailed;
use App\Services\BrandGuidelineExtractorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The frontend watches /brand-guidelines/status while an extraction runs —
 * the POST that starts one returns in milliseconds, the job takes minutes,
 * and the page used to go silent in between. The endpoint must say what a
 * watcher needs: is there a guideline, how fresh, and did the run fail
 * (with the reason). And a user-triggered re-extract must actually run:
 * the freshness skip that guards duplicate onboarding dispatches was
 * silently eating the explicit "Re-analyze Website" click.
 */
class BrandExtractionStatusTest extends TestCase
{
    use DatabaseTransactions;

    private function customerWithUser(): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);

        return [$customer, $user];
    }

    private function guideline(Customer $customer): BrandGuideline
    {
        return BrandGuideline::create([
            'customer_id' => $customer->id,
            'brand_voice' => ['primary_tone' => 'direct', 'description' => 'Plain.', 'examples' => ['Example.']],
            'tone_attributes' => ['direct'],
            'target_audience' => ['primary' => 'Homeowners'],
            'competitor_differentiation' => ['End to end.'],
            'messaging_themes' => ['Care'],
            'unique_selling_propositions' => ['No subcontractors'],
            'do_not_use' => ['Jargon'],
            'color_palette' => ['primary_colors' => ['#111111']],
            'typography' => ['heading_style' => 'sans'],
            'visual_style' => ['overall_aesthetic' => 'modern'],
            'writing_patterns' => ['sentence_length' => 'varied'],
            'brand_personality' => ['archetype' => 'Everyman'],
            'service_lines' => [['name' => 'Service']],
            'extraction_quality_score' => 88,
            'extracted_at' => now(),
        ]);
    }

    public function test_status_reports_a_missing_guideline(): void
    {
        [$customer, $user] = $this->customerWithUser();

        $response = $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->getJson(route('brand-guidelines.status'));

        $response->assertSuccessful()
            ->assertJson(['exists' => false, 'failed' => false]);
    }

    public function test_status_reports_the_guideline_and_its_freshness(): void
    {
        [$customer, $user] = $this->customerWithUser();
        $guideline = $this->guideline($customer);

        $response = $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->getJson(route('brand-guidelines.status'));

        $response->assertSuccessful()
            ->assertJson(['exists' => true, 'failed' => false, 'quality_score' => 88]);
        $this->assertNotNull($response->json('updated_at'));
    }

    public function test_status_surfaces_a_scan_failure_and_its_reason(): void
    {
        [$customer, $user] = $this->customerWithUser();

        $user->notifyNow(new SiteScanFailed($customer, 'Your site\'s security service blocked our scanner.'));

        $response = $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->getJson(route('brand-guidelines.status'));

        $response->assertSuccessful()->assertJson(['failed' => true]);
        $this->assertStringContainsString('blocked our scanner', $response->json('failure_reason'));
    }

    public function test_a_failure_older_than_the_guideline_is_history_not_status(): void
    {
        [$customer, $user] = $this->customerWithUser();

        $user->notifyNow(new SiteScanFailed($customer, 'Old failure.'));
        $user->notifications()->update(['created_at' => now()->subDay()]);
        $this->guideline($customer);

        $response = $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->getJson(route('brand-guidelines.status'));

        $response->assertSuccessful()
            ->assertJson(['exists' => true, 'failed' => false]);
    }

    public function test_a_forced_re_extract_bypasses_the_freshness_skip(): void
    {
        Notification::fake();
        [$customer] = $this->customerWithUser();
        $this->guideline($customer); // created just now — inside the skip window

        /** @var BrandGuidelineExtractorService&\Mockery\MockInterface $extractor */
        $extractor = \Mockery::mock(BrandGuidelineExtractorService::class);
        $extractor->shouldReceive('extractGuidelines')->andReturn(null);

        (new ExtractBrandGuidelines($customer, force: true))->handle($extractor);

        // The un-forced dispatch (onboarding chain) still skips.
        /** @var BrandGuidelineExtractorService&\Mockery\MockInterface $quiet */
        $quiet = \Mockery::mock(BrandGuidelineExtractorService::class);
        $quiet->shouldNotReceive('extractGuidelines');

        (new ExtractBrandGuidelines($customer))->handle($quiet);
    }
}
