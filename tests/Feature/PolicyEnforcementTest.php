<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Cross-tenant access must be denied.
 *
 * Controllers used to hand-roll this check inline
 * (`$user->customers()->where('customers.id', $campaign->customer_id)->exists()`),
 * duplicated across ~20 methods. Those now delegate to CampaignPolicy /
 * CustomerPolicy; these tests pin the behaviour so the consolidation can't
 * silently invert a check.
 *
 * Uses DatabaseTransactions rather than RefreshDatabase — the migrate:fresh that
 * RefreshDatabase performs is what makes several existing tests in this suite
 * hang against a live-ish database.
 */
class PolicyEnforcementTest extends TestCase
{
    use DatabaseTransactions;

    private function userWithCustomer(): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id);

        return [$user, $customer];
    }

    public function test_owner_can_view_own_campaign(): void
    {
        [$user, $customer] = $this->userWithCustomer();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        $this->assertTrue($user->can('view', $campaign));
        $this->assertTrue($user->can('update', $campaign));
        $this->assertTrue($user->can('deploy', $campaign));
    }

    public function test_stranger_cannot_view_another_tenants_campaign(): void
    {
        [, $customerA] = $this->userWithCustomer();
        [$userB] = $this->userWithCustomer();

        $campaign = Campaign::factory()->create(['customer_id' => $customerA->id]);

        $this->assertFalse($userB->can('view', $campaign));
        $this->assertFalse($userB->can('update', $campaign));
        $this->assertFalse($userB->can('delete', $campaign));
        $this->assertFalse($userB->can('deploy', $campaign));
    }

    public function test_owner_can_manage_own_customer(): void
    {
        [$user, $customer] = $this->userWithCustomer();

        $this->assertTrue($user->can('view', $customer));
        $this->assertTrue($user->can('update', $customer));
        $this->assertTrue($user->can('switchTo', $customer));
    }

    public function test_stranger_cannot_manage_another_tenants_customer(): void
    {
        [, $customerA] = $this->userWithCustomer();
        [$userB] = $this->userWithCustomer();

        $this->assertFalse($userB->can('view', $customerA));
        $this->assertFalse($userB->can('update', $customerA));
        $this->assertFalse($userB->can('switchTo', $customerA));
        $this->assertFalse($userB->can('delete', $customerA));
    }

    public function test_policy_matches_the_inline_check_it_replaced(): void
    {
        [$user, $customer] = $this->userWithCustomer();
        [$other] = $this->userWithCustomer();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        foreach ([$user, $other] as $u) {
            $inline = $u->customers()->where('customers.id', $campaign->customer_id)->exists();
            $this->assertSame($inline, $u->can('view', $campaign));
        }
    }
}
