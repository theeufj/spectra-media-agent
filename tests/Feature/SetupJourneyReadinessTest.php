<?php

namespace Tests\Feature;

use App\Jobs\ExtractBrandGuidelines;
use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\Strategy;
use App\Models\User;
use App\Notifications\SiteScanFailed;
use App\Services\Onboarding\SetupJourney;
use App\Services\SetupFeeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SetupJourneyReadinessTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): Customer
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['service_type' => 'setup_only', 'setup_fee_paid_at' => null]);
        $customer->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id]);

        return $customer;
    }

    private function brand(Customer $customer): BrandGuideline
    {
        return BrandGuideline::create([
            'customer_id' => $customer->id, 'brand_voice' => [], 'tone_attributes' => [],
            'color_palette' => [], 'typography' => [], 'visual_style' => [], 'messaging_themes' => [],
            'unique_selling_propositions' => ['Listing-specific marketing'], 'target_audience' => ['primary' => 'Agents'],
            'brand_personality' => [], 'user_verified' => true, 'extracted_at' => now(),
        ]);
    }

    public function test_checkout_cannot_charge_before_confirmed_readable_business_information(): void
    {
        $customer = $this->customer();
        $this->mock(SetupFeeService::class)->shouldNotReceive('checkoutUrl');
        $this->post(route('setup-fee.checkout'))->assertRedirect();
        $this->brand($customer);
        $this->post(route('setup-fee.checkout'))->assertRedirect(route('quick-start.scanning', ['manual' => 1]));
        KnowledgeBase::create(['url' => $customer->website, 'customer_id' => $customer->id, 'user_id' => auth()->id(), 'content' => str_repeat('Supported business detail. ', 20)]);
        $this->assertTrue(app(SetupJourney::class)->checkoutReady($customer->fresh()));
    }

    public function test_inline_brief_is_saved_for_only_the_active_business_and_queues_extraction(): void
    {
        $customer = $this->customer();
        $other = Customer::factory()->create();
        $payload = ['business_name' => 'Listing Services', 'business_description' => str_repeat('We help estate agents market each property listing. ', 10), 'customer_id' => $other->id];
        $this->post(route('quick-start.business-brief'), $payload)->assertRedirect(route('quick-start.scanning'));
        $this->assertSame('Listing Services', $customer->fresh()->name);
        $this->assertSame(1, KnowledgeBase::where('customer_id', $customer->id)->where('source_type', 'text')->where('original_filename', 'onboarding-business-brief.txt')->whereNull('file_path')->count());
        $this->assertSame(0, KnowledgeBase::where('customer_id', $other->id)->count());
        $this->post(route('quick-start.business-brief'), $payload)->assertRedirect();
        Queue::assertPushed(ExtractBrandGuidelines::class, 1);
    }

    public function test_new_brief_supersedes_an_old_scan_failure_and_requires_fresh_confirmation(): void
    {
        $customer = $this->customer();
        $brand = $this->brand($customer);
        auth()->user()->notifications()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => SiteScanFailed::class, 'data' => ['customer_id' => $customer->id, 'reason' => 'Scan blocked'], 'created_at' => now()->subMinute()]);
        $this->travel(2)->seconds();
        $this->post(route('quick-start.business-brief'), ['business_name' => 'New Name', 'business_description' => str_repeat('Confirmed information about our business and buyers. ', 10)])->assertRedirect();
        $this->assertFalse($brand->fresh()->user_verified);
        $this->getJson(route('brand-guidelines.status'))->assertJsonPath('failed', false);
        $this->get(route('quick-start.scanning', ['after' => $brand->fresh()->updated_at->toIso8601String()]))->assertOk();
    }

    public function test_verification_and_handover_are_not_inferred_from_creation(): void
    {
        $customer = $this->customer();
        $customer->update(['setup_fee_paid_at' => now(), 'handover_at' => now()]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'paused', 'budget_confirmed_at' => now(), 'approved_daily_budget' => 50, 'total_budget' => 350]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'signed_off_at' => now(), 'deployment_status' => 'deployed']);
        $journey = app(SetupJourney::class)->forCustomer($customer);
        $this->assertFalse($journey['steps'][2]['completed']);
        $this->assertFalse($journey['steps'][3]['completed']);
        $this->assertStringContainsString('50.00 per day', $journey['checklist'][2]['detail']);
        $strategy->update(['deployment_status' => 'verified']);
        $journey = app(SetupJourney::class)->forCustomer($customer);
        $this->assertTrue($journey['steps'][3]['completed']);
        $this->assertFalse($journey['checklist'][4]['done']);
        $this->assertStringContainsString('acceptance is not confirmed', $journey['checklist'][3]['detail']);
    }

    public function test_an_old_paid_build_is_actionable_instead_of_spinning_forever(): void
    {
        $customer = $this->customer();
        $customer->update(['setup_fee_paid_at' => now()->subHours(2)]);
        $journey = app(SetupJourney::class)->forCustomer($customer);
        $this->assertFalse($journey['is_working']);
        $this->assertSame('failed', $journey['steps'][1]['status']);
    }

    public function test_inline_source_reaches_campaign_writing_without_waiting_for_an_embedding(): void
    {
        $customer = $this->customer();
        KnowledgeBase::create(['url' => $customer->website, 'customer_id' => $customer->id, 'user_id' => auth()->id(), 'source_type' => 'text', 'original_filename' => 'onboarding-business-brief.txt', 'file_path' => null, 'content' => str_repeat('Listing-specific advertising for estate agents. ', 10)]);
        $gemini = $this->mock(\App\Services\GeminiService::class);
        $gemini->shouldNotReceive('embedContent');
        $result = app(\App\Services\KnowledgeBase\KnowledgeBaseRetriever::class)->search($customer, 'What do they sell?');
        $this->assertCount(1, $result);
        $this->assertStringContainsString('Listing-specific advertising', $result[0]['excerpt']);
    }

    public function test_a_failed_creation_is_reported_at_the_review_step_after_approval(): void
    {
        $customer = $this->customer();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'signed_off_at' => now(), 'deployment_status' => 'failed']);
        $journey = app(SetupJourney::class)->forCustomer($customer);
        $this->assertSame('failed', $journey['steps'][2]['status']);
        $this->assertSame(route('campaigns.deployment-status', $campaign), $journey['steps'][2]['action_url']);
    }
}
