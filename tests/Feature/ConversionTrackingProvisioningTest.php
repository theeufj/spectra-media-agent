<?php

namespace Tests\Feature;

use App\Jobs\ScrapeCustomerWebsite;
use App\Jobs\SetupConversionTracking;
use App\Jobs\VerifyGtmInstallation;
use App\Models\AgentActivity;
use App\Models\Customer;
use App\Services\GTM\GTMContainerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Conversion tracking has to exist before a campaign runs, not after.
 *
 * It was previously provisioned from exactly two places: an admin clicking a
 * button, and AutomatedCampaignMaintenance — whose digest loop only covers
 * campaigns already ELIGIBLE or LEARNING with a platform ID. So tracking was set
 * up only for customers who already had a live campaign. Every customer's first
 * campaign therefore launched with no conversion signal, and Smart Bidding bid
 * on zero conversions: the exact failure that sent 89% of this account's own
 * impressions into mobile games.
 *
 * Eight of nine customers had no conversion action at all.
 */
class ConversionTrackingProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_customer_with_a_website_gets_tracking_provisioned(): void
    {
        Queue::fake();

        Customer::factory()->create(['website' => 'https://example.com', 'is_sandbox' => false]);

        Queue::assertPushed(SetupConversionTracking::class);
    }

    public function test_adding_a_website_later_also_triggers_it(): void
    {
        // Customers routinely register before they have filled in a site.
        Queue::fake();

        $customer = Customer::factory()->create(['website' => null, 'is_sandbox' => false]);
        Queue::assertNotPushed(SetupConversionTracking::class);

        $customer->update(['website' => 'https://example.com']);

        Queue::assertPushed(SetupConversionTracking::class);
    }

    public function test_sandbox_customers_are_left_alone(): void
    {
        // Sandbox customers are synthetic; provisioning a real GTM container and
        // Google Ads conversion action for one would create real resources for a
        // customer that does not exist.
        Queue::fake();

        Customer::factory()->create(['website' => 'https://example.com', 'is_sandbox' => true]);

        Queue::assertNotPushed(SetupConversionTracking::class);
        Queue::assertNotPushed(ScrapeCustomerWebsite::class);
    }

    public function test_a_customer_already_tracking_is_not_reprovisioned(): void
    {
        Queue::fake();

        Customer::factory()->create([
            'website' => 'https://example.com',
            'is_sandbox' => false,
            'conversion_action_id' => '123456',
        ]);

        Queue::assertNotPushed(SetupConversionTracking::class);
    }

    public function test_an_unreachable_site_is_not_recorded_as_missing_the_snippet(): void
    {
        // "We could not check" is not "they did not install it". Treating a
        // fetch failure as absence would chase blameless customers and could
        // flip a previously verified customer back to false.
        $customer = Customer::factory()->create([
            'website' => 'https://example.com',
            'is_sandbox' => false,
            'gtm_container_id' => 'GTM-ABC123',
            'gtm_installed' => true,
            'gtm_last_verified' => now()->subDays(30),
        ]);

        /** @var GTMContainerService&\Mockery\MockInterface $gtm */
        $gtm = Mockery::mock(GTMContainerService::class);
        $gtm->shouldReceive('verifySnippetInstalled')
            ->andReturn(['success' => false, 'error' => 'Could not fetch website content']);

        (new VerifyGtmInstallation)->handle($gtm);

        $this->assertTrue($customer->fresh()->gtm_installed, 'a failed check must not clear a verified install');
    }

    public function test_a_missing_snippet_is_recorded_so_it_is_visible(): void
    {
        $customer = Customer::factory()->create([
            'website' => 'https://example.com',
            'is_sandbox' => false,
            'gtm_container_id' => 'GTM-ABC123',
            'gtm_last_verified' => null,
        ]);

        /** @var GTMContainerService&\Mockery\MockInterface $gtm */
        $gtm = Mockery::mock(GTMContainerService::class);
        $gtm->shouldReceive('verifySnippetInstalled')
            ->andReturn(['success' => true, 'installed' => false, 'detected' => [], 'expected' => 'GTM-ABC123']);

        (new VerifyGtmInstallation)->handle($gtm);

        $this->assertFalse($customer->fresh()->gtm_installed);
        $this->assertTrue(
            AgentActivity::where('customer_id', $customer->id)->where('action', 'gtm_snippet_missing')->exists(),
            'provisioned-but-never-installed must be a visible state, not an absence of conversions'
        );
    }

    public function test_an_installed_snippet_is_marked_verified(): void
    {
        $customer = Customer::factory()->create([
            'website' => 'https://example.com',
            'is_sandbox' => false,
            'gtm_container_id' => 'GTM-ABC123',
            'gtm_installed' => false,
            'gtm_last_verified' => null,
        ]);

        /** @var GTMContainerService&\Mockery\MockInterface $gtm */
        $gtm = Mockery::mock(GTMContainerService::class);
        $gtm->shouldReceive('verifySnippetInstalled')
            ->andReturn(['success' => true, 'installed' => true, 'detected' => ['GTM-ABC123'], 'expected' => 'GTM-ABC123']);

        (new VerifyGtmInstallation)->handle($gtm);

        $fresh = $customer->fresh();
        $this->assertTrue($fresh->gtm_installed);
        $this->assertNotNull($fresh->gtm_last_verified);
    }

    public function test_recently_verified_customers_are_not_rechecked(): void
    {
        Customer::factory()->create([
            'website' => 'https://example.com',
            'is_sandbox' => false,
            'gtm_container_id' => 'GTM-ABC123',
            'gtm_last_verified' => now()->subDay(),
        ]);

        /** @var GTMContainerService&\Mockery\MockInterface $gtm */
        $gtm = Mockery::mock(GTMContainerService::class);
        $gtm->shouldNotReceive('verifySnippetInstalled');

        (new VerifyGtmInstallation)->handle($gtm);

        // Mockery verifies the expectation on teardown; assert explicitly so the
        // test states its intent rather than passing by absence.
        $this->assertSame(0, AgentActivity::where('action', 'gtm_snippet_missing')->count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
