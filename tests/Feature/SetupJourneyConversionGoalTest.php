<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Onboarding\SetupJourney;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SetupJourneyConversionGoalTest extends TestCase
{
    use DatabaseTransactions;

    private function journey(array $metadata = []): array
    {
        $customer = Customer::factory()->create(['service_type' => 'setup_only', 'setup_fee_paid_at' => now(), 'google_ads_customer_id' => '123',
            'handover_at' => now(), 'gtm_installed' => false, 'conversion_tracking_verified_at' => now()]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'paused']);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'campaign_type' => 'search',
            'google_ads_campaign_id' => 'customers/123/campaigns/456',
            'conversion_goals' => ['primary_goal' => 'SUBMIT_LEAD_FORM', 'action_resource' => 'customers/123/conversionActions/7'],
            'signed_off_at' => now(), 'deployment_status' => 'verified', 'execution_result' => ['metadata' => $metadata]]);

        return [app(SetupJourney::class)->forCustomer($customer), $campaign];
    }

    public function test_handover_links_directly_to_our_goal_workflow_instead_of_manual_google_settings(): void
    {
        [$journey, $campaign] = $this->journey(['conversion_goal_readiness' => ['ready' => false]]);
        $goal = collect($journey['checklist'])->firstWhere('title', 'Campaign conversion goal');

        $this->assertFalse($goal['done']);
        $this->assertSame('Set up goal', $goal['action_text']);
        $this->assertSame(route('campaigns.conversion-goal.edit', ['campaign' => $campaign, 'strategy' => $campaign->strategies()->sole()->uuid]), $goal['url']);
        $this->assertSame($goal['url'], $journey['campaign']['goal_setup_url']);
        $this->assertStringContainsString('We will configure', $goal['detail']);
        $this->assertStringNotContainsString('primary', $goal['detail']);
    }

    public function test_verified_goal_configuration_cannot_mark_website_installation_or_real_conversion_complete(): void
    {
        [$journey] = $this->journey($this->verifiedMetadata());
        $goal = collect($journey['checklist'])->firstWhere('title', 'Campaign conversion goal');
        $tracking = collect($journey['checklist'])->firstWhere('title', 'Conversion tracking');

        $this->assertTrue($goal['done']);
        $this->assertSame('Edit goal', $goal['action_text']);
        $this->assertStringContainsString('Website installation and a real conversion still need their own checks', $goal['detail']);
        $this->assertFalse($tracking['done']);
        $this->assertStringContainsString('Install your website tag', $tracking['detail']);
    }

    private function verifiedMetadata(string $status = 'configured'): array
    {
        $intent = ['action_resource' => 'customers/123/conversionActions/7', 'category' => 'SUBMIT_LEAD_FORM'];

        return ['setup_conversion_goal' => ['status' => $status, 'request_id' => 'current-request',
            'requested_at' => now()->subMinute()->toIso8601String(), 'action_resource' => $intent['action_resource'], 'goal' => ['objective' => 'lead']],
            'conversion_goal_readiness' => ['ready' => true, 'status' => 'ready', 'configuration_verified' => true,
                'campaign_resource' => 'customers/123/campaigns/456', 'intent' => $intent, 'checked_at' => now()->toIso8601String()],
            'google_search_baseline' => ['conversion_goal' => $intent]];
    }

    public function test_a_goal_saved_before_creation_becomes_configured_only_with_current_matching_campaign_evidence(): void
    {
        [$journey] = $this->journey($this->verifiedMetadata('waiting_for_campaign'));
        $goal = collect($journey['checklist'])->firstWhere('title', 'Campaign conversion goal');

        $this->assertTrue($goal['done']);
        $this->assertSame('Edit goal', $goal['action_text']);
        $this->assertStringContainsString('Your selected goal is configured', $goal['detail']);
    }

    public function test_saved_configuration_cannot_hide_a_new_failed_goal_check(): void
    {
        $metadata = $this->verifiedMetadata();
        $metadata['conversion_goal_readiness'] = ['ready' => false, 'status' => 'unknown'];
        [$journey] = $this->journey($metadata);
        $goal = collect($journey['checklist'])->firstWhere('title', 'Campaign conversion goal');

        $this->assertFalse($goal['done']);
        $this->assertStringContainsString('Goal setup needs attention', $goal['detail']);
    }

    public function test_goal_proof_for_a_previous_google_campaign_cannot_verify_its_replacement(): void
    {
        $metadata = $this->verifiedMetadata();
        $metadata['conversion_goal_readiness']['campaign_resource'] = 'customers/123/campaigns/789';
        [$journey] = $this->journey($metadata);
        $goal = collect($journey['checklist'])->firstWhere('title', 'Campaign conversion goal');

        $this->assertFalse($goal['done']);
        $this->assertStringContainsString('Goal setup needs attention', $goal['detail']);
    }

    public function test_a_prepared_purchase_goal_links_to_connecting_its_signal_before_installation_or_testing(): void
    {
        $metadata = $this->verifiedMetadata();
        $metadata['setup_conversion_goal']['goal'] = ['objective' => 'purchase', 'trigger' => 'prepare_only'];
        $metadata['conversion_goal_readiness']['intent']['category'] = 'PURCHASE';
        $metadata['google_search_baseline']['conversion_goal']['category'] = 'PURCHASE';
        [, $campaign] = $this->journey($metadata);
        $campaign->customer->update(['gtm_installed' => true, 'gtm_container_id' => 'GTM-LEGACY']);
        $strategy = $campaign->strategies()->sole();
        $strategy->update(['conversion_goals' => ['primary_goal' => 'PURCHASE', 'action_resource' => 'customers/123/conversionActions/7']]);
        $journey = app(SetupJourney::class)->forCustomer($campaign->customer->fresh());
        $goal = collect($journey['checklist'])->firstWhere('title', 'Campaign conversion goal');
        $tracking = collect($journey['checklist'])->firstWhere('title', 'Conversion tracking');

        $this->assertTrue($goal['done']);
        $this->assertFalse($tracking['done']);
        $this->assertSame('Connect purchase tracking', $tracking['action_text']);
        $this->assertSame(route('campaigns.conversion-goal.edit', ['campaign' => $campaign, 'strategy' => $strategy->uuid]), $tracking['url']);
        $this->assertStringContainsString('Connect a completed purchase', $tracking['detail']);
        $this->assertStringContainsString('Then install or update', $tracking['detail']);
        $this->assertStringNotContainsString('Website tag detected', $tracking['detail']);
    }

    public function test_handover_aggregates_multiple_search_goals_and_links_to_the_first_unconfigured_strategy(): void
    {
        [, $campaign] = $this->journey($this->verifiedMetadata());
        $pending = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'campaign_type' => 'search',
            'signed_off_at' => now(), 'deployment_status' => 'verified']);
        $journey = app(SetupJourney::class)->forCustomer($campaign->customer);
        $goal = collect($journey['checklist'])->firstWhere('title', 'Campaign conversion goal');

        $this->assertFalse($goal['done']);
        $this->assertStringContainsString('1 of 2 Search campaign goals configured', $goal['detail']);
        $this->assertSame(route('campaigns.conversion-goal.edit', ['campaign' => $campaign, 'strategy' => $pending->uuid]), $goal['url']);
    }

    public static function pendingConfigurations(): array
    {
        return [
            'configuring' => ['configuring', 'View goal setup', 'We are configuring'],
            'before campaign creation' => ['waiting_for_campaign', 'Edit goal', 'Configuration will finish after your paused campaign is created'],
            'failed' => ['needs_review', 'Edit goal', 'Goal setup needs attention'],
            'unavailable' => ['unknown', 'Edit goal', 'Goal setup needs attention'],
        ];
    }

    #[DataProvider('pendingConfigurations')]
    public function test_pending_goal_requests_do_not_reuse_a_previous_ready_goal(string $status, string $action, string $detail): void
    {
        [$journey] = $this->journey(['setup_conversion_goal' => ['status' => $status, 'request_id' => 'new-request'],
            'conversion_goal_readiness' => ['ready' => true, 'status' => 'ready']]);
        $goal = collect($journey['checklist'])->firstWhere('title', 'Campaign conversion goal');

        $this->assertFalse($goal['done']);
        $this->assertSame($action, $goal['action_text']);
        $this->assertStringContainsString($detail, $goal['detail']);
    }
}
