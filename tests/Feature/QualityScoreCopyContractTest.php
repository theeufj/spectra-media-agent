<?php

namespace Tests\Feature;

use App\Jobs\VerifyGoogleAdImprovement;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\AdminMonitorService;
use App\Services\Agents\QualityScoreImprovementAgent;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\CommonServices\UpdateResponsiveSearchAd;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class QualityScoreCopyContractTest extends TestCase
{
    use DatabaseTransactions;

    public function test_object_and_legacy_array_responses_are_reviewed_as_complete_ads(): void
    {
        $copy = ['headlines' => ['Google Ads Management', 'Your AI Marketing Team', 'Explore Our Plans'],
            'descriptions' => ['Manage your campaigns with AI.', 'See your results in one place.']];
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123']);
        foreach ([$copy, [$copy]] as $response) {
            $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => 'customers/123/campaigns/9']);
            Strategy::factory()->create(['campaign_id' => $campaign->id, 'google_ads_ad_group_id' => 'customers/123/adGroups/456']);
            $resource = 'customers/123/adGroupAds/456~789';
            $reader = Mockery::mock(ReadCampaignConfiguration::class);
            $reader->shouldReceive('ads')->andReturn([['adGroupAd' => ['resourceName' => $resource, 'adStrength' => 'POOR',
                'ad' => ['id' => '789', 'responsiveSearchAd' => ['headlines' => [['text' => 'Old headline']], 'descriptions' => [['text' => 'Old description']]]]]]]);
            $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
            $ai = Mockery::mock(GeminiService::class);
            $ai->shouldReceive('generateContent')->andReturn(['text' => json_encode($response)]);
            $this->app->instance(GeminiService::class, $ai);
            $review = Mockery::mock(AdminMonitorService::class);
            $review->shouldReceive('reviewAdCopy')->withArgs(fn ($candidate, $existing) => $existing
                && $candidate->headlines === $copy['headlines'] && $candidate->descriptions === $copy['descriptions'])
                ->andReturn(['overall_status' => 'approved']);
            $this->app->instance(AdminMonitorService::class, $review);
            $updater = Mockery::mock(UpdateResponsiveSearchAd::class);
            $updater->shouldReceive('replace')->withArgs(fn ($id, $ad, $headlines, $descriptions) => $id === '123' && $ad === $resource
                && $headlines === $copy['headlines'] && $descriptions === $copy['descriptions'])->andReturn(true);
            $this->app->bind(UpdateResponsiveSearchAd::class, fn () => $updater);
            $result = app(QualityScoreImprovementAgent::class)->checkAdStrength($campaign);
            $this->assertSame([], $result['errors']);
            $this->assertCount(1, $result['actions']);
            $this->assertSame('pending', $result['actions'][0]['verification']);
        }
        Queue::assertPushed(VerifyGoogleAdImprovement::class, 2);
    }

    public function test_review_prompt_has_one_consistent_required_json_contract(): void
    {
        $prompt = (new \App\Prompts\AdCopyReviewPrompt('google', 'Headline', 'Description'))->getPrompt();
        $this->assertStringNotContainsString('two keys', $prompt);
        foreach (['"factual_accuracy": true', '"intent_relevance": true', '"blocking_issues": []'] as $field) {
            $this->assertStringContainsString($field, $prompt);
        }
    }
}
