<?php

namespace Tests\Feature\Admin;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding things across seventy-one admin routes.
 *
 * There was no cross-entity search, so answering "which customer is
 * support@acme.com" or "whose campaign is 23786194932" meant knowing which page
 * to start from and paging through it. The identifiers that matter are the ones
 * people arrive with — an email from a ticket, a domain from a complaint, a
 * numeric campaign id copied out of the Google Ads console.
 */
class AdminSearchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        return $user;
    }

    public function test_a_customer_is_found_by_domain(): void
    {
        Customer::factory()->create(['name' => 'Acme Roofing', 'website' => 'https://acmeroofing.com.au']);

        $response = $this->actingAs($this->admin())->getJson(route('admin.search', ['q' => 'acmeroofing']));

        $response->assertSuccessful();
        $this->assertSame('Acme Roofing', $response->json('results.0.title'));
    }

    public function test_a_user_is_found_by_email(): void
    {
        // The single most common way a support question arrives.
        User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@acme.test']);

        $results = $this->actingAs($this->admin())
            ->getJson(route('admin.search', ['q' => 'jane@acme']))
            ->json('results');

        $this->assertContains('user', array_column($results, 'type'));
    }

    public function test_a_campaign_is_found_by_its_platform_id(): void
    {
        // The id copied out of the Google Ads console, not our internal one.
        $customer = Customer::factory()->create();
        Campaign::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'Winter Sale',
            'google_ads_campaign_id' => 'customers/3598653839/campaigns/23786194932',
        ]);

        $results = $this->actingAs($this->admin())
            ->getJson(route('admin.search', ['q' => '23786194932']))
            ->json('results');

        $this->assertSame('Winter Sale', $results[0]['title'] ?? null);
    }

    public function test_a_deleted_customer_is_still_findable(): void
    {
        // Exactly who you are looking for when someone asks why their campaigns
        // stopped.
        $customer = Customer::factory()->create(['name' => 'Gone Pty Ltd']);
        $customer->delete();

        $results = $this->actingAs($this->admin())
            ->getJson(route('admin.search', ['q' => 'Gone Pty']))
            ->json('results');

        $this->assertStringContainsString('deleted', $results[0]['title'] ?? '');
    }

    public function test_a_single_character_returns_nothing(): void
    {
        // Matches half the database and is slower than useless.
        Customer::factory()->create(['name' => 'Acme']);

        $this->actingAs($this->admin())
            ->getJson(route('admin.search', ['q' => 'a']))
            ->assertJsonCount(0, 'results');
    }

    public function test_search_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('admin.search', ['q' => 'acme']))
            ->assertForbidden();
    }

    public function test_support_may_search(): void
    {
        // It is a read, and looking things up is what the role is for.
        $support = User::factory()->create();
        $support->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'support'])));

        $this->actingAs($support)
            ->getJson(route('admin.search', ['q' => 'acme']))
            ->assertSuccessful();
    }
}
