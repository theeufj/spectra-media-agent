<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Strategy;
use App\Models\User;
use App\Notifications\CriticalAgentAlert;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GoogleReadinessNotificationLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private Campaign $campaign;

    private Customer $customer;

    private Strategy $strategy;

    private User $owner;

    private array $issue = ['code' => 'conversion_goal_intent_missing', 'message' => 'Select the campaign conversion goal.'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Cache::flush();
        EnabledPlatform::updateOrCreate(['slug' => 'google'], ['name' => 'Google Ads', 'is_enabled' => true]);
        $this->customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890', 'service_type' => 'managed']);
        $this->owner = User::factory()->create();
        $this->customer->users()->attach($this->owner, ['role' => 'owner']);
        $this->campaign = Campaign::factory()->create(['customer_id' => $this->customer->id,
            'status' => 'active', 'platform_status' => 'ENABLED', 'primary_status' => 'NOT_ELIGIBLE',
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/61']);
        $this->strategy = Strategy::factory()->create(['campaign_id' => $this->campaign->id, 'platform' => 'Google Ads',
            'google_ads_campaign_id' => '61', 'signed_off_at' => now(), 'deployment_status' => 'verified']);
        $this->observation();
    }

    private function observation(array $changes = []): void
    {
        $this->strategy->update(['execution_result' => ['metadata' => ['google_readiness' => array_replace([
            'checked_at' => now()->toIso8601String(), 'campaign_resource' => 'customers/1234567890/campaigns/61',
            'status' => 'needs_review', 'skip_reason' => null, 'issues' => [$this->issue],
            'ad_strength' => [], 'audience_observation' => [],
        ], $changes)]]]);
    }

    private function alert(string $type = 'google_campaign_readiness', array $changes = []): CriticalAgentAlert
    {
        return new CriticalAgentAlert($type, 'Readiness needs review', 'Review the conversion goal.', array_replace([
            'campaign_id' => $this->campaign->id, 'customer_id' => $this->customer->id,
            'strategy_id' => $this->strategy->id, 'campaign_resource' => 'customers/1234567890/campaigns/61',
            'issues' => [$this->issue],
        ], $changes));
    }

    private function deliverQueued(CriticalAgentAlert $alert): ReadinessMailChannelFixture
    {
        $channel = new ReadinessMailChannelFixture;
        $manager = app(ChannelManager::class);
        $manager->forgetDrivers();
        $manager->extend('mail', fn () => $channel);
        $job = unserialize(serialize(new SendQueuedNotifications(collect([$this->owner]), $alert, ['mail'])));
        $job->handle($manager);

        return $channel;
    }

    public function test_a_paused_campaign_drops_an_already_queued_readiness_email_at_actual_send_time(): void
    {
        $alert = $this->alert();
        $this->assertSame(['mail'], $alert->via($this->owner));
        $this->campaign->update(['status' => 'paused', 'spend_safety_hold' => true]);

        $this->assertSame(0, $this->deliverQueued($alert)->sent);
        $this->assertSame([$this->issue], $this->strategy->fresh()->execution_result['metadata']['google_readiness']['issues']);
    }

    public function test_known_remote_pause_is_rechecked_from_new_observations_even_if_local_active_is_stale(): void
    {
        $alert = $this->alert();
        $this->assertSame(['mail'], $alert->via($this->owner));
        $this->observation(['ad_strength' => ['checked' => true, 'skipped' => 'google_campaign_not_enabled',
            'unresolved' => [['reason' => 'google_campaign_paused']]]]);

        $this->assertSame(0, $this->deliverQueued($alert)->sent);
        $this->assertSame('active', $this->campaign->fresh()->status->value);
    }

    public function test_active_intended_campaigns_with_real_readiness_faults_still_send(): void
    {
        $this->assertSame(1, $this->deliverQueued($this->alert())->sent);
        $this->assertSame('NOT_ELIGIBLE', $this->campaign->fresh()->primary_status,
            'Blocked serving is a real fault when delivery is still intended.');
    }

    public function test_local_remote_end_and_platform_disable_states_suppress_routine_readiness_mail(): void
    {
        foreach (['paused', 'draft', 'ended', 'completed', 'pending_admin_deployment'] as $status) {
            $this->campaign->update(['status' => $status]);
            $this->assertFalse($this->alert()->shouldSend($this->owner, 'mail'));
        }
        $this->campaign->update(['status' => 'active', 'platform_status' => 'PAUSED']);
        $this->assertFalse($this->alert()->shouldSend($this->owner, 'mail'));
        $this->campaign->update(['platform_status' => 'ENABLED', 'end_date' => now()->subDay()]);
        $this->assertFalse($this->alert()->shouldSend($this->owner, 'mail'));
        $this->campaign->update(['end_date' => now()->addDays(7)]);
        EnabledPlatform::where('slug', 'google')->update(['is_enabled' => false]);
        $this->assertFalse($this->alert()->shouldSend($this->owner, 'mail'));
    }

    public function test_resolved_or_obsolete_observations_do_not_generate_stale_queued_demands(): void
    {
        $alert = $this->alert();
        $this->observation(['status' => 'ready', 'issues' => []]);
        $this->assertSame(0, $this->deliverQueued($alert)->sent);
        $this->observation(['checked_at' => now()->subDays(2)->toIso8601String()]);
        $this->assertFalse($alert->shouldSend($this->owner, 'mail'));
        $this->observation();
        $this->strategy->forceFill(['google_ads_campaign_id' => '62'])->save();
        $this->assertFalse($alert->shouldSend($this->owner, 'mail'));
    }

    public function test_recipient_and_record_ownership_are_rechecked_before_delivery(): void
    {
        $alert = $this->alert();
        $this->customer->users()->detach($this->owner);
        $this->assertSame(0, $this->deliverQueued($alert)->sent);
        $this->customer->users()->attach($this->owner, ['role' => 'owner']);
        $this->assertFalse($this->alert(changes: ['customer_id' => $this->customer->id + 999])->shouldSend($this->owner, 'mail'));
        $other = Strategy::factory()->create();
        $this->assertFalse($this->alert(changes: ['strategy_id' => $other->id])->shouldSend($this->owner, 'mail'));
    }

    public function test_explicit_admin_preview_and_protective_pause_notifications_are_not_silenced(): void
    {
        $this->campaign->update(['status' => 'paused', 'spend_safety_hold' => true]);
        $this->assertSame(1, $this->deliverQueued($this->alert(changes: ['test_preview' => true]))->sent);
        $this->assertSame(1, $this->deliverQueued($this->alert('spend_safety'))->sent);
    }
}

class ReadinessMailChannelFixture
{
    public int $sent = 0;

    public function send(object $notifiable, Notification $notification): void
    {
        $this->sent++;
    }
}
