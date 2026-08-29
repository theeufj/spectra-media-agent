<?php

namespace Tests\Feature;

use App\Jobs\AutomatedCampaignMaintenance;
use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use App\Services\EarlyExitFeeService;
use App\Services\GoogleAds\VerifyAccountAccess;
use App\Services\SetupFeeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The seams a coverage audit found untested: the Stripe acceptance rules
 * behind the setup fee, the revoked-vs-inconclusive classification behind
 * drift detection, the exact eligibility query the maintenance agents run,
 * and the deploy gate for revoked links.
 */
class CoverageGapsTest extends TestCase
{
    use DatabaseTransactions;

    /** A SetupFeeService whose Stripe read returns a canned session. */
    private function serviceReturning(object $session): SetupFeeService
    {
        return new class($session) extends SetupFeeService
        {
            public function __construct(private object $session) {}

            protected function retrieveSession(string $sessionId): object
            {
                return $this->session;
            }
        };
    }

    private function stripeSession(Customer $customer, User $user, array $overrides = []): object
    {
        return (object) array_merge([
            'metadata' => ['customer_id' => (string) $customer->id],
            'customer' => $user->stripe_id,
            'payment_status' => 'paid',
        ], $overrides);
    }

    public function test_setup_fee_confirm_accepts_a_paid_session_for_the_right_customer(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_ok']);
        $customer = Customer::factory()->create(['service_type' => 'managed']);

        $ok = $this->serviceReturning($this->stripeSession($customer, $user))
            ->confirm($user, $customer, 'cs_x');

        $this->assertTrue($ok);
        $customer->refresh();
        $this->assertSame('setup_only', $customer->service_type);
        $this->assertNotNull($customer->setup_fee_paid_at);
    }

    public function test_setup_fee_confirm_rejects_an_unpaid_session(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_ok']);
        $customer = Customer::factory()->create();

        $ok = $this->serviceReturning($this->stripeSession($customer, $user, ['payment_status' => 'unpaid']))
            ->confirm($user, $customer, 'cs_x');

        $this->assertFalse($ok);
        $this->assertNull($customer->fresh()->setup_fee_paid_at);
    }

    public function test_setup_fee_confirm_rejects_a_session_for_another_customer(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_ok']);
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();

        $ok = $this->serviceReturning($this->stripeSession($other, $user))
            ->confirm($user, $customer, 'cs_x');

        $this->assertFalse($ok);
        $this->assertNull($customer->fresh()->setup_fee_paid_at);
    }

    public function test_setup_fee_confirm_rejects_a_session_owned_by_a_different_stripe_customer(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_ok']);
        $customer = Customer::factory()->create();

        $ok = $this->serviceReturning($this->stripeSession($customer, $user, ['customer' => 'cus_somebody_else']))
            ->confirm($user, $customer, 'cs_x');

        $this->assertFalse($ok);
    }

    public function test_revocation_is_only_read_from_explicit_permission_errors(): void
    {
        $this->assertFalse(VerifyAccountAccess::classifyFailure('The caller does not have permission: USER_PERMISSION_DENIED.'));
        $this->assertFalse(VerifyAccountAccess::classifyFailure('PERMISSION_DENIED: request not authorised'));
        $this->assertFalse(VerifyAccountAccess::classifyFailure('NOT_ADS_USER'));

        // Everything else is inconclusive — quota, transport, suspension.
        $this->assertNull(VerifyAccountAccess::classifyFailure('RESOURCE_EXHAUSTED: Quota exceeded'));
        $this->assertNull(VerifyAccountAccess::classifyFailure('DEADLINE_EXCEEDED'));
        $this->assertNull(VerifyAccountAccess::classifyFailure('CUSTOMER_NOT_ENABLED'));
    }

    public function test_the_agents_eligibility_query_excludes_the_untouchables(): void
    {
        $eligible = Campaign::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'primary_status' => 'ELIGIBLE',
            'google_ads_campaign_id' => '111',
        ]);
        $setupOnly = Campaign::factory()->create([
            'customer_id' => Customer::factory()->create(['service_type' => 'setup_only'])->id,
            'primary_status' => 'ELIGIBLE',
            'google_ads_campaign_id' => '222',
        ]);
        $revoked = Campaign::factory()->create([
            'customer_id' => Customer::factory()->create(['google_ads_link_status' => 'revoked'])->id,
            'primary_status' => 'ELIGIBLE',
            'google_ads_campaign_id' => '333',
        ]);
        $undeployed = Campaign::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'primary_status' => 'ELIGIBLE',
            'google_ads_campaign_id' => null,
        ]);

        $ids = AutomatedCampaignMaintenance::eligibleCampaigns()->pluck('campaigns.id');

        $this->assertTrue($ids->contains($eligible->id));
        $this->assertFalse($ids->contains($setupOnly->id));
        $this->assertFalse($ids->contains($revoked->id));
        $this->assertFalse($ids->contains($undeployed->id));
    }

    public function test_a_revoked_link_blocks_deployment(): void
    {
        $user = User::factory()->create(['subscription_status' => 'active']);
        $customer = Customer::factory()->create([
            'google_ads_link_status' => 'revoked',
            'google_ads_customer_id' => '4445556667',
        ]);
        $customer->users()->attach($user->id, ['role' => 'owner']);
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

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->post('/deployment/deploy', ['campaign_id' => $campaign->id]);

        // Whatever the response shape, the campaign must not have moved.
        $this->assertSame(\App\Enums\CampaignStatus::Draft, $campaign->fresh()->status);
    }

    public function test_early_exit_needs_deployed_work_to_protect(): void
    {
        $customer = Customer::factory()->create([
            'google_ads_link_status' => 'active',
            'service_type' => 'managed',
        ]);
        // A campaign exists but nothing was ever deployed to their account.
        Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => null]);

        $this->assertFalse((new EarlyExitFeeService)->applies($customer));
    }

    public function test_the_engagement_emails_render_on_brand(): void
    {
        $customer = Customer::factory()->create([
            'tenant_key' => 'realpropertyads',
            'google_ads_customer_id' => '1231231234',
        ]);

        $receipt = (new \App\Mail\SetupFeeReceived($customer, 'Josh'))->render();
        $this->assertStringContainsString('Real Property Ads', $receipt);
        $this->assertStringContainsString('Everything arrives paused', $receipt);

        $handover = (new \App\Mail\HandoverComplete($customer))->render();
        $this->assertStringContainsString('1231231234', $handover);
        $this->assertStringContainsString('Add your billing', $handover);

        $lost = (new \App\Mail\LinkAccessLost($customer))->render();
        $this->assertStringContainsString('what stops happening', $lost);
        $this->assertStringContainsString('Real Property Ads', $lost);
    }
}
