<?php

namespace Tests\Feature;

use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Confirming the brand profile must not send you to build what you already have.
 *
 * The onboarding chain writes the first campaign for the customer, then writes
 * its strategies — and the two are about forty seconds apart. Measured on
 * customer 45: the campaign row and auto_generated_at at 11:30:22, the strategy
 * at 11:31:03. The redirect required both, so anyone who confirmed inside that
 * window landed in the template chooser and was invited to build a campaign
 * that already existed. Two campaigns, and the duplicate is the one they would
 * have kept, because it is the one they were looking at.
 *
 * Landing on a campaign whose strategies are still generating is fine: Show
 * seeds its polling from strategy_generation_started_at and narrates the wait.
 */
class BrandConfirmRoutingTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Customer} */
    private function verifiedCustomer(): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        return [$user, $customer];
    }

    private function guideline(Customer $customer): BrandGuideline
    {
        return BrandGuideline::create([
            'customer_id' => $customer->id,
            'brand_voice' => 'Direct and warm',
            'tone_attributes' => ['friendly'],
            'color_palette' => ['primary' => '#ff0000'],
            'typography' => ['heading' => 'Inter'],
            'visual_style' => ['overall_aesthetic' => 'clean', 'imagery_style' => 'photographic'],
            'messaging_themes' => ['speed'],
            'unique_selling_propositions' => ['fast'],
            'target_audience' => ['primary' => 'founders'],
            'brand_personality' => ['helpful'],
            'extracted_at' => now(),
        ]);
    }

    public function test_it_goes_to_the_campaign_even_before_its_strategies_land(): void
    {
        [$user, $customer] = $this->verifiedCustomer();
        $guideline = $this->guideline($customer);

        // The 41-second window: the campaign exists, the strategy does not yet.
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'auto_generated_at' => now(),
            'strategy_generation_started_at' => now(),
        ]);

        $this->assertSame(0, $campaign->strategies()->count());

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->post(route('brand-guidelines.verify', $guideline->id), ['continue' => true])
            ->assertRedirect(route('campaigns.show', $campaign));
    }

    public function test_a_customer_with_no_auto_campaign_still_gets_the_wizard(): void
    {
        [$user, $customer] = $this->verifiedCustomer();
        $guideline = $this->guideline($customer);

        // Nothing was built for them, so building one is the right next step.
        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->post(route('brand-guidelines.verify', $guideline->id), ['continue' => true])
            ->assertRedirect(route('campaigns.create'));
    }

    public function test_a_plain_verify_stays_on_the_page(): void
    {
        [$user, $customer] = $this->verifiedCustomer();
        $guideline = $this->guideline($customer);

        Campaign::factory()->create(['customer_id' => $customer->id, 'auto_generated_at' => now()]);

        // Only the onboarding review flow moves you on.
        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->post(route('brand-guidelines.verify', $guideline->id))
            ->assertRedirect();

        $this->assertTrue($guideline->fresh()->user_verified);
    }
}
