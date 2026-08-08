<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\BrandGuidelineExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BrandGuidelineExtractorTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_extracts_brand_guidelines_from_cloudflare_homepage()
    {
        echo "\n🔍 Starting Brand Guideline Extraction Test\n";

        // Skip if we don't have Gemini API key configured
        if (empty(config('services.gemini.api_key'))) {
            $this->markTestSkipped('Gemini API key not configured');
        }

        echo "✓ Gemini API key configured\n";

        // 1. Setup Customer with Cloudflare website
        echo "📝 Creating test customer for Cloudflare...\n";
        $customer = Customer::factory()->create([
            'website' => 'https://www.cloudflare.com',
            'name' => 'Test Cloudflare',
        ]);

        $user = User::factory()->create();
        $customer->users()->attach($user);
        echo "✓ Customer and user created (Customer ID: {$customer->id})\n";

        // 2. Add some sample knowledge base content (simulating crawled pages)
        echo "📚 Adding knowledge base entries...\n";
        KnowledgeBase::create([
            'user_id' => $user->id,
            'content' => 'Cloudflare is a global network designed to make everything you connect to the Internet secure, private, fast, and reliable. Cloudflare helps build a better Internet with performance, security, and reliability services.',
            'url' => 'https://www.cloudflare.com',
        ]);

        KnowledgeBase::create([
            'user_id' => $user->id,
            'content' => 'Cloudflare provides CDN services, DDoS protection, Internet security, and distributed DNS services. Our mission is to help build a better Internet.',
            'url' => 'https://www.cloudflare.com/about',
        ]);
        echo "✓ Knowledge base entries created\n";

        // 3. Run the actual service (this will take a screenshot and call Gemini Vision AI)
        echo "📸 Taking screenshot of Cloudflare homepage...\n";
        echo "⏳ This may take 30-60 seconds...\n";
        $service = app(BrandGuidelineExtractorService::class);

        echo "🤖 Calling Gemini Vision AI for brand analysis...\n";
        $result = $service->extractGuidelines($customer);
        echo "✓ Brand guidelines extracted!\n";
        echo "✓ Brand guidelines extracted!\n";

        // 4. Assertions
        echo "🧪 Running assertions...\n";
        $this->assertNotNull($result, 'Brand guidelines should be extracted');
        $this->assertEquals($customer->id, $result->customer_id);
        echo "✓ Basic assertions passed\n";

        // Check that we got visual analysis data
        echo "🎨 Checking visual analysis data...\n";
        $this->assertNotEmpty($result->color_palette, 'Color palette should be extracted');
        $this->assertNotEmpty($result->typography, 'Typography should be extracted');
        $this->assertNotEmpty($result->visual_style, 'Visual style should be extracted');
        echo "✓ Visual analysis data present\n";

        // Check brand voice
        echo "📢 Checking brand voice data...\n";
        $this->assertNotEmpty($result->brand_voice, 'Brand voice should be extracted');
        $this->assertNotEmpty($result->tone_attributes, 'Tone attributes should be extracted');
        echo "✓ Brand voice data present\n";

        // Check quality score
        echo "📊 Checking quality score...\n";
        $this->assertGreaterThan(0, $result->extraction_quality_score ?? 0, 'Should have quality score');
        echo "✓ Quality score: {$result->extraction_quality_score}\n";
        echo "✓ Quality score: {$result->extraction_quality_score}\n";

        // Log the results for review
        Log::info('Extracted Brand Guidelines for Cloudflare:', [
            'brand_voice' => $result->brand_voice,
            'colors' => $result->color_palette,
            'typography' => $result->typography,
            'quality_score' => $result->extraction_quality_score,
        ]);

        // Output for manual inspection
        echo "\n📋 EXTRACTED BRAND GUIDELINES:\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        dump([
            'Brand Voice' => $result->brand_voice,
            'Colors' => $result->color_palette,
            'Typography' => $result->typography,
            'Visual Style' => $result->visual_style,
            'Quality Score' => $result->extraction_quality_score,
        ]);
        echo "\n✅ Test completed successfully!\n";
    }
}
