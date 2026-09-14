<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Plan;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What happens when the image providers are down.
 *
 * All of this came out of one run. OpenRouter returned 402 — the balance was
 * empty — and the retry loop kept asking: three attempts per aspect, three
 * aspects, five scenes, three concurrent jobs, about 135 calls in two minutes.
 * Every one failed instantly and fell through to Gemini, which is how the
 * fallback started returning 429 RESOURCE_EXHAUSTED. The Gemini quota was not
 * small; the fallback was carrying 100% of the load at three times the rate it
 * was designed for.
 *
 * And none of it was visible. The job finished, logged "Successfully generated
 * and stored 0 image(s)", cleared collateral_errors on the way past, and the
 * customer sat on "Generating your collateral... 1-2 minutes" indefinitely.
 */
class ImageProviderFailureTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(int $allowance = 6): Campaign
    {
        $user = User::factory()->create();
        $plan = Plan::firstOrCreate(['slug' => 'test-allowance'], ['name' => 'Test', 'price_cents' => 100, 'billing_interval' => 'month']);
        $plan->forceFill(['creative_limits' => ['image_generations' => $allowance]])->save();

        $customer = Customer::factory()->create(['plan_id' => $plan->id]);
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        return Campaign::factory()->create(['customer_id' => $customer->id]);
    }

    public function test_an_empty_balance_stops_us_asking_again(): void
    {
        Cache::forget('openrouter:unavailable');
        config(['services.openrouter.api_key' => 'test-key']);

        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Insufficient credits.', 'code' => 402],
        ], 402)]);

        $service = app(OpenRouterService::class);

        $this->assertNull($service->generateImage('a photograph'));
        $this->assertTrue(OpenRouterService::isUnavailable());

        // The second call must not reach the network at all: it is the
        // multiplication, not the single failure, that took Gemini down.
        $this->assertNull($service->generateImage('another photograph'));
        Http::assertSentCount(1);

        Cache::forget('openrouter:unavailable');
    }

    /** @return list<array<string, mixed>> One picture, as its three ad formats. */
    private function rows(Campaign $campaign): array
    {
        return array_map(fn ($format) => [
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads (SEM)',
            's3_path' => 'collateral/images/'.$campaign->id.'/'.uniqid().'.jpeg',
            'cloudfront_url' => 'https://example.test/'.uniqid().'.jpeg',
            'format' => $format,
        ], ['square', 'landscape', 'mrec']);
    }

    public function test_the_cap_holds_when_jobs_write_at_once(): void
    {
        $campaign = $this->campaign(allowance: 6);

        // Every write a concurrent job could attempt, back to back. Under the
        // old read-then-write this produced one picture more than the cap.
        $keys = [];
        for ($i = 0; $i < 10; $i++) {
            $keys[] = ImageCollateral::createConcept($campaign, $this->rows($campaign));
        }

        $written = array_filter($keys);

        $this->assertCount(ImageCollateral::MAX_CONCEPTS_PER_CAMPAIGN, $written);
        $this->assertSame(ImageCollateral::MAX_CONCEPTS_PER_CAMPAIGN, ImageCollateral::conceptsForCampaign($campaign));
        $this->assertNull($keys[9], 'a write past the cap must be refused');
    }

    public function test_a_refused_write_stores_nothing_at_all(): void
    {
        $campaign = $this->campaign(allowance: 1);

        ImageCollateral::createConcept($campaign, $this->rows($campaign));
        $this->assertNull(ImageCollateral::createConcept($campaign, $this->rows($campaign)));

        // Half a picture is worse than none: three rows or zero, never one.
        $this->assertSame(3, ImageCollateral::where('campaign_id', $campaign->id)->count());
    }

    public function test_each_picture_gets_its_own_key_and_keeps_its_formats(): void
    {
        $campaign = $this->campaign(allowance: 3);

        $a = ImageCollateral::createConcept($campaign, $this->rows($campaign));
        $b = ImageCollateral::createConcept($campaign, $this->rows($campaign));

        // Two pictures sharing a key would merge onto one card.
        $this->assertNotSame($a, $b);
        $this->assertSame(3, ImageCollateral::where('concept_key', $a)->count());
        $this->assertSame(2, ImageCollateral::conceptsForCampaign($campaign));
    }
}
