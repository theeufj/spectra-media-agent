<?php

namespace Tests\Feature;

use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Strategy;
use App\Services\AdminMonitorService;
use App\Services\Campaigns\AdvertisingEvidence;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\CreateCalloutAsset;
use App\Services\GoogleAds\CommonServices\CreatePriceAsset;
use App\Services\GoogleAds\CommonServices\CreatePromotionAsset;
use App\Services\GoogleAds\CommonServices\CreateSitelinkAsset;
use App\Services\GoogleAds\CommonServices\LinkCampaignAsset;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

class AdvertisingEvidenceTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): Customer
    {
        $customer = Customer::factory()->create(['website' => 'https://example.com', 'currency_code' => 'AUD']);
        $customer->pages()->create(['url' => 'https://example.com', 'title' => 'AI Google Ads Management', 'content' => 'Google Ads management for small businesses.']);
        $customer->pages()->create(['url' => 'https://example.com/pricing', 'title' => 'Pricing', 'page_type' => 'pricing', 'content' => 'Starter US$149 per month. Growth US$249 per month.']);

        return $customer;
    }

    public function test_invented_industry_prices_and_discounts_cannot_reach_google(): void
    {
        $customer = $this->customer();
        $this->assertNull((new CreatePriceAsset($customer))('123', 10, 2, array_fill(0, 3,
            ['header' => 'Invented', 'price_micros' => 499000000, 'currency_code' => 'AUD', 'final_url' => $customer->website])));
        $this->assertNull((new CreatePromotionAsset($customer))('123', '20% Off First Month', ['percent_off' => 200000]));
    }

    public function test_catalogue_prices_keep_their_currency_and_ignore_model_amounts(): void
    {
        $customer = $this->customer();
        $product = Product::factory()->create(['customer_id' => $customer->id, 'title' => 'Useful Product', 'price' => '149.99', 'currency_code' => 'USD']);
        $offer = app(AdvertisingEvidence::class)->priceOffering($customer, ['source_product_id' => $product->id,
            'price_micros' => 999000000, 'currency_code' => 'AUD', 'unit' => 5, 'final_url' => 'https://wrong.example']);
        $this->assertSame(149990000, $offer['price_micros']);
        $this->assertSame('USD', $offer['currency_code']);
        $this->assertSame(0, $offer['unit']);
        $this->assertSame($product->link, $offer['final_url']);
        $this->assertNull((new CreatePriceAsset($customer))('123', 6, 2,
            array_fill(0, 3, ['source_product_id' => $product->id])), 'One product repeated three times is not three offers.');
    }

    public function test_known_product_links_preserve_query_parameters_and_anchors(): void
    {
        $customer = $this->customer();
        $url = 'https://example.com/product?id=42#details';
        $customer->pages()->create(['url' => $url, 'title' => 'Useful Product', 'content' => 'Details of this product.']);
        $links = app(AdvertisingEvidence::class)->sitelinks($customer, [['text' => 'Useful Product', 'url' => $url]]);
        $this->assertSame($url, $links[0]['url']);
    }

    public function test_only_real_customer_owned_sales_can_be_promoted(): void
    {
        $customer = $this->customer();
        $product = Product::factory()->create(['customer_id' => $customer->id, 'title' => 'Useful Product', 'price' => '149.99', 'sale_price' => '129.99', 'currency_code' => 'USD']);
        $evidence = app(AdvertisingEvidence::class);
        $offer = $evidence->promotion($customer, ['source_product_id' => $product->id, 'percent_off' => 900000, 'promotion_code' => 'INVENTED']);
        $this->assertSame(['amount_micros' => 20000000, 'currency_code' => 'USD'], $offer['money_amount_off']);
        $this->assertArrayNotHasKey('percent_off', $offer);
        $this->assertArrayNotHasKey('promotion_code', $offer);
        $this->assertNull($evidence->promotion(Customer::factory()->create(), ['source_product_id' => $product->id]));
        $product->update(['sale_price' => null]);
        $this->assertNull($evidence->promotion($customer, ['source_product_id' => $product->id]));
    }

    public function test_sitelinks_keep_real_strategy_destinations_and_do_not_fabricate_pages_or_claims(): void
    {
        $customer = $this->customer();
        $links = app(AdvertisingEvidence::class)->sitelinks($customer, [
            ['text' => 'View Pricing', 'url' => 'https://example.com/pricing', 'description1' => 'Save 90% today'],
            ['text' => 'Case Studies', 'url' => 'https://example.com'],
            ['text' => 'Start Free Trial', 'url' => 'https://example.com/trial'],
            ['text' => 'Our Pricing', 'url' => 'https://other-customer.example/pricing'],
        ]);
        $this->assertCount(1, $links);
        $this->assertSame('View Pricing', $links[0]['text']);
        $this->assertSame('https://example.com/pricing', $links[0]['url']);
        $this->assertSame('', $links[0]['desc1']);
        $this->assertSame('Google Ads Negative', app(AdvertisingEvidence::class)->shortText('Google Ads Negative Keywords Explained', 25));
    }

    public function test_copy_review_receives_keywords_offer_and_price_evidence_and_blocks_high_scoring_false_claims(): void
    {
        $customer = $this->customer();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'product_focus' => 'Google Ads management',
            'keywords' => [['text' => 'google ads management', 'match_type' => 'EXACT']]]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id]);
        $ad = new AdCopy(['strategy_id' => $strategy->id, 'platform' => 'google',
            'headlines' => ['Google Ads Management', 'Agency Results At $149', '20% Off Your First Month', 'Grow Your Business Today', 'Start Your Campaign Today'],
            'descriptions' => ['Manage your Google Ads today.', 'Save 20% on your first month.', 'Your campaigns managed daily.']]);
        $ai = Mockery::mock(GeminiService::class);
        $ai->shouldReceive('generateContent')->withArgs(function ($model, $prompt) {
            return str_contains($prompt, 'google ads management') && str_contains($prompt, 'US$149')
                && str_contains($prompt, 'factual_accuracy');
        })->andReturn(['text' => json_encode(['overall_score' => 95, 'feedback' => [], 'factual_accuracy' => false,
            'intent_relevance' => true, 'blocking_issues' => ['No first-month discount is offered.']])]);
        $this->app->instance(GeminiService::class, $ai);
        $review = app(AdminMonitorService::class)->reviewAdCopy($ad);
        $this->assertSame('needs_revision', $review['overall_status']);
        $this->assertFalse($review['programmatic_validation']['is_valid'], 'The lower-score fallback must not publish unsupported offers.');
    }

    public function test_deployment_consumes_canonical_strategy_extensions_instead_of_fallback_defaults(): void
    {
        $customer = $this->customer();
        $customer->phone = null;
        $strategy = new Strategy(['ad_extensions' => ['sitelinks' => [['text' => 'View Pricing', 'url' => 'https://example.com/pricing',
            'description1' => 'Starter US$149 per month.', 'description2' => '']], 'callouts' => []],
            'bidding_strategy' => ['sitelinks' => [['text' => 'Case Studies', 'url' => 'https://example.com']]]]);
        $links = Mockery::mock(CreateSitelinkAsset::class);
        $links->shouldReceive('__invoke')->withArgs(fn (...$args) => $args === ['123', 'View Pricing', 'Starter US$149 per month.', '', 'https://example.com/pricing'])
            ->andReturn('customers/123/assets/4');
        $callouts = Mockery::mock(CreateCalloutAsset::class);
        $callouts->shouldNotReceive('__invoke');
        $linker = Mockery::mock(LinkCampaignAsset::class);
        $linker->shouldReceive('__invoke')->andReturn('customers/123/campaignAssets/9~4~SITELINK');
        $this->app->bind(CreateSitelinkAsset::class, fn () => $links);
        $this->app->bind(CreateCalloutAsset::class, fn () => $callouts);
        $this->app->bind(LinkCampaignAsset::class, fn () => $linker);
        $ai = Mockery::mock(GeminiService::class);
        $ai->shouldNotReceive('generateContent');
        $this->app->instance(GeminiService::class, $ai);
        $builder = new class($customer, app(GeminiService::class)) extends \App\Services\Agents\Google\AdExtensionBuilder
        {
            protected function createLocationExtension(string $customerId, string $campaignResourceName, \App\Services\Agents\ExecutionResult $result): void {}
        };
        $result = \App\Services\Agents\ExecutionResult::success();
        $builder->createAndLinkAdExtensions('123', 'customers/123/campaigns/9', $strategy, $result);
        $this->assertSame('customers/123/assets/4', $result->platformIds['sitelink_asset']);
        $this->assertArrayNotHasKey('promotion_asset', $result->platformIds);
        $this->assertArrayNotHasKey('price_asset', $result->platformIds);
    }
}
