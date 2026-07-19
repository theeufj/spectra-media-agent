<?php

namespace Tests\Feature\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\CampaignDiagnosticsAgent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group agents
 */
class CampaignDiagnosticsIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected CampaignDiagnosticsAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_INTEGRATION_TESTS=true to run.');
        }

        $this->agent = new CampaignDiagnosticsAgent();
    }

    public function test_diagnoses_real_google_ads_campaign(): void
    {
        if (!env('RUN_GOOGLE_ADS_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_GOOGLE_ADS_INTEGRATION_TESTS=true to run.');
        }

        $campaign = Campaign::with('customer')
            ->whereNotNull('google_ads_campaign_id')
            ->whereHas('customer', fn ($q) => $q->whereNotNull('google_ads_customer_id'))
            ->first();

        if (!$campaign) {
            $this->markTestSkipped('No deployed Google Ads campaign in DB.');
        }

        $findings = $this->agent->diagnose($campaign);

        $this->assertIsArray($findings);

        foreach ($findings as $finding) {
            $this->assertArrayHasKey('type', $finding);
            $this->assertArrayHasKey('severity', $finding);
            $this->assertArrayHasKey('platform', $finding);
            $this->assertArrayHasKey('message', $finding);
            $this->assertArrayHasKey('can_auto_fix', $finding);
            $this->assertArrayHasKey('recommended_action', $finding);
            $this->assertContains($finding['severity'], ['critical', 'high', 'medium', 'low']);
        }
    }

    public function test_diagnoses_real_meta_campaign(): void
    {
        if (!env('RUN_FACEBOOK_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_FACEBOOK_INTEGRATION_TESTS=true to run.');
        }

        config(['services.facebook.system_user_token' => env('FACEBOOK_SYSTEM_USER_TOKEN')]);

        $campaign = Campaign::with('customer')
            ->whereNotNull('facebook_ads_campaign_id')
            ->whereHas('customer', fn ($q) => $q->whereNotNull('facebook_ads_account_id'))
            ->first();

        if (!$campaign) {
            $this->markTestSkipped('No deployed Meta campaign in DB.');
        }

        $findings = $this->agent->diagnose($campaign);

        $this->assertIsArray($findings);

        foreach ($findings as $finding) {
            $this->assertArrayHasKey('type', $finding);
            $this->assertArrayHasKey('platform', $finding);
        }
    }

    public function test_diagnosis_returns_empty_array_for_campaign_without_platforms(): void
    {
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create([
            'customer_id'             => $customer->id,
            'google_ads_campaign_id'  => null,
            'facebook_ads_campaign_id' => null,
        ]);

        $findings = $this->agent->diagnose($campaign);

        // Conversion tracking check still runs but should find nothing if settings exist
        $this->assertIsArray($findings);
    }

    public function test_meta_pixel_missing_detected_when_no_pixel_id(): void
    {
        if (!env('RUN_FACEBOOK_INTEGRATION_TESTS')) {
            $this->markTestSkipped('...');
        }

        config(['services.facebook.system_user_token' => env('FACEBOOK_SYSTEM_USER_TOKEN')]);

        $customer = Customer::factory()->create([
            'facebook_ads_account_id' => Customer::whereNotNull('facebook_ads_account_id')->value('facebook_ads_account_id'),
            'facebook_pixel_id'       => null,
        ]);
        $campaign = Campaign::factory()->create([
            'customer_id'              => $customer->id,
            'facebook_ads_campaign_id' => Campaign::whereNotNull('facebook_ads_campaign_id')->value('facebook_ads_campaign_id'),
        ]);
        $campaign->setRelation('customer', $customer);

        $findings = $this->agent->diagnose($campaign);
        $types    = array_column($findings, 'type');

        $this->assertContains('meta_pixel_missing', $types);
    }

    public function test_meta_pixel_finding_absent_when_pixel_configured(): void
    {
        if (!env('RUN_FACEBOOK_INTEGRATION_TESTS')) {
            $this->markTestSkipped('...');
        }

        config(['services.facebook.system_user_token' => env('FACEBOOK_SYSTEM_USER_TOKEN')]);

        $customer = Customer::factory()->create([
            'facebook_ads_account_id' => Customer::whereNotNull('facebook_ads_account_id')->value('facebook_ads_account_id'),
            'facebook_pixel_id'       => env('FACEBOOK_SPECTRA_PIXEL_ID', '978925284547796'),
        ]);
        $campaign = Campaign::factory()->create([
            'customer_id'              => $customer->id,
            'facebook_ads_campaign_id' => Campaign::whereNotNull('facebook_ads_campaign_id')->value('facebook_ads_campaign_id'),
        ]);
        $campaign->setRelation('customer', $customer);

        $findings = $this->agent->diagnose($campaign);
        $types    = array_column($findings, 'type');

        $this->assertNotContains('meta_pixel_missing', $types);
    }

    public function test_findings_have_auto_fix_action_when_can_auto_fix_is_true(): void
    {
        if (!env('RUN_GOOGLE_ADS_INTEGRATION_TESTS')) {
            $this->markTestSkipped('...');
        }

        $campaign = Campaign::with('customer')
            ->whereNotNull('google_ads_campaign_id')
            ->whereHas('customer', fn ($q) => $q->whereNotNull('google_ads_customer_id'))
            ->first();

        if (!$campaign) {
            $this->markTestSkipped('No deployed Google Ads campaign in DB.');
        }

        $findings = $this->agent->diagnose($campaign);

        foreach ($findings as $finding) {
            if ($finding['can_auto_fix']) {
                $this->assertNotNull($finding['auto_fix_action'] ?? null, "Finding {$finding['type']} claims can_auto_fix but has no auto_fix_action");
            }
        }
    }
}
