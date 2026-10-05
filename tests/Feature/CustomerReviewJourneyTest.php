<?php

namespace Tests\Feature;

use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\CustomerPage;
use App\Models\ImageCollateral;
use App\Models\KnowledgeBase;
use App\Models\Persona;
use App\Models\Product;
use App\Models\ProductFeed;
use App\Models\Strategy;
use App\Models\User;
use App\Services\BrandGuidelineExtractorService;
use App\Services\Brands\BrandProfileReview;
use App\Services\Campaigns\PersonaSelection;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class CustomerReviewJourneyTest extends TestCase
{
    use DatabaseTransactions;

    private function owner(array $attributes = []): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create($attributes + ['website' => null]);
        $customer->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id]);

        return [$user, $customer];
    }

    private function brand(Customer $customer): BrandGuideline
    {
        return BrandGuideline::create(array_replace(array_fill_keys(BrandProfileReview::FIELDS, []), ['customer_id' => $customer->id, 'brand_voice' => ['primary_tone' => 'Clear', 'description' => 'Practical'], 'tone_attributes' => ['Direct'], 'user_verified' => true, 'approved_version' => 1, 'extracted_at' => now()]));
    }

    public function test_reanalysis_preserves_manual_corrections_and_requires_versioned_approval(): void
    {
        [, $customer] = $this->owner();
        $brand = $this->brand($customer);
        $review = app(BrandProfileReview::class);
        $draft = $review->saveDraft($brand, ['brand_voice' => ['primary_tone' => 'Warm', 'description' => 'Practical']], 1);
        $review->approve($draft, $draft->profile_version);
        $proposal = $review->propose($draft, ['brand_voice' => ['primary_tone' => 'Corporate', 'description' => 'New description'], 'tone_attributes' => ['Friendly']], []);
        $this->assertSame('Warm', $proposal->brand_voice['primary_tone']);
        $this->assertSame('Warm', $proposal->proposed_profile['brand_voice']['primary_tone']);
        $this->assertSame('New description', $proposal->proposed_profile['brand_voice']['description']);
        $this->assertFalse($proposal->user_verified);
        $this->assertNull($proposal->approved_version);
        $this->assertSame('brand_voice.primary_tone', $proposal->source_suggestions[0]['path']);
        $this->post(route('brand-guidelines.verify', $brand), ['profile_version' => 1])->assertSessionHasErrors('profile_version');
        $this->assertFalse($brand->fresh()->user_verified);
        $this->post(route('brand-guidelines.verify', $brand), ['profile_version' => $proposal->profile_version])->assertSessionHasNoErrors();
        $this->assertSame('New description', $brand->fresh()->brand_voice['description']);
    }

    public function test_owner_can_explicitly_accept_a_source_suggestion_without_automatic_approval(): void
    {
        [, $customer] = $this->owner();
        $brand = $this->brand($customer);
        $brand->update(['manual_overrides' => ['brand_voice.primary_tone' => 'Clear']]);
        $draft = app(BrandProfileReview::class)->propose($brand, ['brand_voice' => ['primary_tone' => 'Warm', 'description' => 'Practical']], []);
        $this->post(route('brand-guidelines.suggestions', $brand), ['paths' => ['brand_voice.primary_tone'], 'profile_version' => $draft->profile_version])->assertSessionHasNoErrors();
        $saved = $brand->fresh();
        $this->assertSame('Clear', $saved->brand_voice['primary_tone']);
        $this->assertSame('Warm', $saved->proposed_profile['brand_voice']['primary_tone']);
        $this->assertFalse($saved->user_verified);
        $this->assertSame([], $saved->manual_overrides);
    }

    public function test_uploaded_business_brief_informs_brand_and_excluded_mirror_cannot_return(): void
    {
        [$user, $customer] = $this->owner();
        $brief = KnowledgeBase::create(['customer_id' => $customer->id, 'user_id' => $user->id, 'source_type' => 'pdf', 'url' => 'file://brand-brief', 'title' => 'Brand brief', 'content' => 'We repair sailboats. BRIEF_EVIDENCE']);
        KnowledgeBase::create(['customer_id' => $customer->id, 'user_id' => $user->id, 'source_type' => 'url', 'url' => 'https://previous.example/offers', 'content' => 'EXCLUDED_EVIDENCE', 'excluded_at' => now()]);
        CustomerPage::create(['customer_id' => $customer->id, 'url' => 'https://previous.example/offers', 'title' => 'Previous', 'content' => 'EXCLUDED_MIRROR']);
        $profile = array_fill_keys(['brand_voice', 'tone_attributes', 'color_palette', 'typography', 'visual_style', 'messaging_themes', 'unique_selling_propositions', 'target_audience', 'brand_personality'], ['Known']);
        /** @var GeminiService&\Mockery\MockInterface $gemini */
        $gemini = Mockery::mock(GeminiService::class);
        /** @var \Mockery\Expectation $expectation */
        $expectation = $gemini->shouldReceive('generateContent');
        $expectation->once()->withArgs(function ($model, $prompt) {
            $this->assertStringContainsString('BRIEF_EVIDENCE', $prompt);
            $this->assertStringNotContainsString('EXCLUDED_EVIDENCE', $prompt);
            $this->assertStringNotContainsString('EXCLUDED_MIRROR', $prompt);

            return true;
        })->andReturn(['text' => json_encode($profile)]);
        $brand = (new BrandGuidelineExtractorService($gemini))->extractGuidelines($customer);
        $this->assertNotNull($brand);
        $this->assertSame($brief->id, $brand->source_snapshot[0]['id']);
        $this->assertFalse($brand->user_verified);
    }

    public function test_revised_manual_brief_keeps_prior_passages_and_cannot_collide_with_homepage(): void
    {
        [$user, $customer] = $this->owner(['website' => 'https://old.example']);
        $brand = $this->brand($customer);
        $original = trim(str_repeat('Original business offer. ', 20));
        $revised = trim(str_repeat('Revised service and audience. ', 20));
        $brief = KnowledgeBase::create(['customer_id' => $customer->id, 'user_id' => $user->id, 'source_type' => 'text', 'original_filename' => 'onboarding-business-brief.txt', 'url' => $customer->website, 'content' => $original]);
        app(\App\Services\KnowledgeBase\KnowledgeBaseIndexer::class)->prepare($brief, $original, 'Original brief');
        $this->post(route('quick-start.business-brief'), ['business_name' => 'Correct business', 'business_description' => $revised])->assertSessionHasNoErrors()->assertRedirect();
        $brief->refresh();
        $this->assertSame('note:onboarding-'.$customer->id, $brief->url);
        $this->assertSame(2, $brief->source_version);
        $this->assertSame($revised, $brief->content);
        $this->assertSame($original, $brief->chunks()->where('source_version', 1)->first()->content);
        $this->assertSame($revised, $brief->chunks()->where('source_version', 2)->first()->content);
        $this->assertFalse($brand->fresh()->user_verified);
        $this->assertSame(2, $brand->fresh()->profile_version);
        $this->assertSame(1, KnowledgeBase::where('customer_id', $customer->id)->count());
    }

    public function test_source_freshness_tracks_content_and_inclusion_without_reextracting_for_index_status(): void
    {
        [$user, $customer] = $this->owner();
        $source = KnowledgeBase::create(['customer_id' => $customer->id, 'user_id' => $user->id, 'source_type' => 'text', 'url' => 'text://owner-brief', 'content' => 'Original offer']);
        $first = BrandGuidelineExtractorService::sourceFingerprint($customer);
        $source->update(['processing_status' => 'ready', 'indexed_at' => now()]);
        $this->assertSame($first, BrandGuidelineExtractorService::sourceFingerprint($customer));
        $source->update(['content' => 'Changed offer']);
        $updated = BrandGuidelineExtractorService::sourceFingerprint($customer);
        $this->assertNotSame($first, $updated);
        $source->update(['excluded_at' => now()]);
        $this->assertNotSame($updated, BrandGuidelineExtractorService::sourceFingerprint($customer));
    }

    public function test_redundant_source_refresh_keeps_approval_but_explicit_reanalysis_runs(): void
    {
        [$user, $customer] = $this->owner();
        KnowledgeBase::create(['customer_id' => $customer->id, 'user_id' => $user->id, 'source_type' => 'text', 'url' => 'text://refresh-brief', 'content' => 'Owner offer']);
        $brand = $this->brand($customer);
        $brand->update(['source_fingerprint' => BrandGuidelineExtractorService::sourceFingerprint($customer)]);
        /** @var BrandGuidelineExtractorService&\Mockery\MockInterface $extractor */
        $extractor = Mockery::mock(BrandGuidelineExtractorService::class);
        (new \App\Jobs\ExtractBrandGuidelines($customer, force: true, sourceRefresh: true))->handle($extractor);
        $this->assertTrue($brand->fresh()->user_verified);
        $this->assertSame(1, $brand->fresh()->profile_version);
        /** @var \Mockery\Expectation $expectation */
        $expectation = $extractor->shouldReceive('extractGuidelines');
        $expectation->once()->with($customer)->andReturn(null);
        (new \App\Jobs\ExtractBrandGuidelines($customer, force: true))->handle($extractor);
    }

    public function test_website_change_excludes_previous_pages_keeps_brief_and_invalidates_brand(): void
    {
        [$user, $customer] = $this->owner(['website' => 'https://old.example']);
        $brand = $this->brand($customer);
        $old = KnowledgeBase::create(['customer_id' => $customer->id, 'user_id' => $user->id, 'source_type' => 'url', 'url' => 'https://old.example/about', 'content' => 'Old offer']);
        $upload = KnowledgeBase::create(['customer_id' => $customer->id, 'user_id' => $user->id, 'source_type' => 'pdf', 'url' => 'file://owner-brief', 'content' => 'Owner brief']);
        $response = $this->put(route('customers.update', $customer), ['name' => $customer->name, 'website' => 'https://8.8.8.8'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNotNull($old->fresh()->excluded_at);
        $this->assertNull($upload->fresh()->excluded_at);
        $this->assertFalse($brand->fresh()->user_verified);
        $this->assertSame(2, $brand->fresh()->profile_version);
        $this->assertStringContainsString(route('quick-start.scanning'), $response->headers->get('Location'));
    }

    public function test_all_sizes_save_together_and_foreign_asset_aborts_every_change(): void
    {
        [, $customer] = $this->owner();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $images = collect(['square', 'landscape', 'portrait'])->map(fn ($format) => ImageCollateral::create(['campaign_id' => $campaign->id, 'concept_key' => 'test-concept', 'format' => $format, 'platform' => 'Google Ads', 'cloudfront_url' => 'https://assets.example/'.$format.'.png', 's3_path' => $format.'.png', 'should_deploy' => false]));
        $url = route('campaigns.collateral-approval.update', $campaign);
        $this->putJson($url, ['type' => 'image', 'ids' => $images->pluck('id')->all(), 'field' => 'should_deploy', 'value' => true])->assertOk()->assertJsonCount(3, 'rows');
        foreach ($images as $image) {
            $this->assertTrue($image->fresh()->should_deploy);
        }
        $other = Campaign::factory()->create();
        $foreign = ImageCollateral::create(['campaign_id' => $other->id, 'platform' => 'Google Ads', 'cloudfront_url' => 'https://assets.example/foreign.png', 's3_path' => 'foreign.png', 'should_deploy' => false]);
        $this->putJson($url, ['type' => 'image', 'ids' => [$images[0]->id, $foreign->id], 'field' => 'should_deploy', 'value' => false])->assertNotFound();
        $this->assertTrue($images[0]->fresh()->should_deploy);
        $this->assertFalse($foreign->fresh()->should_deploy);
    }

    public function test_catalogue_has_search_pagination_and_scoped_feed_recovery(): void
    {
        [, $customer] = $this->owner();
        $feed = ProductFeed::factory()->create(['customer_id' => $customer->id, 'status' => 'pending']);
        Product::factory()->count(51)->create(['customer_id' => $customer->id, 'product_feed_id' => $feed->id, 'title' => 'Sailboat repair kit']);
        $this->get(route('products.list', ['feed' => $feed->id, 'search' => 'Sailboat']))->assertInertia(fn (Assert $page) => $page
            ->component('Products/List')->has('products', 50)->where('pagination.total', 51)->where('search', 'Sailboat')->where('feed', $feed->id)->where('pagination.next', fn ($url) => str_contains($url, 'page=2')));
        $this->getJson(route('products.index'))->assertOk()->assertJsonPath('working', true);
        $this->put(route('products.feeds.update', $feed), ['feed_name' => 'Repaired source', 'merchant_id' => '12345', 'sync_frequency' => 'weekly'])->assertSessionHasNoErrors();
        $this->assertSame('Repaired source', $feed->fresh()->feed_name);
        $foreign = ProductFeed::factory()->create();
        $this->get(route('products.list', ['feed' => $foreign->id]))->assertNotFound();
    }

    public function test_ad_copy_approval_is_saved_under_its_campaign_policy(): void
    {
        [, $customer] = $this->owner();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id]);
        $copy = \App\Models\AdCopy::create(['strategy_id' => $strategy->id, 'platform' => $strategy->platform, 'headlines' => ['Headline'], 'descriptions' => ['Description'], 'should_deploy' => false]);
        $this->putJson(route('campaigns.collateral-approval.update', $campaign), ['type' => 'ad_copy', 'ids' => [$copy->id], 'field' => 'should_deploy', 'value' => true])->assertOk();
        $this->assertTrue($copy->fresh()->should_deploy);
    }

    public function test_explicit_persona_choice_replaces_its_scope_and_rejects_foreign_campaign(): void
    {
        [, $customer] = $this->owner();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $first = Persona::create(['customer_id' => $customer->id, 'campaign_id' => $campaign->id, 'name' => 'First', 'description' => 'First audience', 'is_active' => true]);
        $second = Persona::create(['customer_id' => $customer->id, 'campaign_id' => $campaign->id, 'name' => 'Second', 'description' => 'Second audience', 'is_active' => true]);
        $this->put(route('personas.update', $first), ['is_active' => true, 'use_for_generation' => true])->assertSessionHasNoErrors();
        $this->assertFalse($second->fresh()->is_active);
        $this->assertSame($first->id, app(PersonaSelection::class)->forCampaign($campaign)->id);
        $foreign = Campaign::factory()->create();
        $this->put(route('personas.update', $first), ['campaign_id' => $foreign->id])->assertNotFound();
        $this->assertSame($campaign->id, $first->fresh()->campaign_id);
    }
}
