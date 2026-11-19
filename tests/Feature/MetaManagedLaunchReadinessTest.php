<?php

namespace Tests\Feature;

use App\Jobs\ProvisionMetaAdAccount;
use App\Jobs\ResearchCampaignMarket;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Strategy;
use App\Services\Campaigns\CampaignMarketResearchWorkflow;
use App\Services\FacebookAds\BusinessManagerService;
use App\Services\FacebookAds\CreateFacebookAdsAccount;
use App\Services\FacebookAds\FacebookMarketResearchProvider;
use App\Services\FacebookAds\PixelService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MetaManagedLaunchReadinessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.facebook.business_manager_id' => '111111', 'services.facebook.system_user_token' => 'meta-test-token']);
    }

    public static function accounts(): array
    {
        return [
            'eligible' => [[], null],
            'unsettled despite a funding source' => [['account_status' => 3], 'facebook_account_unsettled'],
            'disabled' => [['account_status' => 2], 'facebook_account_cannot_serve'],
            'no funding' => [['funding_source' => null], 'facebook_account_funding_missing'],
            'other business' => [['business' => ['id' => '999999']], 'facebook_account_not_owned'],
            'read-only user' => [['user_tasks' => ['ANALYZE']], 'facebook_account_role_missing'],
            'wrong resource' => [['id' => 'act_99999'], 'facebook_account_unverified'],
        ];
    }

    #[DataProvider('accounts')]
    public function test_new_launch_requires_the_managed_account_role_and_billing(array $fields, ?string $issue): void
    {
        Http::fake(['graph.facebook.com/*/act_22222*' => Http::response(array_replace([
            'id' => 'act_22222', 'business' => ['id' => '111111'], 'account_status' => 1,
            'user_tasks' => ['ADVERTISE'], 'funding_source' => '3',
        ], $fields))]);

        $this->assertSame($issue, app(BusinessManagerService::class)->launchAccountIssue('act_22222')['code'] ?? null);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer meta-test-token'));
    }

    public function test_ad_account_creation_uses_the_tenants_selected_page(): void
    {
        $customer = Customer::factory()->create(['facebook_page_id' => '98765']);
        Http::fake(['graph.facebook.com/*/111111/adaccount' => Http::response(['id' => 'act_22222'])]);

        $this->assertSame('22222', (new CreateFacebookAdsAccount($customer))('Workspace Ads')['account_id']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/111111/adaccount') && $request['end_advertiser'] === '98765');
    }

    public function test_paid_meta_research_prepares_its_account_before_evidence_is_requested(): void
    {
        Bus::fake();
        $customer = $this->eligibleWorkspace();
        $campaign = Campaign::factory()->for($customer)->create();
        Strategy::factory()->for($campaign)->create(['platform' => 'Meta Ads', 'campaign_type' => 'display']);
        $workflow = app(CampaignMarketResearchWorkflow::class);

        $request = $workflow->request($campaign);
        Bus::assertChained([
            fn (ProvisionMetaAdAccount $job) => $job->campaign->is($campaign) && $job->requestId === $request['request_id'],
            ResearchCampaignMarket::class,
        ]);
        $this->assertNull($campaign->fresh()->budget_confirmed_at);
        $this->assertNull($customer->fresh()->facebook_ads_account_id);
        Http::assertNothingSent();
    }

    public function test_unverified_page_never_causes_account_creation(): void
    {
        $customer = $this->eligibleWorkspace();
        Http::fake([
            'graph.facebook.com/*/me*' => Http::response(['id' => '44444']),
            'graph.facebook.com/*/98765/assigned_users*' => Http::response(['data' => [['id' => '44444', 'tasks' => ['ANALYZE']]]]),
        ]);

        $this->assertNull((new CreateFacebookAdsAccount($customer))->provision());
        $this->assertNull($customer->fresh()->facebook_ads_account_id);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_waiting_job_stops_if_the_campaign_no_longer_requests_meta(): void
    {
        Http::fake();
        $customer = $this->eligibleWorkspace();
        $campaign = Campaign::factory()->for($customer)->create([
            'search_delivery_state' => ['market_research' => ['request_id' => 'before-meta-was-removed']],
        ]);
        Strategy::factory()->for($campaign)->create(['platform' => 'google']);

        (new ProvisionMetaAdAccount($campaign, 'before-meta-was-removed'))->handle();

        Http::assertNothingSent();
    }

    public function test_complete_five_account_inventory_routes_to_manual_setup_without_creating_an_account(): void
    {
        $customer = $this->eligibleWorkspace();
        $campaign = Campaign::factory()->for($customer)->create();
        $strategy = Strategy::factory()->for($campaign)->create(['platform' => 'Facebook Ads']);
        Http::fake([
            'graph.facebook.com/*/me*' => Http::response(['id' => '44444']),
            'graph.facebook.com/*/98765/assigned_users*' => Http::response(['data' => [['id' => '44444', 'tasks' => ['ADVERTISE']]]]),
            'graph.facebook.com/*/111111/owned_ad_accounts*' => Http::response(['data' => array_map(
                fn (int $id) => ['id' => 'act_'.$id, 'name' => 'Other workspace '.$id], range(11110, 11114)
            )]),
        ]);

        $this->assertNull((new CreateFacebookAdsAccount($customer))->provision());
        $this->assertNull((new CreateFacebookAdsAccount($customer->fresh()))->provision());
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
        $this->assertNull($customer->fresh()->facebook_ads_account_id);
        $this->assertSame(1, AgentActivity::where('customer_id', $customer->id)->where('action', 'meta_account_requires_manual_setup')->count());
        $proposal = app(FacebookMarketResearchProvider::class)->propose($campaign, $strategy, [], []);
        $this->assertSame('needs_review', $proposal['status']);
        $this->assertStringContainsString('administrator must set up and link a dedicated Meta Business ad account', $proposal['reasons'][0]);
    }

    public function test_provisioning_recovers_the_exact_owned_account_after_an_ambiguous_response(): void
    {
        $customer = $this->eligibleWorkspace();
        $name = 'Site to Spend workspace '.$customer->uuid;
        $metaAccounts = [];
        Http::fake(function (Request $request) use (&$metaAccounts) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/me') => Http::response(['id' => '44444']),
                str_ends_with($path, '/98765/assigned_users') => Http::response(['data' => [['id' => '44444', 'tasks' => ['ADVERTISE']]]]),
                str_ends_with($path, '/111111/owned_ad_accounts') => Http::response(['data' => $metaAccounts]),
                str_ends_with($path, '/111111/adaccount') => Http::response(['error' => ['message' => 'Ambiguous response']], 503),
                default => Http::response([], 404),
            };
        });

        $this->assertNull((new CreateFacebookAdsAccount($customer))->provision());
        $this->assertNull((new CreateFacebookAdsAccount($customer->fresh()))->provision());
        $this->assertNull($customer->fresh()->facebook_ads_account_id);
        $metaAccounts = [
            ['id' => 'act_22222', 'name' => $name, 'currency' => 'AUD', 'timezone_id' => 15, 'end_advertiser' => '98765'],
            ...array_map(fn (int $id) => ['id' => 'act_'.$id, 'name' => 'Other workspace '.$id], range(11110, 11113)),
        ];
        $this->assertSame('22222', (new CreateFacebookAdsAccount($customer->fresh()))->provision());
        $this->assertTrue($customer->fresh()->facebook_bm_owned);
        $this->assertSame('22222', (new CreateFacebookAdsAccount($customer->fresh()))->provision());
        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->method() === 'POST'));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['timezone_id'] === 15 && $request['end_advertiser'] === '98765');
    }

    private function eligibleWorkspace(): Customer
    {
        Setting::set('deployment_enabled', true, 'boolean');
        EnabledPlatform::updateOrCreate(['slug' => 'facebook'], ['name' => 'Facebook Ads', 'is_enabled' => true]);
        $plan = Plan::firstOrCreate(['slug' => 'growth'], ['name' => 'Growth', 'price_cents' => 24900, 'billing_interval' => 'month']);
        $customer = Customer::factory()->create(['plan_id' => $plan->id, 'facebook_page_id' => '98765', 'timezone' => 'Australia/Sydney', 'currency_code' => 'AUD']);
        $brand = $customer->brandGuideline()->make([
            'extracted_at' => now(), 'brand_voice' => ['primary_tone' => 'direct'], 'tone_attributes' => ['direct'],
            'target_audience' => ['primary' => 'Homeowners'], 'competitor_differentiation' => ['Clear pricing'],
            'messaging_themes' => ['Service'], 'unique_selling_propositions' => ['Price transparency'],
            'do_not_use' => ['Jargon'], 'color_palette' => ['primary_colors' => ['#111111']],
            'typography' => ['heading_style' => 'sans'], 'visual_style' => ['overall_aesthetic' => 'modern'],
            'writing_patterns' => ['sentence_length' => 'varied'], 'brand_personality' => ['archetype' => 'Everyman'],
            'service_lines' => [['name' => 'Service']], 'extraction_quality_score' => 90,
        ]);
        $brand->forceFill(['user_verified' => true])->save();

        return $customer;
    }

    public function test_dataset_is_saved_only_after_verified_sharing_and_retry_reuses_it(): void
    {
        $customer = Customer::factory()->create(['facebook_ads_account_id' => 'act_22222', 'facebook_pixel_id' => null]);
        $name = 'Site to Spend workspace '.$customer->uuid.' dataset';
        Http::fake([
            'graph.facebook.com/*/act_22222/adspixels*' => Http::sequence()
                ->push(['data' => []])->push(['data' => []])
                ->push(['data' => []])->push(['data' => [['id' => '33333']]]),
            'graph.facebook.com/*/111111/owned_pixels*' => Http::sequence()
                ->push(['data' => []])->push(['data' => [['id' => '33333', 'name' => $name]]]),
            'graph.facebook.com/*/111111/adspixels' => Http::response(['id' => '33333']),
            'graph.facebook.com/*/33333/shared_accounts' => Http::response(['success' => true]),
        ]);

        $this->assertNull((new PixelService($customer))->resolvePixelId());
        $this->assertNull($customer->fresh()->facebook_pixel_id);
        $this->assertSame('33333', (new PixelService($customer->fresh()))->resolvePixelId());
        $this->assertSame('33333', $customer->fresh()->facebook_pixel_id);
        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/111111/adspixels')));
    }
}
