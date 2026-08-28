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
            'website_url' => 'https://example-agency.com',
            'service_type' => 'setup_only',
        ]);

        $this->assertSame('setup_only', $user->customers()->firstOrFail()->service_type);
    }

    public function test_quick_start_defaults_to_managed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/quick-start', ['website_url' => 'https://example-agency.com']);

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

        $this->mock(SetupFeeService::class, function ($mock) use ($customer) {
            $mock->shouldReceive('confirm')
                ->andReturnUsing(function () use ($customer) {
                    $customer->forceFill(['setup_fee_paid_at' => $customer->setup_fee_paid_at ?? now()])->save();

                    return true;
                });
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
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));
        [, $customer] = $this->setupOnlyCustomer(paid: true);

        $this->actingAs($admin)->post(route('admin.customers.handover', $customer));

        $this->assertNotNull($customer->fresh()->handover_at);
        Mail::assertSent(HandoverComplete::class, 1);

        $this->post(route('admin.customers.handover', $customer));
        Mail::assertSent(HandoverComplete::class, 1);
    }
}
