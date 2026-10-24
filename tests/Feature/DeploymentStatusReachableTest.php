<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The deployment status page has to be reachable, and only by its owner.
 *
 * campaigns.deployment-status has existed for months with no inbound link from
 * anywhere in the UI — not from the campaign list, not from the campaign page.
 * A customer could only reach it by typing the URL. It is the only screen that
 * shows per-platform deployment state and the error message when a platform
 * rejects a campaign, which is precisely what someone whose ads have not
 * appeared goes looking for.
 *
 * The route existing is not the same as the page being reachable, so this
 * asserts both: that it answers for the owner, and that the pages which now
 * link to it can actually render that link.
 */
class DeploymentStatusReachableTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Campaign} */
    private function ownedCampaign(array $strategyAttributes = []): array
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $customer->users()->attach($user->id, ['role' => 'owner']);

        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        Strategy::factory()->create(array_merge([
            'campaign_id' => $campaign->id,
        ], $strategyAttributes));

        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id]);

        return [$user, $campaign];
    }

    public function test_the_owner_can_open_the_deployment_status_page(): void
    {
        [, $campaign] = $this->ownedCampaign(['deployment_status' => 'deployed']);

        $this->get(route('campaigns.deployment-status', ['campaign' => $campaign->id]))
            ->assertOk();
    }

    public function test_the_campaign_list_can_link_to_it(): void
    {
        // The link is rendered from deployment_status / deployed_at on each
        // strategy, so the list has to actually carry those.
        [, $campaign] = $this->ownedCampaign(['deployment_status' => 'deployed']);

        $this->get(route('campaigns.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('campaigns.0.strategies.0.deployment_status'));

        $this->assertNotNull($campaign->strategies()->first()->deployment_status);
    }

    public function test_a_guest_cannot_open_it(): void
    {
        $campaign = Campaign::factory()->create();

        $this->get(route('campaigns.deployment-status', ['campaign' => $campaign->id]))
            ->assertRedirect(route('login'));
    }

    public function test_another_tenants_campaign_is_not_found_rather_than_forbidden(): void
    {
        // Cross-tenant access returns 404, not 403: route-model binding cannot
        // find the row, and a 403 would confirm the id exists.
        $this->ownedCampaign(['deployment_status' => 'deployed']);

        $stranger = Campaign::factory()->create();

        $this->get(route('campaigns.deployment-status', ['campaign' => $stranger->id]))
            ->assertNotFound();
    }

    public function test_status_polling_distinguishes_deployed_from_verified_and_other_terminal_outcomes(): void
    {
        [, $campaign] = $this->ownedCampaign(['deployment_status' => 'deployed']);
        $this->getJson(route('api.campaigns.deployment-status', $campaign))
            ->assertOk()->assertJsonPath('is_complete', false);

        foreach (['verified', 'failed', 'deploy_unverified', 'skipped_plan'] as $status) {
            $campaign->strategies()->update(['deployment_status' => $status]);
            $this->getJson(route('api.campaigns.deployment-status', $campaign))
                ->assertOk()->assertJsonPath('is_complete', true)
                ->assertJsonPath('deployments.0.status', $status);
        }
    }

    public function test_page_and_polling_expose_current_goal_failure_alongside_prior_readiness(): void
    {
        $previous = ['status' => 'ready', 'ready' => true, 'checked_at' => now()->subHour()->toIso8601String()];
        $current = ['status' => 'unknown', 'ready' => false, 'checked_at' => now()->toIso8601String(), 'issues' => [['code' => 'conversion_goal_check_failed', 'message' => 'Google conversion goals could not be checked.']]];
        [, $campaign] = $this->ownedCampaign(['platform' => 'Google Ads (SEM)', 'deployment_status' => 'verified', 'execution_result' => ['metadata' => ['google_readiness' => $previous, 'conversion_goal_readiness' => $current]]]);
        $this->get(route('campaigns.deployment-status', $campaign))->assertOk()->assertInertia(fn ($page) => $page
            ->where('deployments.0.google_readiness.status', 'ready')
            ->where('deployments.0.conversion_goal_readiness.status', 'unknown')
            ->where('deployments.0.conversion_goal_readiness.issues.0.message', $current['issues'][0]['message']));
        $this->getJson(route('api.campaigns.deployment-status', $campaign))->assertOk()
            ->assertJsonPath('deployments.0.google_readiness.status', 'ready')
            ->assertJsonPath('deployments.0.conversion_goal_readiness.ready', false)
            ->assertJsonPath('deployments.0.conversion_goal_readiness.status', 'unknown');
    }

    public function test_legacy_active_is_terminal_but_keeps_deployed_progress_without_claiming_verification(): void
    {
        [, $campaign] = $this->ownedCampaign(['deployment_status' => 'deployed']);
        $deployedProgress = $this->getJson(route('api.campaigns.deployment-status', $campaign))->assertOk()->json('deployments.0.progress');
        $campaign->strategies()->update(['deployment_status' => 'active']);
        $this->getJson(route('api.campaigns.deployment-status', $campaign))->assertOk()
            ->assertJsonPath('is_complete', true)
            ->assertJsonPath('deployments.0.status', 'active')
            ->assertJsonPath('deployments.0.progress', $deployedProgress);
        $this->assertLessThan(4, $deployedProgress);
    }

    /** @return array{Campaign, Strategy} */
    private function servingGoogleCampaign(): array
    {
        [, $campaign] = $this->ownedCampaign([
            'platform' => 'Google Ads (SEM)', 'deployment_status' => 'active',
            'deployed_at' => now()->subHour(),
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/111',
            'execution_result' => ['metadata' => [
                'configuration_verification' => ['passed' => true, 'issues' => [],
                    'scope' => 'configuration_and_goals', 'checked_at' => now()->subMinutes(50)->toIso8601String()],
                'search_reach_activation' => ['enabled_verified' => true,
                    'campaign_resource' => 'customers/1234567890/campaigns/111'],
                'google_readiness' => ['status' => 'pending', 'ready' => false,
                    'pending' => [['code' => 'ad_strength_strength_pending', 'message' => 'Google is still evaluating ad strength.']],
                    'ad_strength' => ['checked' => true, 'verified' => false, 'errors' => []]],
            ]],
        ]);
        $campaign->customer->update(['google_ads_customer_id' => '1234567890', 'service_type' => 'managed']);
        $campaign->update(['status' => 'active', 'platform_status' => 'ENABLED', 'primary_status' => 'ELIGIBLE',
            'last_checked_at' => now(), 'google_ads_campaign_id' => 'customers/1234567890/campaigns/111',
            'start_date' => now()->subDay(), 'end_date' => now()->addWeek(),
            'policy_checks' => ['platforms' => ['google_ads' => ['status' => 'clear', 'issues' => [], 'checked_at' => now()->toIso8601String()]]]]);

        return [$campaign->refresh(), $campaign->strategies()->firstOrFail()];
    }

    public function test_approved_serving_google_campaign_is_live_and_complete_while_strength_is_pending(): void
    {
        [$campaign, $strategy] = $this->servingGoogleCampaign();
        $before = $strategy->getRawOriginal();
        $this->get(route('campaigns.deployment-status', $campaign))->assertOk()->assertInertia(fn ($page) => $page
            ->where('deployments.0.delivery_status.status', 'live')
            ->where('deployments.0.deployment_complete', true)
            ->where('deployments.0.progress', 4)
            ->where('deployments.0.status', 'active')
            ->where('deployments.0.google_readiness.ready', false));
        $this->getJson(route('api.campaigns.deployment-status', $campaign))->assertOk()
            ->assertJsonPath('deployments.0.delivery_status.status', 'live')
            ->assertJsonPath('deployments.0.deployment_complete', true)
            ->assertJsonPath('deployments.0.configuration_verification.passed', true)
            ->assertJsonPath('overall_progress', 100)
            ->assertJsonPath('is_complete', true);
        $this->assertSame($before, $strategy->refresh()->getRawOriginal());
        $this->assertSame('active', $campaign->refresh()->status->value);
    }

    public function test_completed_construction_is_separate_from_google_policy_review_and_actual_restrictions(): void
    {
        [$campaign] = $this->servingGoogleCampaign();
        $campaign->update(['primary_status' => 'PENDING', 'primary_status_reasons' => ['MOST_ADS_UNDER_REVIEW']]);
        $this->getJson(route('api.campaigns.deployment-status', $campaign))->assertOk()
            ->assertJsonPath('deployments.0.delivery_status.status', 'under_review')
            ->assertJsonPath('deployments.0.deployment_complete', true)
            ->assertJsonPath('overall_progress', 100);
        $campaign->update(['primary_status' => 'LIMITED', 'policy_checks' => ['platforms' => ['google_ads' => [
            'status' => 'violations', 'checked_at' => now()->toIso8601String(), 'issues' => [['message' => 'Restricted ad.']],
        ]]]]);
        $this->getJson(route('api.campaigns.deployment-status', $campaign))->assertOk()
            ->assertJsonPath('deployments.0.delivery_status.status', 'needs_review')
            ->assertJsonPath('deployments.0.deployment_complete', true);
    }

    public function test_current_configuration_failure_overrides_legacy_verified_and_blocks_completion(): void
    {
        [$campaign, $strategy] = $this->servingGoogleCampaign();
        $result = $strategy->execution_result;
        $result['metadata']['configuration_verification']['passed'] = false;
        $result['metadata']['configuration_verification']['issues'] = ['Budget no longer matches approval.'];
        $strategy->update(['deployment_status' => 'verified', 'execution_result' => $result]);
        $response = $this->getJson(route('api.campaigns.deployment-status', $campaign))->assertOk()
            ->assertJsonPath('deployments.0.delivery_status.status', 'needs_review')
            ->assertJsonPath('deployments.0.deployment_complete', false);
        $this->assertLessThan(4, $response->json('deployments.0.progress'));
    }

    public function test_setup_handover_and_manually_paused_campaigns_never_become_live(): void
    {
        [$campaign, $strategy] = $this->servingGoogleCampaign();
        $campaign->update(['status' => 'paused']);
        $this->getJson(route('api.campaigns.deployment-status', $campaign))->assertOk()
            ->assertJsonPath('deployments.0.delivery_status.status', 'paused')
            ->assertJsonPath('deployments.0.deployment_complete', true);
        $campaign->update(['status' => 'active']);
        $campaign->customer->update(['service_type' => 'setup_only']);
        $result = $strategy->execution_result;
        $result['metadata']['configuration_verification']['scope'] = 'paused_objects';
        $strategy->update(['execution_result' => $result]);
        $this->getJson(route('api.campaigns.deployment-status', $campaign))->assertOk()
            ->assertJsonPath('deployments.0.delivery_status.status', 'paused')
            ->assertJsonPath('deployments.0.progress', 4);
    }

    #[DataProvider('nonLiveEvidence')]
    public function test_missing_stale_or_out_of_scope_evidence_does_not_claim_live(string $case): void
    {
        [$campaign, $strategy] = $this->servingGoogleCampaign();
        $result = $strategy->execution_result;
        match ($case) {
            'stale_status' => $campaign->update(['last_checked_at' => now()->subHours(4)]),
            'stale_policy' => $campaign->update(['policy_checks' => ['platforms' => ['google_ads' => ['status' => 'clear', 'issues' => [], 'checked_at' => now()->subHours(4)->toIso8601String()]]]]),
            'unknown_policy' => $campaign->update(['policy_checks' => []]),
            'unknown_status' => $campaign->update(['primary_status' => 'UNKNOWN']),
            'pending_without_review_evidence' => $campaign->update(['primary_status' => 'PENDING']),
            'future_start' => $campaign->update(['start_date' => now()->addDays(2)]),
            'ended' => $campaign->update(['end_date' => now()->subDay()]),
            'manual_hold' => $campaign->update(['spend_safety_hold' => ['reason' => 'manual_pause']]),
            'secondary_resource' => $strategy->forceFill(['google_ads_campaign_id' => 'customers/1234567890/campaigns/222'])->save(),
            'foreign_account' => $strategy->forceFill(['google_ads_campaign_id' => 'customers/9999999999/campaigns/111'])->save(),
            'future_configuration' => $result['metadata']['configuration_verification']['checked_at'] = now()->addHour()->toIso8601String(),
            'old_configuration' => $result['metadata']['configuration_verification']['checked_at'] = now()->subHours(2)->toIso8601String(),
            'new_goal_error' => $result['metadata']['conversion_goal_readiness'] = ['ready' => false, 'status' => 'unknown', 'checked_at' => now()->toIso8601String()],
            default => throw new \InvalidArgumentException('Unknown evidence case: '.$case),
        };
        if (in_array($case, ['old_configuration', 'future_configuration', 'new_goal_error'], true)) {
            $strategy->update(['execution_result' => $result]);
        }
        $this->getJson(route('api.campaigns.deployment-status', $campaign))->assertOk()
            ->assertJsonPath('deployments.0.delivery_status.status', 'unknown');
    }

    public static function nonLiveEvidence(): array
    {
        return array_map(fn ($case) => [$case], ['stale_status', 'stale_policy', 'unknown_policy', 'unknown_status',
            'pending_without_review_evidence', 'future_start', 'ended', 'manual_hold', 'secondary_resource', 'foreign_account', 'old_configuration', 'future_configuration', 'new_goal_error']);
    }

    public function test_each_strategy_keeps_its_own_completion_and_delivery_evidence(): void
    {
        [$campaign, $first] = $this->servingGoogleCampaign();
        $second = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads (SEM)',
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/222', 'deployment_status' => 'active']);
        $response = $this->getJson(route('api.campaigns.deployment-status', $campaign))->assertOk();
        $rows = collect($response->json('deployments'))->keyBy('id');
        $this->assertSame('live', $rows[$first->id]['delivery_status']['status']);
        $this->assertTrue($rows[$first->id]['deployment_complete']);
        $this->assertSame('unknown', $rows[$second->id]['delivery_status']['status']);
        $this->assertFalse($rows[$second->id]['deployment_complete']);
        $this->assertLessThan(100, $response->json('overall_progress'));
    }

    public function test_other_platforms_preserve_their_existing_verified_presentation(): void
    {
        [, $campaign] = $this->ownedCampaign(['platform' => 'Facebook Ads', 'deployment_status' => 'verified']);
        $this->getJson(route('api.campaigns.deployment-status', $campaign))->assertOk()
            ->assertJsonPath('deployments.0.delivery_status', null)
            ->assertJsonPath('deployments.0.deployment_complete', true)
            ->assertJsonPath('deployments.0.progress', 4);
    }

    public function test_retrying_deployment_cannot_reuse_previous_configuration_to_claim_completion(): void
    {
        [$campaign, $strategy] = $this->servingGoogleCampaign();
        foreach (['pending', 'queued', 'deploying'] as $status) {
            $strategy->update(['deployment_status' => $status]);
            $response = $this->getJson(route('api.campaigns.deployment-status', $campaign))->assertOk()
                ->assertJsonPath('deployments.0.deployment_complete', false)
                ->assertJsonPath('deployments.0.delivery_status.status', 'unknown');
            $this->assertLessThan(100, $response->json('overall_progress'));
        }
    }

    public function test_parent_paused_platform_status_is_not_evidence_that_a_secondary_google_strategy_is_paused(): void
    {
        [$campaign, $strategy] = $this->servingGoogleCampaign();
        $campaign->update(['platform_status' => 'PAUSED']);
        $strategy->forceFill(['google_ads_campaign_id' => 'customers/1234567890/campaigns/222'])->save();
        $this->getJson(route('api.campaigns.deployment-status', $campaign))->assertOk()
            ->assertJsonPath('deployments.0.delivery_status.status', 'unknown');
    }

    public function test_pending_review_policy_evidence_is_distinct_from_provider_read_failures(): void
    {
        [$campaign] = $this->servingGoogleCampaign();
        $campaign->update(['primary_status' => 'PENDING', 'policy_checks' => ['platforms' => ['google_ads' => [
            'status' => 'unknown', 'issues' => [], 'checked_at' => now()->toIso8601String(),
            'error' => 'The current ads are still awaiting review. Approval has not been confirmed.',
        ]]]]);
        $this->getJson(route('api.campaigns.deployment-status', $campaign))->assertOk()
            ->assertJsonPath('deployments.0.delivery_status.status', 'under_review')
            ->assertJsonPath('deployments.0.progress', 4);
        $campaign->update(['policy_checks' => ['platforms' => ['google_ads' => [
            'status' => 'unknown', 'issues' => [], 'checked_at' => now()->toIso8601String(), 'error' => 'Google request failed.',
        ]]]]);
        $this->getJson(route('api.campaigns.deployment-status', $campaign))->assertOk()
            ->assertJsonPath('deployments.0.delivery_status.status', 'unknown');
    }
}
