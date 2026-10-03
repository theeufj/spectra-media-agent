<?php

namespace Tests\Feature;

use App\Models\AttributionTouchpoint;
use App\Models\Campaign;
use App\Models\CrmIntegration;
use App\Models\Customer;
use App\Models\HarvestedAsset;
use App\Models\Product;
use App\Models\ProductFeed;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Smoke tests for the six features with zero production rows.
 *
 * None of them is broken by wiring — each has a real entry point — but none has
 * ever been exercised, so nobody knows whether they work. Given four fatal bugs
 * turned up on 2026-08-08 in exactly this kind of never-run code, "it has an
 * entry point" is not evidence of anything.
 *
 * These walk each feature's happy path far enough to prove the code executes and
 * writes what it claims to. They are deliberately shallow — the aim is to catch
 * the class of bug that only appears when something actually runs.
 */
class NeverExercisedFeaturesTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(array $attrs = []): Customer
    {
        return Customer::factory()->create($attrs);
    }

    /** Browser payload sent by the public pixel from the registered website. */
    private function browserEvent(Customer $customer, array $payload): array
    {
        $origin = 'https://example.com';

        return [
            'payload' => array_merge([
                'site_id' => $customer->uuid,
                'visitor_id' => '123e4567-e89b-42d3-a456-426614174000',
                'page_url' => $origin.'/landing',
            ], $payload),
            'headers' => ['Origin' => $origin],
        ];
    }

    // ── Attribution ──────────────────────────────────────────────────────────

    public function test_attribution_records_a_touchpoint(): void
    {
        $customer = $this->customer(['website' => 'https://example.com']);

        $req = $this->browserEvent($customer, [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'brand',
        ]);

        $response = $this->withHeaders($req['headers'])
            ->postJson('/api/tracking/touchpoint', $req['payload']);

        $response->assertSuccessful();

        $this->assertDatabaseHas('attribution_touchpoints', [
            'customer_id' => $customer->id,
            'visitor_id' => '123e4567-e89b-42d3-a456-426614174000',
            'utm_source' => 'google',
        ]);
    }

    public function test_attribution_rejects_an_unregistered_origin(): void
    {
        $customer = $this->customer(['website' => 'https://example.com']);

        $req = $this->browserEvent($customer, []);
        $this->withHeaders(['Origin' => 'https://attacker.example'])
            ->postJson('/api/tracking/touchpoint', $req['payload'])
            ->assertStatus(403);

        $this->assertSame(0, AttributionTouchpoint::where('customer_id', $customer->id)->count());
    }

    public function test_attribution_rejects_a_mismatched_page_url(): void
    {
        $customer = $this->customer(['website' => 'https://example.com']);
        $req = $this->browserEvent($customer, ['page_url' => 'https://attacker.example/landing']);

        $this->withHeaders($req['headers'])
            ->postJson('/api/tracking/touchpoint', $req['payload'])
            ->assertStatus(403);
    }

    public function test_attribution_records_a_conversion(): void
    {
        $customer = $this->customer(['website' => 'https://example.com']);

        $req = $this->browserEvent($customer, [
            'event_id' => '123e4567-e89b-42d3-a456-426614174001',
            'conversion_type' => 'purchase',
            'conversion_value' => 149.99,
        ]);

        $this->withHeaders($req['headers'])
            ->postJson('/api/tracking/conversion', $req['payload'])
            ->assertSuccessful();

        $this->assertDatabaseHas('attribution_conversions', [
            'customer_id' => $customer->id,
            'visitor_id' => '123e4567-e89b-42d3-a456-426614174000',
        ]);
    }

    // ── Personas ─────────────────────────────────────────────────────────────

    public function test_personas_can_be_generated(): void
    {
        // GeminiService authenticates before calling; skip the real GCP round-trip
        // the way GeminiServiceTest does.
        config(['services.google.credentials_path' => '/dev/null']);
        Cache::put('gcp_vertex_access_token', 'test-token', 3000);

        $personas = [[
            'name' => 'Budget-conscious renovator',
            'description' => 'Homeowners renovating on a tight budget',
            'demographics' => ['age_range' => '35-54'],
            'pain_points' => ['cost', 'time'],
        ]];

        // Vertex streams its response as a list of chunks.
        Http::fake(['*' => Http::response([[
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode($personas)]], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 10, 'totalTokenCount' => 20],
        ]], 200)]);

        $result = app(\App\Services\PersonaGeneratorService::class)
            ->generate($this->customer(), null, 1);

        // The point is that the whole path runs and parses the model's output —
        // prompt build, brand-guideline lookup, Gemini call, JSON decode.
        $this->assertNotEmpty($result, 'generate() should parse personas out of the model response');
        $this->assertSame('Budget-conscious renovator', $result[0]['name'] ?? null);
    }

    // ── Products / Merchant Center ───────────────────────────────────────────

    public function test_a_product_feed_and_products_can_be_stored(): void
    {
        $customer = $this->customer();

        $feed = ProductFeed::factory()->create(['customer_id' => $customer->id]);
        $product = Product::factory()->create([
            'customer_id' => $customer->id,
            'product_feed_id' => $feed->id,
        ]);

        $this->assertDatabaseHas('product_feeds', ['id' => $feed->id, 'customer_id' => $customer->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'product_feed_id' => $feed->id]);
        $this->assertTrue($product->productFeed->is($feed), 'product should resolve its feed');
    }

    // ── CRM ──────────────────────────────────────────────────────────────────

    public function test_a_crm_integration_round_trips_its_encrypted_credentials(): void
    {
        $customer = $this->customer();

        $integration = CrmIntegration::create([
            'customer_id' => $customer->id,
            'provider' => 'hubspot',
            'credentials' => ['access_token' => 'secret-token-value'],
            'is_active' => true,
        ]);

        $this->assertSame('secret-token-value', $integration->fresh()->credentials['access_token']);

        // Credentials are cast encrypted — the raw column must not hold plaintext.
        $raw = \DB::table('crm_integrations')->where('id', $integration->id)->value('credentials');
        $this->assertStringNotContainsString('secret-token-value', (string) $raw);
    }

    // ── Asset harvesting ─────────────────────────────────────────────────────

    public function test_a_harvested_asset_can_be_stored_and_scoped(): void
    {
        $customer = $this->customer();

        $asset = HarvestedAsset::create([
            'customer_id' => $customer->id,
            'source_url' => 'https://example.com/logo.png',
            'type' => 'image',
            'status' => 'processed',
        ]);

        $this->assertDatabaseHas('harvested_assets', ['id' => $asset->id, 'customer_id' => $customer->id]);
    }

    // ── Audiences ────────────────────────────────────────────────────────────

    public function test_an_audience_can_be_stored_against_a_campaign(): void
    {
        $customer = $this->customer();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        $audience = \App\Models\Audience::create([
            'customer_id' => $customer->id,
            'campaign_id' => $campaign->id,
            'name' => 'In-market: home renovation',
            // audiences.type has a CHECK constraint: customer_match, remarketing,
            // combined, lookalike.
            'type' => 'remarketing',
            'platform' => 'google',
        ]);

        $this->assertDatabaseHas('audiences', ['id' => $audience->id, 'campaign_id' => $campaign->id]);
    }
}
