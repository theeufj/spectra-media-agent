<?php

namespace Tests\Feature;

use App\Jobs\ExtractBrandGuidelines;
use App\Jobs\GenerateFirstCampaign;
use App\Models\BrandGuideline;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * The free first campaign has to actually be asked for.
 *
 * GenerateFirstCampaign re-checks qualification when it runs — it says so in
 * its own code, because a queued job runs minutes after it was queued. The
 * dispatch was gated on that same check anyway, evaluated the instant
 * extraction finished, which is not the moment the crawl has finished writing
 * content into the knowledge base rows that qualification counts.
 *
 * Seen live on customer 52: brand guideline written, qualifies() true a minute
 * later, campaigns zero for ever. The customer confirmed their brand profile
 * and was dropped into the wizard to build by hand the campaign that was
 * supposed to be waiting for them — the single most valuable thing the
 * onboarding does, silently not happening.
 */
class FirstCampaignDispatchTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): Customer
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['website' => 'https://example.test']);
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        return $customer->fresh();
    }

    private function guideline(Customer $customer): void
    {
        BrandGuideline::create([
            'customer_id' => $customer->id,
            'brand_voice' => 'Direct and warm',
            'tone_attributes' => ['friendly'],
            'color_palette' => ['primary' => '#ff0000'],
            'typography' => ['heading' => 'Inter'],
            'visual_style' => ['overall_aesthetic' => 'clean', 'imagery_style' => 'photographic'],
            'messaging_themes' => ['speed'],
            'unique_selling_propositions' => ['fast'],
            'target_audience' => ['primary' => 'founders'],
            'brand_personality' => ['helpful'],
            'extracted_at' => now(),
        ]);
    }

    public function test_it_is_asked_for_even_when_the_content_has_not_landed_yet(): void
    {
        Bus::fake();

        $customer = $this->customer();
        $this->guideline($customer);

        // No knowledge base content, so qualifies() is false right now — the
        // exact state the old gate saw and refused on.
        $this->assertFalse(GenerateFirstCampaign::qualifies($customer));

        // A duplicate run takes the freshness path, which also used to skip it.
        (new ExtractBrandGuidelines($customer))->handle(app(\App\Services\BrandGuidelineExtractorService::class));

        Bus::assertDispatched(GenerateFirstCampaign::class);
    }

    public function test_the_job_still_refuses_when_it_should(): void
    {
        $customer = $this->customer();

        /*
           Dispatching unconditionally is only safe because the job itself is
           the thing that decides. Without content there is nothing to write a
           campaign from, and a generic campaign is a worse first impression
           than none.
        */
        $this->assertFalse(GenerateFirstCampaign::qualifies($customer));
    }

    public function test_it_qualifies_once_the_content_is_there(): void
    {
        $customer = $this->customer();
        $user = $customer->users()->first();

        foreach (range(1, 4) as $i) {
            KnowledgeBase::create([
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'url' => "https://example.test/page-{$i}",
                'title' => "Page {$i}",
                'content' => str_repeat('Real crawled copy about the business. ', 30),
            ]);
        }

        // The state that arrives a minute after extraction finishes, and that
        // nothing was re-checking.
        $this->assertTrue(GenerateFirstCampaign::qualifies($customer->fresh()));
    }
}
