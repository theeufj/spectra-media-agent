<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The collateral page has to know when work is still arriving.
 *
 * Polling only ever started when the visitor pressed Generate on this page. The
 * ordinary path never touches that button: signing off a strategy dispatches
 * one ad copy job and three image jobs from somewhere else entirely, and the
 * customer then lands here to watch. The page showed whatever existed at page
 * load and sat perfectly still while the rest of the set appeared in storage
 * behind it — a half-finished set until they refreshed by hand.
 *
 * Judged on what is missing rather than on queue state, which is not reliably
 * queryable, and bounded by time so a strategy whose generation failed weeks
 * ago does not poll on every load for ever.
 */
class CollateralPollsOnArrivalTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Strategy} */
    private function signedOffStrategy(?\DateTimeInterface $signedAt = null): array
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create();
        $campaign->customer->users()->attach($user->id, ['role' => 'owner']);

        $strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'signed_off_at' => $signedAt ?? now(),
        ]);

        return [$user, $strategy];
    }

    private function pendingFlag(User $user, Strategy $strategy): bool
    {
        $response = $this->actingAs($user)
            ->withSession(['active_customer_id' => $strategy->campaign->customer_id])
            ->get(route('campaigns.collateral.show', [
                'campaign' => $strategy->campaign,
                'strategy' => $strategy,
            ]));

        return (bool) $response->viewData('page')['props']['generationPending'];
    }

    public function test_an_empty_freshly_signed_strategy_is_still_working(): void
    {
        [$user, $strategy] = $this->signedOffStrategy();

        // Nothing generated yet and the jobs were dispatched moments ago.
        $this->assertTrue($this->pendingFlag($user, $strategy));
    }

    public function test_a_strategy_signed_off_long_ago_does_not_poll_for_ever(): void
    {
        [$user, $strategy] = $this->signedOffStrategy(now()->subHours(6));

        // Whatever went wrong here is not going to finish now, and polling a
        // dead set on every page load helps nobody.
        $this->assertFalse($this->pendingFlag($user, $strategy));
    }

    public function test_a_finished_set_stops_asking(): void
    {
        [$user, $strategy] = $this->signedOffStrategy();

        $strategy->adCopies()->create([
            'campaign_id' => $strategy->campaign_id,
            'platform' => $strategy->platform ?? 'Google Ads (SEM)',
            'headlines' => ['Build Your Store in 5 Mins'],
            'descriptions' => ['Launch in five minutes with AI.'],
        ]);

        /*
           Filled to the campaign's cap.

           This used to create three rows, because three images per strategy was
           the shape when one image meant one row. One picture is now three rows
           — square, landscape and MREC — and the page counts pictures against
           the cap, so three rows is one or three pictures and never a finished
           set.
        */
        $cap = \App\Models\ImageCollateral::capForCampaign($strategy->campaign);

        foreach (range(1, $cap) as $i) {
            $key = (string) \Illuminate\Support\Str::uuid();

            foreach (['square', 'landscape', 'mrec'] as $format) {
                $strategy->imageCollaterals()->create([
                    'campaign_id' => $strategy->campaign_id,
                    'platform' => $strategy->platform ?? 'Google Ads (SEM)',
                    'cloudfront_url' => "https://example.test/{$i}-{$format}.jpeg",
                    's3_path' => "collateral/images/{$i}-{$format}.jpeg",
                    'format' => $format,
                    'concept_key' => $key,
                ]);
            }
        }

        // Everything asked for has arrived, so the page has nothing to wait on
        // and should not poll a completed set on every load.
        $this->assertFalse($this->pendingFlag($user, $strategy->fresh()));
    }
}
