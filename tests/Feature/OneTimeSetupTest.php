<?php

namespace Tests\Feature;

use App\Mail\HandoverComplete;
use App\Mail\SetupFeeReceived;
use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\SetupFeeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The one-and-done product: US$999 once, we build their Google Ads
 * presence and hand over. No subscription ever exists — the paid setup fee
 * IS their access — and the recurring management agents never touch them.
 */
class OneTimeSetupTest extends TestCase
{
    use DatabaseTransactions;

    private function setupOnlyCustomer(bool $paid = true): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create([
            'service_type' => 'setup_only',
            'setup_fee_paid_at' => $paid ? now() : null,
        ]);
        $customer->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $customer];
    }

    public function test_quick_start_records_the_one_and_done_choice(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/quick-start', [
            'website_url' => 'https://example.org',
            'service_type' => 'setup_only',
        ]);

        $this->assertSame('setup_only', $user->customers()->firstOrFail()->service_type);
    }

    public function test_quick_start_defaults_to_managed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/quick-start', ['website_url' => 'https://example.org']);

        $this->assertSame('managed', $user->customers()->firstOrFail()->service_type);
    }

    public function test_the_paid_setup_fee_is_their_subscription(): void
    {
        [$user, $customer] = $this->setupOnlyCustomer(paid: true);

        $this->assertTrue($user->hasSubscriptionAccess($customer));
    }

    public function test_an_unpaid_setup_intent_grants_nothing(): void
    {
        [$user, $customer] = $this->setupOnlyCustomer(paid: false);

        $this->assertFalse($user->hasSubscriptionAccess($customer));
    }

    public function test_a_paid_setup_customer_clears_the_deploy_paywall(): void
    {
        [$user, $customer] = $this->setupOnlyCustomer(paid: true);
        BrandGuideline::create([
            'customer_id' => $customer->id,
            'brand_voice' => ['primary_tone' => 'direct'],
            'tone_attributes' => ['direct'],
            'target_audience' => ['primary' => 'Homeowners'],
            'competitor_differentiation' => ['End to end.'],
            'messaging_themes' => ['Care'],
            'unique_selling_propositions' => ['None'],
            'do_not_use' => ['Jargon'],
            'color_palette' => ['primary_colors' => ['#111111']],
            'typography' => ['heading_style' => 'sans'],
            'visual_style' => ['overall_aesthetic' => 'modern'],
            'writing_patterns' => ['sentence_length' => 'varied'],
            'brand_personality' => ['archetype' => 'Everyman'],
            'service_lines' => [['name' => 'Service']],
            'extraction_quality_score' => 90,
            'extracted_at' => now(),
            'user_verified' => true,
        ]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'draft']);

        $response = $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->post('/deployment/deploy', ['campaign_id' => $campaign->id]);

        // Past the paywall and the brand gate — wherever the deploy pipeline
        // sends it next, it is not the pricing page.
        $this->assertStringNotContainsString('pricing', (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString('brand-guidelines', (string) $response->headers->get('Location'));
    }

    public function test_the_checklist_asks_for_the_fee_not_a_plan(): void
    {
        [$user, $customer] = $this->setupOnlyCustomer(paid: false);

        $step = collect(
            $this->actingAs($user)
                ->withSession(['active_customer_id' => $customer->id])
                ->getJson('/api/setup-progress')
                ->json('steps')
        )->firstWhere('key', 'payment');

        $this->assertSame('Pay your one-time setup fee', $step['title']);
        $this->assertFalse($step['completed']);
    }

    public function test_confirmed_payment_notifies_once_and_only_once(): void
    {
        Mail::fake();
        [$user, $customer] = $this->setupOnlyCustomer(paid: false);
        $user->forceFill(['stripe_id' => 'cus_once'])->save();

        // Real service, stubbed Stripe read — the acceptance rules and the
        // one-send guarantee both run for real.
        $session = (object) [
            'metadata' => ['customer_id' => (string) $customer->id],
            'customer' => 'cus_once',
            'payment_status' => 'paid',
        ];
        $this->app->instance(SetupFeeService::class, new class($session) extends SetupFeeService
        {
            public function __construct(private object $session) {}

            protected function retrieveSession(string $sessionId): object
            {
                return $this->session;
            }
        });

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get(route('setup-fee.success', ['session_id' => 'cs_test_123']))
            ->assertRedirect(route('dashboard', absolute: false));

        Mail::assertSent(SetupFeeReceived::class, 1);

        // The success URL can be revisited; the receipt must not resend.
        $this->get(route('setup-fee.success', ['session_id' => 'cs_test_123']));
        Mail::assertSent(SetupFeeReceived::class, 1);
    }

    public function test_the_webhook_records_a_payment_the_redirect_never_delivered(): void
    {
        // The buyer paid, then closed the tab on Stripe's confirmation
        // screen: the success redirect never ran. The webhook is the
        // fallback recorder — and it must be idempotent against the
        // redirect arriving later.
        Mail::fake();
        // Signature verification is Stripe-secret-bound; the handler's
        // behaviour is what's under test here.
        $this->withoutMiddleware(\Laravel\Cashier\Http\Middleware\VerifyWebhookSignature::class);
        [$user, $customer] = $this->setupOnlyCustomer(paid: false);
        $user->forceFill(['stripe_id' => 'cus_webhook'])->save();

        $payload = [
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_webhook_1',
                'customer' => 'cus_webhook',
                'mode' => 'payment',
                'status' => 'complete',
                'payment_status' => 'paid',
                'metadata' => ['purpose' => 'setup_fee', 'customer_id' => (string) $customer->id],
            ]],
        ];

        $this->postJson('/api/stripe/webhook', $payload)->assertSuccessful();

        $customer->refresh();
        $this->assertTrue($customer->isPaidSetupOnly());
        Mail::assertSent(SetupFeeReceived::class, 1);

        // Replayed webhook: no double-record, no second receipt.
        $this->postJson('/api/stripe/webhook', $payload)->assertSuccessful();
        Mail::assertSent(SetupFeeReceived::class, 1);
    }

    public function test_a_fully_discounted_session_is_a_paid_session(): void
    {
        Mail::fake();
        [$user, $customer] = $this->setupOnlyCustomer(paid: false);
        $user->forceFill(['stripe_id' => 'cus_free'])->save();

        /*
           A 100%-off promotion code.

           Stripe creates no PaymentIntent when a coupon takes the total to
           zero, so the session comes back 'no_payment_required' rather than
           'paid'. Gating on 'paid' alone meant a free code was accepted at
           Stripe and then refused here — the customer redeems it, the checkout
           succeeds, and they land back on a page that says they have not paid.
        */
        $session = (object) [
            'metadata' => ['customer_id' => (string) $customer->id],
            'customer' => 'cus_free',
            'payment_status' => 'no_payment_required',
        ];
        $this->app->instance(SetupFeeService::class, new class($session) extends SetupFeeService
        {
            public function __construct(private object $session) {}

            protected function retrieveSession(string $sessionId): object
            {
                return $this->session;
            }
        });

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get(route('setup-fee.success', ['session_id' => 'cs_free_1']));

        $this->assertTrue($customer->fresh()->isPaidSetupOnly());
        Mail::assertSent(SetupFeeReceived::class, 1);
    }

    public function test_an_unpaid_session_is_still_refused(): void
    {
        Mail::fake();
        [$user, $customer] = $this->setupOnlyCustomer(paid: false);
        $user->forceFill(['stripe_id' => 'cus_unpaid'])->save();

        // Widening the accepted set for coupons must not let a genuinely
        // unpaid session through.
        $session = (object) [
            'metadata' => ['customer_id' => (string) $customer->id],
            'customer' => 'cus_unpaid',
            'payment_status' => 'unpaid',
        ];
        $this->app->instance(SetupFeeService::class, new class($session) extends SetupFeeService
        {
            public function __construct(private object $session) {}

            protected function retrieveSession(string $sessionId): object
            {
                return $this->session;
            }
        });

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get(route('setup-fee.success', ['session_id' => 'cs_unpaid_1']));

        $this->assertFalse($customer->fresh()->isPaidSetupOnly());
        Mail::assertNotSent(SetupFeeReceived::class);
    }

    public function test_the_webhook_also_accepts_a_fully_discounted_session(): void
    {
        Mail::fake();
        $this->withoutMiddleware(\Laravel\Cashier\Http\Middleware\VerifyWebhookSignature::class);
        [$user, $customer] = $this->setupOnlyCustomer(paid: false);
        $user->forceFill(['stripe_id' => 'cus_webhook_free'])->save();

        $this->postJson('/api/stripe/webhook', [
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_webhook_free',
                'customer' => 'cus_webhook_free',
                'mode' => 'payment',
                'status' => 'complete',
                'payment_status' => 'no_payment_required',
                'metadata' => ['purpose' => 'setup_fee', 'customer_id' => (string) $customer->id],
            ]],
        ])->assertSuccessful();

        $this->assertTrue($customer->fresh()->isPaidSetupOnly());
        Mail::assertSent(SetupFeeReceived::class, 1);
    }

    public function test_paying_commissions_the_campaign_even_below_the_bonus_threshold(): void
    {
        Mail::fake();
        Queue::fake();
        $this->stubInviter();
        [$user, $customer] = $this->setupOnlyCustomer(paid: false);

        /*
           Two good pages: below the bar for an unprompted campaign.

           qualifies() declines to write one off its own bat below the
           threshold, which is right for a free bonus — a generic campaign is a
           worse first impression than none. It is wrong for a paid engagement:
           a US$999 customer who is declined gets an account, conversion
           tracking and no campaign, with the wizard closed to them and nothing
           else that would ever produce one. The journey sat at "writing your
           campaign and ads" permanently.

           Two rather than four because the threshold itself has since moved to
           four — measured across every crawled account, nothing at all sits
           between one substantive page and four, so five was a cliff catching
           real sites for no benefit. This test is about payment moving the bar,
           so it needs a page count that is still under it.
        */
        foreach (range(1, 2) as $i) {
            \Illuminate\Support\Facades\DB::table('knowledge_bases')->insert([
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'url' => "https://example.test/page-{$i}",
                'content' => str_repeat('substantive page text. ', 60),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(SetupFeeService::class)->recordPayment($customer, $user);

        Queue::assertPushed(\App\Jobs\GenerateFirstCampaign::class, fn ($job) => $job->paidFor === true);

        // And the job it dispatched must actually write one. Asserted on
        // qualifies() directly because Queue::fake stops the job short of it —
        // dispatching into a gate that then declines is the bug, not the fix.
        $customer->refresh();
        $this->assertTrue(\App\Jobs\GenerateFirstCampaign::qualifies($customer, paidFor: true));
        $this->assertFalse(
            \App\Jobs\GenerateFirstCampaign::qualifies($customer),
            'a thin crawl must still not earn an unprompted campaign — the bar moves for payment, not in general',
        );
    }

    public function test_an_account_with_nothing_readable_is_still_not_given_a_campaign(): void
    {
        Mail::fake();
        Queue::fake();
        $this->stubInviter();
        [$user, $customer] = $this->setupOnlyCustomer(paid: false);

        // Paying lowers the bar to "there is something to write from"; it does
        // not remove it. A campaign written from nothing is worse than none,
        // and that judgement does not change because money changed hands.
        app(SetupFeeService::class)->recordPayment($customer, $user);

        $this->assertFalse(
            \App\Jobs\GenerateFirstCampaign::qualifies($customer->fresh(), paidFor: true),
        );
    }

    public function test_setup_only_campaigns_start_paused_even_when_enabled_is_requested(): void
    {
        [, $customer] = $this->setupOnlyCustomer(paid: true);
        \App\Models\Setting::where('key', 'campaign_testing_mode')->delete();
        config(['campaigns.testing_mode_default' => false, 'campaigns.default_status' => 'ENABLED']);

        $paused = \Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus::PAUSED;
        $this->assertSame($paused, \App\Services\CampaignStatusHelper::getGoogleAdsStatus(customer: $customer));
        $this->assertSame($paused, \App\Services\CampaignStatusHelper::getGoogleAdsStatus('ENABLED', $customer));

        $customer->service_type = 'managed';
        $this->assertSame(
            \Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus::ENABLED,
            \App\Services\CampaignStatusHelper::getGoogleAdsStatus('ENABLED', $customer),
        );
    }

    public function test_setup_only_deploys_arrive_paused(): void
    {
        [, $customer] = $this->setupOnlyCustomer(paid: true);
        $customer->forceFill(['google_ads_customer_id' => '111-222-3333'])->save();
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'google_ads_campaign_id' => '987654321',
        ]);

        $calls = new \ArrayObject;
        $stub = new class($calls) extends \App\Services\GoogleAds\CommonServices\UpdateCampaignStatus
        {
            public function __construct(private \ArrayObject $calls) {}

            public function execute(string $customerId, string $campaignResourceName, string $status): array
            {
                $this->calls[] = [$customerId, $campaignResourceName, $status];

                return ['success' => true, 'new_status' => $status];
            }
        };

        // The pause used to live inside DeployCampaign and this test reached
        // into it through a subclass. It is SettleDeployedCampaign's now —
        // because the deploy job was not the only thing that finishes a
        // deployment, and the one that finishes it late was skipping this
        // entirely and leaving a setup-only customer's ads running.
        $settler = new class($stub) extends \App\Services\Campaigns\SettleDeployedCampaign
        {
            public function __construct(private $stub) {}

            protected function campaignStatusService(\App\Models\Customer $customer): \App\Services\GoogleAds\CommonServices\UpdateCampaignStatus
            {
                return $this->stub;
            }
        };

        $moved = $settler->settle($campaign->fresh('customer'));

        $this->assertCount(1, $calls);
        $this->assertSame(['1112223333', 'customers/1112223333/campaigns/987654321', 'PAUSED'], $calls[0]);

        $this->assertTrue($moved);
        $this->assertSame(
            \App\Enums\CampaignStatus::Paused,
            $campaign->fresh()->status,
            'a setup-only campaign must never be settled to active — the receipt email promises it arrives paused',
        );
    }

    public function test_recurring_management_never_sees_setup_only_customers(): void
    {
        [, $setupOnly] = $this->setupOnlyCustomer(paid: true);
        $managed = Customer::factory()->create();

        $ids = Customer::managed()->pluck('id');

        $this->assertTrue($ids->contains($managed->id));
        $this->assertFalse($ids->contains($setupOnly->id));
    }

    public function test_admin_handover_emails_the_keys_once(): void
    {
        Mail::fake();
        $this->stubInviter();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));
        [, $customer] = $this->setupOnlyCustomer(paid: true);
        $customer->forceFill(['google_ads_customer_id' => '111-222-3333'])->save();

        $this->actingAs($admin)->post(route('admin.customers.handover', $customer));

        $this->assertNotNull($customer->fresh()->handover_at);
        Mail::assertSent(HandoverComplete::class, 1);

        $this->post(route('admin.customers.handover', $customer));
        Mail::assertSent(HandoverComplete::class, 1);
    }

    public function test_there_is_no_handover_before_there_is_an_account(): void
    {
        Mail::fake();
        $this->stubInviter();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));
        // Paid, but the build has not produced a Google Ads account yet.
        [, $customer] = $this->setupOnlyCustomer(paid: true);

        $this->actingAs($admin)->post(route('admin.customers.handover', $customer));

        /*
           The old version stamped this and sent the email anyway, because the
           invite loop was wrapped in `if ($customer->google_ads_customer_id)`
           and an empty loop leaves no failures behind. The result was the exact
           thing this engagement is supposed to avoid: a "the keys are yours"
           email naming an account that does not exist, and a handover_at that
           stops anyone chasing it.
        */
        $this->assertNull($customer->fresh()->handover_at);
        Mail::assertNotSent(HandoverComplete::class);
    }

    /** The real one builds a Google Ads client in its constructor. */
    private function stubInviter(): void
    {
        $this->app->bind(
            \App\Services\GoogleAds\CommonServices\InviteCustomerUser::class,
            fn () => new class extends \App\Services\GoogleAds\CommonServices\InviteCustomerUser
            {
                public function __construct() {}

                public function execute(string $customerId, string $email, int $accessRole = 2): array
                {
                    return ['success' => true];
                }
            },
        );
    }
}
