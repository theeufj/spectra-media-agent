<?php

namespace Tests\Feature;

use App\Jobs\ReviewGoogleAdsRecommendations;
use App\Jobs\VerifyGoogleAdImprovement;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Notifications\CriticalAgentAlert;
use App\Services\AdminMonitorService;
use App\Services\Agents\QualityScoreImprovementAgent;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\ApplyRecommendation;
use App\Services\GoogleAds\CommonServices\DismissRecommendation;
use App\Services\GoogleAds\CommonServices\GetGoogleAdsRecommendations;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\CommonServices\UpdateResponsiveSearchAd;
use App\Services\GoogleAds\GoogleAdStrengthRepair;
use Google\Ads\GoogleAds\V22\Enums\RecommendationTypeEnum\RecommendationType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class GoogleAdStrengthRepairLoopTest extends TestCase
{
    use DatabaseTransactions;

    private const AD = 'customers/123/adGroupAds/456~789';

    private array $copy = ['headlines' => ['Google Ads Management', 'Your AI Marketing Team', 'Explore Our Plans'],
        'descriptions' => ['Manage your campaigns with AI.', 'See your results in one place.']];

    private function campaign(): Campaign
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123', 'service_type' => 'managed']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'active', 'google_ads_campaign_id' => 'customers/123/campaigns/9']);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'signed_off_at' => now(), 'google_ads_ad_group_id' => 'customers/123/adGroups/456']);
        \App\Models\AdSpendCredit::factory()->create(['customer_id' => $customer->id]);
        \Laravel\Pennant\Feature::for($customer)->activate(\App\Features\AutoHealing::class);

        return $campaign->load('customer');
    }

    private function row(string $strength = 'POOR', string $resource = self::AD, ?array $copy = null, string $review = 'REVIEWED', string $approval = 'APPROVED'): array
    {
        $copy ??= ['headlines' => ['Old headline'], 'descriptions' => ['Old description']];

        return ['campaign' => ['status' => 'ENABLED'], 'adGroup' => ['status' => 'ENABLED'], 'adGroupAd' => [
            'resourceName' => $resource, 'status' => 'ENABLED', 'adStrength' => $strength,
            'actionItems' => ['Try adding a few more unique headlines.'],
            'policySummary' => ['approvalStatus' => $approval, 'reviewStatus' => $review],
            'ad' => ['id' => basename($resource), 'responsiveSearchAd' => [
                'headlines' => array_map(fn ($text) => ['text' => $text], $copy['headlines']),
                'descriptions' => array_map(fn ($text) => ['text' => $text], $copy['descriptions']),
            ]],
        ]];
    }

    private function reader(array $rows): void
    {
        $reader = Mockery::mock(ReadCampaignConfiguration::class);
        $reader->shouldReceive('ads')->andReturn($rows);
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
    }

    private function repairServices(bool $accepted = true): void
    {
        $ai = Mockery::mock(GeminiService::class);
        $ai->shouldReceive('generateContent')->withArgs(function ($model, $prompt, $context) {
            $this->assertStringContainsString('unique headlines', $prompt);
            $this->assertStringContainsString('Do not manufacture prices', $prompt);
            $this->assertStringContainsString('Try adding a few more unique headlines.', $prompt);

            return true;
        })->andReturn(['text' => json_encode($this->copy)]);
        $this->app->instance(GeminiService::class, $ai);
        $review = Mockery::mock(AdminMonitorService::class);
        $review->shouldReceive('reviewAdCopy')->withArgs(fn ($ad, $existing) => $existing && $ad->headlines === $this->copy['headlines'])
            ->andReturn(['overall_status' => 'approved']);
        $this->app->instance(AdminMonitorService::class, $review);
        $updater = Mockery::mock(UpdateResponsiveSearchAd::class);
        $updater->shouldReceive('replace')->withArgs(fn ($id, $resource, $headlines, $descriptions) => $id === '123'
            && $headlines === $this->copy['headlines'] && $descriptions === $this->copy['descriptions'])->andReturn($accepted);
        $this->app->bind(UpdateResponsiveSearchAd::class, fn () => $updater);
    }

    private function submitted(Campaign $campaign, string $resource = self::AD): AgentActivity
    {
        $state = app(GoogleAdStrengthRepair::class);
        $attempt = $state->start($campaign, GoogleAdStrengthRepair::ad($this->row(resource: $resource)));
        $state->submitted($attempt, $this->copy);

        return $attempt;
    }

    public function test_zero_impressions_do_not_block_reviewed_repair_and_exact_verification_is_queued(): void
    {
        $campaign = $this->campaign();
        $this->reader([$this->row()]);
        $this->repairServices();
        $result = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
        $this->assertCount(1, $result['actions']);
        $this->assertFalse($result['verified']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('submitted', $result['unresolved'][0]['copy_identity_source']);
        $this->assertSame(GoogleAdStrengthRepair::copyFingerprint(GoogleAdStrengthRepair::ad($this->row(copy: $this->copy))), $result['unresolved'][0]['copy_fingerprint']);
        $this->assertNotSame($result['unresolved'][0]['live_copy_fingerprint'], $result['unresolved'][0]['copy_fingerprint']);
        $this->assertDatabaseHas('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'ad_copy_update_submitted', 'status' => 'pending']);
        Queue::assertPushed(VerifyGoogleAdImprovement::class, fn ($job) => $job->attemptId !== null && $job->headlines === $this->copy['headlines']);
        $this->reader([$this->row('PENDING', copy: $this->copy)]);
        $observed = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
        $this->assertSame('observed', $observed['unresolved'][0]['copy_identity_source']);
        $this->assertSame($result['unresolved'][0]['copy_fingerprint'], $observed['unresolved'][0]['copy_fingerprint']);
    }

    public function test_failed_google_read_is_not_cached_as_healthy_and_can_retry_immediately(): void
    {
        $campaign = $this->campaign();
        $reader = $this->createMock(ReadCampaignConfiguration::class);
        $reads = 0;
        $reader->expects($this->exactly(2))->method('ads')->willReturnCallback(function () use (&$reads) {
            if (++$reads === 1) {
                throw new \RuntimeException('Google unavailable');
            }

            return [$this->row('GOOD')];
        });
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
        $this->mock(GeminiService::class);
        $agent = app(QualityScoreImprovementAgent::class);
        $failed = $agent->checkAdStrength($campaign);
        $this->assertFalse($failed['checked']);
        $this->assertFalse($failed['verified']);
        $this->assertSame('Google unavailable', $failed['errors'][0]->message);
        $this->assertSame('google_ad_strength_read_failed', $failed['errors'][0]->code);
        $this->assertFalse(Cache::has("ad_strength_check:{$campaign->id}"));
        $this->assertTrue($agent->checkAdStrength($campaign)['verified']);
    }

    public function test_pending_strength_remains_retryable_without_copy_rewrites(): void
    {
        $campaign = $this->campaign();
        $this->reader([$this->row('PENDING')]);
        $this->mock(GeminiService::class)->shouldNotReceive('generateContent');
        $pending = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
        $this->assertSame([], $pending['actions']);
        $this->assertFalse($pending['verified']);
        $this->assertSame('strength_pending', $pending['unresolved'][0]['reason']);
        $this->assertSame('PENDING', $pending['unresolved'][0]['evidence']['ad_strength']);
        $this->assertSame('APPROVED', $pending['unresolved'][0]['evidence']['approval_status']);
        $this->assertNotEmpty($pending['unresolved'][0]['observed_at']);
        $this->assertSame(GoogleAdStrengthRepair::observation(GoogleAdStrengthRepair::ad($this->row('GOOD')))['copy_fingerprint'],
            $pending['unresolved'][0]['copy_fingerprint'], 'Review and strength changes must not reset the copy identity.');
        $this->reader([$this->row()]);
        $this->repairServices();
        $this->assertCount(1, app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign)['actions']);
    }

    public function test_only_confirmed_provider_waiting_is_classified_as_transient(): void
    {
        $campaign = $this->campaign();
        $ai = $this->createMock(GeminiService::class);
        $ai->expects($this->never())->method('generateContent');
        $this->app->instance(GeminiService::class, $ai);
        $cases = [
            [$this->row('GOOD', review: 'REVIEW_IN_PROGRESS'), 'review_pending'],
            [$this->row('GOOD', review: 'UNDER_APPEAL'), 'review_pending'],
            [$this->row('PENDING', review: 'REVIEW_IN_PROGRESS', approval: 'UNKNOWN'), 'review_pending'],
            [$this->row('PENDING', review: 'REVIEW_IN_PROGRESS', approval: 'UNSPECIFIED'), 'review_pending'],
            [$this->row('UNKNOWN'), 'ad_status_unknown'],
            [$this->row('NO_ADS'), 'ad_status_unknown'],
            [$this->row('PENDING', review: 'UNKNOWN'), 'ad_status_unknown'],
            [$this->row('PENDING', approval: 'UNKNOWN'), 'ad_status_unknown'],
            [$this->row('PENDING', approval: 'DISAPPROVED'), 'policy_disapproved'],
            [$this->row('PENDING', approval: 'AREA_OF_INTEREST_ONLY'), 'policy_targeting_restricted'],
        ];
        $assetRejected = $this->row('PENDING');
        $assetRejected['adGroupAd']['ad']['responsiveSearchAd']['headlines'][0]['policySummaryInfo'] = ['approvalStatus' => 'DISAPPROVED', 'reviewStatus' => 'REVIEWED'];
        $cases[] = [$assetRejected, 'policy_disapproved'];
        $assetUnknown = $this->row('GOOD');
        $assetUnknown['adGroupAd']['ad']['responsiveSearchAd']['headlines'][0]['policySummaryInfo'] = ['approvalStatus' => 'APPROVED', 'reviewStatus' => 'UNKNOWN'];
        $cases[] = [$assetUnknown, 'ad_status_unknown'];
        foreach ($cases as [$row, $reason]) {
            $this->reader([$row]);
            $result = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
            $this->assertSame($reason, $result['unresolved'][0]['reason']);
            $this->assertFalse($result['verified']);
            $this->assertSame([], $result['actions']);
            $this->assertNotEmpty($result['unresolved'][0]['copy_fingerprint']);
        }
    }

    public function test_new_rejection_or_unknown_state_is_not_hidden_by_the_initial_verification_delay(): void
    {
        Notification::fake();
        $campaign = $this->campaign();
        $state = app(GoogleAdStrengthRepair::class);
        foreach ([['DISAPPROVED', 'PENDING', 'policy_disapproved'], ['APPROVED', 'UNKNOWN', 'ad_status_unknown']] as [$approval, $rating, $reason]) {
            $attempt = $this->submitted($campaign);
            $ad = GoogleAdStrengthRepair::ad($this->row($rating, copy: $this->copy, approval: $approval));
            $result = $state->verify($campaign, $attempt, $ad);
            $this->assertSame($reason, $result['reason']);
            $this->assertNotSame('verification_pending', $result['reason']);
        }
    }

    public function test_exhausted_verification_carries_its_terminal_state_with_the_current_pending_evidence(): void
    {
        Notification::fake();
        $campaign = $this->campaign();
        $attempt = $this->submitted($campaign);
        $attempt->update(['details' => array_merge($attempt->details, ['verification_reads' => 5, 'next_verification_at' => now()->subMinute()->toIso8601String()])]);
        $this->reader([$this->row('PENDING', copy: $this->copy)]);
        $result = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
        $this->assertSame('strength_pending', $result['unresolved'][0]['reason']);
        $this->assertSame('needs_review', $result['unresolved'][0]['verification_status']);
        $this->assertSame(6, $result['unresolved'][0]['verification_reads']);
        $this->assertSame($attempt->details['submitted_at'], $result['unresolved'][0]['submitted_at']);
        $this->assertFalse($result['verified']);
        $this->assertSame([], $result['actions']);
    }

    public function test_a_bounded_trial_observes_weak_copy_without_modifying_the_agreed_creative(): void
    {
        $campaign = $this->campaign();
        $campaign->update(['spend_guardrails' => ['enabled' => true]]);
        $this->reader([$this->row()]);
        $ai = $this->createMock(GeminiService::class);
        $ai->expects($this->never())->method('generateContent');
        $this->app->instance(GeminiService::class, $ai);
        $result = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
        $this->assertTrue($result['checked']);
        $this->assertSame('approved_bounded_trial', $result['unresolved'][0]['reason']);
        $this->assertSame([], $result['actions']);
        $this->assertFalse($result['verified']);
        Queue::assertNotPushed(VerifyGoogleAdImprovement::class);
        $this->assertSame(0, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_strength_repair_attempt')->count());
    }

    public function test_one_pending_ad_does_not_block_a_different_weak_ad(): void
    {
        $campaign = $this->campaign();
        $this->submitted($campaign, 'customers/123/adGroupAds/456~700');
        $this->reader([$this->row(resource: 'customers/123/adGroupAds/456~700', copy: $this->copy), $this->row()]);
        $this->repairServices();
        $result = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
        $this->assertCount(1, $result['actions']);
        $this->assertSame(self::AD, $result['actions'][0]['ad_resource']);
    }

    public function test_three_failed_attempts_escalate_once_and_do_not_pause_the_campaign(): void
    {
        Notification::fake();
        $campaign = $this->campaign();
        $user = User::factory()->create();
        $campaign->customer->users()->attach($user);
        $this->reader([$this->row()]);
        $this->repairServices(false);
        for ($i = 0; $i < 3; $i++) {
            $result = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
            $this->assertCount(1, $result['errors']);
            $this->travel(61)->minutes();
        }
        $result = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
        $this->assertSame('repair_limit_reached', $result['unresolved'][0]['reason']);
        $this->assertSame(3, app(GoogleAdStrengthRepair::class)->attemptCount($campaign, self::AD));
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_strength_escalated')->count());
        $this->assertSame(1, \App\Models\Notification::where('user_id', $user->id)->where('type', 'ad_strength_unresolved')->count());
        Notification::assertSentTo($user, CriticalAgentAlert::class);
        $this->assertSame('active', $campaign->fresh()->status->value);
        Queue::assertNotPushed(VerifyGoogleAdImprovement::class);
    }

    public function test_google_paused_campaign_is_observed_without_any_repair(): void
    {
        $campaign = $this->campaign();
        $row = $this->row();
        $row['campaign']['status'] = 'PAUSED';
        $this->reader([$row]);
        $this->mock(GeminiService::class)->shouldNotReceive('generateContent');
        $result = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
        $this->assertSame('google_campaign_not_enabled', $result['skipped']);
        $this->assertSame([], $result['actions']);
        $this->assertFalse($result['verified']);
    }

    public function test_empty_read_never_proves_healthy_ad_strength(): void
    {
        $campaign = $this->campaign();
        $this->reader([]);
        $this->mock(GeminiService::class);
        $result = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
        $this->assertFalse($result['verified']);
        $this->assertSame('no_enabled_rsa', $result['unresolved'][0]['reason']);
    }

    public function test_improved_rating_requires_matching_copy_and_completed_policy_review(): void
    {
        $campaign = $this->campaign();
        $attempt = $this->submitted($campaign);
        $this->travel(61)->minutes();
        $state = app(GoogleAdStrengthRepair::class);
        $pending = $state->verify($campaign, $attempt, GoogleAdStrengthRepair::ad($this->row('GOOD', copy: $this->copy, review: 'REVIEW_IN_PROGRESS')));
        $this->assertSame('pending', $pending['status']);
        $this->assertDatabaseMissing('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'ad_strength_improved']);
        $complete = $state->verify($campaign, $attempt, GoogleAdStrengthRepair::ad($this->row('GOOD', copy: $this->copy)));
        $this->assertSame('completed', $complete['status']);
        $this->assertSame('ad_strength_improved', $attempt->fresh()->action);
    }

    public function test_still_poor_verification_is_bounded_and_allows_a_later_repair(): void
    {
        $campaign = $this->campaign();
        $attempt = $this->submitted($campaign);
        $state = app(GoogleAdStrengthRepair::class);
        for ($i = 0; $i < GoogleAdStrengthRepair::MAX_VERIFICATION_READS; $i++) {
            $this->travel(61)->minutes();
            $result = $state->verify($campaign, $attempt, GoogleAdStrengthRepair::ad($this->row(copy: $this->copy)));
        }
        $this->assertSame('needs_review', $result['status']);
        $this->assertSame('still_weak', $result['reason']);
        $this->assertDatabaseMissing('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'ad_strength_improved']);
        $this->travel(61)->minutes();
        $this->reader([$this->row(copy: $this->copy)]);
        $this->copy['headlines'][2] = 'Discover Our Plans';
        $this->repairServices();
        $this->assertCount(1, app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign)['actions']);
    }

    public function test_policy_disapproval_is_unresolved_even_when_strength_increases(): void
    {
        Notification::fake();
        $campaign = $this->campaign();
        $attempt = $this->submitted($campaign);
        $this->travel(61)->minutes();
        $result = app(GoogleAdStrengthRepair::class)->verify($campaign, $attempt,
            GoogleAdStrengthRepair::ad($this->row('GOOD', copy: $this->copy, approval: 'DISAPPROVED')));
        $this->assertSame('needs_review', $result['status']);
        $this->assertSame('policy_disapproved', $result['reason']);
        $this->assertSame('active', $campaign->fresh()->status->value);
    }

    public function test_verification_api_failure_is_durable_and_later_evidence_can_recover(): void
    {
        Notification::fake();
        $campaign = $this->campaign();
        $attempt = $this->submitted($campaign);
        $reader = $this->createMock(ReadCampaignConfiguration::class);
        $reader->method('ads')->willThrowException(new \RuntimeException('Temporary Google failure'));
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
        $job = new VerifyGoogleAdImprovement($campaign, self::AD, 4, $this->copy['headlines'], $this->copy['descriptions'], $attempt->id);
        try {
            $job->handle();
            $this->fail('API errors must trigger queue retry.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Temporary Google failure', $e->getMessage());
        }
        $this->assertSame('Temporary Google failure', $attempt->fresh()->details['last_read_error']);
        $job->failed(new \RuntimeException('Retries exhausted'));
        $this->assertSame('needs_review', $attempt->fresh()->status);
        $this->reader([$this->row('GOOD', copy: $this->copy)]);
        $job->handle();
        $this->assertSame('completed', $attempt->fresh()->status);
    }

    public function test_stale_verification_does_not_replace_a_newer_attempt(): void
    {
        $campaign = $this->campaign();
        $old = $this->submitted($campaign);
        $new = $this->submitted($campaign);
        $reader = Mockery::mock(ReadCampaignConfiguration::class);
        $reader->shouldNotReceive('ads');
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
        (new VerifyGoogleAdImprovement($campaign, self::AD, 4, $this->copy['headlines'], $this->copy['descriptions'], $old->id))->handle();
        $this->assertSame('pending', $new->fresh()->status);
    }

    public function test_strength_recommendation_is_dismissed_only_after_verified_healthy_read(): void
    {
        $campaign = $this->campaign();
        $get = Mockery::mock(GetGoogleAdsRecommendations::class);
        $get->shouldReceive('__invoke')->andReturn([['resource_name' => 'customers/123/recommendations/r',
            'campaign_resource' => 'customers/123/campaigns/9', 'type' => RecommendationType::RESPONSIVE_SEARCH_AD_IMPROVE_AD_STRENGTH]]);
        $this->app->bind(GetGoogleAdsRecommendations::class, fn () => $get);
        $apply = Mockery::mock(ApplyRecommendation::class);
        $this->app->bind(ApplyRecommendation::class, fn () => $apply);
        $dismiss = $this->createMock(DismissRecommendation::class);
        $dismiss->expects($this->once())->method('__invoke')->with('123', ['customers/123/recommendations/r'])->willReturn(true);
        $this->app->bind(DismissRecommendation::class, fn () => $dismiss);
        $agent = $this->createMock(QualityScoreImprovementAgent::class);
        $reads = 0;
        $agent->expects($this->exactly(2))->method('checkAdStrength')->willReturnCallback(function () use (&$reads) {
            return ++$reads === 1 ? ['actions' => [['verification' => 'pending']], 'errors' => [], 'verified' => false, 'checked' => true]
                : ['actions' => [], 'errors' => [], 'verified' => true, 'checked' => true];
        });
        $this->app->instance(QualityScoreImprovementAgent::class, $agent);
        (new ReviewGoogleAdsRecommendations)->handle();
        $this->assertDatabaseHas('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'ad_strength_recommendation_checked', 'status' => 'pending']);
        (new ReviewGoogleAdsRecommendations)->handle();
    }

    public function test_sitelink_action_item_uses_verified_asset_repair_and_persists_unresolved_coverage(): void
    {
        Notification::fake();
        $campaign = $this->campaign();
        $row = $this->row();
        $row['adGroupAd']['actionItems'][] = 'Add 2 more sitelinks.';
        $this->reader([$row]);
        $this->repairServices();
        $extensions = $this->createMock(\App\Services\Agents\AdExtensionAgent::class);
        $extensions->expects($this->once())->method('repairSitelinks')->with($campaign, 2, $this->anything())->willReturn([
            'created' => [], 'errors' => [], 'unresolved' => ['More verified destination pages are required.'],
        ]);
        $this->app->instance(\App\Services\Agents\AdExtensionAgent::class, $extensions);
        $result = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
        $this->assertCount(1, $result['actions']);
        $this->assertFalse($result['verified']);
        $this->assertSame('sitelink_coverage_unresolved', $result['unresolved'][0]['reason']);
        $attempt = app(GoogleAdStrengthRepair::class)->latest($campaign, self::AD);
        $this->assertSame(['More verified destination pages are required.'], $attempt->details['sitelink_repair']['unresolved']);
    }

    public function test_verified_sitelink_repair_deduplicates_existing_urls_and_requires_successful_links(): void
    {
        $campaign = $this->campaign();
        $campaign->customer->update(['website' => 'https://example.com']);
        foreach (['pricing' => 'Pricing', 'features' => 'Features', 'contact' => 'Contact'] as $path => $title) {
            \App\Models\CustomerPage::create(['customer_id' => $campaign->customer_id, 'url' => 'https://example.com/'.$path,
                'title' => $title, 'content' => 'Verified '.$title.' source page.', 'page_type' => 'service']);
        }
        $reader = $this->createMock(ReadCampaignConfiguration::class);
        $reader->method('campaignStatus')->willReturn('ENABLED');
        $reader->method('sitelinks')->willReturn([['asset' => ['finalUrls' => ['https://example.com/pricing']]]]);
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
        $creator = $this->createMock(\App\Services\GoogleAds\CommonServices\CreateSitelinkAsset::class);
        $creator->expects($this->exactly(2))->method('__invoke')->willReturnCallback(function ($id, $text, $desc1, $desc2, $url) {
            $this->assertNotSame('https://example.com/pricing', $url);
            $this->assertContains($url, ['https://example.com/features', 'https://example.com/contact']);

            return 'customers/123/assets/'.basename($url);
        });
        $this->app->bind(\App\Services\GoogleAds\CommonServices\CreateSitelinkAsset::class, fn () => $creator);
        $linker = $this->createMock(\App\Services\GoogleAds\CommonServices\LinkCampaignAsset::class);
        $linker->expects($this->exactly(2))->method('__invoke')->willReturn('customers/123/campaignAssets/link');
        $this->app->bind(\App\Services\GoogleAds\CommonServices\LinkCampaignAsset::class, fn () => $linker);
        $this->mock(GeminiService::class);
        $result = app(\App\Services\Agents\AdExtensionAgent::class)->repairSitelinks($campaign, 2);
        $this->assertCount(2, $result['created']);
        $this->assertSame([], $result['unresolved']);
        $this->assertSame([], $result['errors']);
    }

    public function test_daily_maintenance_retains_strength_outcomes_and_counts_failures(): void
    {
        Notification::fake();
        $campaign = $this->campaign();
        $campaign->update(['primary_status' => 'ELIGIBLE']);
        $selfHealing = $this->createMock(\App\Services\Agents\SelfHealingAgent::class);
        $selfHealing->method('heal')->willReturn(['actions_taken' => [], 'warnings' => [], 'errors' => []]);
        $search = $this->createMock(\App\Services\Agents\SearchTermMiningAgent::class);
        $search->method('mine')->willReturn([]);
        $budget = $this->createMock(\App\Services\Agents\BudgetIntelligenceAgent::class);
        $budget->method('optimize')->willReturn([]);
        $creative = $this->createMock(\App\Services\Agents\CreativeIntelligenceAgent::class);
        $creative->method('analyze')->willReturn(['errors' => ['Creative read failed']]);
        $extensions = $this->createMock(\App\Services\Agents\AdExtensionAgent::class);
        $extensions->method('manage')->willReturn([]);
        $bid = $this->createMock(\App\Services\Agents\BidAdjustmentAgent::class);
        $bid->method('optimize')->willReturn([]);
        $qs = $this->createMock(QualityScoreImprovementAgent::class);
        $qs->method('improve')->willReturn([]);
        $strength = ['actions' => [], 'errors' => ['Google read failed'], 'unresolved' => [['reason' => 'google_read_failed']], 'verified' => false, 'checked' => false];
        $qs->expects($this->once())->method('checkAdStrength')->willReturn($strength);
        (new \App\Jobs\AutomatedCampaignMaintenance)->handle($selfHealing, $search, $budget, $creative, $extensions, $bid, $qs,
            $this->createMock(\App\Services\Agents\FacebookLearningPhaseAgent::class),
            $this->createMock(\App\Services\Agents\FacebookAdRelevanceDiagnosticsAgent::class),
            $this->createMock(\App\Services\Agents\LinkedInCampaignOptimizationAgent::class),
            $this->createMock(\App\Services\Agents\AudienceIntelligenceAgent::class));
        $this->assertSame($strength, $campaign->fresh()->last_maintenance_results['ad_strength']);
        $run = \App\Models\AgentRun::where('job', 'AutomatedCampaignMaintenance')->latest('id')->first();
        $this->assertSame(2, $run->errors);
        $this->assertSame(1, $run->warnings);
        $this->assertSame(1, $run->details['ad_strength_unresolved']);
    }

    public function test_verification_started_before_a_new_repair_cannot_complete_the_old_attempt(): void
    {
        $campaign = $this->campaign();
        $old = $this->submitted($campaign);
        $new = $this->submitted($campaign);
        $result = app(GoogleAdStrengthRepair::class)->verify($campaign, $old,
            GoogleAdStrengthRepair::ad($this->row('GOOD', copy: $this->copy)));
        $this->assertSame('superseded', $result['status']);
        $this->assertSame('pending', $old->fresh()->status);
        $this->assertSame('pending', $new->fresh()->status);
    }

    public function test_fresh_local_holds_block_repair_for_all_callers_even_with_cached_active_models(): void
    {
        $campaign = $this->campaign();
        $this->reader([$this->row()]);
        $this->mock(GeminiService::class)->shouldNotReceive('generateContent');
        Campaign::whereKey($campaign->id)->update(['status' => 'paused']);
        $result = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
        $this->assertSame('campaign_not_active', $result['unresolved'][0]['reason']);
        $this->assertSame([], $result['actions']);
        Campaign::whereKey($campaign->id)->update(['status' => 'active']);
        \App\Models\Setting::set('managed_billing_enabled', true, 'boolean');
        $campaign->customer->adSpendCredit()->update(['payment_status' => 'paused']);
        $result = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
        $this->assertSame('ad_spend_unfunded', $result['unresolved'][0]['reason']);
        $this->assertSame([], $result['actions']);
        $campaign->customer->adSpendCredit()->update(['payment_status' => 'current']);
        \Laravel\Pennant\Feature::for($campaign->customer)->deactivate(\App\Features\AutoHealing::class);
        $result = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
        $this->assertSame('auto_healing_disabled', $result['unresolved'][0]['reason']);
        $this->assertSame([], $result['actions']);
        \Laravel\Pennant\Feature::for($campaign->customer)->activate(\App\Features\AutoHealing::class);
        $campaign->strategies()->update(['signed_off_at' => null]);
        $result = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
        $this->assertSame('strategy_not_approved', $result['unresolved'][0]['reason']);
    }

    public function test_a_pause_during_copy_review_is_rechecked_before_the_google_write(): void
    {
        $campaign = $this->campaign();
        $this->reader([$this->row()]);
        $ai = $this->createMock(GeminiService::class);
        $ai->method('generateContent')->willReturn(['text' => json_encode($this->copy)]);
        $this->app->instance(GeminiService::class, $ai);
        $review = $this->createMock(AdminMonitorService::class);
        $review->method('reviewAdCopy')->willReturnCallback(function () use ($campaign) {
            Campaign::whereKey($campaign->id)->update(['status' => 'paused']);

            return ['overall_status' => 'approved'];
        });
        $this->app->instance(AdminMonitorService::class, $review);
        $updater = $this->createMock(UpdateResponsiveSearchAd::class);
        $updater->expects($this->never())->method('replace');
        $this->app->bind(UpdateResponsiveSearchAd::class, fn () => $updater);
        $result = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
        $this->assertSame([], $result['actions']);
        $this->assertSame('campaign_not_active', $result['unresolved'][0]['reason']);
        $this->assertSame(0, app(GoogleAdStrengthRepair::class)->attemptCount($campaign, self::AD));
    }

    public function test_hourly_verification_syncs_only_reviewed_matching_copy_and_keeps_destination_expectations(): void
    {
        $campaign = $this->campaign();
        $strategy = $campaign->strategies()->first();
        $old = ['headlines' => ['Old headline'], 'descriptions' => ['Old description'], 'resource' => self::AD,
            'final_urls' => ['https://example.com/original']];
        $strategy->update(['execution_result' => ['metadata' => ['google_search_baseline' => ['ads' => [$old]]]]]);
        $attempt = $this->submitted($campaign);
        $attempt->update(['details' => array_merge($attempt->details, ['review' => ['overall_status' => 'approved']])]);
        app(GoogleAdStrengthRepair::class)->verify($campaign, $attempt,
            GoogleAdStrengthRepair::ad($this->row('GOOD', copy: $this->copy)));
        $expected = $strategy->fresh()->execution_result['metadata']['google_search_baseline']['ads'][0];
        $this->assertSame($this->copy['headlines'], $expected['headlines']);
        $this->assertSame($this->copy['descriptions'], $expected['descriptions']);
        $this->assertSame($old['final_urls'], $expected['final_urls']);
        $this->assertSame('completed', $attempt->fresh()->status);
    }

    public function test_acknowledged_sitelink_repairs_extend_only_the_target_strategy_baseline(): void
    {
        $campaign = $this->campaign();
        $strategy = $campaign->strategies()->first();
        $baseline = ['ads' => [['resource' => self::AD]], 'sitelink_urls' => ['https://example.com/pricing'],
            'asset_resources' => ['customers/123/assets/1']];
        $strategy->update(['execution_result' => ['metadata' => ['google_search_baseline' => $baseline]]]);
        app(GoogleAdStrengthRepair::class)->syncSitelinks($strategy, [
            ['url' => 'https://example.com/features', 'asset_resource' => 'customers/123/assets/2'],
        ]);
        $actual = $strategy->fresh()->execution_result['metadata']['google_search_baseline'];
        $this->assertSame(['https://example.com/pricing', 'https://example.com/features'], $actual['sitelink_urls']);
        $this->assertSame(['customers/123/assets/1', 'customers/123/assets/2'], $actual['asset_resources']);
        $this->assertSame($baseline['ads'], $actual['ads']);
    }

    public function test_google_pause_during_generation_is_re_read_before_sending_copy(): void
    {
        $campaign = $this->campaign();
        $reader = $this->createMock(ReadCampaignConfiguration::class);
        $reads = 0;
        $reader->method('ads')->willReturnCallback(function () use (&$reads) {
            $row = $this->row();
            if (++$reads > 1) {
                $row['campaign']['status'] = 'PAUSED';
            }

            return [$row];
        });
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
        $ai = $this->createMock(GeminiService::class);
        $ai->method('generateContent')->willReturn(['text' => json_encode($this->copy)]);
        $this->app->instance(GeminiService::class, $ai);
        $review = $this->createMock(AdminMonitorService::class);
        $review->method('reviewAdCopy')->willReturn(['overall_status' => 'approved']);
        $this->app->instance(AdminMonitorService::class, $review);
        $updater = $this->createMock(UpdateResponsiveSearchAd::class);
        $updater->expects($this->never())->method('replace');
        $this->app->bind(UpdateResponsiveSearchAd::class, fn () => $updater);
        $result = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
        $this->assertSame([], $result['actions']);
        $this->assertSame('google_ad_changed_during_review', $result['unresolved'][0]['reason']);
    }

    public function test_pending_text_asset_review_blocks_improvement_despite_top_level_approval(): void
    {
        $campaign = $this->campaign();
        $attempt = $this->submitted($campaign);
        $this->travel(61)->minutes();
        $row = $this->row('GOOD', copy: $this->copy);
        $row['adGroupAd']['ad']['responsiveSearchAd']['headlines'][0]['policySummaryInfo'] = [
            'approvalStatus' => 'APPROVED', 'reviewStatus' => 'REVIEW_IN_PROGRESS',
        ];
        $state = app(GoogleAdStrengthRepair::class);
        $pending = $state->verify($campaign, $attempt, GoogleAdStrengthRepair::ad($row));
        $this->assertSame('pending', $pending['status']);
        $this->assertFalse(GoogleAdStrengthRepair::healthy(GoogleAdStrengthRepair::ad($row)));
        $this->assertSame('REVIEW_IN_PROGRESS', $attempt->fresh()->details['asset_policy_summaries'][0]['reviewStatus']);
        $row['adGroupAd']['ad']['responsiveSearchAd']['headlines'][0]['policySummaryInfo']['reviewStatus'] = 'REVIEWED';
        $complete = $state->verify($campaign, $attempt, GoogleAdStrengthRepair::ad($row));
        $this->assertSame('completed', $complete['status']);
    }
}
