<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The GTM setup page is now an onboarding surface (checklist step +
 * campaign-review call-out), so its basics deserve pinning: owners see
 * their snippet page, strangers see nothing.
 */
class GTMSetupPageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_an_owner_sees_their_tracking_setup_page(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['gtm_container_id' => 'GTM-TEST999']);
        $customer->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get(route('customers.gtm.setup', $customer))
            ->assertSuccessful();
    }

    public function test_a_stranger_cannot_open_another_customers_tracking_page(): void
    {
        $stranger = User::factory()->create();
        $customer = Customer::factory()->create(['gtm_container_id' => 'GTM-TEST999']);

        $this->actingAs($stranger)
            ->get(route('customers.gtm.setup', $customer))
            ->assertForbidden();
    }
}
