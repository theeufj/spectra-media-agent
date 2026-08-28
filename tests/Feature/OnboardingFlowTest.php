<?php

namespace Tests\Feature;

use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The onboarding spine: QuickStart → narrated holding screen → brand
 * guidelines review → sign-off → first campaign. The sign-off is not
 * decorative — the first launch is gated on it, because every ad is written
 * from the guidelines and nobody should spend money on copy built from a
 * profile they never looked at.
 */
class OnboardingFlowTest extends TestCase
{
    use DatabaseTransactions;

    private function userWithCustomer(array $customerAttrs = []): array
    {
        // subscription_status active: the deploy routes sit behind the
        // `subscribed` middleware, and these tests are about the gate AFTER it.
        $user = User::factory()->create(['subscription_status' => 'active']);
        $customer = Customer::factory()->create($customerAttrs);
        $customer->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $customer];
    }

    private function guideline(Customer $customer, bool $verified = false): BrandGuideline
    {
        return BrandGuideline::create([
            'customer_id' => $customer->id,
            'brand_voice' => ['primary_tone' => 'direct'],
            'tone_attributes' => ['direct'],
            'target_audience' => ['primary' => 'Homeowners'],
            'competitor_differentiation' => ['End to end.'],
            'messaging_themes' => ['Care'],
            'unique_selling_propositions' => ['No subcontractors'],
            'do_not_use' => ['Jargon'],
            'color_palette' => ['primary_colors' => ['#111111']],
            'typography' => ['heading_style' => 'sans'],
            'visual_style' => ['overall_aesthetic' => 'modern'],
            'writing_patterns' => ['sentence_length' => 'varied'],
            'brand_personality' => ['archetype' => 'Everyman'],
            'service_lines' => [['name' => 'Service']],
            'extraction_quality_score' => 90,
            'extracted_at' => now(),
            'user_verified' => $verified,
        ]);
    }

    public function test_the_holding_screen_renders_while_the_scan_runs(): void
    {
        [$user, $customer] = $this->userWithCustomer();

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get(route('quick-start.scanning'))
            ->assertSuccessful();
    }

    public function test_the_holding_screen_skips_ahead_once_the_guideline_exists(): void
    {
        [$user, $customer] = $this->userWithCustomer();
        $this->guideline($customer);

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get(route('quick-start.scanning'))
            ->assertRedirect(route('brand-guidelines.index', ['review' => 1], absolute: false));
    }

    public function test_sign_off_with_continue_lands_in_the_campaign_wizard(): void
    {
        [$user, $customer] = $this->userWithCustomer();
        $guideline = $this->guideline($customer);

        $response = $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->post(route('brand-guidelines.verify', $guideline), ['continue' => true]);

        $response->assertRedirect(route('campaigns.create', absolute: false));
        $this->assertTrue($guideline->fresh()->user_verified);
    }

    public function test_sign_off_prefers_the_auto_generated_first_campaign(): void
    {
        [$user, $customer] = $this->userWithCustomer();
        $guideline = $this->guideline($customer);
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'auto_generated_at' => now(),
        ]);
        $campaign->strategies()->create([
            'platform' => 'Google Ads',
            'ad_copy_strategy' => 'Direct response',
            'imagery_strategy' => 'Lifestyle photography',
            'video_strategy' => 'None',
        ]);

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->post(route('brand-guidelines.verify', $guideline), ['continue' => true])
            ->assertRedirect(route('campaigns.show', $campaign, absolute: false));
    }

    public function test_first_launch_is_blocked_until_the_brand_profile_is_confirmed(): void
    {
        [$user, $customer] = $this->userWithCustomer();
        $this->guideline($customer, verified: false);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'draft']);

        $response = $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->post('/deployment/deploy', ['campaign_id' => $campaign->id]);

        $response->assertRedirect(route('brand-guidelines.index', ['review' => 1], absolute: false));
        $this->assertSame(\App\Enums\CampaignStatus::Draft, $campaign->fresh()->status);
    }

    public function test_a_confirmed_profile_clears_the_launch_gate(): void
    {
        [$user, $customer] = $this->userWithCustomer();
        $this->guideline($customer, verified: true);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'draft']);

        $response = $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->post('/deployment/deploy', ['campaign_id' => $campaign->id]);

        // The next gate (subscription) may bounce it elsewhere — the point is
        // it moved PAST the brand-profile gate.
        $this->assertNotSame(
            route('brand-guidelines.index', ['review' => 1], absolute: false),
            $response->headers->get('Location') ? parse_url($response->headers->get('Location'), PHP_URL_PATH).'?'.parse_url($response->headers->get('Location'), PHP_URL_QUERY) : null
        );
    }

    public function test_setup_checklist_includes_the_tracking_snippet_step(): void
    {
        [$user, $customer] = $this->userWithCustomer(['gtm_installed' => false, 'gtm_container_id' => 'GTM-TEST123']);

        $response = $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->getJson('/api/setup-progress');

        $step = collect($response->json('steps'))->firstWhere('key', 'conversion_tracking');
        $this->assertNotNull($step);
        $this->assertFalse($step['completed']);
        $this->assertStringContainsString('/gtm/setup', $step['action_url']);

        $customer->update(['gtm_installed' => true]);
        $step = collect($this->getJson('/api/setup-progress')->json('steps'))->firstWhere('key', 'conversion_tracking');
        $this->assertTrue($step['completed']);
    }

    public function test_campaign_review_surfaces_the_tracking_snippet_call_to_action(): void
    {
        [$user, $customer] = $this->userWithCustomer(['gtm_installed' => false, 'gtm_container_id' => 'GTM-TEST123']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        $response = $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get(route('campaigns.show', $campaign));

        $response->assertSuccessful();
        $tracking = $response->viewData('page')['props']['conversionTracking'] ?? null;
        $this->assertNotNull($tracking);
        $this->assertSame('GTM-TEST123', $tracking['container_id']);
        $this->assertFalse($tracking['installed']);
    }

    public function test_a_returning_customer_who_has_launched_before_is_not_re_gated(): void
    {
        [$user, $customer] = $this->userWithCustomer();
        $this->guideline($customer, verified: false);
        Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'active']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'draft']);

        $response = $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->post('/deployment/deploy', ['campaign_id' => $campaign->id]);

        $location = $response->headers->get('Location');
        $this->assertStringNotContainsString('brand-guidelines', (string) $location);
    }
}
