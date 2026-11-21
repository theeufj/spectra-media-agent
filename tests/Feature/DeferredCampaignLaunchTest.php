<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Jobs\DeployCampaign;
use App\Jobs\ProvisionGoogleAdsAccount;
use App\Jobs\ResumeCampaignLaunches;
use App\Jobs\SendGoogleAdsLinkInvitation;
use App\Models\AdSpendCredit;
use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\MccAccount;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Campaigns\CampaignMarketResearch;
use App\Services\Campaigns\CampaignMarketResearchWorkflow;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\Creative\CreativeRelease;
use App\Services\Deployment\CampaignLaunchIntent;
use App\Services\FacebookAds\BusinessManagerService;
use App\Services\GoogleAds\CreateAndLinkManagedAccount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeferredCampaignLaunchTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $evidence = $this->createMock(\App\Services\Conversions\CampaignOutcomeEvidence::class);
        $evidence->method('forStrategy')->willReturn(['requires_verification' => true, 'verified_for_launch' => true]);
        $this->app->instance(\App\Services\Conversions\CampaignOutcomeEvidence::class, $evidence);
        $funding = $this->createMock(\App\Services\Billing\PlatformFunding::class);
        $funding->method('assess')->willReturnCallback(function ($customer, $platform) {
            $account = $platform === 'google' ? $customer->cleanGoogleCustomerId() : (string) preg_replace('/^act_/', '', (string) $customer->facebook_ads_account_id);
            $ready = $platform === 'google' ? CampaignLaunchIntent::googleReady($customer) : $platform === 'facebook' && $account !== '' && $customer->facebook_page_id;

            return ['ready' => (bool) $ready, 'account' => $account, 'currency' => $customer->billingCurrency(),
                'payer' => $ready && $customer->google_ads_link_status === 'active' && $platform === 'google' ? 'customer' : 'agency',
                'code' => $ready ? 'ready' : 'account_pending', 'message' => $ready ? 'Ready' : 'Awaiting provider account'];
        });
        $this->app->instance(\App\Services\Billing\PlatformFunding::class, $funding);
        Setting::set('deployment_enabled', true, 'boolean');
        Setting::set('managed_billing_enabled', true, 'boolean');
        MccAccount::query()->update(['is_active' => false]);
        MccAccount::create(['name' => 'Testing Manager', 'google_customer_id' => '1112223333',
            'refresh_token' => 'local-placeholder', 'is_active' => true]);
    }

    public function test_one_platform_launch_resumes_once_after_account_creation_and_funding(): void
    {
        [$user, $campaign, $strategy, $fingerprint] = $this->reviewedCampaign();
        $other = Strategy::factory()->for($campaign)->create([
            'platform' => 'Facebook Ads', 'signed_off_at' => now(), 'daily_budget' => 20, 'campaign_type' => 'display',
        ]);
        $strategy->update(['daily_budget' => 30]);
        $fingerprint = $this->reviewSelection($strategy->fresh());
        $this->approveResearch($campaign);
        $customer = $campaign->customer;
        $create = $this->createMock(CreateAndLinkManagedAccount::class);
        $create->expects($this->once())->method('__invoke')->with('1112223333', $customer->name, $customer->currency_code, $customer->timezone)
            ->willReturn(['customer_id' => '4445556666']);
        $this->app->instance(CreateAndLinkManagedAccount::class, $create);
        $this->mock(\App\Services\Billing\AdSpendPaymentGateway::class)->shouldNotReceive('collect');

        $request = ['campaign_id' => $campaign->id, 'strategy_id' => $strategy->id,
            'creative_fingerprints' => [$strategy->id => $fingerprint]];
        $this->actingAs($user)->post(route('deployment.deploy-platform'), $request)->assertRedirect();
        $intent = $strategy->fresh()->launch_intent;
        $this->assertNotNull($intent);
        $this->assertSame(CampaignLaunchIntent::WAITING, $strategy->fresh()->deployment_status);
        $this->assertSame(CampaignStatus::PendingAdminDeployment, $campaign->fresh()->status);

        $this->post(route('deployment.deploy-platform'), $request)->assertRedirect();
        $this->assertSame($intent['id'], $strategy->fresh()->launch_intent['id']);
        Queue::assertPushed(ProvisionGoogleAdsAccount::class, 1);
        Queue::assertNotPushed(DeployCampaign::class);

        (new ProvisionGoogleAdsAccount($customer))->handle();
        $this->assertSame('managed', $customer->fresh()->google_ads_link_status);
        $this->assertFalse($customer->fresh()->isSelfFundedAds());
        Queue::assertNotPushed(SendGoogleAdsLinkInvitation::class);
        Queue::assertPushed(ResumeCampaignLaunches::class, fn (ResumeCampaignLaunches $job) => $job->customerId === $customer->id);
        $launch = app(CampaignLaunchIntent::class);
        (new ResumeCampaignLaunches($customer->id))->handle($launch);
        Queue::assertNotPushed(DeployCampaign::class);
        $this->assertNull($customer->adSpendCredit);

        AdSpendCredit::factory()->for($customer)->create();
        (new ResumeCampaignLaunches($customer->id))->handle($launch);
        (new ResumeCampaignLaunches)->handle($launch);
        Queue::assertPushed(DeployCampaign::class, 1);
        $this->assertSame('queued', $strategy->fresh()->deployment_status);
        $this->assertNull($other->fresh()->launch_intent);
        $this->assertNull($other->fresh()->deployment_status);
        app(CampaignMarketResearchWorkflow::class)->assertApproved($campaign->fresh());
        $this->assertSame([$strategy->id], $launch->claim($campaign, $intent['id'])->modelKeys());
        $this->assertTrue($launch->claim($campaign, $intent['id'])->isEmpty());
    }

    public function test_changed_creative_or_changed_budget_cannot_reuse_a_waiting_approval(): void
    {
        foreach (['creative', 'budget'] as $index => $change) {
            [$user, $campaign, $strategy, $fingerprint] = $this->reviewedCampaign();
            $this->requestLaunch($user, $campaign, $strategy, $fingerprint);
            if ($change === 'creative') {
                $strategy->adCopies()->firstOrFail()->update(['headlines' => ['Edited after consent']]);
            } else {
                $campaign->update(['daily_budget' => 70]);
            }
            $campaign->customer->update(['google_ads_customer_id' => '444555666'.$index, 'google_ads_manager_customer_id' => '1112223333']);
            AdSpendCredit::factory()->for($campaign->customer)->create();
            (new ResumeCampaignLaunches)->handle(app(CampaignLaunchIntent::class));

            $this->assertSame('failed', $strategy->fresh()->deployment_status);
            $this->assertSame(CampaignStatus::Draft, $campaign->fresh()->status);
        }
        Queue::assertNotPushed(DeployCampaign::class);
    }

    public function test_accepted_manager_link_must_target_the_exact_reviewed_account(): void
    {
        foreach ([false, true] as $index => $swapAccount) {
            $account = '444555666'.$index;
            [$user, $campaign, $strategy, $fingerprint] = $this->reviewedCampaign([
                'google_ads_customer_id' => $account, 'google_ads_link_status' => 'pending',
            ]);
            $this->requestLaunch($user, $campaign, $strategy, $fingerprint);
            $campaign->customer->update(['google_ads_customer_id' => $swapAccount ? '9995556666' : $account,
                'google_ads_link_status' => 'active', 'google_ads_manager_customer_id' => '1112223333']);

            (new ResumeCampaignLaunches($campaign->customer_id))->handle(app(CampaignLaunchIntent::class));

            $this->assertSame($swapAccount ? 'failed' : 'queued', $strategy->fresh()->deployment_status);
        }
        Queue::assertPushed(DeployCampaign::class, 1);
    }

    public function test_released_funding_and_removed_approver_are_rechecked_after_queueing(): void
    {
        [$user, $campaign, $strategy, $fingerprint] = $this->reviewedCampaign([
            'google_ads_customer_id' => '4445556666', 'google_ads_manager_customer_id' => '1112223333',
        ]);
        $credit = AdSpendCredit::factory()->for($campaign->customer)->create();
        $this->requestLaunch($user, $campaign, $strategy, $fingerprint);
        $launch = app(CampaignLaunchIntent::class);
        $id = $strategy->fresh()->launch_intent['id'];
        $credit->update(['current_balance' => 0]);
        $this->assertTrue($launch->claim($campaign, $id)->isEmpty());
        $this->assertSame(CampaignLaunchIntent::WAITING, $strategy->fresh()->deployment_status);
        $this->assertFalse($launch->canPublish($strategy));

        $campaign->customer->users()->detach($user);
        $credit->update(['current_balance' => 350]);
        (new ResumeCampaignLaunches)->handle($launch);
        $this->assertSame('failed', $strategy->fresh()->deployment_status);
        Queue::assertPushed(DeployCampaign::class, 1);
    }

    public function test_setup_only_can_create_after_account_provisioning_but_remains_ineligible_for_activation(): void
    {
        [$user, $campaign, $strategy, $fingerprint] = $this->reviewedCampaign(['service_type' => 'setup_only', 'setup_fee_paid_at' => now()]);
        $this->requestLaunch($user, $campaign, $strategy, $fingerprint);
        $campaign->customer->update(['google_ads_customer_id' => '4445556666', 'google_ads_manager_customer_id' => '1112223333']);
        (new ResumeCampaignLaunches)->handle(app(CampaignLaunchIntent::class));
        Queue::assertPushed(DeployCampaign::class, 1);
        $campaign->update(['status' => CampaignStatus::Active]);
        $this->assertFalse(CampaignSpendGuardrails::canEnable($campaign->fresh()));
    }

    public function test_one_approval_lets_a_funded_google_leg_launch_while_unready_siblings_wait(): void
    {
        [$user, $campaign, $google, $fingerprint] = $this->reviewedCampaign([
            'google_ads_customer_id' => '4445556666', 'google_ads_manager_customer_id' => '1112223333',
            'facebook_ads_account_id' => 'act_111244',
            'microsoft_ads_customer_id' => '1234', 'microsoft_ads_account_id' => '5689',
        ]);
        $google->update(['daily_budget' => 20]);
        $campaign->update(['daily_budget' => 65, 'approved_daily_budget' => 65]);
        $fingerprints = [$google->id => $this->reviewSelection($google->fresh())];
        foreach (['Facebook Ads' => 'display', 'Microsoft Ads (Bing)' => 'search', 'LinkedIn Ads' => 'display'] as $platform => $type) {
            $strategy = Strategy::factory()->for($campaign)->create(['platform' => $platform, 'campaign_type' => $type,
                'signed_off_at' => now(), 'daily_budget' => 15]);
            $fingerprints[$strategy->id] = $this->reviewSelection($strategy);
        }
        $this->approveResearch($campaign);
        AdSpendCredit::factory()->for($campaign->customer)->create();

        $this->actingAs($user)->post(route('deployment.deploy'), [
            'campaign_id' => $campaign->id, 'creative_fingerprints' => $fingerprints,
        ])->assertRedirect();
        $strategies = $campaign->strategies()->orderBy('id')->get();
        $this->assertCount(4, $strategies);
        $this->assertSame('queued', $strategies->first()->deployment_status);
        $this->assertSame([CampaignLaunchIntent::WAITING], $strategies->skip(1)->pluck('deployment_status')->unique()->values()->all());
        $this->assertCount(1, $strategies->map(fn ($s) => $s->launch_intent['approval_id'])->unique());
        $this->assertCount(4, $strategies->map(fn ($s) => $s->launch_intent['id'])->unique());
        Queue::assertPushed(DeployCampaign::class, 1);
        $launch = app(CampaignLaunchIntent::class);
        $this->assertSame([$google->id], $launch->claim($campaign, $strategies->first()->launch_intent['id'])->modelKeys());
    }

    public function test_existing_approval_resumes_after_meta_billing_recovers(): void
    {
        [$user, $campaign, $google] = $this->reviewedCampaign([
            'facebook_ads_account_id' => '111244', 'facebook_page_id' => '476224', 'facebook_bm_owned' => true,
        ]);
        $google->update(['daily_budget' => 30]);
        $meta = Strategy::factory()->for($campaign)->create(['platform' => 'Facebook Ads', 'campaign_type' => 'display',
            'signed_off_at' => now(), 'daily_budget' => 20]);
        $fingerprint = $this->reviewSelection($meta);
        $this->approveResearch($campaign);
        AdSpendCredit::factory()->for($campaign->customer)->create();
        $this->requestLaunch($user, $campaign, $meta, $fingerprint);
        $id = $meta->fresh()->launch_intent['id'];
        $issue = ['code' => 'facebook_account_unsettled', 'message' => 'Meta billing needs attention.'];
        $business = $this->createMock(BusinessManagerService::class);
        $business->expects($this->once())->method('launchAccountIssue')->with('111244')->willReturn($issue);
        $this->app->instance(BusinessManagerService::class, $business);

        $job = new DeployCampaign($campaign, strategyId: $meta->id, launchId: $id);
        $job->handle(app(\App\Services\AdSpendBillingService::class));
        (new \Illuminate\Bus\UniqueLock(app('cache')->store()))->release($job);
        $launch = app(CampaignLaunchIntent::class);
        $this->assertSame(CampaignLaunchIntent::WAITING, $meta->fresh()->deployment_status);
        $this->assertSame($issue['message'], $meta->fresh()->deployment_error);
        $ready = ['ready' => true, 'account' => '111244', 'currency' => $campaign->customer->billingCurrency(), 'payer' => 'agency', 'code' => 'ready', 'message' => 'Ready'];
        $this->mock(\App\Services\Billing\PlatformFunding::class)->shouldReceive('assess')
            ->andReturn([...$ready, ...$issue, 'ready' => false], $ready);
        $this->assertFalse($launch->resume($campaign->id, $id));
        Queue::assertPushed(DeployCampaign::class, 1);

        $this->assertTrue($launch->resume($campaign->id, $id));
        $this->assertSame($id, $meta->fresh()->launch_intent['id']);
        $this->assertNull($meta->fresh()->deployment_error);
        Queue::assertPushed(DeployCampaign::class, 2);
    }

    private function requestLaunch(User $user, Campaign $campaign, Strategy $strategy, string $fingerprint): void
    {
        $this->actingAs($user)->post(route('deployment.deploy-platform'), [
            'campaign_id' => $campaign->id,
            'strategy_id' => $strategy->id,
            'creative_fingerprints' => [$strategy->id => $fingerprint],
        ])->assertRedirect();
    }

    private function reviewedCampaign(array $attributes = []): array
    {
        auth()->forgetGuards();
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_status' => 'active']);
        $plan = Plan::firstOrCreate(['slug' => 'growth'], ['name' => 'Growth', 'price_cents' => 10000, 'billing_interval' => 'month']);
        $customer = Customer::factory()->create(['plan_id' => $plan->id, 'currency_code' => 'USD', ...$attributes]);
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        session(['active_customer_id' => $customer->id]);
        $brand = new BrandGuideline(['customer_id' => $customer->id, 'extracted_at' => now(),
            'brand_voice' => ['primary_tone' => 'direct'], 'tone_attributes' => ['direct'],
            'target_audience' => ['primary' => 'Homeowners'], 'competitor_differentiation' => ['Clear pricing'],
            'messaging_themes' => ['Service'], 'unique_selling_propositions' => ['Price transparency'],
            'do_not_use' => ['Jargon'], 'color_palette' => ['primary_colors' => ['#111111']],
            'typography' => ['heading_style' => 'sans'], 'visual_style' => ['overall_aesthetic' => 'modern'],
            'writing_patterns' => ['sentence_length' => 'varied'], 'brand_personality' => ['archetype' => 'Everyman'],
            'service_lines' => [['name' => 'Service']], 'extraction_quality_score' => 90,
        ]);
        $brand->forceFill(['user_verified' => true])->save();
        $campaign = Campaign::factory()->for($customer)->create(['daily_budget' => 50, 'approved_daily_budget' => 50,
            'budget_confirmed_at' => now(), 'status' => CampaignStatus::Draft]);
        $strategy = Strategy::factory()->for($campaign)->create(['platform' => 'Google Ads (SEM)', 'campaign_type' => 'search',
            'daily_budget' => 50, 'signed_off_at' => now()]);
        $fingerprint = $this->reviewSelection($strategy);
        $this->approveResearch($campaign);

        return [$user, $campaign, $strategy, $fingerprint];
    }

    private function reviewSelection(Strategy $strategy): string
    {
        $strategy->adCopies()->create(['platform' => $strategy->platform, 'headlines' => ['Reviewed ad'], 'descriptions' => ['Reviewed description'], 'should_deploy' => true]);
        $fingerprint = app(CreativeRelease::class)->selection($strategy)['fingerprint'];
        $strategy->forceFill(['creative_release_review' => ['status' => 'passed', 'fingerprint' => $fingerprint, 'issues' => []]])->save();

        return $fingerprint;
    }

    private function approveResearch(Campaign $campaign): void
    {
        $campaign->update(['search_delivery_state' => ['market_research' => ['status' => 'ready',
            'request_id' => 'research-1', 'approved_request_id' => 'research-1', 'result' => ['status' => 'ready'],
            'approved_fingerprint' => app(CampaignMarketResearch::class)->approvalFingerprint($campaign)]]]);
    }
}
