<?php

namespace Tests\Feature;

use App\Jobs\VerifyGoogleAdImprovement;
use App\Models\AdCopy;
use App\Models\AdSpendCredit;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\AdExtensionAgent;
use App\Services\Agents\QualityScoreImprovementAgent;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\CommonServices\UpdateResponsiveSearchAd;
use App\Services\GoogleAds\GenerateReviewedRsaCopy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleAdStrengthLeaseGuardTest extends TestCase
{
    use DatabaseTransactions;

    public static function lostLeases(): array
    {
        return [
            'expires during generation without a replacement owner' => ['expired', false],
            'expires during generation and another worker acquires it' => ['reacquired', false],
            'another owner replaces the lease before its deadline' => ['replaced', false],
            'expires during the final Google read' => ['expired', true],
            'generation throws after expiry and another ad needs sitelinks' => ['expired', false, true],
        ];
    }

    #[DataProvider('lostLeases')]
    public function test_a_former_lock_owner_cannot_submit_reviewed_copy_or_continue_to_another_ad(string $loss, bool $duringRead, bool $generationThrows = false): void
    {
        config(['cache.default' => 'array']);
        Notification::fake();
        $this->travelTo(now()->startOfSecond());
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123', 'service_type' => 'managed']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'active',
            'google_ads_campaign_id' => 'customers/123/campaigns/9', 'daily_budget' => '50.00',
            'approved_daily_budget' => '50.00', 'total_budget' => '350.00', 'platform_status' => 'ENABLED']);
        $pausedTest = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'paused',
            'daily_budget' => '5.00', 'approved_daily_budget' => '5.00', 'platform_status' => 'PAUSED']);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'signed_off_at' => now(), 'google_ads_ad_group_id' => 'customers/123/adGroups/456']);
        $credit = AdSpendCredit::factory()->create(['customer_id' => $customer->id]);
        \Laravel\Pennant\Feature::for($customer)->activate(\App\Features\AutoHealing::class);
        $original = ['headlines' => ['Original live headline'], 'descriptions' => ['Original live description']];
        $collateral = AdCopy::create(['strategy_id' => $strategy->id, 'platform' => 'Google Ads', ...$original]);
        $campaignBefore = $campaign->only(['status', 'platform_status', 'daily_budget', 'approved_daily_budget', 'total_budget']);
        $pausedBefore = $pausedTest->only(['status', 'platform_status', 'daily_budget', 'approved_daily_budget']);
        $creditBefore = $credit->fresh()->getAttributes();

        $row = ['campaign' => ['status' => 'ENABLED'], 'adGroup' => ['status' => 'ENABLED'], 'adGroupAd' => [
            'resourceName' => 'customers/123/adGroupAds/456~789', 'status' => 'ENABLED', 'adStrength' => 'POOR', 'actionItems' => [],
            'policySummary' => ['approvalStatus' => 'APPROVED', 'reviewStatus' => 'REVIEWED'],
            'ad' => ['id' => '789', 'responsiveSearchAd' => [
                'headlines' => [['text' => $original['headlines'][0]]],
                'descriptions' => [['text' => $original['descriptions'][0]]],
            ]],
        ]];
        $nextAd = $row;
        $nextAd['adGroupAd']['resourceName'] = 'customers/123/adGroupAds/456~790';
        $nextAd['adGroupAd']['ad']['id'] = '790';
        $nextAd['adGroupAd']['actionItems'] = ['Add 2 more sitelinks'];
        $newOwner = null;
        $loseLease = function () use ($campaign, $loss, &$newOwner): void {
            if ($loss !== 'replaced') {
                $this->travel(901)->seconds();
            }
            if ($loss === 'replaced') {
                Cache::lock("ad_strength_repair:{$campaign->id}", 900)->forceRelease();
            }
            if ($loss !== 'expired') {
                $newOwner = Cache::lock("ad_strength_repair:{$campaign->id}", 900);
                $this->assertTrue($newOwner->get());
            }
        };
        $reads = 0;
        $reader = $this->createMock(ReadCampaignConfiguration::class);
        $reader->expects($this->exactly($generationThrows ? 1 : 2))->method('ads')->willReturnCallback(function () use (&$reads, $duringRead, $loseLease, $row, $nextAd) {
            if (++$reads === 2 && $duringRead) {
                $loseLease();
            }

            return [$row, $nextAd];
        });
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
        $copy = ['headlines' => ['Campaign Setup Service', 'Keywords Researched for You', 'Ad Copy Written for Search'],
            'descriptions' => ['Get keywords researched and search ads written for your business.', 'Explore the campaign setup service.']];
        $review = ['overall_status' => 'approved', 'programmatic_validation' => ['is_valid' => true, 'fee_scope' => ['is_valid' => true]],
            'gemini_feedback' => ['overall_score' => 88, 'factual_accuracy' => true, 'intent_relevance' => true,
                'feedback' => ['headlines' => [], 'descriptions' => []], 'blocking_issues' => []]];
        $generator = $this->createMock(GenerateReviewedRsaCopy::class);
        $generator->expects($this->once())->method('generate')->willReturnCallback(function ($campaign, $strategy, $existing, $onCandidate) use ($duringRead, $loseLease, $copy, $review, $generationThrows) {
            if (! $duringRead) {
                $loseLease();
            }
            if ($generationThrows) {
                throw new \RuntimeException('Generation provider failed after the repair lease expired.');
            }
            $history = [['attempt' => 1, 'status' => 'approved']];
            $onCandidate($history, $review, $copy);

            return ['copy' => $copy, 'review' => $review, 'generation_attempts' => $history];
        });
        $updater = $this->createMock(UpdateResponsiveSearchAd::class);
        $updater->expects($this->never())->method('replace');
        $this->app->bind(UpdateResponsiveSearchAd::class, fn () => $updater);
        $extensions = $this->createMock(AdExtensionAgent::class);
        $extensions->expects($this->never())->method('repairSitelinks');
        $this->app->instance(AdExtensionAgent::class, $extensions);

        try {
            $result = (new QualityScoreImprovementAgent($generator))->checkAdStrength($campaign->load('customer'));

            $this->assertSame([], $result['actions']);
            $attempt = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_strength_repair_attempt')->sole();
            if ($generationThrows) {
                $this->assertCount(1, $result['errors']);
                $this->assertSame('ad_strength_repair_failed', $result['errors'][0]->code);
                $this->assertSame('needs_review', $attempt->status);
                $this->assertSame('repair_failed', $attempt->details['reason']);
                $this->assertSame('repair_lock_lost', $result['unresolved'][1]['reason']);
                $this->assertSame($nextAd['adGroupAd']['resourceName'], $result['unresolved'][1]['ad_resource']);
            } else {
                $this->assertSame([], $result['errors']);
                $this->assertSame('repair_lock_lost', $result['unresolved'][0]['reason']);
                $this->assertSame('skipped', $attempt->status);
                $this->assertSame('repair_lock_lost', $attempt->details['reason']);
                $this->assertSame($copy, $attempt->details['last_candidate']);
            }
            $this->assertArrayNotHasKey('google_write_started_at', $attempt->details);
            $this->assertArrayNotHasKey('submitted_at', $attempt->details);
            $this->assertSame($original, $collateral->fresh()->only(['headlines', 'descriptions']));
            $this->assertSame(1, $strategy->adCopies()->count());
            $this->assertSame($campaignBefore, $campaign->fresh()->only(array_keys($campaignBefore)));
            $this->assertSame($pausedBefore, $pausedTest->fresh()->only(array_keys($pausedBefore)));
            $this->assertSame($creditBefore, $credit->fresh()->getAttributes());
            Queue::assertNotPushed(VerifyGoogleAdImprovement::class);
            Notification::assertNothingSent();
            if ($newOwner !== null) {
                $this->assertInstanceOf(\Illuminate\Cache\Lock::class, $newOwner);
                $this->assertTrue($newOwner->isOwnedByCurrentProcess(), 'The old worker must not release a replacement owner\'s lease.');
            }
        } finally {
            $newOwner?->release();
            $this->travelBack();
        }
    }
}
