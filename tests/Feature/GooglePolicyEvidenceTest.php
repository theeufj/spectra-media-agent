<?php

namespace Tests\Feature;

use App\Jobs\VerifyAdApproval;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\CriticalAgentAlert;
use App\Services\Agents\SelfHealingAgent;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\GetAdStatus;
use App\Services\Health\CampaignHealthChecker;
use App\Support\GoogleAdPolicy;
use Google\Ads\GoogleAds\V22\Common\PolicyTopicEntry;
use Google\Ads\GoogleAds\V22\Common\PolicyTopicEvidence;
use Google\Ads\GoogleAds\V22\Common\PolicyTopicEvidence\DestinationNotWorking;
use Google\Ads\GoogleAds\V22\Common\PolicyTopicEvidence\TextList;
use Google\Ads\GoogleAds\V22\Enums\PolicyApprovalStatusEnum\PolicyApprovalStatus;
use Google\Ads\GoogleAds\V22\Enums\PolicyReviewStatusEnum\PolicyReviewStatus;
use Google\Ads\GoogleAds\V22\Resources\Ad;
use Google\Ads\GoogleAds\V22\Resources\AdGroup;
use Google\Ads\GoogleAds\V22\Resources\AdGroupAd;
use Google\Ads\GoogleAds\V22\Resources\AdGroupAdPolicySummary;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use Google\ApiCore\PagedListResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GooglePolicyEvidenceTest extends TestCase
{
    use DatabaseTransactions;

    private const AD = 'customers/1234567890/adGroupAds/555~777';

    private function row(): GoogleAdsRow
    {
        return new GoogleAdsRow([
            'ad_group' => new AdGroup(['resource_name' => 'customers/1234567890/adGroups/555', 'status' => 2]),
            'ad_group_ad' => new AdGroupAd([
                'resource_name' => self::AD,
                'status' => 2,
                'ad' => new Ad(['final_urls' => ['https://example.com/offer']]),
                'policy_summary' => new AdGroupAdPolicySummary([
                    'approval_status' => PolicyApprovalStatus::DISAPPROVED,
                    'review_status' => PolicyReviewStatus::REVIEWED,
                    'policy_topic_entries' => [new PolicyTopicEntry([
                        'topic' => 'DESTINATION_NOT_WORKING',
                        'type' => 4,
                        'evidences' => [new PolicyTopicEvidence([
                            'destination_not_working' => new DestinationNotWorking([
                                'expanded_url' => 'https://example.com/offer?from=google',
                                'device' => 2,
                                'last_checked_date_time' => '2026-10-03 12:00:00',
                                'http_error_code' => 522,
                            ]),
                        ])],
                    ])],
                ]),
            ]),
        ]);
    }

    private function source(GoogleAdsRow $row, ?string $adFilter = null): GetAdStatus
    {
        $response = $this->createMock(PagedListResponse::class);
        $response->method('getIterator')->willReturn(new \ArrayIterator([$row]));
        $source = $this->createPartialMock(GetAdStatus::class, ['ensureClient', 'searchQuery']);
        $source->method('ensureClient')->willReturnCallback(fn () => null);
        $source->method('searchQuery')->willReturnCallback(function ($customerId, $query) use ($response, $adFilter) {
            $this->assertSame('1234567890', $customerId);
            $this->assertStringContainsString('ad_group_ad.policy_summary.policy_topic_entries', $query);
            $this->assertStringContainsString('ad_group_ad.ad.final_urls', $query);
            if ($adFilter) {
                $this->assertStringContainsString("WHERE ad_group_ad.resource_name = '{$adFilter}'", $query);
            }

            return $response;
        });

        return $source;
    }

    private function campaign(): Campaign
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123-456-7890']);

        return Campaign::factory()->create([
            'customer_id' => $customer->id,
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/999',
        ])->load('customer');
    }

    private function ad(): array
    {
        return $this->source($this->row())('123-456-7890')[0];
    }

    public function test_sdk_destination_evidence_reaches_normalized_policy_details_and_message(): void
    {
        $ad = $this->ad();
        $this->assertSame(['https://example.com/offer'], $ad['final_urls']);
        $evidence = $ad['policy_topics'][0]['evidences'][0];
        $this->assertSame('destination_not_working', $evidence['type']);
        $this->assertSame(522, $evidence['http_error_code']);
        $this->assertSame('DESKTOP', $evidence['device']);
        $this->assertSame('https://example.com/offer?from=google', $evidence['expanded_url']);
        $this->assertSame('2026-10-03 12:00:00', $evidence['last_checked_at']);
        $this->assertNull($evidence['dns_error_type']);
        $this->assertTrue(GoogleAdPolicy::isDestinationIssue($ad));
        $this->assertStringContainsString('HTTP 522', GoogleAdPolicy::summarize($ad));
        $this->assertStringContainsString('DESKTOP', GoogleAdPolicy::summarize($ad));
        $this->assertSame(self::AD, GoogleAdPolicy::details($ad)['ad_resource_name']);
    }

    public function test_dns_and_other_policy_evidence_are_preserved_without_fabricated_http_errors(): void
    {
        $topic = GoogleAdPolicy::topic(new PolicyTopicEntry([
            'topic' => 'DESTINATION_NOT_WORKING',
            'evidences' => [new PolicyTopicEvidence([
                'destination_not_working' => new DestinationNotWorking(['dns_error_type' => 2, 'device' => 3]),
            ]), new PolicyTopicEvidence(['text_list' => new TextList(['texts' => ['Affected ad text']])])],
        ]));
        $this->assertNull($topic['evidences'][0]['http_error_code']);
        $this->assertSame('HOSTNAME_NOT_FOUND', $topic['evidences'][0]['dns_error_type']);
        $this->assertSame('ANDROID', $topic['evidences'][0]['device']);
        $this->assertSame(['Affected ad text'], $topic['evidences'][1]['data']['textList']['texts']);
    }

    public function test_missing_policy_summary_is_unknown_and_exact_ad_query_is_scoped(): void
    {
        $row = new GoogleAdsRow(['ad_group_ad' => new AdGroupAd(['resource_name' => self::AD])]);
        $source = $this->source($row, self::AD);
        $ad = $source->forAd('123-456-7890', self::AD);
        $this->assertNull($ad['approval_status']);
        $this->assertSame([], $ad['policy_topics']);

        $this->expectException(\InvalidArgumentException::class);
        $source->forAd('9999999999', self::AD);
    }

    public function test_failed_api_read_throws_instead_of_becoming_an_empty_successful_read(): void
    {
        $source = $this->createPartialMock(GetAdStatus::class, ['ensureClient', 'searchQuery', 'logError']);
        $source->method('ensureClient')->willReturnCallback(fn () => null);
        $failure = new \RuntimeException('Read failed');
        $source->method('searchQuery')->willThrowException($failure);
        $source->expects($this->once())->method('logError')->with($this->isType('string'), $failure);
        $this->expectExceptionObject($failure);
        $source('1234567890');
    }

    public function test_destination_failure_is_recorded_unresolved_without_copy_regeneration(): void
    {
        $campaign = $this->campaign();
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->never())->method('generateContent');
        $agent = new SelfHealingAgent($gemini);
        $ad = $this->ad();
        $results = $this->handleDestination($agent, $campaign, $ad);
        $this->handleDestination($agent, $campaign, $ad);

        $this->assertSame([], $results['actions_taken']);
        $this->assertSame('google_destination_unresolved', $results['warnings'][0]['type']);
        $this->assertStringContainsString('HTTP 522', $results['warnings'][0]['message']);
        $activities = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'google_destination_unresolved')->get();
        $this->assertCount(1, $activities);
        $this->assertSame('needs_review', $activities[0]->status);
    }

    public function test_destination_failure_blocks_later_google_healing_checks(): void
    {
        $campaign = $this->campaign();
        $agent = $this->createPartialMock(SelfHealingAgent::class,
            ['healGoogleDisapprovedAds', 'checkGoogleBudgetHealth', 'checkGoogleDeliveryHealth']);
        $agent->expects($this->once())->method('healGoogleDisapprovedAds')->willReturnCallback(function ($customer, $id, $resource, &$results) {
            $results['warnings'][] = ['type' => 'google_destination_unresolved'];
        });
        $agent->expects($this->never())->method('checkGoogleBudgetHealth');
        $agent->expects($this->never())->method('checkGoogleDeliveryHealth');
        $results = ['actions_taken' => [], 'warnings' => [], 'errors' => []];
        (new \ReflectionMethod(SelfHealingAgent::class, 'healGoogleAdsCampaign'))
            ->invokeArgs($agent, [$campaign, $campaign->customer, &$results]);
        $this->assertSame([], $results['actions_taken']);
    }

    public function test_recurring_copy_policy_failure_escalates_to_customer_without_another_rewrite(): void
    {
        Notification::fake();
        config(['budget_rules.self_healing.max_fix_attempts' => 3]);
        $campaign = $this->campaign();
        $user = User::factory()->create();
        $campaign->customer->users()->attach($user);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            AgentActivity::record('self_healing', 'google_ad_regenerated', 'Previous rewrite attempt',
                $campaign->customer_id, $campaign->id, ['violation_topic' => 'MISLEADING_CLAIMS']);
        }
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->never())->method('generateContent');
        $ad = $this->ad();
        $ad['policy_topics'] = [['topic' => 'MISLEADING_CLAIMS', 'type' => 4, 'evidences' => []]];

        $results = $this->handleDestination(new SelfHealingAgent($gemini), $campaign, $ad);

        $this->assertSame('escalated_recurring_violation', $results['actions_taken'][0]['type']);
        Notification::assertSentTo($user, CriticalAgentAlert::class, fn ($alert) => $alert->title === 'Recurring Google ad policy violation'
            && str_contains($alert->message, 'MISLEADING_CLAIMS')
            && $alert->details['ad'] === self::AD
            && $alert->details['campaign_id'] === $campaign->id);
    }

    public function test_health_check_displays_destination_evidence_and_failed_read_is_unknown(): void
    {
        $campaign = $this->campaign();
        $source = $this->createMock(GetAdStatus::class);
        $source->expects($this->once())->method('__invoke')->with('1234567890', $campaign->google_ads_campaign_id)->willReturn([$this->ad()]);
        $this->app->bind(GetAdStatus::class, fn () => $source);
        $method = new \ReflectionMethod(CampaignHealthChecker::class, 'checkAdApprovalStatus');
        $health = $method->invoke(new CampaignHealthChecker, $campaign);
        $this->assertStringContainsString('HTTP 522', $health['issues'][0]['message']);
        $this->assertStringNotContainsString('Our team is working', $health['issues'][0]['message']);
        $this->assertSame(self::AD, $health['issues'][0]['details']['ad_resource_name']);

        $source = $this->createMock(GetAdStatus::class);
        $source->method('__invoke')->willThrowException(new \RuntimeException('Cannot read policy'));
        $this->app->bind(GetAdStatus::class, fn () => $source);
        $health = $method->invoke(new CampaignHealthChecker, $campaign);
        $this->assertSame('google_ad_policy_unknown', $health['warnings'][0]['type']);
        $this->assertSame([], $health['issues']);
    }

    public function test_exact_replacement_approval_verification_uses_policy_enums_and_retains_evidence(): void
    {
        Notification::fake();
        $campaign = $this->campaign();
        $user = User::factory()->create();
        $campaign->customer->users()->attach($user);
        $source = $this->createMock(GetAdStatus::class);
        $source->expects($this->once())->method('forAd')->with('1234567890', self::AD)->willReturn($this->ad());
        $this->app->bind(GetAdStatus::class, fn () => $source);
        (new VerifyAdApproval($campaign->customer, self::AD, $campaign->id))->handle();
        $activity = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'google_ad_approval_checked')->firstOrFail();
        $this->assertSame('disapproved', $activity->details['outcome']);
        $this->assertSame(522, $activity->details['policy_topics'][0]['evidences'][0]['http_error_code']);
        Notification::assertSentTo($user, CriticalAgentAlert::class, fn ($alert) => $alert->alertType === 'google_ad_approval_disapproved'
            && str_contains($alert->message, 'HTTP 522'));
    }

    public function test_review_pending_missing_ad_and_approved_limited_are_not_reported_as_full_approval(): void
    {
        Notification::fake();
        $campaign = $this->campaign();
        foreach ([
            [null, 'unknown'],
            [['status' => 2, 'approval_status' => PolicyApprovalStatus::APPROVED, 'review_status' => PolicyReviewStatus::REVIEW_IN_PROGRESS], 'pending'],
            [['status' => 2, 'approval_status' => PolicyApprovalStatus::APPROVED_LIMITED, 'review_status' => PolicyReviewStatus::REVIEWED], 'limited'],
            [['status' => 2, 'approval_status' => PolicyApprovalStatus::APPROVED, 'review_status' => PolicyReviewStatus::REVIEWED], 'approved'],
        ] as [$ad, $expected]) {
            $source = $this->createMock(GetAdStatus::class);
            $source->expects($this->once())->method('forAd')->willReturn($ad);
            $this->app->bind(GetAdStatus::class, fn () => $source);
            (new VerifyAdApproval($campaign->customer, self::AD, $campaign->id))->handle();
            $activity = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'google_ad_approval_checked')->latest('id')->firstOrFail();
            $this->assertSame($expected, $activity->details['outcome']);
            $this->assertSame($expected === 'approved' ? 'completed' : 'needs_review', $activity->status);
        }
    }

    public function test_verification_api_failure_persists_unknown_and_retries(): void
    {
        Notification::fake();
        $campaign = $this->campaign();
        $source = $this->createMock(GetAdStatus::class);
        $source->method('forAd')->willThrowException(new \RuntimeException('Policy unavailable'));
        $this->app->bind(GetAdStatus::class, fn () => $source);
        try {
            (new VerifyAdApproval($campaign->customer, self::AD, $campaign->id))->handle();
            $this->fail('The verification job must retry a failed API read.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Policy unavailable', $e->getMessage());
        }
        $activity = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'google_ad_approval_checked')->firstOrFail();
        $this->assertSame('unknown', $activity->details['outcome']);
        $this->assertSame('needs_review', $activity->status);
    }

    private function handleDestination(SelfHealingAgent $agent, Campaign $campaign, array $ad): array
    {
        $results = ['actions_taken' => [], 'warnings' => [], 'errors' => []];
        (new \ReflectionMethod(SelfHealingAgent::class, 'handleGoogleDisapprovedAd'))
            ->invokeArgs($agent, [$campaign, $campaign->customer, '1234567890', $ad, &$results]);

        return $results;
    }
}
