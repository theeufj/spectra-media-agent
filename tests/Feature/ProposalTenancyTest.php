<?php

namespace Tests\Feature;

use App\Jobs\GenerateProposal;
use App\Models\Customer;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Proposal is tenant-scoped, so it may never be written with a NULL customer_id.
 *
 * A NULL matches no tenant's `IN`, which made the proposal invisible to the user
 * who had just generated it — the redirect to proposals.show 404'd on the row it
 * had itself created, and nothing could reach it again. The proposals routes
 * carry no ensureUserHasCustomer, so a subscriber who has not onboarded yet gets
 * all the way here.
 */
class ProposalTenancyTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'client_name' => 'Acme Roofing',
            'industry' => 'Construction',
            'website_url' => 'https://acme-roofing.test',
            'budget' => 5000,
            'goals' => 'Book more quotes.',
            'platforms' => ['Google Ads'],
        ];
    }

    public function test_a_proposal_is_stored_under_the_active_customer_and_readable_afterwards(): void
    {
        $user = User::factory()->create(['subscription_status' => 'active']);
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->post(route('proposals.store'), $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('proposals', [
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'client_name' => 'Acme Roofing',
        ]);

        Queue::assertPushed(GenerateProposal::class);

        $proposal = Proposal::withoutCustomerScope()->where('user_id', $user->id)->firstOrFail();

        // The point of the whole fix: the redirect target resolves.
        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get(route('proposals.show', $proposal))
            ->assertOk();
    }

    public function test_a_user_with_no_customer_is_refused_rather_than_stranding_a_proposal(): void
    {
        $user = User::factory()->create(['subscription_status' => 'active']);

        $this->actingAs($user)
            ->post(route('proposals.store'), $this->payload())
            ->assertRedirect();

        $this->assertDatabaseMissing('proposals', ['user_id' => $user->id]);

        Queue::assertNotPushed(GenerateProposal::class);
    }

    public function test_a_sandbox_only_account_is_refused_too(): void
    {
        // getActiveCustomer() excludes sandbox customers, so "has a customer"
        // is not the same question as "has a customer this row can belong to".
        $user = User::factory()->create(['subscription_status' => 'active']);
        $sandbox = Customer::factory()->create(['is_sandbox' => true]);
        $user->customers()->attach($sandbox->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $sandbox->id])
            ->post(route('proposals.store'), $this->payload())
            ->assertRedirect();

        $this->assertDatabaseMissing('proposals', ['user_id' => $user->id]);

        Queue::assertNotPushed(GenerateProposal::class);
    }
}
