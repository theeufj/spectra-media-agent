<?php

namespace Tests\Feature;

use App\Jobs\CheckCampaignPolicyViolations;
use App\Jobs\Scheduled\DispatchCampaignPolicyViolationChecks;
use App\Models\AdSpendCredit;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\CriticalAgentAlert;
use App\Services\Agents\CampaignAlertService;
use App\Services\Agents\SelfHealingAgent;
use App\Services\Customers\DeactivateCustomerService;
use Google\Ads\GoogleAds\V22\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V22\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V22\Enums\PolicyApprovalStatusEnum\PolicyApprovalStatus;
use Google\Ads\GoogleAds\V22\Enums\PolicyReviewStatusEnum\PolicyReviewStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignPolicyVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(string $status = 'paused', string $serviceType = 'setup_only'): array
    {
        NotificationFacade::fake();
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890', 'service_type' => $serviceType]);
        $user = User::factory()->create();
        $customer->users()->attach($user, ['role' => 'owner']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => $status, 'platform_status' => $status === 'paused' ? 'PAUSED' : 'ENABLED', 'primary_status' => $status === 'paused' ? 'PAUSED' : 'NOT_ELIGIBLE', 'end_date' => now()->addMonth(), 'google_ads_campaign_id' => 'customers/1234567890/campaigns/24282121420']);
        $deactivator = $this->createMock(DeactivateCustomerService::class);
        $deactivator->expects($this->never())->method('pauseCampaign');
        $this->app->instance(DeactivateCustomerService::class, $deactivator);

        return [$campaign, $user];
    }

    private function ad(int $approval = PolicyApprovalStatus::DISAPPROVED, int $review = PolicyReviewStatus::REVIEWED): array
    {
        return ['resource_name' => 'customers/1234567890/adGroupAds/1~2', 'ad_group_resource_name' => 'customers/1234567890/adGroups/1', 'status' => AdGroupAdStatus::ENABLED, 'ad_group_status' => AdGroupStatus::ENABLED, 'approval_status' => $approval, 'review_status' => $review, 'final_urls' => ['https://example.com/landing'], 'policy_topics' => $approval === PolicyApprovalStatus::DISAPPROVED ? [['topic' => 'DESTINATION_NOT_WORKING', 'type' => 2, 'evidences' => [['type' => 'destination_not_working', 'expanded_url' => 'https://example.com/landing', 'device' => 'DESKTOP', 'device_code' => 2, 'http_error_code' => 522, 'last_checked_at' => '2026-10-03 10:00:00']]]] : []];
    }

    private function runCheck(Campaign $campaign, array $ads, ?\Throwable $error = null): void
    {
        $job = new class($campaign->id, $ads, $error) extends CheckCampaignPolicyViolations
        {
            public function __construct(int $campaignId, private array $ads, private ?\Throwable $error)
            {
                parent::__construct($campaignId);
            }

            protected function googleAds(Campaign $campaign, string $campaignResourceName): array
            {
                if ($this->error) {
                    throw $this->error;
                }

                return $this->ads;
            }
        };
        $healer = $this->createMock(SelfHealingAgent::class);
        $healer->expects($this->never())->method('heal');
        $job->handle($healer);
        $campaign->refresh();
    }

    public function test_paused_setup_campaign_records_destination_evidence_and_alerts_without_mutating_spend(): void
    {
        [$campaign, $user] = $this->campaign();
        $credit = AdSpendCredit::factory()->create(['customer_id' => $campaign->customer_id, 'current_balance' => 180]);
        (new DispatchCampaignPolicyViolationChecks)->handle();
        Queue::assertPushed(CheckCampaignPolicyViolations::class);
        $this->runCheck($campaign, [$this->ad()]);
        $state = CampaignAlertService::policyStatus($campaign);
        $this->assertSame('issues', $state['status']);
        $this->assertSame('needs_website_repair', $state['repair_status']);
        $this->assertSame(522, $state['issues'][0]['policy_topics'][0]['evidences'][0]['http_error_code']);
        $this->assertSame('DESKTOP', $state['issues'][0]['policy_topics'][0]['evidences'][0]['device']);
        $this->assertSame(['https://example.com/landing'], $state['issues'][0]['final_urls']);
        $this->assertSame('paused', $campaign->status->value);
        $this->assertSame('PAUSED', $campaign->platform_status);
        $this->assertEquals(180, $credit->fresh()->current_balance);
        $notice = Notification::where('user_id', $user->id)->where('type', 'policy_disapproved')->sole();
        $this->assertStringContainsString('HTTP 522', $notice->message);
        $this->assertStringContainsString('DESKTOP', $notice->message);
        $this->assertSame(route('campaigns.show', $campaign), $notice->action_url);
        NotificationFacade::assertSentTo($user, CriticalAgentAlert::class, fn ($alert) => $alert->alertType === 'policy_disapproved');
        $alert = NotificationFacade::sent($user, CriticalAgentAlert::class)->first();
        $mail = implode(' ', $alert->toMail($user)->introLines);
        $this->assertStringContainsString('Reported issues:', $mail);
        $this->assertStringContainsString('HTTP 522', $mail);
        $this->assertStringNotContainsString('what we fixed', $mail);
        Http::assertNothingSent();
    }

    public function test_same_issue_is_deduped_but_verified_recovery_then_recurrence_alerts_again(): void
    {
        [$campaign, $user] = $this->campaign('paused', 'managed');
        $this->runCheck($campaign, [$this->ad()]);
        $first = CampaignAlertService::policyStatus($campaign)['platforms']['google_ads']['incident_id'];
        $ad = $this->ad();
        $ad['policy_topics'][0]['evidences'][0]['last_checked_at'] = '2026-10-04 10:00:00';
        $this->runCheck($campaign, [$ad]);
        $this->assertSame(1, Notification::where('user_id', $user->id)->count());
        NotificationFacade::assertSentToTimes($user, CriticalAgentAlert::class, 1);
        $this->runCheck($campaign, [$this->ad(PolicyApprovalStatus::APPROVED)]);
        $this->assertSame('clear', CampaignAlertService::policyStatus($campaign)['status']);
        $this->assertNotNull(CampaignAlertService::policyStatus($campaign)['last_incident']['resolved_at']);
        $this->runCheck($campaign, [$this->ad()]);
        $this->assertNotSame($first, CampaignAlertService::policyStatus($campaign)['platforms']['google_ads']['incident_id']);
        $this->assertSame(3, Notification::where('user_id', $user->id)->count());
        NotificationFacade::assertSentToTimes($user, CriticalAgentAlert::class, 3);
        $alerts = NotificationFacade::sent($user, CriticalAgentAlert::class)->filter(fn ($alert) => $alert->alertType === 'policy_disapproved')->values();
        foreach ($alerts as $alert) {
            NotificationFacade::assertSentTo($user, CriticalAgentAlert::class, fn ($sent, $channels) => $sent->details['dedupe_key'] === $alert->details['dedupe_key'] && $channels === ['mail']);
            $this->assertSame([], $alert->via($user), 'An already delivered incident remains on cooldown.');
        }
    }

    public function test_api_failure_is_unknown_retains_evidence_and_is_reported_then_pending_review_cannot_clear_it(): void
    {
        [$campaign, $user] = $this->campaign();
        $this->runCheck($campaign, [$this->ad()]);
        $verifiedAt = CampaignAlertService::policyStatus($campaign)['last_successful_checked_at'];
        $reported = [];
        $handler = $this->createMock(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $handler->expects($this->once())->method('report')->willReturnCallback(function (\Throwable $e) use (&$reported) {
            $reported[] = $e;
        });
        $this->app->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, $handler);
        $this->travel(1)->hours();
        $this->runCheck($campaign, [], new \RuntimeException('Google transport failed'));
        $state = CampaignAlertService::policyStatus($campaign);
        $this->assertSame('unknown', $state['status']);
        $this->assertSame($verifiedAt, $state['last_successful_checked_at']);
        $this->assertSame(522, $state['issues'][0]['policy_topics'][0]['evidences'][0]['http_error_code']);
        $this->assertCount(1, $reported);
        $this->assertSame('Google transport failed', $reported[0]->getMessage());
        $this->runCheck($campaign, [$this->ad(PolicyApprovalStatus::UNKNOWN, PolicyReviewStatus::REVIEW_IN_PROGRESS)]);
        $this->assertSame('unknown', CampaignAlertService::policyStatus($campaign)['status']);
        $this->runCheck($campaign, []);
        $this->assertSame('unknown', CampaignAlertService::policyStatus($campaign)['status']);
        $this->assertCount(1, CampaignAlertService::policyStatus($campaign)['issues']);
        $this->assertSame(0, Notification::where('user_id', $user->id)->where('type', 'policy_recovered')->count());
    }

    public function test_setup_only_policy_read_does_not_mutate_an_enabled_campaign(): void
    {
        [$campaign] = $this->campaign('active', 'setup_only');
        $this->runCheck($campaign, [$this->ad()]);
        $this->assertSame('issues', CampaignAlertService::policyStatus($campaign)['status']);
        $this->assertSame('active', $campaign->status->value);
        $this->assertSame('ENABLED', $campaign->platform_status);
    }

    public function test_initial_empty_and_pending_policy_reads_are_unknown_not_clear(): void
    {
        [$campaign] = $this->campaign();
        $this->runCheck($campaign, []);
        $this->assertSame('unknown', CampaignAlertService::policyStatus($campaign)['status']);
        $this->runCheck($campaign, [$this->ad(PolicyApprovalStatus::UNKNOWN, PolicyReviewStatus::REVIEW_IN_PROGRESS)]);
        $this->assertSame('unknown', CampaignAlertService::policyStatus($campaign)['status']);
        $this->assertStringContainsString('awaiting review', CampaignAlertService::policyStatus($campaign)['platforms']['google_ads']['error']);
    }

    public function test_facebook_missing_or_pending_ad_does_not_clear_previous_rejection(): void
    {
        [$campaign] = $this->campaign();
        $campaign->update(['google_ads_campaign_id' => null, 'facebook_ads_campaign_id' => 'fb_campaign']);
        $campaign->customer->update(['facebook_ads_account_id' => 'fb_account']);
        foreach ([['DISAPPROVED'], [], ['PENDING_REVIEW']] as $statuses) {
            $ads = array_map(fn ($status) => ['id' => 'fb_ad', 'name' => 'Rejected ad', 'effective_status' => $status], $statuses);
            $job = new class($campaign->id, $ads) extends CheckCampaignPolicyViolations
            {
                public function __construct(int $campaignId, private array $ads)
                {
                    parent::__construct($campaignId);
                }

                protected function facebookAds(Customer $customer, string $accountId, array $filters): array
                {
                    return $this->ads;
                }
            };
            $healer = $this->createMock(SelfHealingAgent::class);
            $healer->expects($this->never())->method('heal');
            $job->handle($healer);
            $campaign->refresh();
            $this->assertSame($statuses === ['DISAPPROVED'] ? 'issues' : 'unknown', CampaignAlertService::policyStatus($campaign)['status']);
            $this->assertCount(1, CampaignAlertService::policyStatus($campaign)['issues']);
        }
        $this->assertSame(0, Notification::where('customer_id', $campaign->customer_id)->where('type', 'policy_recovered')->count());
    }

    public function test_unexpected_job_failure_is_rethrown_for_queue_retry(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        (new CheckCampaignPolicyViolations(PHP_INT_MAX))->handle($this->createMock(SelfHealingAgent::class));
    }

    public function test_deleted_customer_is_not_queued_and_an_existing_check_retains_unknown_evidence(): void
    {
        [$campaign, $user] = $this->campaign();
        $this->runCheck($campaign, [$this->ad()]);
        $verifiedAt = CampaignAlertService::policyStatus($campaign)['last_successful_checked_at'];
        // Bypass the deletion observer: this exercises a check already queued
        // before the customer disappeared, without invoking remote ad APIs.
        \Illuminate\Support\Facades\DB::table('customers')->where('id', $campaign->customer_id)->update(['deleted_at' => now()]);
        $campaign->unsetRelation('customer');

        Queue::fake();
        (new DispatchCampaignPolicyViolationChecks)->handle();
        Queue::assertNotPushed(CheckCampaignPolicyViolations::class, fn ($job) => (new \ReflectionProperty($job, 'campaignId'))->getValue($job) === $campaign->id);

        $handler = $this->createMock(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $handler->expects($this->once())->method('report')->with($this->callback(fn ($error) => str_contains($error->getMessage(), 'no active customer')));
        $this->app->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, $handler);
        $job = new class($campaign->id) extends CheckCampaignPolicyViolations
        {
            protected function googleAds(Campaign $campaign, string $campaignResourceName): array
            {
                throw new \LogicException('A deleted customer must never call the ad API.');
            }
        };
        $healer = $this->createMock(SelfHealingAgent::class);
        $healer->expects($this->never())->method('heal');
        $job->handle($healer);

        $state = CampaignAlertService::policyStatus($campaign->fresh());
        $this->assertSame('unknown', $state['status']);
        $this->assertSame($verifiedAt, $state['last_successful_checked_at']);
        $this->assertCount(1, $state['issues']);
        $this->assertSame(1, Notification::where('user_id', $user->id)->count());
        NotificationFacade::assertSentToTimes($user, CriticalAgentAlert::class, 1);
        Http::assertNothingSent();
    }

    public function test_all_google_ads_rejected_for_destination_errors_alert_without_pausing_or_copy_healing(): void
    {
        [$campaign, $user] = $this->campaign('active', 'managed');
        $credit = AdSpendCredit::factory()->create(['customer_id' => $campaign->customer_id, 'current_balance' => 180]);
        $secondAd = $this->ad();
        $secondAd['resource_name'] = 'customers/1234567890/adGroupAds/1~3';
        $this->runCheck($campaign, [$this->ad(), $secondAd]);
        $this->assertSame('active', $campaign->status->value);
        $this->assertSame('ENABLED', $campaign->platform_status);
        $this->assertEquals(180, $credit->fresh()->current_balance);
        $this->assertSame('issues', CampaignAlertService::policyStatus($campaign)['status']);
        $this->assertCount(2, CampaignAlertService::policyStatus($campaign)['issues']);
        $this->assertSame(1, Notification::where('user_id', $user->id)->where('type', 'policy_disapproved')->count());
        NotificationFacade::assertSentTo($user, CriticalAgentAlert::class, fn ($alert, $channels) => $alert->alertType === 'policy_disapproved' && $channels === ['mail']);
        $this->assertDatabaseMissing('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'policy_paused_campaign']);
        Http::assertNothingSent();
    }

    public function test_all_facebook_ads_rejected_alert_without_pausing_and_allow_copy_healing(): void
    {
        [$campaign, $user] = $this->campaign('active', 'managed');
        $campaign->update(['google_ads_campaign_id' => null, 'facebook_ads_campaign_id' => 'fb_campaign']);
        $campaign->customer->update(['facebook_ads_account_id' => 'fb_account']);
        $credit = AdSpendCredit::factory()->create(['customer_id' => $campaign->customer_id, 'current_balance' => 180]);
        $ads = [
            ['id' => 'fb_ad_1', 'name' => 'First rejected ad', 'effective_status' => 'DISAPPROVED'],
            ['id' => 'fb_ad_2', 'name' => 'Second rejected ad', 'effective_status' => 'DISAPPROVED'],
        ];
        $job = new class($campaign->id, $ads) extends CheckCampaignPolicyViolations
        {
            public function __construct(int $campaignId, private array $ads)
            {
                parent::__construct($campaignId);
            }

            protected function facebookAds(Customer $customer, string $accountId, array $filters): array
            {
                return $this->ads;
            }
        };
        $healer = $this->createMock(SelfHealingAgent::class);
        $healer->expects($this->once())->method('heal')
            ->with($this->callback(fn (Campaign $target) => $target->id === $campaign->id && $target->status->value === 'active'));
        $job->handle($healer);
        $campaign->refresh();

        $this->assertSame('active', $campaign->status->value);
        $this->assertSame('ENABLED', $campaign->platform_status);
        $this->assertEquals(180, $credit->fresh()->current_balance);
        $this->assertSame('issues', CampaignAlertService::policyStatus($campaign)['status']);
        $this->assertCount(2, CampaignAlertService::policyStatus($campaign)['issues']);
        $this->assertSame(1, Notification::where('user_id', $user->id)->where('type', 'policy_disapproved')->count());
        NotificationFacade::assertSentTo($user, CriticalAgentAlert::class, fn ($alert, $channels) => $alert->alertType === 'policy_disapproved' && $channels === ['mail']);
        $this->assertDatabaseMissing('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'policy_paused_campaign']);
        Http::assertNothingSent();
    }
}
