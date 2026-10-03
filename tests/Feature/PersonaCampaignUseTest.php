<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Persona;
use App\Services\Campaigns\PersonaSelection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PersonaCampaignUseTest extends TestCase
{
    use DatabaseTransactions;

    private function persona(Customer $customer, ?Campaign $campaign, string $name, bool $active = true): Persona
    {
        return Persona::create([
            'customer_id' => $customer->id,
            'campaign_id' => $campaign?->id,
            'name' => $name,
            'description' => $name,
            'source' => 'manual',
            'is_active' => $active,
        ]);
    }

    public function test_campaign_persona_wins_then_account_persona_falls_back(): void
    {
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $global = $this->persona($customer, null, 'Account-wide');
        $selector = app(PersonaSelection::class);

        $this->assertTrue($global->is($selector->forCampaign($campaign)));

        $specific = $this->persona($customer, $campaign, 'This campaign');
        $this->assertTrue($specific->is($selector->forCampaign($campaign)));

        $specific->update(['is_active' => false]);
        $this->assertTrue($global->is($selector->forCampaign($campaign)));
    }

    public function test_personas_from_other_campaigns_or_customers_cannot_shape_copy(): void
    {
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $otherCampaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $otherCustomer = Customer::factory()->create();

        $foreignCampaign = $this->persona($customer, $otherCampaign, 'Other campaign');
        $foreignCustomer = $this->persona($otherCustomer, null, 'Other customer');
        $selector = app(PersonaSelection::class);

        $this->assertNull($selector->forCampaign($campaign));
        $this->assertNull($selector->forCampaign($campaign, $foreignCampaign->id));
        $this->assertNull($selector->forCampaign($campaign, $foreignCustomer->id));
    }
}
