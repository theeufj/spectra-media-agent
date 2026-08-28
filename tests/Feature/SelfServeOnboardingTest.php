<?php

namespace Tests\Feature;

use App\Jobs\CrawlSitemap;
use App\Jobs\DiscoverNavigationUrls;
use App\Jobs\ExtractBrandGuidelines;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The self-serve funnel: a new account should land on the quick start, and
 * whichever way they create a customer, the site scan should start.
 */
class SelfServeOnboardingTest extends TestCase
{
    use DatabaseTransactions;

    private function newUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_a_user_with_no_customer_is_sent_to_the_quick_start(): void
    {
        $response = $this->actingAs($this->newUser())->get('/dashboard');

        $response->assertRedirect(route('quick-start', absolute: false));
    }

    public function test_the_quick_start_itself_is_reachable_without_a_customer(): void
    {
        $this->actingAs($this->newUser())->get(route('quick-start'))->assertOk();
    }

    public function test_the_manual_form_stays_reachable_as_the_escape_hatch(): void
    {
        $this->actingAs($this->newUser())->get(route('customers.create'))->assertOk();
    }

    public function test_the_quick_start_creates_a_customer_and_starts_the_scan(): void
    {
        Queue::fake();
        $user = $this->newUser();

        $response = $this->actingAs($user)->post(route('quick-start.process'), [
            'website_url' => 'https://example.com',
            'timezone' => 'Australia/Sydney',
        ]);

        // The holding screen, not the dashboard — the scan is narrated now.
        $response->assertRedirect(route('quick-start.scanning', absolute: false));

        $customer = $user->customers()->firstOrFail();
        $this->assertSame('https://example.com', $customer->website);

        // Derived from the timezone rather than defaulted to US.
        $this->assertSame('AU', $customer->country);

        Queue::assertPushed(CrawlSitemap::class, fn ($job) => $job->customerId === $customer->id
            && $job->sitemapUrl === 'https://example.com/sitemap.xml');

        // The crawl chain dispatches this once pages exist; a second delayed
        // copy used to be queued here and run the extraction twice.
        Queue::assertNotPushed(ExtractBrandGuidelines::class);
    }

    public function test_an_unrecognised_timezone_still_falls_back_to_us(): void
    {
        Queue::fake();
        $user = $this->newUser();

        $this->actingAs($user)->post(route('quick-start.process'), [
            'website_url' => 'https://example.com',
            'timezone' => 'UTC',
        ]);

        $this->assertSame('US', $user->customers()->firstOrFail()->country);
    }

    public function test_the_manual_form_also_starts_the_scan(): void
    {
        Queue::fake();
        $user = $this->newUser();

        $this->actingAs($user)->post(route('customers.store'), [
            'name' => 'Acme',
            'country' => 'AU',
            'timezone' => 'Australia/Sydney',
            'website' => 'https://acme.example',
        ])->assertRedirect(route('dashboard', absolute: false));

        $customer = $user->customers()->firstOrFail();

        Queue::assertPushed(CrawlSitemap::class, fn ($job) => $job->customerId === $customer->id
            && $job->sitemapUrl === 'https://acme.example/sitemap.xml');
    }

    public function test_a_customer_with_no_website_starts_no_scan(): void
    {
        Queue::fake();
        $user = $this->newUser();

        $this->actingAs($user)->post(route('customers.store'), [
            'name' => 'Acme',
            'country' => 'AU',
            'timezone' => 'Australia/Sydney',
        ])->assertRedirect(route('dashboard', absolute: false));

        Queue::assertNotPushed(CrawlSitemap::class);
    }

    public function test_a_missing_sitemap_falls_back_to_crawling_the_navigation(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('Not Found', 404)]);

        $user = $this->newUser();
        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        (new CrawlSitemap($user, 'https://example.com/sitemap.xml', $customer->id))->handle();

        // Used to log and return, leaving the customer on "we're scanning your
        // website now" with nothing ever crawled.
        Queue::assertPushed(DiscoverNavigationUrls::class,
            fn ($job) => $job->customer->id === $customer->id);
    }
}
