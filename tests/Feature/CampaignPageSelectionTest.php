<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\CustomerPage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `selected_pages` is a list of raw ids, and `exists:customer_pages,id` runs
 * through the presence verifier — which never sees CustomerScope. Left
 * unscoped, one tenant could name another tenant's page and a queue worker
 * (no acting user, scope inert) would render that page's url, title and price
 * into the attacker's strategy, ad copy, and final URL.
 */
class CampaignPageSelectionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_campaign_cannot_reference_another_tenants_page(): void
    {
        [$user, $customer] = $this->userWithCustomer();
        $theirPage = $this->page(Customer::factory()->create(), 'https://someone-else.test/pricing');

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->post('/campaigns', $this->campaignPayload(['selected_pages' => [$theirPage->id]]))
            ->assertSessionHasErrors('selected_pages.0');

        $this->assertSame(0, Campaign::withoutCustomerScope()->where('customer_id', $customer->id)->count());
    }

    public function test_a_campaign_still_accepts_its_own_pages(): void
    {
        [$user, $customer] = $this->userWithCustomer();
        $ourPage = $this->page($customer, 'https://ours.test/pricing');

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->post('/campaigns', $this->campaignPayload(['selected_pages' => [$ourPage->id]]))
            ->assertSessionHasNoErrors();

        $campaign = Campaign::where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame([$ourPage->id], $campaign->pages()->pluck('customer_pages.id')->all());
    }

    /**
     * @return array{0: User, 1: Customer}
     */
    private function userWithCustomer(): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $customer];
    }

    private function page(Customer $customer, string $url): CustomerPage
    {
        return CustomerPage::create([
            'customer_id' => $customer->id,
            'url' => $url,
            'title' => 'Pricing',
            'page_type' => 'money',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function campaignPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Spring push',
            'reason' => 'Launching a new product line.',
            'goals' => 'Leads',
            'target_market' => 'Australia',
            'voice' => 'Direct',
            'total_budget' => 700,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'primary_kpi' => 'conversions',
            'platforms' => ['google'],
        ], $overrides);
    }
}
