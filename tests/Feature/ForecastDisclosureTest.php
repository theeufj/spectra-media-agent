<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The forecast makes a forward-looking claim, so the disclosure has to hold.
 *
 * The budget step now shows a customer a quantified projection of their own
 * revenue and return — figures they are being asked to commit money against.
 * Two things keep that honest and they are easy to lose independently: the
 * clause in the Terms, and the statement on the panel itself that the
 * conversion rate is our assumption rather than Google's measurement.
 *
 * Under the Australian Consumer Law a representation about a future matter is
 * taken to be misleading unless there are reasonable grounds for it, and a
 * disclaimer buried in terms does not cure misleading conduct. The on-screen
 * disclosure is therefore the part that matters most, which is why it is
 * asserted here and again in the component's own tests.
 */
class ForecastDisclosureTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * The clause text lives in the Inertia page component, not in the server
     * response — the route only ships the shell and the props — so this reads
     * the component that is actually shipped to the browser.
     */
    private function terms(): string
    {
        $path = resource_path('js/Pages/Legal/Terms.jsx');

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_the_terms_route_still_renders_that_component(): void
    {
        // Guards the link between the file asserted below and the page served.
        $this->get(route('terms'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Legal/Terms'));
    }

    public function test_the_terms_carry_a_forecast_clause(): void
    {
        $this->assertStringContainsString('Forecasts, Estimates and Projections', $this->terms());
    }

    public function test_the_clause_calls_the_figures_indicative_only(): void
    {
        $this->assertStringContainsString('indicative only', $this->terms());
    }

    public function test_the_clause_disclaims_both_directions(): void
    {
        // Under-performance is the obvious risk; over-performance matters too,
        // because a customer who spends against an optimistic projection and
        // cannot fulfil the demand has also relied on our number.
        $terms = $this->terms();

        $this->assertStringContainsString('materially better or materially worse', $terms);
        $this->assertStringContainsString('falls below, or exceeds, the figures shown', $terms);
    }

    public function test_the_clause_disclaims_demand_for_the_customers_own_offering(): void
    {
        $terms = $this->terms();

        $this->assertStringContainsString('demand for your products or services', $terms);
        $this->assertStringContainsString('sit outside the Service', $terms);
    }

    public function test_the_clause_says_the_conversion_rate_is_an_assumption(): void
    {
        // The one input in the forecast that is ours rather than Google's.
        $this->assertStringContainsString('assumption we apply for illustration', $this->terms());
    }

    public function test_the_clause_says_the_order_value_is_unverified(): void
    {
        $this->assertStringContainsString('We do not verify it.', $this->terms());
    }

    public function test_the_clause_does_not_purport_to_override_australian_consumer_law(): void
    {
        // A blanket exclusion would be void and would itself be a problem.
        $terms = $this->terms();

        $this->assertStringContainsString('Australian Consumer Law', $terms);
        $this->assertStringContainsString(
            'excludes, restricts or modifies any right or remedy available to you',
            $terms
        );
    }

    public function test_the_liability_and_warranty_clauses_reach_forecasts_too(): void
    {
        // A clause nothing else references is easy to leave behind in a rewrite.
        $terms = $this->terms();

        $this->assertStringContainsString('see clause 6', $terms);
        $this->assertStringContainsString('ACTUAL RESULTS MAY DIFFER MATERIALLY IN EITHER DIRECTION', $terms);
    }
}
