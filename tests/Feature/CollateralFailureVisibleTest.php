<?php

namespace Tests\Feature;

use App\Jobs\GenerateImage;
use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A run that produces nothing must say so.
 *
 * Both image providers were down at once — OpenRouter out of credit, Gemini
 * returning 429 — and the job handled it perfectly calmly: it logged
 * "Successfully generated and stored 0 image(s)", cleared
 * collateral_errors['image'] on the way past, and returned. The queue recorded
 * success. Nothing reached failed_jobs. The customer sat on "Generating your
 * collateral... this usually takes 1-2 minutes" indefinitely, and the only
 * record that anything had gone wrong was a log line nobody reads.
 *
 * Clearing the error was the sharp edge: the one field the page could have
 * used to tell them was actively wiped by the failing run.
 */
class CollateralFailureVisibleTest extends TestCase
{
    use DatabaseTransactions;

    private function strategy(): Strategy
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        $strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'signed_off_at' => now(),
            'imagery_strategy' => 'A maker in a sunlit studio.',
        ]);

        // Without copy the job releases to wait for it rather than generating,
        // which is correct and is not what these tests are about.
        AdCopy::create([
            'strategy_id' => $strategy->id,
            'platform' => 'Google Ads (SEM)',
            'headlines' => ['Build a Store in 5 Minutes'],
            'descriptions' => ['Launch in five minutes.'],
        ]);

        return $strategy;
    }

    public function test_a_run_that_generates_nothing_records_the_failure(): void
    {
        $strategy = $this->strategy();

        // Both providers refusing, which is the case that produced this.
        Http::fake(['*' => Http::response(['error' => ['message' => 'Insufficient credits.']], 402)]);

        app()->call([new GenerateImage($strategy->campaign, $strategy, 0), 'handle']);

        $errors = $strategy->fresh()->collateral_errors ?? [];

        $this->assertArrayHasKey('image', $errors, 'a run producing no images left no trace');
        $this->assertStringContainsString('could not generate images', $errors['image']);
    }

    public function test_it_does_not_wipe_an_existing_error_on_the_way_past(): void
    {
        $strategy = $this->strategy();
        $strategy->update(['collateral_errors' => ['image' => 'an earlier failure', 'video' => 'unrelated']]);

        Http::fake(['*' => Http::response(['error' => ['message' => 'Insufficient credits.']], 402)]);

        app()->call([new GenerateImage($strategy->campaign, $strategy, 0), 'handle']);

        $errors = $strategy->fresh()->collateral_errors ?? [];

        // The old code cleared 'image' unconditionally, including on the run
        // that had just failed to produce anything.
        $this->assertArrayHasKey('image', $errors);
        $this->assertSame('unrelated', $errors['video'], 'an unrelated error was collateral damage');
    }

    public function test_it_stays_quiet_when_another_slot_already_produced_images(): void
    {
        $strategy = $this->strategy();

        // Three of these jobs run per strategy. Two succeeded here.
        foreach (['square', 'landscape', 'mrec'] as $format) {
            ImageCollateral::create([
                'campaign_id' => $strategy->campaign_id,
                'strategy_id' => $strategy->id,
                'platform' => 'Google Ads (SEM)',
                's3_path' => 'collateral/images/x-'.$format.'.jpeg',
                'cloudfront_url' => 'https://example.test/'.$format.'.jpeg',
                'format' => $format,
                'concept_key' => 'shared-key',
            ]);
        }

        Http::fake(['*' => Http::response(['error' => ['message' => 'Insufficient credits.']], 402)]);

        app()->call([new GenerateImage($strategy->campaign, $strategy, 2), 'handle']);

        /*
           The first version of this check was per-job, so one failing slot
           printed "We could not generate images just now" above nine perfectly
           good pictures — worse than either outcome alone, because it makes a
           working page look broken.
        */
        $errors = $strategy->fresh()->collateral_errors ?? [];

        $this->assertArrayNotHasKey('image', $errors);
    }
}
