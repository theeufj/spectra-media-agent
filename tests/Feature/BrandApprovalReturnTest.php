<?php

namespace Tests\Feature;

use App\Jobs\DeployCampaign;
use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Brands\BrandProfileReview;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BrandApprovalReturnTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Customer, BrandGuideline, Campaign, Strategy} */
    private function reviewContext(): array
    {
        $user = User::factory()->create(['subscription_status' => 'active']);
        $customer = Customer::factory()->create(['website' => null, 'service_type' => 'managed']);
        $customer->users()->attach($user, ['role' => 'owner']);
        $brand = BrandGuideline::create(array_replace(array_fill_keys(BrandProfileReview::FIELDS, []), ['customer_id' => $customer->id, 'profile_version' => 2,
            'user_verified' => false, 'extracted_at' => now(), 'brand_voice' => ['primary_tone' => 'Direct'],
            'proposed_profile' => ['unique_selling_propositions' => ['US$999 one-time setup; ad spend separate.']],
            'manual_overrides' => ['brand_voice.primary_tone' => 'Direct']]));
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'auto_generated_at' => null]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'signed_off_at' => now(),
            'creative_review' => ['status' => 'passed']]);
        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id]);

        return [$user, $customer, $brand, $campaign, $strategy];
    }

    public function test_manual_campaign_launch_reviews_the_brand_then_returns_to_its_collateral_without_launching(): void
    {
        [, $customer, $brand, $campaign, $strategy] = $this->reviewContext();
        Campaign::factory()->create(['customer_id' => $customer->id, 'auto_generated_at' => now()]);
        $origin = ['return_campaign' => $campaign->uuid, 'return_strategy' => $strategy->uuid];
        $reviewUrl = route('brand-guidelines.index', ['review' => 1, ...$origin]);
        $this->post(route('deployment.deploy-platform'), ['campaign_id' => $campaign->id, 'strategy_id' => $strategy->id])
            ->assertRedirect($reviewUrl);
        $this->from($reviewUrl)->put(route('brand-guidelines.update', $brand), [
            'profile_version' => 2, 'unique_selling_propositions' => ['US$999 Google Ads setup. Advertising spend is separate.'],
        ])->assertSessionHasNoErrors()->assertRedirect($reviewUrl);
        $this->post(route('brand-guidelines.verify', $brand), ['continue' => true, 'profile_version' => 3, ...$origin])
            ->assertRedirect(route('campaigns.collateral.show', ['campaign' => $campaign, 'strategy' => $strategy]));

        $this->assertTrue($brand->fresh()->user_verified);
        $this->assertSame(3, $brand->fresh()->approved_version);
        $this->assertSame('Direct', $brand->fresh()->manual_overrides['brand_voice.primary_tone']);
        $this->assertSame(2, $customer->campaigns()->count());
        $this->assertNull($campaign->fresh()->google_ads_campaign_id);
        Queue::assertNotPushed(DeployCampaign::class);
    }

    public function test_all_platform_launch_returns_to_the_existing_campaign_review(): void
    {
        [, , $brand, $campaign] = $this->reviewContext();
        $this->post(route('deployment.deploy'), ['campaign_id' => $campaign->id])
            ->assertRedirect(route('brand-guidelines.index', ['review' => 1, 'return_campaign' => $campaign->uuid]));
        $this->post(route('brand-guidelines.verify', $brand), ['continue' => true, 'profile_version' => 2, 'return_campaign' => $campaign->uuid])
            ->assertRedirect(route('campaigns.show', $campaign));
        Queue::assertNotPushed(DeployCampaign::class);
    }

    public function test_return_campaign_must_belong_to_the_same_brand_customer_even_if_the_user_owns_both(): void
    {
        [$user, , $brand] = $this->reviewContext();
        $otherCustomer = Customer::factory()->create(['website' => null]);
        $otherCustomer->users()->attach($user, ['role' => 'owner']);
        \App\Models\Scopes\CustomerScope::flush();
        $foreign = Campaign::factory()->create(['customer_id' => $otherCustomer->id]);
        $this->post(route('brand-guidelines.verify', $brand), ['continue' => true, 'profile_version' => 2, 'return_campaign' => $foreign->uuid])
            ->assertNotFound();
        $this->assertFalse($brand->fresh()->user_verified);
    }

    public function test_return_strategy_must_belong_to_the_return_campaign(): void
    {
        [, $customer, $brand, $campaign] = $this->reviewContext();
        $otherCampaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $foreign = Strategy::factory()->create(['campaign_id' => $otherCampaign->id]);
        $this->post(route('brand-guidelines.verify', $brand), ['continue' => true, 'profile_version' => 2,
            'return_campaign' => $campaign->uuid, 'return_strategy' => $foreign->uuid])->assertNotFound();
        $this->assertFalse($brand->fresh()->user_verified);
    }

    public function test_external_return_values_cannot_redirect_or_approve_an_invalid_context(): void
    {
        [, , $brand, $campaign, $strategy] = $this->reviewContext();
        $this->postJson(route('brand-guidelines.verify', $brand), ['profile_version' => 2, 'return_campaign' => 'https://other.example/'])
            ->assertUnprocessable()->assertJsonValidationErrors('return_campaign');
        $this->assertFalse($brand->fresh()->user_verified);
        $this->post(route('brand-guidelines.verify', $brand), ['profile_version' => 2, 'continue' => true,
            'return_campaign' => $campaign->uuid, 'return_strategy' => $strategy->uuid, 'return_url' => 'https://other.example/'])
            ->assertRedirect(route('campaigns.collateral.show', ['campaign' => $campaign, 'strategy' => $strategy]));
    }

    public function test_a_strategy_without_signoff_returns_to_campaign_review_instead_of_collateral(): void
    {
        [, , $brand, $campaign, $strategy] = $this->reviewContext();
        $strategy->update(['signed_off_at' => null]);
        $this->post(route('brand-guidelines.verify', $brand), ['profile_version' => 2, 'continue' => true,
            'return_campaign' => $campaign->uuid, 'return_strategy' => $strategy->uuid])
            ->assertRedirect(route('campaigns.show', $campaign));
        $this->assertTrue($brand->fresh()->user_verified);
    }

    public function test_a_strategy_return_without_a_campaign_is_rejected_before_approval(): void
    {
        [, , $brand, , $strategy] = $this->reviewContext();
        $this->postJson(route('brand-guidelines.verify', $brand), ['profile_version' => 2, 'return_strategy' => $strategy->uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('return_campaign');
        $this->assertFalse($brand->fresh()->user_verified);
    }
}
