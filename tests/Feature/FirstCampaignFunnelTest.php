<?php

namespace Tests\Feature;

use App\Jobs\DeployCampaign;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Notifications\DeploymentFailed;
use App\Notifications\SiteScanFailed;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The first-campaign funnel beyond the crawl kickoff (which
 * SelfServeOnboardingTest covers): locale/currency at creation, the demo
 * hand-off, the setup checklist's states, and deploy failures being loud.
 */
class FirstCampaignFunnelTest extends TestCase
{
    use DatabaseTransactions;

    private function newUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function attach(User $user, Customer $customer): void
    {
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        session(['active_customer_id' => $customer->id]);
    }

    public function test_quick_start_derives_the_currency_with_the_country(): void
    {
        Queue::fake();
        $user = $this->newUser();

        $this->actingAs($user)->post(route('quick-start.process'), [
            'website_url' => 'https://example.com',
            'timezone' => 'Australia/Sydney',
        ]);

        $customer = $user->customers()->firstOrFail();
        $this->assertSame('AU', $customer->country);

        // The Google Ads sub-account is provisioned in this currency and it can
        // never be changed — leaving the column default gave Australians USD.
        $this->assertSame('AUD', $customer->currency_code);
    }

    public function test_an_unplaceable_timezone_falls_back_to_usd(): void
    {
        Queue::fake();
        $user = $this->newUser();

        $this->actingAs($user)->post(route('quick-start.process'), [
            'website_url' => 'https://example.com',
            'timezone' => 'UTC',
        ]);

        $this->assertSame('USD', $user->customers()->firstOrFail()->currency_code);
    }

    public function test_a_demo_signup_gets_the_prefilled_form_not_a_server_side_customer(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'demo_url' => 'https://demo.example.com',
        ]);

        // The page carries the URL for the client to auto-submit — processing
        // here on the GET had no browser timezone and created every demo
        // signup as UTC/US.
        $this->actingAs($user)->get(route('quick-start'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('QuickStart')
                ->where('demoUrl', 'https://demo.example.com'));

        $this->assertSame(0, $user->customers()->count());
    }

    public function test_setup_progress_reports_a_fresh_scan_as_in_progress(): void
    {
        $user = $this->newUser();
        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        $this->attach($user, $customer);

        $response = $this->actingAs($user)->getJson('/api/setup-progress')->assertOk();

        $steps = collect($response->json('steps'));
        $this->assertSame(
            ['site_scan', 'first_campaign', 'budget_confirmed', 'payment', 'deployed'],
            $steps->pluck('key')->all()
        );
        $this->assertSame('in_progress', $steps->firstWhere('key', 'site_scan')['status']);
        $this->assertTrue($response->json('is_working'));
    }

    public function test_setup_progress_reports_a_stalled_scan_as_failed(): void
    {
        $user = $this->newUser();
        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        $customer->forceFill(['created_at' => now()->subHours(3)])->save();
        $this->attach($user, $customer);

        $response = $this->actingAs($user)->getJson('/api/setup-progress')->assertOk();

        $this->assertSame('failed',
            collect($response->json('steps'))->firstWhere('key', 'site_scan')['status']);
    }

    public function test_setup_progress_completes_the_scan_step_once_content_exists(): void
    {
        $user = $this->newUser();
        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        $this->attach($user, $customer);

        KnowledgeBase::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'url' => 'https://example.com/about',
            'content' => str_repeat('Real page content. ', 30),
        ]);

        $response = $this->actingAs($user)->getJson('/api/setup-progress')->assertOk();

        $steps = collect($response->json('steps'));
        $this->assertSame('completed', $steps->firstWhere('key', 'site_scan')['status']);
        $this->assertFalse($response->json('is_working'));
    }

    public function test_a_manual_campaign_counts_as_budget_confirmed_but_an_auto_one_does_not(): void
    {
        $user = $this->newUser();
        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        $this->attach($user, $customer);

        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'auto_generated_at' => now(),
            'budget_confirmed_at' => null,
        ]);

        $steps = collect($this->actingAs($user)->getJson('/api/setup-progress')->json('steps'));
        $this->assertSame('pending', $steps->firstWhere('key', 'budget_confirmed')['status']);

        $campaign->update(['budget_confirmed_at' => now()]);

        $steps = collect($this->actingAs($user)->getJson('/api/setup-progress')->json('steps'));
        $this->assertSame('completed', $steps->firstWhere('key', 'budget_confirmed')['status']);
    }

    public function test_a_dead_deploy_job_tells_the_user_instead_of_only_the_log(): void
    {
        Notification::fake();

        $user = $this->newUser();
        $customer = Customer::factory()->create();
        $this->attach($user, $customer);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        // tries = 1: failed() is the only chance anyone has to hear about it.
        (new DeployCampaign($campaign))->failed(
            new \Exception('Cannot deploy campaign: Payment issue. Status: failed')
        );

        Notification::assertSentTo($user, DeploymentFailed::class, function ($notification) use ($user) {
            return str_contains($notification->toArray($user)['message'], 'payment method');
        });
    }

    public function test_a_scan_failure_notification_flips_the_checklist_step(): void
    {
        $user = $this->newUser();
        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        $this->attach($user, $customer);

        // notifyNow: the base TestCase fakes the queue, and this row has to
        // actually exist for the controller to see it.
        $user->notifyNow(new SiteScanFailed($customer, 'We couldn\'t reach your website.'));

        $response = $this->actingAs($user)->getJson('/api/setup-progress')->assertOk();

        $this->assertSame('failed',
            collect($response->json('steps'))->firstWhere('key', 'site_scan')['status']);
    }
}
