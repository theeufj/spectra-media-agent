<?php

namespace Tests\Feature;

use App\Jobs\RunCroAudit;
use App\Jobs\RunSeoAudit;
use App\Models\Customer;
use App\Models\User;
use App\Rules\SafePublicUrl;
use App\Services\BrandGuidelineExtractorService;
use App\Services\Demo\CampaignForecastPreview;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Every URL field in this application ends up being fetched BY OUR SERVERS —
 * the public demo scrapes it, RunSeoAudit and RunCroAudit render it in
 * Browsershot, CrawlCompetitorWebsite reads it. So each one is an SSRF hole
 * until the host is proven publicly routable, and /api/demo/generate-full is
 * the worst of them: unauthenticated, three requests a minute, on the box that
 * holds the MCC credentials.
 */
class SsrfProtectionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_rule_rejects_everything_that_is_not_a_public_website(): void
    {
        foreach ([
            // Laravel's `url` rule accepts ~400 protocols, these among them.
            'file:///etc/passwd',
            'gopher://127.0.0.1:11211/_stats',
            'dict://169.254.169.254:80/',
            // Credentials hide the real authority from a human and from a log.
            'https://www.example.com@169.254.169.254/latest/meta-data/',
            'http://user:pass@8.8.8.8/',
            // Loopback, private, link-local, multicast, CGNAT, IPv6 loopback.
            'http://127.0.0.1/admin',
            'https://localhost',
            'https://redis.internal',
            'http://10.0.0.5/',
            'https://192.168.1.1',
            'https://172.16.0.9/x',
            'https://169.254.169.254/latest/meta-data/',
            'http://224.0.0.1/',
            'http://100.64.0.1/',
            'http://[::1]/',
            'http://[::ffff:127.0.0.1]/',
            'not a url at all',
            '',
        ] as $bad) {
            $this->assertFalse(SafePublicUrl::isSafe($bad), "should reject: {$bad}");
        }
    }

    public function test_the_rule_accepts_an_ordinary_public_website(): void
    {
        // IP literals so the assertion does not depend on DNS being reachable
        // from wherever the suite runs.
        $this->assertTrue(SafePublicUrl::isSafe('https://93.184.216.34/'));
        $this->assertTrue(SafePublicUrl::isSafe('http://8.8.8.8/x?y=1#z'));
    }

    public function test_the_public_demo_refuses_the_cloud_metadata_endpoint(): void
    {
        // Fake rather than leave it unfaked: this is what makes
        // assertNothingSent() an assertion rather than a tautology.
        Http::fake();

        $this->postJson('/api/demo/generate-full', ['url' => 'http://169.254.169.254/latest/meta-data/'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');

        Http::assertNothingSent();
    }

    public function test_the_public_demo_still_reads_a_real_website(): void
    {
        $this->fakeDemoServices();

        Http::fake([
            'https://93.184.216.34/app.css' => Http::response('body { color: #ff4d00; }', 200),
            'https://93.184.216.34/*' => Http::response(
                '<html><head><title>Acme</title>'
                // The stylesheet href comes from the fetched page, so it is the
                // attacker's second bite at the same apple.
                .'<link rel="stylesheet" href="http://169.254.169.254/latest/meta-data/">'
                .'<link rel="stylesheet" href="/app.css">'
                .'</head><body><h1>Acme sells things</h1></body></html>',
                200
            ),
            '*' => Http::response('', 404),
        ]);

        $this->postJson('/api/demo/generate-full', ['url' => 'https://93.184.216.34/'])
            ->assertOk()
            ->assertJson(['success' => true, 'url' => 'https://93.184.216.34/']);

        Http::assertSent(fn ($request) => $request->url() === 'https://93.184.216.34/app.css');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_the_seo_audit_refuses_a_private_host(): void
    {
        $this->actingAsCustomerUser();

        $this->post('/seo/audit', ['url' => 'http://10.0.0.5/'])
            ->assertSessionHasErrors('url');

        Queue::assertNotPushed(RunSeoAudit::class);
    }

    public function test_the_seo_audit_still_runs_for_a_public_host(): void
    {
        $this->actingAsCustomerUser();

        $this->post('/seo/audit', ['url' => 'https://93.184.216.34/'])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(RunSeoAudit::class);
    }

    public function test_the_cro_audit_refuses_a_private_host(): void
    {
        $customer = $this->actingAsCustomerUser();

        $this->withSession(['active_customer_id' => $customer->id])
            ->post('/seo/cro/run', ['url' => 'http://127.0.0.1:8000/'])
            ->assertSessionHasErrors('url');

        Queue::assertNotPushed(RunCroAudit::class);
    }

    public function test_a_war_room_competitor_cannot_be_an_internal_address(): void
    {
        $customer = $this->actingAsCustomerUser();

        $this->withSession(['active_customer_id' => $customer->id])
            ->post('/strategy/war-room/competitors', ['url' => 'https://169.254.169.254/'])
            ->assertSessionHasErrors('url');

        $this->assertSame(0, $customer->competitors()->count());
    }

    /**
     * Every route under test sits behind 'auth' + 'ensureUserHasCustomer', and
     * a factory user has no customer — without the pivot row the request is
     * redirected to the quick start and the assertion passes for the wrong
     * reason.
     */
    private function actingAsCustomerUser(): Customer
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user);

        return $customer;
    }

    /**
     * The demo's AI and Keyword Planner legs are not what is under test here;
     * only which URLs the box is willing to fetch is.
     */
    private function fakeDemoServices(): void
    {
        $this->mock(GeminiService::class, function ($mock) {
            $mock->shouldReceive('generateContent')->andReturn(null);
        });

        $this->mock(BrandGuidelineExtractorService::class, function ($mock) {
            $mock->shouldReceive('analyzeVisualStyle')->andReturn([]);
            // The demo renders the page through Chromium for its text now, in
            // the same request as the screenshot. Null here is "the render gave
            // us nothing", which is the path that falls back to the static
            // fetch — and that fetch is what these tests are about.
            $mock->shouldReceive('renderedText')->andReturn(null);
        });

        $this->mock(CampaignForecastPreview::class, function ($mock) {
            $mock->shouldReceive('forUrl')->andReturn(null);
        });
    }
}
