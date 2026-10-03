<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CampaignEndDateSafetyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_cannot_restart_a_campaign_past_its_approved_end_date(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123-456-7890']);
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'paused',
            'platform_status' => 'PAUSED',
            'end_date' => now()->subDay(),
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/999',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.campaigns.show', $campaign))
            ->post(route('admin.campaigns.start', $campaign))
            ->assertRedirect(route('admin.campaigns.show', $campaign))
            ->assertSessionHas('flash.message', 'This campaign has passed its approved end date. Review its dates before restarting ads.');

        $this->assertSame('paused', $campaign->fresh()->status->value);
    }
}
