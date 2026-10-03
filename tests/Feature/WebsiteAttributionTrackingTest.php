<?php

namespace Tests\Feature;

use App\Models\AttributionConversion;
use App\Models\AttributionTouchpoint;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\GoogleAdsPerformanceData;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebsiteAttributionTrackingTest extends TestCase
{
    use DatabaseTransactions;

    private const VISITOR = '123e4567-e89b-42d3-a456-426614174000';

    private const EVENT = '123e4567-e89b-42d3-a456-426614174001';

    private function payload(Customer $customer, array $extra = []): array
    {
        return array_merge([
            'site_id' => $customer->uuid,
            'visitor_id' => self::VISITOR,
            'page_url' => 'https://www.example.com/landing',
        ], $extra);
    }

    public function test_form_encoded_cross_domain_events_populate_the_campaign_report(): void
    {
        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $user = User::factory()->create(['subscription_status' => 'active']);
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        $this->withHeader('Origin', 'https://www.example.com')
            ->post('/api/tracking/touchpoint', $this->payload($customer, [
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'spectra_'.$campaign->id,
            ]))->assertCreated();

        $conversion = $this->payload($customer, [
            'event_id' => self::EVENT,
            'conversion_type' => 'lead',
            'conversion_value' => 50,
            'touchpoints' => [['utm_source' => 'forged']],
        ]);
        $this->post('/api/tracking/conversion', $conversion)->assertCreated();
        $this->post('/api/tracking/conversion', $conversion)->assertOk();

        $this->assertSame(1, AttributionConversion::forCustomer($customer->id)->count());
        $stored = AttributionConversion::forCustomer($customer->id)->firstOrFail();
        $this->assertSame('google', $stored->touchpoints[0]['utm_source']);
        $this->assertEquals(1, $stored->attributed_to['last_click'][0]['credit']);

        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id])
            ->get(route('campaigns.attribution', $campaign->uuid))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Campaigns/Attribution')
                ->where('summary.total_conversions', 1)
                ->where('trackingSetup.website_host', 'example.com')
                ->etc());
    }

    public function test_public_identifier_requires_registered_origin_and_matching_page(): void
    {
        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        $payload = $this->payload($customer);

        $this->post('/api/tracking/touchpoint', $payload)->assertForbidden();
        $this->withHeader('Origin', 'https://another-site.example')
            ->post('/api/tracking/touchpoint', $payload)->assertForbidden();
        $this->withHeader('Origin', 'https://example.com')
            ->post('/api/tracking/touchpoint', array_merge($payload, ['page_url' => 'https://another-site.example/']))
            ->assertForbidden();
        $this->post('/api/tracking/touchpoint', array_merge($payload, ['site_id' => (string) $customer->id]))
            ->assertForbidden();
        $this->post('/api/tracking/touchpoint', array_merge($payload, ['site_id' => str_repeat('-', 36)]))
            ->assertForbidden();

        $this->assertSame(0, AttributionTouchpoint::forCustomer($customer->id)->count());
    }

    public function test_conversion_cannot_claim_another_site_or_future_browser_journey(): void
    {
        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        $other = Customer::factory()->create(['website' => 'https://other.example']);
        AttributionTouchpoint::create([
            'customer_id' => $other->id,
            'visitor_id' => self::VISITOR,
            'utm_source' => 'other',
            'touched_at' => now(),
        ]);
        AttributionTouchpoint::create([
            'customer_id' => $customer->id,
            'visitor_id' => self::VISITOR,
            'utm_source' => 'stale',
            'touched_at' => now()->subDays(91),
        ]);

        $this->withHeader('Origin', 'https://example.com')
            ->post('/api/tracking/conversion', $this->payload($customer, [
                'event_id' => self::EVENT,
                'conversion_type' => 'lead',
                'touchpoints' => [['utm_source' => 'forged']],
            ]))->assertCreated();

        $stored = AttributionConversion::forCustomer($customer->id)->firstOrFail();
        $this->assertSame([], $stored->touchpoints);
        $this->assertSame([], $stored->attributed_to);
    }

    public function test_google_totals_are_separate_and_scoped_to_the_active_customer(): void
    {
        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        $other = Customer::factory()->create(['website' => 'https://other.example']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $otherCampaign = Campaign::factory()->create(['customer_id' => $other->id]);
        $user = User::factory()->create(['subscription_status' => 'active']);
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        foreach ([[$campaign, 2, 80], [$otherCampaign, 99, 9900]] as [$target, $conversions, $value]) {
            GoogleAdsPerformanceData::create([
                'campaign_id' => $target->id,
                'date' => now()->toDateString(),
                'impressions' => 100,
                'clicks' => 10,
                'cost' => 20,
                'conversions' => $conversions,
                'conversion_value' => $value,
            ]);
        }

        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id])
            ->get(route('analytics.attribution'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Analytics/Attribution')
                ->where('googleSummary.conversions', 2)
                ->where('googleSummary.conversion_value', 80)
                ->where('summary.total_conversions', 0)
                ->etc());
    }
}
