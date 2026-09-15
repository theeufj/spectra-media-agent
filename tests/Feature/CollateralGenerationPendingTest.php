<?php

namespace Tests\Feature;

use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "More is still coming" has to be counted in pictures, not rows.
 *
 * The check compared imageCollaterals()->count() to three, which was the number
 * of images a strategy got back when one image meant one row. One picture is
 * now three rows — square, landscape and MREC — so the very first concept
 * satisfied the test. Generation was declared finished with three more concepts
 * still on their way: the page stopped watching, showed one creative out of
 * four, and never updated. Campaign 49 landed on the collateral page with a
 * single image while seven rows existed in storage.
 */
class CollateralGenerationPendingTest extends TestCase
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
        ]);

        AdCopy::create([
            'strategy_id' => $strategy->id,
            'platform' => 'Google Ads (SEM)',
            'headlines' => ['Build a Store in 5 Minutes'],
            'descriptions' => ['Launch in five minutes.'],
        ]);

        return $strategy;
    }

    private function addConcept(Strategy $strategy): void
    {
        $key = (string) Str::uuid();

        foreach (['square', 'landscape', 'mrec'] as $format) {
            ImageCollateral::create([
                'campaign_id' => $strategy->campaign_id,
                'strategy_id' => $strategy->id,
                'platform' => 'Google Ads (SEM)',
                's3_path' => 'collateral/images/'.Str::random(8).'.jpeg',
                'cloudfront_url' => 'https://example.test/'.Str::random(8).'.jpeg',
                'format' => $format,
                'concept_key' => $key,
            ]);
        }
    }

    private function pending(Strategy $strategy): bool
    {
        $m = new \ReflectionMethod(\App\Http\Controllers\CollateralController::class, 'generationPending');

        return $m->invoke(app(\App\Http\Controllers\CollateralController::class), $strategy->fresh());
    }

    public function test_one_picture_does_not_mean_the_set_is_finished(): void
    {
        $strategy = $this->strategy();
        $this->addConcept($strategy);

        // Three rows — which is exactly what the old test counted as done.
        $this->assertSame(3, ImageCollateral::where('strategy_id', $strategy->id)->count());
        $this->assertTrue($this->pending($strategy), 'the page stopped watching after the first picture');
    }

    public function test_it_is_finished_once_the_campaign_is_full(): void
    {
        $strategy = $this->strategy();

        foreach (range(1, ImageCollateral::capForCampaign($strategy->campaign)) as $i) {
            $this->addConcept($strategy);
        }

        $this->assertFalse($this->pending($strategy));
    }

    public function test_a_set_that_stopped_early_does_not_spin_for_ever(): void
    {
        /*
           Formats and whole concepts are skipped when their aspect fails to
           generate, so a set legitimately finishes below the cap. Without a
           time bound the page would wait for pictures that are never coming —
           which is the failure this whole thread started with.
        */
        $strategy = $this->strategy();
        $this->addConcept($strategy);
        $strategy->forceFill(['signed_off_at' => now()->subMinutes(30)])->save();

        $this->assertFalse($this->pending($strategy));
    }

    public function test_missing_ad_copy_alone_means_pending(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        $strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'signed_off_at' => now(),
        ]);

        $this->assertTrue($this->pending($strategy));
    }
}
