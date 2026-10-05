<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PolicyVisibilityJourneyTest extends TestCase
{
    use DatabaseTransactions;

    private function workspace(string $serviceType = 'managed'): Customer
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $customer = Customer::factory()->create(['website' => null, 'service_type' => $serviceType]);
        $customer->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id]);
        $this->withoutVite();

        return $customer;
    }

    private function pausedIncident(Customer $customer): Campaign
    {
        return Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => CampaignStatus::Paused,
            'google_ads_campaign_id' => '12345',
            'policy_checks' => [
                'status' => 'issues',
                'checked_at' => '2026-10-03T00:21:00Z',
                'repair_status' => 'needs_website_repair',
                'issues' => [[
                    'platform' => 'google_ads',
                    'destination_issue' => true,
                    'ad_resource_name' => 'customers/123/adGroupAds/456~789',
                    'final_urls' => ['https://business.example/services'],
                    'policy_topics' => [['topic' => 'DESTINATION_NOT_WORKING', 'evidences' => [[
                        'type' => 'destination_not_working',
                        'expanded_url' => 'https://business.example/services',
                        'device' => 'DESKTOP',
                        'http_error_code' => 522,
                    ]]]],
                ]],
            ],
        ]);
    }

    public function test_campaign_review_exposes_paused_destination_incident_and_scopes_foreign_rows(): void
    {
        $customer = $this->workspace();
        $campaign = $this->pausedIncident($customer);
        $this->get(route('campaigns.show', $campaign))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Campaigns/Show')
            ->where('campaign.status', 'paused')
            ->where('policyStatus.status', 'issues')
            ->where('policyStatus.issues.0.policy_topics.0.evidences.0.http_error_code', 522));

        $foreign = Campaign::factory()->create();
        $this->get(route('campaigns.show', $foreign))->assertNotFound();
    }

    public function test_managed_dashboard_keeps_paused_policy_incidents_and_excludes_other_tenants(): void
    {
        $customer = $this->workspace();
        $campaign = $this->pausedIncident($customer);
        $foreignCustomer = Customer::factory()->create(['website' => null]);
        $this->pausedIncident($foreignCustomer);
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->has('policyCampaigns', 1)
            ->where('policyCampaigns.0.id', $campaign->id)
            ->where('policyCampaigns.0.status', 'paused')
            ->where('policyCampaigns.0.policy_checks.issues.0.policy_topics.0.evidences.0.device', 'DESKTOP'));
    }

    public function test_setup_dashboard_keeps_policy_evidence_without_a_subscription(): void
    {
        $customer = $this->workspace('setup_only');
        $this->pausedIncident($customer);
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Setup/Index')
            ->has('policyCampaigns', 1)
            ->where('policyCampaigns.0.policy_checks.status', 'issues')
            ->where('policyCampaigns.0.policy_checks.issues.0.final_urls.0', 'https://business.example/services'));
    }

    public function test_dashboard_prioritizes_issues_bounds_unavailable_cards_and_omits_clear_or_ended_unknown_campaigns(): void
    {
        $customer = $this->workspace('setup_only');
        $incident = $this->pausedIncident($customer);
        Campaign::factory()->count(26)->create(['customer_id' => $customer->id, 'status' => CampaignStatus::Paused, 'google_ads_campaign_id' => '555', 'policy_checks' => null]);
        Campaign::factory()->create(['customer_id' => $customer->id, 'status' => CampaignStatus::Paused, 'google_ads_campaign_id' => '777', 'policy_checks' => ['status' => 'clear', 'issues' => []]]);
        Campaign::factory()->create(['customer_id' => $customer->id, 'status' => CampaignStatus::Ended, 'google_ads_campaign_id' => '888', 'policy_checks' => ['status' => 'unknown', 'issues' => []]]);
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Setup/Index')
            ->has('policyCampaigns', 25)
            ->where('policyCampaignCount', 27)
            ->where('policyCampaigns.0.id', $incident->id)
            ->missing('policyCampaigns.0.reason'));
    }

    public function test_admin_campaign_detail_exposes_the_same_persisted_unknown_snapshot(): void
    {
        $customer = $this->workspace();
        $campaign = $this->pausedIncident($customer);
        $campaign->update(['policy_checks' => array_replace($campaign->policy_checks, ['status' => 'unknown'])]);
        $role = Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin']));
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->roles()->attach($role);
        $this->actingAs($admin)->get(route('admin.campaigns.show', $campaign))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/CampaignDetail')
            ->where('policyStatus.status', 'unknown')
            ->where('policyStatus.issues.0.policy_topics.0.evidences.0.http_error_code', 522));
    }
}
