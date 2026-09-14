<?php

namespace Tests\Feature;

use App\Jobs\GenerateImage;
use App\Models\Campaign;
use App\Models\Customer;
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

        return Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'signed_off_at' => now(),
            'imagery_strategy' => 'A maker in a sunlit studio.',
        ]);
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
}
