<?php

namespace Tests\Feature\Campaigns;

use App\Jobs\GenerateFirstCampaign;
use App\Jobs\GenerateStrategy;
use App\Mail\FirstCampaignReady;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Build the customer's first campaign from their own website.
 *
 * By the time the crawl finishes we know their brand voice, audience,
 * messaging and every page they sell from. Sixteen production accounts pasted
 * a URL, were crawled, and never built anything — so the wizard asks them to
 * describe a business we have already read.
 *
 * The budget is the part that has to be right. A language model proposes it,
 * and deployment charges seven days of it up front, so these tests pin that
 * nothing can be billed against a figure no human has accepted.
 */
class GenerateFirstCampaignTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $customer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
        $this->withoutVite();
        Customer::unsetEventDispatcher();

        $this->customer = Customer::factory()->create([
            'name' => 'Acme Plumbing',
            'website' => 'https://acmeplumbing.test',
            'currency_code' => 'AUD',
        ]);
        $this->user = User::factory()->create(['email_verified_at' => now()]);
        $this->user->customers()->attach($this->customer->id, ['role' => 'owner']);
    }

    private function crawlPages(int $count): void
    {
        foreach (range(1, $count) as $i) {
            DB::table('customer_pages')->insert([
                'customer_id' => $this->customer->id,
                'url' => "https://acmeplumbing.test/page-{$i}",
                'title' => "Page {$i}",
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function fakeAi(array $brief, bool $fenced = false): void
    {
        $json = json_encode($brief);
        $text = $fenced ? "```json\n{$json}\n```" : $json;

        $this->app->instance(GeminiService::class, new class($text) extends GeminiService
        {
            public function __construct(private readonly string $text) {}

            public function generateContent(
                string $model, string $prompt, array $config = [], ?string $systemInstruction = null,
                bool $enableThinking = false, bool $enableGoogleSearch = false, ?int $maxRetries = null,
                ?string $imageBase64 = null, string $imageMimeType = 'image/jpeg', array $context = []
            ): array {
                return ['text' => $this->text];
            }
        });
    }

    /** @return array<string, mixed> */
    private function brief(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Emergency plumbing enquiries',
            'reason' => 'Capture urgent local demand.',
            'goals' => 'Drive phone calls from people with a burst pipe.',
            'target_market' => 'Homeowners in Sydney with an urgent plumbing problem.',
            'voice' => 'Calm and practical.',
            'primary_kpi' => 'Cost per call under $40',
            'product_focus' => 'Emergency callouts',
            'daily_budget' => 60,
            'budget_rationale' => 'Enough to appear for urgent searches without overcommitting.',
        ], $overrides);
    }

    // ── It builds something real ─────────────────────────────────────────────

    public function test_it_builds_a_campaign_from_the_crawled_site(): void
    {
        Queue::fake();
        $this->crawlPages(12);
        $this->fakeAi($this->brief());

        (new GenerateFirstCampaign($this->customer))->handle(app(GeminiService::class));

        $campaign = Campaign::where('customer_id', $this->customer->id)->firstOrFail();

        $this->assertSame('Emergency plumbing enquiries', $campaign->name);
        // goals is cast to array on the model, so compare the stored value.
        $this->assertStringContainsString('burst pipe', (string) json_encode($campaign->goals));
        $this->assertNotNull($campaign->auto_generated_at);
        $this->assertSame(['google'], $campaign->platforms);
        // Something to actually review, not an empty shell.
        Queue::assertPushed(GenerateStrategy::class);
    }

    public function test_it_emails_the_customer(): void
    {
        Queue::fake();
        $this->crawlPages(12);
        $this->fakeAi($this->brief());

        (new GenerateFirstCampaign($this->customer))->handle(app(GeminiService::class));

        Mail::assertQueued(FirstCampaignReady::class);
    }

    public function test_it_survives_the_model_wrapping_json_in_a_code_fence(): void
    {
        Queue::fake();
        $this->crawlPages(12);
        // Models do this however firmly you ask them not to.
        $this->fakeAi($this->brief(), fenced: true);

        (new GenerateFirstCampaign($this->customer))->handle(app(GeminiService::class));

        $this->assertSame(1, Campaign::where('customer_id', $this->customer->id)->count());
    }

    // ── The budget must never bill unreviewed ────────────────────────────────

    public function test_the_suggested_budget_is_not_confirmed(): void
    {
        Queue::fake();
        $this->crawlPages(12);
        $this->fakeAi($this->brief(['daily_budget' => 60]));

        (new GenerateFirstCampaign($this->customer))->handle(app(GeminiService::class));

        $campaign = Campaign::where('customer_id', $this->customer->id)->firstOrFail();

        $this->assertEquals(60, $campaign->daily_budget);
        // The whole point: deployment charges seven days of this figure, so it
        // stays a suggestion until a person accepts it.
        $this->assertNull($campaign->budget_confirmed_at);
        $this->assertNotNull($campaign->budget_rationale);
        $this->assertEquals(420, $campaign->total_budget, 'total should be seven days at the suggested rate');
    }

    public function test_an_absurd_model_budget_is_clamped(): void
    {
        Queue::fake();
        $this->crawlPages(12);
        // A misplaced decimal must not present someone with a four-figure daily spend.
        $this->fakeAi($this->brief(['daily_budget' => 99999]));

        (new GenerateFirstCampaign($this->customer))->handle(app(GeminiService::class));

        $this->assertEquals(200, Campaign::where('customer_id', $this->customer->id)->value('daily_budget'));
    }

    public function test_deployment_is_refused_until_the_budget_is_confirmed(): void
    {
        Queue::fake();

        // A paying subscriber, so the subscription gate cannot be what stops
        // this — the budget confirmation has to be doing the work.
        $subscriber = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_status' => 'active',
        ]);
        $subscriber->customers()->attach($this->customer->id, ['role' => 'owner']);

        $campaign = Campaign::factory()->create([
            'customer_id' => $this->customer->id,
            'auto_generated_at' => now(),
            'daily_budget' => 60,
            'budget_confirmed_at' => null,
        ]);

        $this->actingAs($subscriber)
            ->withSession(['active_customer_id' => $this->customer->id])
            ->post(route('deployment.deploy'), ['campaign_id' => $campaign->id]);

        // Nothing may be charged against a figure a language model chose and
        // nobody accepted. Seven days of it is billed up front.
        Queue::assertNotPushed(\App\Jobs\DeployCampaign::class);
    }

    public function test_a_confirmed_budget_clears_the_deployment_guard(): void
    {
        Queue::fake();

        $subscriber = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_status' => 'active',
        ]);
        $subscriber->customers()->attach($this->customer->id, ['role' => 'owner']);

        $campaign = Campaign::factory()->create([
            'customer_id' => $this->customer->id,
            'auto_generated_at' => now(),
            'daily_budget' => 60,
            'budget_confirmed_at' => now(),
        ]);

        $response = $this->actingAs($subscriber)
            ->withSession(['active_customer_id' => $this->customer->id])
            ->post(route('deployment.deploy'), ['campaign_id' => $campaign->id]);

        // Whatever else the controller decides about this campaign, the budget
        // guard is no longer what stopped it.
        $this->assertStringNotContainsString(
            'Confirm your daily budget',
            (string) json_encode(session('flash')),
        );
        $response->assertRedirect();
    }

    public function test_confirming_the_budget_records_who_accepted_what(): void
    {
        $campaign = Campaign::factory()->create([
            'customer_id' => $this->customer->id,
            'auto_generated_at' => now(),
            'daily_budget' => 60,
            'budget_confirmed_at' => null,
        ]);

        $this->actingAs($this->user)
            ->withSession(['active_customer_id' => $this->customer->id])
            ->post(route('campaigns.confirm-budget', $campaign), ['daily_budget' => 35]);

        $campaign->refresh();

        // Their number, not the model's — and the seven-day total follows it.
        $this->assertEquals(35, $campaign->daily_budget);
        $this->assertEquals(245, $campaign->total_budget);
        $this->assertNotNull($campaign->budget_confirmed_at);
    }

    public function test_an_auto_generated_campaign_does_not_render_video(): void
    {
        Queue::fake();
        $this->crawlPages(12);
        $this->fakeAi($this->brief());

        (new GenerateFirstCampaign($this->customer))->handle(app(GeminiService::class));

        $campaign = Campaign::where('customer_id', $this->customer->id)->firstOrFail();

        // GenerateCampaignCollateral queues two Veo renders per strategy off
        // this rule, and Veo is billed per second of output.
        $this->assertFalse($campaign->allowsAutomaticVideo());
    }

    public function test_a_campaign_the_customer_built_still_allows_video(): void
    {
        $campaign = Campaign::factory()->create([
            'customer_id' => $this->customer->id,
            'auto_generated_at' => null,
        ]);

        // The rule is about who asked for the campaign, not about video being
        // unwanted in general.
        $this->assertTrue($campaign->allowsAutomaticVideo());
    }

    public function test_the_review_screen_carries_everything_the_budget_panel_needs(): void
    {
        // The deployment guard is enforced server-side, but if the review
        // screen cannot render a confirmation the customer clicks Deploy, is
        // told to confirm a budget, and has nowhere to do it.
        $campaign = Campaign::factory()->create([
            'customer_id' => $this->customer->id,
            'auto_generated_at' => now(),
            'daily_budget' => 60,
            'budget_confirmed_at' => null,
            'budget_rationale' => 'Enough to appear for urgent searches.',
        ]);

        $this->actingAs($this->user)
            ->withSession(['active_customer_id' => $this->customer->id])
            ->get(route('campaigns.show', $campaign))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->where('campaign.auto_generated_at', fn ($v) => $v !== null)
                ->where('campaign.budget_confirmed_at', null)
                ->where('campaign.budget_rationale', 'Enough to appear for urgent searches.')
                // Needed to state what will actually be charged.
                ->where('campaign.currency_code', 'AUD')
            );
    }

    public function test_confirming_from_the_review_screen_unblocks_deployment(): void
    {
        Queue::fake();

        $subscriber = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_status' => 'active',
        ]);
        $subscriber->customers()->attach($this->customer->id, ['role' => 'owner']);

        $campaign = Campaign::factory()->create([
            'customer_id' => $this->customer->id,
            'auto_generated_at' => now(),
            'daily_budget' => 60,
            'budget_confirmed_at' => null,
        ]);

        // The full path a customer takes: change the number, confirm, deploy.
        $this->actingAs($subscriber)
            ->withSession(['active_customer_id' => $this->customer->id])
            ->post(route('campaigns.confirm-budget', $campaign), ['daily_budget' => 40]);

        $this->actingAs($subscriber)
            ->withSession(['active_customer_id' => $this->customer->id])
            ->post(route('deployment.deploy'), ['campaign_id' => $campaign->id]);

        $campaign->refresh();

        $this->assertEquals(40, $campaign->daily_budget);
        $this->assertNotNull($campaign->budget_confirmed_at);
        // Seven days at the figure they chose, not the one we suggested.
        $this->assertEquals(280, $campaign->total_budget);
    }

    // ── Who qualifies ────────────────────────────────────────────────────────

    public function test_a_thin_crawl_does_not_qualify(): void
    {
        // A parked domain or a one-page placeholder produces a generic campaign,
        // which is a worse first impression than none — and spends model budget
        // on a signup that was never real.
        $this->crawlPages(2);

        $this->assertFalse(GenerateFirstCampaign::qualifies($this->customer));
    }

    public function test_an_account_that_already_has_a_campaign_does_not_qualify(): void
    {
        $this->crawlPages(12);
        Campaign::factory()->create(['customer_id' => $this->customer->id]);

        // Never write over work they did themselves.
        $this->assertFalse(GenerateFirstCampaign::qualifies($this->customer));
    }

    public function test_the_kill_switch_stops_it(): void
    {
        $this->crawlPages(12);
        config(['first_campaign.enabled' => false]);

        $this->assertFalse(GenerateFirstCampaign::qualifies($this->customer));
    }

    public function test_a_model_failure_does_not_break_onboarding(): void
    {
        $this->crawlPages(12);
        $this->app->instance(GeminiService::class, new class extends GeminiService
        {
            public function __construct() {}

            public function generateContent(
                string $model, string $prompt, array $config = [], ?string $systemInstruction = null,
                bool $enableThinking = false, bool $enableGoogleSearch = false, ?int $maxRetries = null,
                ?string $imageBase64 = null, string $imageMimeType = 'image/jpeg', array $context = []
            ): ?array {
                throw new \RuntimeException('Gemini is down');
            }
        });

        // This runs unbidden at the end of onboarding. A failure means the
        // customer misses a bonus, not that their signup broke.
        (new GenerateFirstCampaign($this->customer))->handle(app(GeminiService::class));

        $this->assertSame(0, Campaign::where('customer_id', $this->customer->id)->count());
    }

    public function test_unparseable_output_creates_nothing(): void
    {
        $this->crawlPages(12);
        $this->app->instance(GeminiService::class, new class extends GeminiService
        {
            public function __construct() {}

            public function generateContent(
                string $model, string $prompt, array $config = [], ?string $systemInstruction = null,
                bool $enableThinking = false, bool $enableGoogleSearch = false, ?int $maxRetries = null,
                ?string $imageBase64 = null, string $imageMimeType = 'image/jpeg', array $context = []
            ): array {
                return ['text' => 'Sure! Here is a great campaign idea for you.'];
            }
        });

        (new GenerateFirstCampaign($this->customer))->handle(app(GeminiService::class));

        // Half a campaign is worse than none.
        $this->assertSame(0, Campaign::where('customer_id', $this->customer->id)->count());
    }
}
