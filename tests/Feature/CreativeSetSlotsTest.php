<?php

namespace Tests\Feature;

use App\Jobs\GenerateImage;
use App\Jobs\GenerateStrategyCollateral;
use App\Models\Campaign;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The bug that survived three rounds of fixes to the same complaint.
 *
 * A set of creatives is not one job producing three images.
 * GenerateStrategyCollateral dispatches GenerateImage three separate times,
 * each running its own splitter call and its own loop counting from zero. So
 * every job took slot 0 — the scene exactly as briefed, no lens — and the
 * lenses that exist to force the pictures apart never reached the work.
 *
 * Three photographs of the same woman in the same apron in the same studio.
 * Twice, across two regenerations, after two commits that were supposed to have
 * fixed it. The lens was applied correctly inside each job; the set was never
 * told that the three jobs were a set.
 *
 * Only the caller knows which of the three a given job is, so only the caller
 * can say.
 */
class CreativeSetSlotsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_each_image_job_is_told_which_creative_in_the_set_it_is(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $campaign = Campaign::factory()->create();
        // The image quota is the account's, so the account needs a member for
        // canGenerateForCampaign() to resolve one.
        $campaign->customer->users()->attach($user->id, ['role' => 'owner']);
        $strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'imagery_strategy' => 'A boutique maker in a sunlit studio holding a tablet.',
            'video_strategy' => 'N/A — text ads only.',
            // Collateral only generates for a signed-off strategy.
            'signed_off_at' => now(),
        ]);

        (new GenerateStrategyCollateral($campaign, $strategy, $user->id))->handle();

        $slots = [];
        Queue::assertPushed(GenerateImage::class, function ($job) use (&$slots) {
            $slots[] = (new \ReflectionProperty($job, 'slot'))->getValue($job);

            return true;
        });

        sort($slots);

        // Three jobs, three different slots. Identical slots mean identical
        // lenses, which means one picture printed three times.
        $this->assertSame([0, 1, 2], $slots);
    }
}
