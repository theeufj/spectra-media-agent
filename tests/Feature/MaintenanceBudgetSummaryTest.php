<?php

namespace Tests\Feature;

use App\Jobs\AutomatedCampaignMaintenance;
use App\Models\AgentActivity;
use App\Models\AgentRun;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\MaintenanceSummaryNotification;
use App\Services\Agents\AdExtensionAgent;
use App\Services\Agents\AudienceIntelligenceAgent;
use App\Services\Agents\BidAdjustmentAgent;
use App\Services\Agents\BudgetIntelligenceAgent;
use App\Services\Agents\CreativeIntelligenceAgent;
use App\Services\Agents\FacebookAdRelevanceDiagnosticsAgent;
use App\Services\Agents\FacebookLearningPhaseAgent;
use App\Services\Agents\LinkedInCampaignOptimizationAgent;
use App\Services\Agents\QualityScoreImprovementAgent;
use App\Services\Agents\SearchTermMiningAgent;
use App\Services\Agents\SelfHealingAgent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MaintenanceBudgetSummaryTest extends TestCase
{
    use DatabaseTransactions;

    private function receipt(array $overrides = []): array
    {
        return array_replace([
            'type' => 'budget_updated',
            'verified' => true,
            'platform' => 'google',
            'resource_name' => 'customers/123/campaigns/456',
            'old_budget_micros' => 50_000_000,
            'new_budget_micros' => 60_000_000,
            'currency' => 'AUD',
            'reason' => 'More conversions during this hour of the day justify the increase.',
        ], $overrides);
    }

    private function notification(array $changes, array $extra = []): MaintenanceSummaryNotification
    {
        return new MaintenanceSummaryNotification(Customer::factory()->create(), [
            'Summer campaign' => array_replace([
                'total_changes' => count($changes),
                'budget_adjustments' => count($changes),
                'budget_changes' => $changes,
            ], $extra),
        ], 1);
    }

    public function test_same_value_receipts_and_legacy_count_only_summaries_do_not_send_mail(): void
    {
        $user = User::factory()->create();
        $notification = $this->notification([$this->receipt(['new_budget_micros' => 50_000_000])]);
        $this->assertSame([], $notification->via($user));
        $this->assertSame([], $notification->toArray($user)['changes']);

        $legacy = $this->notification([], ['total_changes' => 1, 'budget_adjustments' => 1]);
        // Queued notifications are revalidated at delivery, not only construction.
        $legacy = unserialize(serialize($legacy));
        $this->assertSame([], $legacy->via($user));
        $this->assertStringNotContainsString('budget adjustment', $legacy->toMail($user)->render());
        $this->mock(MailChannel::class)->shouldNotReceive('send');
        // Simulate a job queued by the previous code with its mail channel already chosen.
        $queued = unserialize(serialize(new SendQueuedNotifications(collect([$user]), $legacy, ['mail'])));
        $queued->handle(app(ChannelManager::class));
    }

    public function test_mail_shows_confirmed_increases_and_decreases_with_currency_and_reason(): void
    {
        $user = User::factory()->create();
        $notification = $this->notification([
            $this->receipt(),
            $this->receipt(['platform' => 'facebook', 'resource_name' => '789',
                'old_budget_micros' => 30_000_000, 'new_budget_micros' => 20_000_000,
                'currency' => 'USD', 'reason' => 'Lower demand on this day of the week.']),
        ]);

        $this->assertSame(['mail'], $notification->via($user));
        $mail = $notification->toMail($user);
        $this->assertSame('2 change(s) made across your campaigns today', $mail->subject);
        $html = $mail->render();
        $this->assertStringContainsString('Google Ads budget:', $html);
        $this->assertStringContainsString('AUD 50.00/day → AUD 60.00/day', $html);
        $this->assertStringContainsString('More conversions during this hour of the day justify the increase.', $html);
        $this->assertStringContainsString('Facebook Ads budget:', $html);
        $this->assertStringContainsString('USD 30.00/day → USD 20.00/day', $html);
        $this->assertStringContainsString('Lower demand on this day of the week.', $html);
        $this->assertStringNotContainsString('While you slept', $html);
        $this->assertStringNotContainsString('to improve campaign performance', $html);
    }

    public function test_unverified_failed_or_incomplete_budget_receipts_are_excluded(): void
    {
        $user = User::factory()->create();
        foreach ([
            ['verified' => false], ['verified' => null], ['type' => 'budget_update_failed'],
            ['old_budget_micros' => null], ['old_budget_micros' => '50000000'],
            ['new_budget_micros' => null], ['new_budget_micros' => -1],
            ['currency' => ''], ['reason' => ''], ['resource_name' => ''], ['platform' => 'unknown'],
        ] as $invalid) {
            $notification = $this->notification([$this->receipt($invalid)]);
            $this->assertSame([], $notification->via($user), json_encode($invalid));
        }
    }

    public function test_other_changes_still_send_without_no_op_or_legacy_budget_claims(): void
    {
        $user = User::factory()->create();
        $notification = $this->notification([$this->receipt(['new_budget_micros' => 50_000_000])], [
            'total_changes' => 9, 'budget_adjustments' => 7, 'keywords_added' => 2,
        ]);
        $this->assertSame(['mail'], $notification->via($user));
        $mail = $notification->toMail($user);
        $this->assertSame('2 change(s) made across your campaigns today', $mail->subject);
        $html = $mail->render();
        $this->assertStringContainsString('Added 2 new keyword(s)', $html);
        $this->assertStringNotContainsString('budget adjustment', $html);
        $this->assertStringNotContainsString('Google Ads budget:', $html);
    }

    public function test_mail_counts_only_campaigns_that_actually_changed(): void
    {
        $notification = new MaintenanceSummaryNotification(Customer::factory()->create(), [
            'Unchanged' => ['total_changes' => 1, 'budget_adjustments' => 1],
            'Changed' => ['total_changes' => 1, 'keywords_added' => 1],
        ], 2);
        $html = $notification->toMail(User::factory()->create())->render();
        $this->assertStringContainsString('across 1 campaign(s)', $html);
        $this->assertStringNotContainsString('Unchanged', $html);
    }

    public function test_maintenance_does_not_record_or_notify_same_value_or_legacy_budget_updates(): void
    {
        Notification::fake();
        [$campaign] = $this->runMaintenance([
            'adjustments' => [
                $this->receipt(['new_budget_micros' => 50_000_000]),
                ['type' => 'budget_updated', 'adjusted_budget' => 50],
            ],
        ]);

        Notification::assertNothingSent();
        $this->assertFalse(AgentActivity::where('campaign_id', $campaign->id)->where('action', 'adjusted_budget')->exists());
        $run = AgentRun::where('job', 'AutomatedCampaignMaintenance')->latest('id')->firstOrFail();
        $this->assertSame(0, $run->details['budget_adjustments']);
        $this->assertSame(0, $run->actions_taken);
        $this->assertSame('no_op', $run->status);
    }

    public function test_maintenance_reports_only_verified_platform_change_during_partial_failure(): void
    {
        Notification::fake();
        $confirmed = $this->receipt();
        [$campaign, $user] = $this->runMaintenance([
            'adjustments' => [
                $confirmed,
                $this->receipt(['new_budget_micros' => 50_000_000]),
                $this->receipt(['platform' => 'facebook', 'verified' => false]),
            ],
            'errors' => ['Facebook did not confirm the requested budget.'],
        ]);

        Notification::assertSentTo($user, MaintenanceSummaryNotification::class, function ($notification) use ($user, $campaign, $confirmed) {
            $summary = $notification->toArray($user)['changes'][$campaign->name];
            $this->assertSame(1, $summary['total_changes']);
            $this->assertSame([$confirmed], $summary['budget_changes']);

            return true;
        });
        $activity = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'adjusted_budget')->sole();
        $this->assertSame([$confirmed], $activity->details['adjustments']);
        $run = AgentRun::where('job', 'AutomatedCampaignMaintenance')->latest('id')->firstOrFail();
        $this->assertSame(1, $run->details['budget_adjustments']);
        $this->assertSame(1, $run->errors);
        $this->assertSame('partial', $run->status);
    }

    public function test_maintenance_preserves_keyword_changes_without_counting_a_no_op_budget(): void
    {
        Notification::fake();
        [$campaign, $user] = $this->runMaintenance([
            'adjustments' => [$this->receipt(['new_budget_micros' => 50_000_000])],
        ], ['keywords_added' => ['relevant keyword']]);

        Notification::assertSentTo($user, MaintenanceSummaryNotification::class, function ($notification) use ($user, $campaign) {
            $summary = $notification->toArray($user)['changes'][$campaign->name];
            $this->assertSame(1, $summary['total_changes']);
            $this->assertSame(1, $summary['keywords_added']);
            $this->assertSame(0, $summary['budget_adjustments']);
            $this->assertSame([], $summary['budget_changes']);

            return true;
        });
    }

    private function runMaintenance(array $budgetResult, array $miningResult = []): array
    {
        $customer = Customer::factory()->create(['service_type' => 'managed', 'google_ads_customer_id' => '123']);
        $user = User::factory()->create();
        $customer->users()->attach($user);
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id, 'status' => 'active', 'primary_status' => 'ELIGIBLE',
            'google_ads_campaign_id' => '456', 'daily_budget' => 50, 'approved_daily_budget' => 50,
        ]);
        $healing = $this->createMock(SelfHealingAgent::class);
        $healing->method('heal')->willReturn([]);
        $search = $this->createMock(SearchTermMiningAgent::class);
        $search->method('mine')->willReturn($miningResult);
        $budget = $this->createMock(BudgetIntelligenceAgent::class);
        $budget->method('optimize')->willReturn($budgetResult);
        $creative = $this->createMock(CreativeIntelligenceAgent::class);
        $creative->method('analyze')->willReturn([]);
        $extensions = $this->createMock(AdExtensionAgent::class);
        $extensions->method('manage')->willReturn([]);
        $bids = $this->createMock(BidAdjustmentAgent::class);
        $bids->method('optimize')->willReturn([]);
        $quality = $this->createMock(QualityScoreImprovementAgent::class);
        $quality->method('improve')->willReturn([]);
        $quality->method('checkAdStrength')->willReturn([]);

        (new AutomatedCampaignMaintenance)->handle($healing, $search, $budget, $creative, $extensions, $bids, $quality,
            $this->createMock(FacebookLearningPhaseAgent::class),
            $this->createMock(FacebookAdRelevanceDiagnosticsAgent::class),
            $this->createMock(LinkedInCampaignOptimizationAgent::class),
            $this->createMock(AudienceIntelligenceAgent::class));

        return [$campaign, $user];
    }
}
