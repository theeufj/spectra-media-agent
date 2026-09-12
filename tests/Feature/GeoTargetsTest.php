<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\Agents\Google\GeoTargetResolver;
use App\Services\GoogleAds\GeoTargets;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The country a forecast and a deployment are aimed at.
 *
 * This table used to be reachable only through an execution agent bound to a
 * customer, so the forecast — which has neither — fell back to
 * config('demo.geo_target'), a hardcoded Australian constant. Every forecast
 * for every customer anywhere ran against Australian search volume and
 * Australian bids. That is the worst shape a bug can take here: nothing looks
 * wrong, because the numbers are real, internally consistent, and about
 * somebody else's market.
 */
class GeoTargetsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_iso_codes_and_names_resolve_to_the_same_target(): void
    {
        foreach ([['us', 'united states', 2840], ['gb', 'united kingdom', 2826], ['au', 'australia', 2036]] as [$code, $name, $id]) {
            $this->assertSame($id, GeoTargets::idFor($code));
            $this->assertSame($id, GeoTargets::idFor($name));
        }
    }

    public function test_lookup_survives_casing_and_padding(): void
    {
        // Customer.country arrives from a browser timezone lookup, not a
        // curated list.
        $this->assertSame(2840, GeoTargets::idFor('US'));
        $this->assertSame(2840, GeoTargets::idFor('  us  '));
    }

    public function test_a_known_country_becomes_its_own_resource_name(): void
    {
        $this->assertSame('geoTargetConstants/2840', GeoTargets::resourceNameForCountry('US'));
        $this->assertTrue(GeoTargets::knows('US'));
    }

    public function test_an_unknown_country_falls_back_rather_than_failing(): void
    {
        // A forecast against the default geo is worth more to the customer than
        // no forecast; the caller is told it is a fallback so the page can say so.
        config(['demo.geo_target' => 'geoTargetConstants/2036']);

        $this->assertSame('geoTargetConstants/2036', GeoTargets::resourceNameForCountry('ZZ'));
        $this->assertSame('geoTargetConstants/2036', GeoTargets::resourceNameForCountry(null));
        $this->assertFalse(GeoTargets::knows('ZZ'));
        $this->assertFalse(GeoTargets::knows(null));
    }

    public function test_the_resolver_still_warns_on_an_unknown_location(): void
    {
        // Built before the log expectation: creating a customer writes its own
        // activity log, and that is not what this test is about.
        $resolver = new GeoTargetResolver(Customer::factory()->create());
        $method = new \ReflectionMethod(GeoTargetResolver::class, 'geoTargetNameToId');

        // Extracting the table must not cost the deployment path its warning:
        // an unresolved location there is a targeting gap, not a fallback.
        Log::shouldReceive('warning')
            ->once()
            ->with('GoogleAdsExecutionAgent: Unknown geo target name, cannot resolve to ID', ['name' => 'Atlantis']);
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->assertNull($method->invoke($resolver, 'Atlantis'));
    }

    public function test_the_resolver_still_resolves_what_it_always_did(): void
    {
        $resolver = new GeoTargetResolver(Customer::factory()->create());
        $method = new \ReflectionMethod(GeoTargetResolver::class, 'geoTargetNameToId');

        $this->assertSame(2036, $method->invoke($resolver, 'Australia'));
        $this->assertSame(2840, $method->invoke($resolver, 'us'));
    }
}
