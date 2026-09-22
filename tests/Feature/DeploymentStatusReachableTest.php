<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The deployment status page has to be reachable, and only by its owner.
 *
 * campaigns.deployment-status has existed for months with no inbound link from
 * anywhere in the UI — not from the campaign list, not from the campaign page.
 * A customer could only reach it by typing the URL. It is the only screen that
 * shows per-platform deployment state and the error message when a platform
 * rejects a campaign, which is precisely what someone whose ads have not
 * appeared goes looking for.
 *
 * The route existing is not the same as the page being reachable, so this
 * asserts both: that it answers for the owner, and that the pages which now
 * link to it can actually render that link.
 */
class DeploymentStatusReachableTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Campaign} */
    private function ownedCampaign(array $strategyAttributes = []): array
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $customer->users()->attach($user->id, ['role' => 'owner']);

        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        Strategy::factory()->create(array_merge([
            'campaign_id' => $campaign->id,
        ], $strategyAttributes));

        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id]);

        return [$user, $campaign];
    }

    public function test_the_owner_can_open_the_deployment_status_page(): void
    {
        [, $campaign] = $this->ownedCampaign(['deployment_status' => 'deployed']);

        $this->get(route('campaigns.deployment-status', ['campaign' => $campaign->id]))
            ->assertOk();
    }

    public function test_the_campaign_list_can_link_to_it(): void
    {
        // The link is rendered from deployment_status / deployed_at on each
        // strategy, so the list has to actually carry those.
        [, $campaign] = $this->ownedCampaign(['deployment_status' => 'deployed']);

        $this->get(route('campaigns.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('campaigns.0.strategies.0.deployment_status'));

        $this->assertNotNull($campaign->strategies()->first()->deployment_status);
    }

    public function test_a_guest_cannot_open_it(): void
    {
        $campaign = Campaign::factory()->create();

        $this->get(route('campaigns.deployment-status', ['campaign' => $campaign->id]))
            ->assertRedirect(route('login'));
    }

    public function test_another_tenants_campaign_is_not_found_rather_than_forbidden(): void
    {
        // Cross-tenant access returns 404, not 403: route-model binding cannot
        // find the row, and a 403 would confirm the id exists.
        $this->ownedCampaign(['deployment_status' => 'deployed']);

        $stranger = Campaign::factory()->create();

        $this->get(route('campaigns.deployment-status', ['campaign' => $stranger->id]))
            ->assertNotFound();
    }

    public function test_status_polling_distinguishes_deployed_from_verified_and_other_terminal_outcomes(): void
    {
        [, $campaign] = $this->ownedCampaign(['deployment_status' => 'deployed']);
        $this->getJson(route('api.campaigns.deployment-status', $campaign))
            ->assertOk()->assertJsonPath('is_complete', false);

        foreach (['verified', 'failed', 'deploy_unverified', 'skipped_plan'] as $status) {
            $campaign->strategies()->update(['deployment_status' => $status]);
            $this->getJson(route('api.campaigns.deployment-status', $campaign))
                ->assertOk()->assertJsonPath('is_complete', true)
                ->assertJsonPath('deployments.0.status', $status);
        }
    }
}
