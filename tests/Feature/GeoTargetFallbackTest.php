<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\TargetingConfig;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\Google\GeoTargetResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Unresolvable geo targeting used to become "spend in four countries".
 *
 * geoTargetNameToId() is a country-only map, so any city, state or postcode
 * resolved to null; array_filter() dropped the nulls and the empty array landed
 * on the same branch as "nothing configured" — US/CA/AU/GB, logged as
 * "No geo configured", deploy reported successful. A strategy targeting
 * "Melbourne" therefore served in four countries the customer never chose, and
 * the loss only showed up later as spend.
 *
 * Nothing configured is still a legitimate default. Something configured that
 * resolves to nothing is not.
 */
class GeoTargetFallbackTest extends TestCase
{
    use DatabaseTransactions;

    private const CUSTOMER_ID = '3598653839';

    private const CAMPAIGN_RESOURCE = 'customers/3598653839/campaigns/24045681965';

    public function test_locations_that_cannot_be_resolved_fail_the_deploy(): void
    {
        [$campaign, $strategy] = $this->campaignAndStrategy(['geographic_targeting' => ['Melbourne']]);
        $resolver = $this->resolver($campaign->customer);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Melbourne/');

        $resolver->addLocationTargeting(
            self::CUSTOMER_ID,
            self::CAMPAIGN_RESOURCE,
            $campaign,
            $strategy,
            new ExecutionPlan([]),
            new ExecutionResult(true)
        );
    }

    public function test_the_default_markets_are_never_substituted_for_a_failed_resolution(): void
    {
        [$campaign, $strategy] = $this->campaignAndStrategy(['geographic_targeting' => ['Melbourne', 'Geelong']]);
        $resolver = $this->resolver($campaign->customer);

        $threw = false;

        try {
            $resolver->addLocationTargeting(
                self::CUSTOMER_ID,
                self::CAMPAIGN_RESOURCE,
                $campaign,
                $strategy,
                new ExecutionPlan([]),
                new ExecutionResult(true)
            );
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'the deploy must fail rather than guess at the markets');
        // 2840, 2124, 2036, 2826 — US/CA/AU/GB, the four that used to be
        // applied silently in exactly this situation.
        $this->assertSame([], $resolver->applied, 'no criterion may be written when nothing resolved');
    }

    public function test_nothing_configured_still_defaults_to_the_english_speaking_markets(): void
    {
        [$campaign, $strategy] = $this->campaignAndStrategy();
        $resolver = $this->resolver($campaign->customer);
        $result = new ExecutionResult(true);

        $resolver->addLocationTargeting(
            self::CUSTOMER_ID,
            self::CAMPAIGN_RESOURCE,
            $campaign,
            $strategy,
            new ExecutionPlan([]),
            $result
        );

        $this->assertSame([2840, 2124, 2036, 2826], $resolver->applied);
        $this->assertTrue($result->success);
    }

    public function test_a_partly_resolved_list_targets_what_resolved_and_says_what_did_not(): void
    {
        [$campaign, $strategy] = $this->campaignAndStrategy(['geographic_targeting' => ['Australia', 'Melbourne']]);
        $resolver = $this->resolver($campaign->customer);
        $result = new ExecutionResult(true);

        $resolver->addLocationTargeting(
            self::CUSTOMER_ID,
            self::CAMPAIGN_RESOURCE,
            $campaign,
            $strategy,
            new ExecutionPlan([]),
            $result
        );

        $this->assertSame([2036], $resolver->applied);
        $this->assertSame('geo_target_unresolved', $result->warnings[0]->code);
        $this->assertStringContainsString('Melbourne', $result->warnings[0]->message);
        // A warning, not an error: the locations that did resolve are still the
        // customer's own, so the deploy is allowed to continue.
        $this->assertTrue($result->success);
    }

    public function test_a_targeting_config_location_without_a_criterion_id_is_resolved_by_name(): void
    {
        // TargetingConfig::getGoogleGeoTargeting() array_filter()s away anything
        // without google_criterion_id — a second silent drop the resolver used to
        // inherit. Resolving the raw values covers both shapes in one pass.
        [$campaign, $strategy] = $this->campaignAndStrategy();
        TargetingConfig::create([
            'strategy_id' => $strategy->id,
            'geo_locations' => [
                ['name' => 'United States', 'google_criterion_id' => 2840],
                ['name' => 'Canada'],
            ],
        ]);
        $strategy->load('targetingConfig');

        $resolver = $this->resolver($campaign->customer);
        $result = new ExecutionResult(true);

        $resolver->addLocationTargeting(
            self::CUSTOMER_ID,
            self::CAMPAIGN_RESOURCE,
            $campaign,
            $strategy,
            new ExecutionPlan([]),
            $result
        );

        $this->assertSame([2840, 2124], $resolver->applied);
        $this->assertSame([], $result->warnings);
    }

    public function test_execution_plan_locations_are_used_when_nothing_else_is_configured(): void
    {
        [$campaign, $strategy] = $this->campaignAndStrategy();
        $resolver = $this->resolver($campaign->customer);

        $plan = new ExecutionPlan([], [], [], '', ['campaign_structure' => ['locations' => ['Ireland']]]);

        $resolver->addLocationTargeting(
            self::CUSTOMER_ID,
            self::CAMPAIGN_RESOURCE,
            $campaign,
            $strategy,
            $plan,
            new ExecutionResult(true)
        );

        $this->assertSame([2372], $resolver->applied);
    }

    /**
     * @return array{0: Campaign, 1: Strategy}
     */
    private function campaignAndStrategy(array $campaignAttributes = []): array
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => self::CUSTOMER_ID]);

        $campaign = Campaign::factory()->create(array_merge([
            'customer_id' => $customer->id,
        ], $campaignAttributes));

        $strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads',
        ]);

        return [$campaign, $strategy];
    }

    /**
     * A resolver that records the criteria it would write instead of calling
     * Google — the decision under test is which locations get targeted, and the
     * Google Ads SDK speaks gRPC, which Http::preventStrayRequests() cannot see.
     *
     * Deliberately untyped: the return is the anonymous subclass, not the base
     * class, and the tests read its $applied property.
     */
    private function resolver(Customer $customer)
    {
        return new class($customer) extends GeoTargetResolver
        {
            /** @var list<int> */
            public array $applied = [];

            protected function applyLocationCriteria(
                string $customerId,
                string $campaignResourceName,
                array $locationIds,
                ExecutionResult $result
            ): void {
                $this->applied = array_merge($this->applied, $locationIds);
            }
        };
    }
}
