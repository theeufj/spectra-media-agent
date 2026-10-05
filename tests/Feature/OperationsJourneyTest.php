<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Jobs\DeployCampaign;
use App\Jobs\SyncCrmConversions;
use App\Models\AdSpendCredit;
use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\CrmIntegration;
use App\Models\Customer;
use App\Models\Keyword;
use App\Models\NegativeKeywordList;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\Strategy;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperationsJourneyTest extends TestCase
{
    use DatabaseTransactions;

    private function workspace(): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_status' => 'active']);
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890', 'google_ads_link_status' => 'managed']);
        $user->customers()->attach($customer, ['role' => 'owner']);
        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id]);
        Setting::set('deployment_enabled', true, 'boolean');
        Setting::set('managed_billing_enabled', true, 'boolean');
        BrandGuideline::create(['customer_id' => $customer->id, 'brand_voice' => ['primary_tone' => 'direct'], 'tone_attributes' => [], 'writing_patterns' => [], 'color_palette' => [], 'typography' => [], 'visual_style' => [], 'messaging_themes' => [], 'unique_selling_propositions' => [], 'target_audience' => [], 'competitor_differentiation' => [], 'brand_personality' => [], 'do_not_use' => [], 'user_verified' => true, 'profile_version' => 2, 'approved_version' => 2, 'extracted_at' => now()]);

        return [$user, $customer];
    }

    public function test_full_and_single_platform_launch_require_current_brand_approval_even_after_prior_launch(): void
    {
        [, $customer] = $this->workspace();
        Campaign::factory()->create(['customer_id' => $customer->id, 'status' => CampaignStatus::Active]);
        $customer->brandGuideline->update(['approved_version' => 1]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'signed_off_at' => now()]);
        foreach (['deployment.deploy', 'deployment.deploy-platform'] as $endpoint) {
            $this->post(route($endpoint), ['campaign_id' => $campaign->id, 'strategy_id' => $strategy->id])
                ->assertRedirect(route('brand-guidelines.index', ['review' => 1], absolute: false));
        }
        Queue::assertNotPushed(DeployCampaign::class);
        $this->assertNull($strategy->fresh()->deployment_status);
    }

    public function test_single_platform_launch_has_same_budget_gate_and_only_queues_its_target(): void
    {
        [, $customer] = $this->workspace();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'auto_generated_at' => now(), 'budget_confirmed_at' => null]);
        $target = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'signed_off_at' => now()]);
        $other = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Facebook Ads', 'signed_off_at' => now()]);
        $this->post(route('deployment.deploy-platform'), ['campaign_id' => $campaign->id, 'strategy_id' => $target->id])->assertSessionHas('flash.type', 'error');
        Queue::assertNotPushed(DeployCampaign::class);
        $campaign->update(['budget_confirmed_at' => now()]);
        $this->post(route('deployment.deploy-platform'), ['campaign_id' => $campaign->id, 'strategy_id' => $target->id])->assertRedirect(route('campaigns.deployment-status', $campaign, absolute: false));
        $this->assertSame('queued', $target->fresh()->deployment_status);
        $this->assertNull($other->fresh()->deployment_status);
        Queue::assertPushed(DeployCampaign::class, 1);
        $this->post(route('deployment.deploy'), ['campaign_id' => $campaign->id])->assertRedirect(route('campaigns.deployment-status', $campaign, absolute: false));
        Queue::assertPushed(DeployCampaign::class, 1);
    }

    public function test_paused_payment_is_actionable_before_a_deployment_job_is_queued(): void
    {
        [, $customer] = $this->workspace();
        AdSpendCredit::factory()->paused()->create(['customer_id' => $customer->id]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'signed_off_at' => now()]);
        $this->post(route('deployment.deploy-platform'), ['campaign_id' => $campaign->id, 'strategy_id' => $strategy->id])->assertRedirect(route('billing.ad-spend', absolute: false));
        Queue::assertNotPushed(DeployCampaign::class);
        $this->assertNull($strategy->fresh()->deployment_status);
    }

    public function test_funding_refuses_unreviewed_creative_before_touching_stripe(): void
    {
        [, $customer] = $this->workspace();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'daily_budget' => 20]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'signed_off_at' => now(), 'creative_review' => ['status' => 'reviewing']]);
        $this->postJson(route('billing.ad-spend.setup-for-deployment'), ['campaign_id' => $campaign->id, 'strategy_id' => $strategy->id, 'daily_budget' => 20])->assertUnprocessable()->assertJson(['success' => false]);
        $this->assertNull($customer->fresh()->adSpendCredit);
        Http::assertNothingSent();
    }

    public function test_failed_queued_deployment_retains_a_recoverable_failure_on_its_selected_strategy(): void
    {
        [, $customer] = $this->workspace();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $target = Strategy::factory()->create(['campaign_id' => $campaign->id, 'deployment_status' => 'queued']);
        $other = Strategy::factory()->create(['campaign_id' => $campaign->id, 'deployment_status' => 'deployed']);
        (new DeployCampaign($campaign, strategyId: $target->id))->failed(new \RuntimeException('worker unavailable'));
        $this->assertSame('failed', $target->fresh()->deployment_status);
        $this->assertNotEmpty($target->fresh()->deployment_error);
        $this->assertSame('deployed', $other->fresh()->deployment_status);
    }

    public function test_billing_reports_real_owner_card_and_payment_paused_without_claiming_active(): void
    {
        [$user, $customer] = $this->workspace();
        $payer = User::factory()->create(['name' => 'Business payer', 'pm_type' => 'visa', 'pm_last_four' => '4242']);
        $customer->users()->attach($payer, ['role' => 'owner']);
        AdSpendCredit::factory()->paused()->create(['customer_id' => $customer->id]);
        Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'active', 'primary_status' => 'ELIGIBLE', 'daily_budget' => 35]);
        $this->get(route('billing.ad-spend'))->assertInertia(fn (Assert $page) => $page->component('Billing/AdSpend')->where('paymentFailed', true)->where('paymentMethod.last4', '4242')->where('paymentMethod.payer_name', 'Business payer')->where('credit.daily_budget', '35.00')->where('topUp.estimated_amount', 245));
    }

    public function test_failed_crm_credentials_remain_a_validation_error_and_can_be_repaired(): void
    {
        [, $customer] = $this->workspace();
        Http::fake(['api.hubapi.com/*' => Http::sequence()->push([], 401)->push([], 200)]);
        $this->post(route('integrations.connect'), ['provider' => 'hubspot', 'access_token' => 'invalid-token'])->assertSessionHasErrors('access_token');
        $integration = CrmIntegration::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('error', $integration->status);
        $this->post(route('integrations.connect'), ['provider' => 'hubspot', 'access_token' => 'replacement-token'])->assertSessionHasNoErrors();
        $this->assertSame('connected', $integration->fresh()->status);
        $this->post(route('integrations.sync', $integration))->assertRedirect();
        $this->assertSame('syncing', $integration->fresh()->status);
        $this->post(route('integrations.sync', $integration))->assertRedirect();
        Queue::assertPushed(SyncCrmConversions::class, 1);
        $this->getJson(route('integrations.status'))->assertJsonPath('integrations.0.status', 'syncing')->assertJsonMissing(['credentials' => ['access_token' => 'replacement-token']]);
    }

    public function test_a_crm_api_failure_does_not_claim_a_successful_sync_or_advance_its_watermark(): void
    {
        [, $customer] = $this->workspace();
        $lastSync = now()->subDay()->startOfSecond();
        $integration = CrmIntegration::create(['customer_id' => $customer->id, 'provider' => 'hubspot', 'credentials' => ['access_token' => 'token'], 'status' => 'syncing', 'last_synced_at' => $lastSync]);
        Http::fake(['api.hubapi.com/*' => Http::response([], 401)]);
        (new SyncCrmConversions($integration->id))->handle();
        $this->assertSame('error', $integration->fresh()->status);
        $this->assertTrue($integration->fresh()->last_synced_at->equalTo($lastSync));
        $this->assertStringContainsString('could not be completed', $integration->fresh()->last_error);
    }

    public function test_support_history_survives_revisit_and_customer_reply_reopens_same_ticket(): void
    {
        [$user, $customer] = $this->workspace();
        $ticket = SupportTicket::create(['user_id' => $user->id, 'customer_id' => $customer->id, 'source' => 'chatbot', 'subject' => 'Billing question', 'description' => 'Where is my receipt?', 'priority' => 'normal', 'category' => 'billing', 'status' => 'open']);
        $ticket->appendMessage('customer', 'Where is my receipt?');
        $ticket->appendMessage('assistant', 'The team can help.');
        $this->getJson(route('support.chat.session'))->assertJsonPath('ticket_id', $ticket->id)->assertJsonCount(2, 'messages');
        $ticket->update(['status' => 'resolved', 'resolved_at' => now()]);
        $this->post(route('support-tickets.reply', $ticket), ['message' => 'I still need the receipt.'])->assertSessionHasNoErrors();
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->resolved_at);
        $this->assertCount(3, $ticket->fresh()->transcript);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($other)->post(route('support-tickets.reply', $ticket), ['message' => 'Not my ticket'])->assertNotFound();
        $this->assertCount(3, $ticket->fresh()->transcript);
    }

    public function test_notifications_use_server_confirmed_reads_and_delete_real_uuid_notifications(): void
    {
        [$user, $customer] = $this->workspace();
        $notification = Notification::notify($user, 'system.info', 'Saved notice', 'A notice', customer: $customer);
        $other = Notification::notify(User::factory()->create(), 'system.info', 'Private notice', 'Private');
        $this->postJson('/api/notifications/'.$other->id.'/read')->assertNotFound();
        $this->assertNull($other->fresh()->read_at);
        $this->postJson('/api/notifications/'.$notification->id.'/read')->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);
        $this->deleteJson('/api/notifications/'.$notification->id)->assertOk();
        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_invalid_budget_splits_are_not_silently_normalized(): void
    {
        [, $customer] = $this->workspace();
        $this->put(route('budget.update'), ['total_monthly_budget' => 1000, 'google_ads_pct' => 35, 'facebook_ads_pct' => 35, 'microsoft_ads_pct' => 0, 'linkedin_ads_pct' => 0, 'strategy' => 'manual', 'auto_rebalance' => false, 'rebalance_frequency' => 'weekly'])->assertSessionHasErrors('split');
        $this->assertDatabaseMissing('platform_budget_allocations', ['customer_id' => $customer->id]);
    }

    public function test_negative_list_editor_rejects_empty_terms_and_keyword_assignment_cannot_cross_workspaces(): void
    {
        [, $customer] = $this->workspace();
        $list = NegativeKeywordList::create(['customer_id' => $customer->id, 'name' => 'Wasteful searches', 'keywords' => ['free'], 'applied_to_campaigns' => []]);
        $this->put(route('keywords.negative-lists.update', $list), ['name' => 'Changed', 'keywords' => []])->assertSessionHasErrors('keywords');
        $this->assertSame('Wasteful searches', $list->fresh()->name);
        $foreignCampaign = Campaign::factory()->create();
        $this->post(route('keywords.add-to-campaign'), ['keywords' => [['text' => 'plumber', 'match_type' => 'EXACT']], 'campaign_id' => $foreignCampaign->id])->assertSessionHasErrors('campaign_id');
        $this->assertSame(0, Keyword::where('customer_id', $customer->id)->count());
    }
}
