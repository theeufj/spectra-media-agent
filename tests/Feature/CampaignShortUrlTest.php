<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CampaignShortUrlTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_open_a_campaign_by_its_short_numeric_url(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get("/campaigns/{$campaign->id}")
            ->assertOk();
    }

    public function test_other_customer_cannot_open_the_short_url(): void
    {
        $ownerCustomer = Customer::factory()->create();
        $visitorCustomer = Customer::factory()->create();
        $user = User::factory()->create();
        $user->customers()->attach($visitorCustomer->id, ['role' => 'owner']);
        $campaign = Campaign::factory()->create(['customer_id' => $ownerCustomer->id]);

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $visitorCustomer->id])
            ->get("/campaigns/{$campaign->id}")
            ->assertNotFound();
    }
}
