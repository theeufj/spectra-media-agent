<?php

namespace Tests\Feature\Campaigns;

use App\Jobs\GenerateStrategy;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Build a campaign for free; pay to put it live.
 *
 * Everything from the wizard to sign-off used to sit behind the `subscribed`
 * middleware, so a signed-up user was redirected to the pricing page before
 * they could open the wizard — asked for a card before the product had shown
 * them anything. In production 16 accounts reached "pasted their website" and
 * exactly one ever subscribed.
 *
 * CampaignController@store already capped free accounts at one campaign. That
 * code was unreachable, because the middleware redirected first. These tests
 * pin the new contract in both directions: a guest can build and review, and
 * cannot deploy.
 */
class FreeToBuildPaidToDeployTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $customer;

    private User $guest;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->withoutVite();
        Customer::unsetEventDispatcher();

        $this->customer = Customer::factory()->create();

        // Exactly what production has: verified, guest, no plan, no subscription.
        $this->guest = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_status' => 'guest',
        ]);
        $this->guest->customers()->attach($this->customer->id, ['role' => 'owner']);
    }

    private function asGuest(): self
    {
        $this->actingAs($this->guest)->withSession(['active_customer_id' => $this->customer->id]);

        return $this;
    }

    /** @return array<string, mixed> */
    private function campaignPayload(string $name): array
    {
        return [
            'name' => $name,
            'reason' => 'Launching a new product line',
            'goals' => 'Drive qualified traffic',
            'target_market' => 'Small businesses in Australia',
            'voice' => 'Direct and practical',
            'primary_kpi' => 'conversions',
            'total_budget' => 500,
            'daily_budget' => 25,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'platforms' => ['google'],
        ];
    }

    // ── Free ─────────────────────────────────────────────────────────────────

    public function test_a_guest_can_open_the_wizard(): void
    {
        $this->asGuest()->get(route('campaigns.wizard'))->assertStatus(200);
    }

    public function test_a_guest_can_create_a_campaign_and_a_strategy_is_generated(): void
    {
        Queue::fake();

        $this->asGuest()
            ->post(route('campaigns.store'), $this->campaignPayload('First campaign'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Campaign::where('customer_id', $this->customer->id)->count());

        // The point of the free tier: they see what the AI produces for their
        // own website before being asked for a card.
        Queue::assertPushed(GenerateStrategy::class);
    }

    public function test_a_guest_can_review_the_strategy_it_produced(): void
    {
        Queue::fake();
        $this->asGuest()->post(route('campaigns.store'), $this->campaignPayload('Reviewable'));

        $campaign = Campaign::where('customer_id', $this->customer->id)->firstOrFail();

        $this->asGuest()->get(route('campaigns.show', $campaign))->assertStatus(200);
    }

    // ── The cost ceiling ─────────────────────────────────────────────────────

    public function test_a_second_free_campaign_is_refused(): void
    {
        Queue::fake();

        $this->asGuest()->post(route('campaigns.store'), $this->campaignPayload('First'));
        $this->asGuest()->post(route('campaigns.store'), $this->campaignPayload('Second'));

        // Creating a campaign dispatches GenerateStrategy, which spends real
        // money on a paid model. With the paywall moved, this cap is the only
        // ceiling on what an unsubscribed account can cost.
        $this->assertSame(1, Campaign::where('customer_id', $this->customer->id)->count());
        Queue::assertPushed(GenerateStrategy::class, 1);
    }

    public function test_regeneration_stays_behind_the_paywall(): void
    {
        Queue::fake();
        $this->asGuest()->post(route('campaigns.store'), $this->campaignPayload('Only one'));
        $campaign = Campaign::where('customer_id', $this->customer->id)->firstOrFail();

        // Otherwise the one-campaign cap is trivially bypassed by regenerating
        // the same campaign's strategies in a loop.
        $this->asGuest()
            ->post(route('campaigns.regenerate-strategies', $campaign))
            ->assertRedirect(route('subscription.pricing'));
    }

    public function test_the_copilot_stays_behind_the_paywall(): void
    {
        $campaign = Campaign::factory()->create(['customer_id' => $this->customer->id]);

        $this->asGuest()
            ->post(route('campaigns.copilot.chat', $campaign), ['message' => 'hello'])
            ->assertRedirect(route('subscription.pricing'));
    }

    // ── Paid ─────────────────────────────────────────────────────────────────

    public function test_a_guest_cannot_deploy(): void
    {
        $campaign = Campaign::factory()->create(['customer_id' => $this->customer->id]);

        // The gate, and the only one. It is also where the ad-spend credit
        // account is created and the first seven days are charged, so the
        // customer meets one payment moment rather than two.
        $this->asGuest()
            ->post(route('deployment.deploy'), ['campaign_id' => $campaign->id])
            ->assertRedirect(route('subscription.pricing'));
    }

    public function test_a_subscriber_reaches_deployment(): void
    {
        $subscriber = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_status' => 'active',
        ]);
        $subscriber->customers()->attach($this->customer->id, ['role' => 'owner']);
        $campaign = Campaign::factory()->create(['customer_id' => $this->customer->id]);

        $response = $this->actingAs($subscriber)
            ->withSession(['active_customer_id' => $this->customer->id])
            ->post(route('deployment.deploy'), ['campaign_id' => $campaign->id]);

        // Whatever the controller decides about this campaign, the subscription
        // check is no longer what stopped it.
        $this->assertNotSame(
            route('subscription.pricing'),
            $response->headers->get('Location'),
            'a subscriber was still bounced to pricing',
        );
    }
}
