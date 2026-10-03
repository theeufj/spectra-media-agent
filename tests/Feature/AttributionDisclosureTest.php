<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttributionDisclosureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_campaign_report_does_not_send_the_tracking_signing_secret_to_the_browser(): void
    {
        $user = User::factory()->create(['subscription_status' => 'active']);
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get(route('campaigns.attribution', $campaign->uuid))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Campaigns/Attribution')
                ->missing('pixelConfig'));
    }
}
