<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The wizard's platform step must not require infrastructure we build later.
 *
 * Selectable platforms were the intersection of system-enabled, plan-allowed
 * AND customer-configured, and that last term locked the funnel shut:
 *
 *   no campaign -> no budget confirmed -> no Google Ads account
 *      -> no configured platform -> step 2 blocked -> no campaign
 *
 * The sub-account is created on deploy intent — confirmBudget() dispatches
 * ProvisionGoogleAdsAccount deliberately, so signups do not mint real Google
 * accounts for tire-kickers. But confirming a budget needs a campaign, and
 * creating one needed an account that only exists after the budget is
 * confirmed.
 *
 * The single way through was GenerateFirstCampaign writing a campaign without
 * the wizard, which made that job the only entrance to the managed product
 * rather than the bonus it is documented as. An account it declined was stuck
 * for good on "contact your admin to set up ad platform accounts" — nobody's
 * job — which is what every real signup stopping at or before budget_confirmed
 * looks like from the inside.
 */
class WizardPlatformLockTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Customer} */
    private function subscriberWithNoAccountYet(): array
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'user'])));

        $customer = Customer::factory()->create([
            'is_sandbox' => false,
            // The state every new managed signup is in.
            'google_ads_customer_id' => null,
            'facebook_ads_account_id' => null,
        ]);
        $customer->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $customer];
    }

    private function wizardProps(User $user, Customer $customer): array
    {
        EnabledPlatform::updateOrCreate(['slug' => 'google'], ['name' => 'Google', 'is_enabled' => true]);

        return $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get(route('campaigns.wizard'))
            ->viewData('page')['props'];
    }

    public function test_a_customer_with_no_ad_account_can_still_pick_a_platform(): void
    {
        [$user, $customer] = $this->subscriberWithNoAccountYet();

        $props = $this->wizardProps($user, $customer);

        // Without this the step has nothing to select, Continue stays disabled,
        // and the account can never reach the budget step that would have
        // created the very account being demanded here.
        $this->assertContains('google', $props['selectablePlatforms']);
        $this->assertSame([], $props['configuredPlatforms']);
    }

    public function test_a_platform_above_the_current_plan_is_pickable_and_priced(): void
    {
        [$user, $customer] = $this->subscriberWithNoAccountYet();
        EnabledPlatform::updateOrCreate(['slug' => 'facebook'], ['name' => 'Facebook', 'is_enabled' => true]);
        \App\Models\Plan::updateOrCreate(['slug' => 'growth'], [
            'name' => 'Growth', 'price_cents' => 24900, 'billing_interval' => 'month',
            'is_active' => true, 'stripe_price_id' => 'price_growth_test',
        ]);

        $props = $this->wizardProps($user, $customer);

        /*
           Pickable, because gating the choice by plan meant buying before
           finding out what the purchase allowed. Entitlement has not moved —
           allowedPlatforms still says what this plan covers, and DeployCampaign
           still filters on it — but the customer now learns the price of what
           they want instead of being refused the choice.
        */
        $this->assertContains('facebook', $props['selectablePlatforms']);
        $this->assertNotContains('facebook', $props['allowedPlatforms']);

        // And the page is given the figure it needs to say so.
        $this->assertSame('Growth', $props['planForPlatforms'][2]['name']);
        $this->assertEquals(249, $props['planForPlatforms'][2]['price']);
    }

    public function test_the_page_is_told_what_the_product_supports_at_all(): void
    {
        [$user, $customer] = $this->subscriberWithNoAccountYet();

        $props = $this->wizardProps($user, $customer);

        /*
           Microsoft and LinkedIn are not enabled system-wide, and the wizard
           offered "upgrade your plan to unlock" against them — selling an
           upgrade that cannot deliver them, since no plan can. The page needs
           the system list to tell that apart from a plan limit.
        */
        $this->assertArrayHasKey('enabledPlatforms', $props);
        $this->assertContains('google', $props['enabledPlatforms']);
    }
}
