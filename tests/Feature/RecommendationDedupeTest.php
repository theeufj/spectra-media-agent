<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The review queue is a queue, not a log.
 *
 * OptimizeCampaigns runs nightly over every campaign and re-derives its
 * recommendations from the same performance data, so an account nobody has
 * triaged in a fortnight collected fourteen copies of the same advice. The
 * list a human is meant to make decisions from became the list they scroll
 * past.
 *
 * Scoped to pending deliberately. An applied or failed recommendation records
 * something that happened, and collapsing those would hide that the same fix
 * was needed twice.
 */
class RecommendationDedupeTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(): Campaign
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        return Campaign::factory()->create(['customer_id' => $customer->id]);
    }

    private function make(Campaign $c, string $status, array $target = ['ad_group' => 'Brand']): Recommendation
    {
        return Recommendation::create([
            'campaign_id' => $c->id,
            'type' => 'AD_GROUP_PAUSE',
            'target_entity' => $target,
            'rationale' => 'No conversions',
            'status' => $status,
            'requires_approval' => $status === 'pending',
        ]);
    }

    public function test_the_same_pending_advice_is_recognised(): void
    {
        $c = $this->campaign();
        $this->make($c, 'pending');

        $this->assertTrue(Recommendation::alreadyPending($c->id, 'AD_GROUP_PAUSE', ['ad_group' => 'Brand']));
    }

    public function test_key_order_does_not_make_two_targets_different(): void
    {
        $c = $this->campaign();
        $this->make($c, 'pending', ['ad_group' => 'Brand', 'resource' => 'customers/1/adGroups/2']);

        /*
           The cast hands back an array whose key order is not guaranteed, so
           comparing the JSON as text would call these two different and let
           the duplicate through — which is the whole failure, quietly.
        */
        $this->assertTrue(Recommendation::alreadyPending($c->id, 'AD_GROUP_PAUSE', [
            'resource' => 'customers/1/adGroups/2',
            'ad_group' => 'Brand',
        ]));
    }

    public function test_a_different_ad_group_is_a_different_recommendation(): void
    {
        $c = $this->campaign();
        $this->make($c, 'pending', ['ad_group' => 'Brand']);

        $this->assertFalse(Recommendation::alreadyPending($c->id, 'AD_GROUP_PAUSE', ['ad_group' => 'Generic']));
    }

    public function test_an_applied_one_does_not_block_a_fresh_suggestion(): void
    {
        $c = $this->campaign();
        $this->make($c, 'applied');

        /*
           History must not silence the queue. If the same problem comes back
           after a fix was applied, the human needs to be told again.
        */
        $this->assertFalse(Recommendation::alreadyPending($c->id, 'AD_GROUP_PAUSE', ['ad_group' => 'Brand']));
    }

    public function test_another_campaign_is_never_confused_for_this_one(): void
    {
        $c = $this->campaign();
        $other = $this->campaign();
        $this->make($other, 'pending');

        $this->assertFalse(Recommendation::alreadyPending($c->id, 'AD_GROUP_PAUSE', ['ad_group' => 'Brand']));
    }

    public function test_targetless_recommendations_dedupe_on_type_alone(): void
    {
        $c = $this->campaign();
        Recommendation::create([
            'campaign_id' => $c->id,
            'type' => 'BUDGET_INCREASE',
            'target_entity' => null,
            'rationale' => 'Budget limited',
            'status' => 'pending',
            'requires_approval' => true,
        ]);

        $this->assertTrue(Recommendation::alreadyPending($c->id, 'BUDGET_INCREASE', null));
        $this->assertFalse(Recommendation::alreadyPending($c->id, 'BUDGET_INCREASE', ['ad_group' => 'Brand']));
    }
}
