<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\GoogleAdsExecutionAgent;
use App\Services\Crawling\PublicWebsiteFetcher;
use App\Services\Deployment\AdDestinationCheck;
use App\Services\Deployment\GoogleAdsDeploymentStrategy;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdDestinationCheckTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(PublicWebsiteFetcher::class, new class extends PublicWebsiteFetcher
        {
            protected function addresses(string $url): array
            {
                return parse_url($url, PHP_URL_HOST) === 'public.example' ? ['8.8.8.8'] : parent::addresses($url);
            }
        });
    }

    public function test_a_browser_success_does_not_hide_a_desktop_crawler_522(): void
    {
        Http::fake(fn ($request) => Http::response('', str_starts_with($request->header('User-Agent')[0], 'AdsBot-Google') ? 522 : 200));
        $this->assertTrue(app(PublicWebsiteFetcher::class)->get('https://public.example/offer')->successful());

        $result = app(AdDestinationCheck::class)->check('https://public.example/offer?utm_source=google');

        $this->assertSame('unreachable', $result['status']);
        $this->assertSame(522, $result['http_status']);
        $this->assertStringContainsString('HTTP 522', $result['message']);
        Http::assertSent(fn ($request) => $request->url() === 'https://public.example/offer?utm_source=google');
    }

    public function test_redirects_are_followed_with_the_desktop_profile_and_a_private_hop_is_blocked(): void
    {
        Http::fake([
            'https://public.example/offer' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
        ]);

        $result = app(AdDestinationCheck::class)->check('https://public.example/offer');

        $this->assertSame('unknown', $result['status']);
        $this->assertNull($result['http_status']);
        Http::assertSentCount(1);
    }

    public function test_transport_failure_is_unknown_and_never_a_successful_destination_check(): void
    {
        Http::fake(fn () => throw new ConnectionException('Private network details'));

        $result = app(AdDestinationCheck::class)->check('https://public.example/offer');

        $this->assertSame('unknown', $result['status']);
        $this->assertStringContainsString('failed or timed out', $result['message']);
        $this->assertStringNotContainsString('Private network', $result['message']);
    }

    public function test_a_bad_actual_strategy_destination_stops_before_any_platform_write(): void
    {
        Http::fake(['*' => Http::response('', 522)]);
        $this->mock(GeminiService::class);
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'landing_page_url' => 'https://public.example/home']);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'bidding_strategy' => ['landing_page_url' => 'https://public.example/offer']]);
        $agent = new class($customer) extends GoogleAdsExecutionAgent
        {
            public function run(ExecutionPlan $plan, ExecutionContext $context): ExecutionResult
            {
                return $this->executePlan($plan, $context);
            }

            protected function setupConversionTracking(string $customerId, ExecutionResult $result, ?Campaign $campaign = null): void
            {
                throw new \LogicException('Platform writes must never start for a failing destination.');
            }
        };

        $result = $agent->run(new ExecutionPlan([]), new ExecutionContext($strategy, $campaign, $customer));

        $this->assertFalse($result->success);
        $this->assertSame('destination_unavailable', $result->errors[0]->code);
        $this->assertStringContainsString('HTTP 522', $result->errorMessage());
        $this->assertSame([], $result->platformIds);
        $this->assertStringStartsWith('https://public.example/offer?', $result->metadata['destination_check']['url']);
        $this->assertDatabaseHas('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'destination_check_failed']);
    }

    public function test_legacy_search_deployment_preserves_the_actionable_destination_error(): void
    {
        Http::fake(['*' => Http::response('', 522)]);
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'landing_page_url' => 'https://public.example/offer']);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'campaign_type' => 'search']);

        $this->assertFalse((new GoogleAdsDeploymentStrategy($customer))->deploy($campaign, $strategy));
        $this->assertStringContainsString('HTTP 522', $strategy->fresh()->deployment_error);
        $this->assertNull($campaign->fresh()->google_ads_campaign_id);
    }
}
