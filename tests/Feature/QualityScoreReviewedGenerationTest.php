<?php

namespace Tests\Feature;

use App\Features\AutoHealing;
use App\Models\AdCopy;
use App\Models\AdSpendCredit;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\KeywordQualityScore;
use App\Models\Strategy;
use App\Services\AdminMonitorService;
use App\Services\Agents\QualityScoreImprovementAgent;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\UpdateResponsiveSearchAd;
use App\Services\GoogleAds\GenerateReviewedRsaCopy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QualityScoreReviewedGenerationTest extends TestCase
{
    use DatabaseTransactions;

    private function workspace(): array
    {
        $this->freezeTime();
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123', 'service_type' => 'managed',
            'website' => 'https://agency.example', 'currency_code' => 'AUD']);
        $customer->pages()->create(['url' => $customer->website, 'title' => 'Campaign Setup',
            'content' => 'Campaign setup costs EUR120 once with no recurring agency fees. '
                .'We research buyer keywords and write your ad copy. Advertising spend is paid separately.']);
        Feature::for($customer)->activate(AutoHealing::class);
        AdSpendCredit::factory()->create(['customer_id' => $customer->id]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'active',
            'google_ads_campaign_id' => 'customers/123/campaigns/9', 'platform_status' => 'ENABLED',
            'daily_budget' => '50.00', 'approved_daily_budget' => '50.00', 'total_budget' => '350.00']);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'signed_off_at' => now(), 'google_ads_campaign_id' => $campaign->google_ads_campaign_id,
            'google_ads_ad_group_id' => 'customers/123/adGroups/456']);
        $keyword = KeywordQualityScore::create(['customer_id' => $customer->id,
            'campaign_google_id' => $campaign->google_ads_campaign_id, 'ad_group_resource_name' => 'customers/123/adGroups/456',
            'criterion_resource_name' => 'customers/123/adGroupCriteria/456~789', 'keyword_text' => 'campaign setup',
            'quality_score' => 2, 'search_predicted_ctr' => 'BELOW_AVERAGE', 'recorded_at' => now()]);
        $writer = $this->createMock(UpdateResponsiveSearchAd::class);
        $writer->expects($this->never())->method('replace');
        $this->app->bind(UpdateResponsiveSearchAd::class, fn () => $writer);

        return [$campaign->load('customer'), $strategy, $keyword];
    }

    private function copy(): array
    {
        return ['headlines' => ['Advertising Campaign Setup', 'No Recurring Agency Fees', 'Keywords for Your Buyers',
            'Ad Copy Written for You', 'EUR120 Campaign Setup'],
            'descriptions' => ['Get buyer keyword research and ad copy with our campaign setup service.',
                'EUR120 is the one-time setup service fee. Advertising spend is paid separately.']];
    }

    private function modelReview(): array
    {
        return ['overall_score' => 88, 'factual_accuracy' => true, 'intent_relevance' => true,
            'feedback' => ['headlines' => [], 'descriptions' => []], 'blocking_issues' => []];
    }

    private function model(bool $recover = true, ?callable $duringReview = null): GeminiService
    {
        $good = $this->copy();
        $bad = $good;
        $bad['headlines'][1] = 'No Recurring Fees';
        $generations = 0;
        $model = $this->createMock(GeminiService::class);
        $model->expects($this->exactly($recover ? 4 : 6))->method('generateContent')
            ->willReturnCallback(function ($name, $prompt) use (&$generations, $good, $bad, $recover, $duringReview) {
                if (str_starts_with($prompt, 'Review the following ad copy')) {
                    if ($duringReview !== null) {
                        $duringReview();
                    }
                    $data = $this->modelReview();
                } else {
                    $generations++;
                    if ($generations > 1) {
                        $this->assertStringContainsString('fee_scope_misleading_recurring_claim', $prompt);
                        $this->assertStringContainsString('Previous draft excerpt', $prompt);
                        $this->assertStringContainsString('No Recurring Fees', $prompt);
                    }
                    $data = $recover && $generations > 1 ? $good : $bad;
                }

                return ['text' => json_encode($data, JSON_THROW_ON_ERROR)];
            });
        $this->app->instance(GeminiService::class, $model);
        $this->app->instance(AdminMonitorService::class, new AdminMonitorService($model));

        return $model;
    }

    public function test_fee_rejection_is_corrected_and_only_reviewed_copy_is_saved_to_the_exact_keyword_strategy(): void
    {
        [$campaign, $strategy] = $this->workspace();
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'signed_off_at' => now(),
            'google_ads_campaign_id' => $campaign->google_ads_campaign_id, 'google_ads_ad_group_id' => 'customers/123/adGroups/999']);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Facebook Ads', 'signed_off_at' => now(),
            'google_ads_ad_group_id' => 'customers/123/adGroups/456']);
        $before = $campaign->only(['status', 'daily_budget', 'approved_daily_budget', 'total_budget']);

        $this->model();
        $result = app(QualityScoreImprovementAgent::class)->improve($campaign);

        $this->assertSame([], $result['errors']);
        $saved = AdCopy::whereIn('strategy_id', $campaign->strategies()->pluck('id'))->sole();
        $this->assertSame($strategy->id, $saved->strategy_id);
        $this->assertSame($this->copy(), $saved->only(['headlines', 'descriptions']));
        $activity = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_copy_generated')->sole();
        $this->assertSame(['rejected', 'approved'], array_column($activity->details['generation_attempts'], 'status'));
        $this->assertSame($saved->id, $activity->details['ad_copy_id']);
        $this->assertSame($before, $campaign->fresh()->only(array_keys($before)));
    }

    public function test_sdk_numeric_buckets_reach_the_reviewed_generation_and_diagnostic_paths(): void
    {
        [$campaign, $strategy, $keyword] = $this->workspace();
        $keyword->update(['search_predicted_ctr' => 2, 'creative_quality_score' => 2, 'post_click_quality_score' => 2]);
        $this->assertSame('2', $keyword->fresh()->search_predicted_ctr);

        $this->model();
        $result = app(QualityScoreImprovementAgent::class)->improve($campaign);

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['actions']);
        $this->assertSame($this->copy(), $strategy->adCopies()->sole()->only(['headlines', 'descriptions']));
        $this->assertSame(['creative_quality_score: BELOW_AVERAGE', 'post_click_quality_score: BELOW_AVERAGE'],
            array_column($result['flagged'], 'issue'));
    }

    public function test_exhausted_fee_rejections_preserve_audit_history_without_saving_collateral(): void
    {
        [$campaign, $strategy] = $this->workspace();
        $this->model(false);
        $result = app(QualityScoreImprovementAgent::class)->improve($campaign);

        $this->assertSame([], $result['actions']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame(0, $strategy->adCopies()->count());
        $activity = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_copy_generation_attempt')->sole();
        $this->assertSame('needs_review', $activity->status);
        $this->assertCount(3, $activity->details['generation_attempts']);
        $this->assertSame('active', $campaign->fresh()->status->value);
        $this->assertSame('50.00', (string) $campaign->fresh()->daily_budget);
    }

    public static function invalidTargets(): array
    {
        return [['missing'], ['conflicting_group'], ['foreign_account'], ['foreign_strategy_target'], ['duplicate_strategy']];
    }

    #[DataProvider('invalidTargets')]
    public function test_unconfirmed_keyword_targets_never_fall_back_to_the_latest_strategy(string $case): void
    {
        [$campaign, $strategy, $keyword] = $this->workspace();
        match ($case) {
            'missing' => $keyword->update(['ad_group_resource_name' => '', 'criterion_resource_name' => '']),
            'conflicting_group' => $keyword->update(['ad_group_resource_name' => 'customers/123/adGroups/999']),
            'foreign_account' => $keyword->update(['criterion_resource_name' => 'customers/987/adGroupCriteria/456~789']),
            'foreign_strategy_target' => $strategy->forceFill(['google_ads_campaign_id' => 'customers/987/campaigns/9'])->save(),
            'duplicate_strategy' => Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
                'signed_off_at' => now(), 'google_ads_campaign_id' => $campaign->google_ads_campaign_id,
                'google_ads_ad_group_id' => 'customers/123/adGroups/456']),
            default => throw new \LogicException('Unknown keyword target fixture.'),
        };
        $generator = $this->createMock(GenerateReviewedRsaCopy::class);
        $generator->expects($this->never())->method('generate');
        $this->app->instance(GenerateReviewedRsaCopy::class, $generator);

        $result = app(QualityScoreImprovementAgent::class)->improve($campaign);

        $this->assertSame([], $result['actions']);
        $this->assertSame('ad_copy_strategy_unconfirmed', $result['flagged'][0]['issue']);
        $this->assertSame(0, $strategy->adCopies()->count());
    }

    public function test_criterion_resource_can_identify_the_exact_group_when_the_group_column_is_missing(): void
    {
        [$campaign, $strategy, $keyword] = $this->workspace();
        $keyword->update(['ad_group_resource_name' => '']);
        $this->model();
        $result = app(QualityScoreImprovementAgent::class)->improve($campaign);

        $this->assertSame([], $result['errors']);
        $this->assertSame($strategy->id, $result['actions'][0]['strategy_id']);
        $this->assertSame($this->copy(), $strategy->adCopies()->sole()->only(['headlines', 'descriptions']));
    }

    public function test_a_pause_saved_after_loading_the_campaign_blocks_generation(): void
    {
        [$campaign, $strategy] = $this->workspace();
        Campaign::whereKey($campaign->id)->update(['status' => 'paused', 'platform_status' => 'PAUSED']);
        $generator = $this->createMock(GenerateReviewedRsaCopy::class);
        $generator->expects($this->never())->method('generate');
        $this->app->instance(GenerateReviewedRsaCopy::class, $generator);

        $result = app(QualityScoreImprovementAgent::class)->improve($campaign);

        $this->assertSame([], $result['actions']);
        $this->assertSame('ad_copy_generation_hold', $result['flagged'][0]['issue']);
        $this->assertSame(0, $strategy->adCopies()->count());
        $this->assertSame('paused', $campaign->fresh()->status->value);
        $this->assertSame('50.00', (string) $campaign->fresh()->daily_budget);
    }

    public function test_a_pause_during_review_preserves_the_approved_candidate_in_audit_but_never_saves_new_copy(): void
    {
        [$campaign, $strategy] = $this->workspace();
        $this->model(duringReview: fn () => Campaign::whereKey($campaign->id)->update(['status' => 'paused', 'platform_status' => 'PAUSED']));
        $result = app(QualityScoreImprovementAgent::class)->improve($campaign);

        $this->assertSame([], $result['actions']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('ad_copy_generation_hold', $result['flagged'][0]['issue']);
        $this->assertSame(0, $strategy->adCopies()->count());
        $activity = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_copy_generation_attempt')->sole();
        $this->assertSame('skipped', $activity->status);
        $this->assertSame('approved', $activity->details['review']['overall_status']);
        $this->assertCount(2, $activity->details['generation_attempts']);
        $this->assertSame('paused', $campaign->fresh()->status->value);
        $this->assertSame('50.00', (string) $campaign->fresh()->daily_budget);
    }
}
