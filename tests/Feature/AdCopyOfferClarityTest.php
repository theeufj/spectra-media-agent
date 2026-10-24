<?php

namespace Tests\Feature;

use App\Jobs\GenerateAdCopy;
use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Prompts\AdCopyPrompt;
use App\Services\AdminMonitorService;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdCopyOfferClarityTest extends TestCase
{
    use DatabaseTransactions;

    public static function platforms(): array
    {
        return ['Google Search' => ['Google Ads', 5, 3], 'Facebook' => ['Facebook Ads', 3, 2],
            'Microsoft Search' => ['Microsoft Ads', 5, 3]];
    }

    #[DataProvider('platforms')]
    public function test_offer_and_customer_value_precede_concepts_without_changing_platform_counts(string $platform, int $headlines, int $descriptions): void
    {
        $prompt = (new AdCopyPrompt('Use the handover concept first.', $platform,
            AdminMonitorService::getRulesForPlatform($platform), evidence: ['offer_brief' => 'Bicycle repair',
                'keywords' => ['bicycle repair'], 'sources' => [['content' => 'Bicycle repairs for local riders.']]]))->getPrompt();

        $this->assertStringContainsString('The first headline must clearly name the actual product or service', $prompt);
        $this->assertStringContainsString('The first description must explain what is being offered', $prompt);
        $this->assertStringContainsString('Search assets can be reordered or omitted', $prompt);
        $this->assertStringContainsString('at most ONE headline mainly about administrative workflow', $prompt);
        $this->assertStringContainsString('at least two DISTINCT, source-supported customer benefits', $prompt);
        $this->assertLessThan(strpos($prompt, 'Use the handover concept first.'), strpos($prompt, '--- OFFER AND CUSTOMER VALUE'));
        $this->assertStringContainsString("Write exactly {$headlines} headlines", $prompt);
        $this->assertStringContainsString("Write exactly {$descriptions} descriptions", $prompt);
        $this->assertStringContainsString("Use the offer's actual currency", $prompt);
        $this->assertStringContainsString('Omit prices, discounts, guarantees and claims that the sources do not establish', $prompt);
    }

    public static function unclearSets(): array
    {
        return ['all process headlines' => [false], 'clear opening with process-dominated remaining assets' => [true]];
    }

    #[DataProvider('unclearSets')]
    public function test_high_scoring_process_dominated_copy_is_rejected_and_cannot_become_the_style_fallback(bool $clearOpening): void
    {
        [$campaign, $strategy] = $this->campaign();
        $copy = $this->clearCopy();
        $existing = AdCopy::create(['strategy_id' => $strategy->id, 'platform' => 'Google Ads', ...$copy]);
        $this->fakeGeneration(recover: false, clearOpening: $clearOpening);

        $job = (new GenerateAdCopy($campaign, $strategy, 'Google Ads'))->withFakeQueueInteractions();
        $job->handle();

        $job->assertFailed();
        $this->assertSame($copy['headlines'], $existing->fresh()->headlines);
        $this->assertSame($copy['descriptions'], $existing->fresh()->descriptions);
        $this->assertSame(1, $strategy->adCopies()->count());
        Http::assertSentCount(20);
    }

    public function test_clear_offer_copy_with_a_lower_style_score_wins_over_an_irrelevant_high_scoring_attempt(): void
    {
        [$campaign, $strategy] = $this->campaign();
        $this->fakeGeneration(recover: true);

        $job = (new GenerateAdCopy($campaign, $strategy, 'Google Ads'))->withFakeQueueInteractions();
        $job->handle();

        $job->assertNotFailed();
        $saved = $strategy->adCopies()->sole();
        $this->assertSame($this->clearCopy()['headlines'], $saved->headlines);
        $this->assertSame($this->clearCopy()['descriptions'], $saved->descriptions);
        Http::assertSentCount(20);
    }

    public static function misleadingFeeScores(): array
    {
        return ['model approves at88' => [88], 'style fallback candidate at62' => [62]];
    }

    #[DataProvider('misleadingFeeScores')]
    public function test_source_fee_scope_rejection_cannot_be_overridden_by_model_approval_or_style_fallback(int $score): void
    {
        [$campaign, $strategy] = $this->campaign();
        $this->setOfferSource($campaign, 'Professional campaign setup costs US$999 once. Ad spend is paid separately to the advertising platform.');
        $existing = AdCopy::create(['strategy_id' => $strategy->id, 'platform' => 'Google Ads', ...$this->clearCopy()]);
        $before = $existing->only(['headlines', 'descriptions']);
        $bad = $this->clearCopy();
        $bad['descriptions'][1] = 'Ad spend goes directly to Google with no markup or recurring charges.';
        $bad['descriptions'][2] = 'No recurring agency fees. Ad spend is separate.';
        Http::fake(function (Request $request) use ($bad, $score) {
            $prompt = $request['contents'][0]['parts'][0]['text'];
            $review = str_starts_with($prompt, 'Review the following ad copy');
            if ($review) {
                $this->assertStringContainsString('fee_scope_misleading_recurring_claim', $prompt);
                $data = ['overall_score' => $score, 'factual_accuracy' => true, 'intent_relevance' => true,
                    'feedback' => ['headlines' => [], 'descriptions' => []], 'blocking_issues' => []];
            } else {
                if (str_contains($prompt, 'CRITICAL CORRECTIONS REQUIRED')) {
                    $this->assertStringContainsString('Qualify any fee waiver as the agency/service fee', $prompt);
                }
                $data = $bad;
            }

            return Http::response([['candidates' => [['content' => ['parts' => [['text' => json_encode($data, JSON_THROW_ON_ERROR)]]], 'finishReason' => 'STOP']]]]);
        });
        $job = (new GenerateAdCopy($campaign, $strategy, 'Google Ads'))->withFakeQueueInteractions();
        $job->handle();
        $job->assertFailed();
        $this->assertSame($before, $existing->fresh()->only(['headlines', 'descriptions']));
        Http::assertSentCount(20);
    }

    public static function supportedFeeClaims(): array
    {
        return [
            'qualified service fee with separate spend' => ['No Recurring Agency Fees',
                'Campaign setup is a one-time service. Ad spend is paid separately to the advertising platform.'],
            'legitimate unrelated one-time purchase' => ['No Recurring Fees',
                'Buy the bicycle repair guide once. No recurring fees or subscriptions apply.'],
        ];
    }

    #[DataProvider('supportedFeeClaims')]
    public function test_context_supported_qualified_or_genuinely_one_time_fee_claims_remain_approvable(string $headline, string $source): void
    {
        [$campaign, $strategy] = $this->campaign();
        $this->setOfferSource($campaign, $source);
        $copy = $this->clearCopy();
        $copy['headlines'][1] = $headline;
        $model = $this->createMock(GeminiService::class);
        $model->expects($this->once())->method('generateContent')->willReturn(['text' => json_encode([
            'overall_score' => 88, 'factual_accuracy' => true, 'intent_relevance' => true,
            'feedback' => ['headlines' => [], 'descriptions' => []], 'blocking_issues' => [],
        ], JSON_THROW_ON_ERROR)]);
        $review = (new AdminMonitorService($model))->reviewAdCopy(new AdCopy([
            'strategy_id' => $strategy->id, 'platform' => 'Google Ads', ...$copy,
        ]));
        $this->assertSame('approved', $review['overall_status']);
        $this->assertTrue($review['programmatic_validation']['fee_scope']['is_valid']);
    }

    private function setOfferSource(Campaign $campaign, string $source): void
    {
        $campaign->customer->pages()->update(['content' => $source]);
    }

    public function test_the_advertised_owned_landing_page_is_included_before_source_limits_and_its_fee_scope_is_enforced(): void
    {
        [$campaign, $strategy] = $this->campaign();
        $landing = $campaign->customer->website.'/campaign-setup';
        $campaign->update(['landing_page_url' => $landing]);
        $page = $campaign->customer->pages()->create(['url' => $landing, 'title' => 'One-time campaign setup',
            'page_type' => 'service', 'content' => 'The campaign setup is a one-time payment. Advertising spend is additional.']);
        $page->forceFill(['updated_at' => now()->subYear()])->save();
        for ($index = 0; $index < 35; $index++) {
            $campaign->customer->pages()->create(['url' => $campaign->customer->website.'/other-service-'.$index,
                'title' => 'Other Service '.$index, 'page_type' => 'service', 'content' => 'A different ordinary service with no fee evidence.']);
        }
        Customer::factory()->create(['website' => $campaign->customer->website])->pages()->create([
            'url' => $landing, 'title' => 'Foreign source', 'content' => 'No recurring costs apply to any purchase.']);
        $evidence = app(\App\Services\Campaigns\AdvertisingEvidence::class)->context($campaign->fresh(), $strategy);
        $this->assertCount(8, $evidence['sources']);
        $this->assertSame($landing, $evidence['sources'][0]['url']);
        $this->assertStringContainsString('Advertising spend is additional.', $evidence['sources'][0]['content']);
        $this->assertNotContains('Foreign source', array_column($evidence['sources'], 'title'));

        $copy = $this->clearCopy();
        $copy['descriptions'][1] = 'Ad spend goes directly to Google with no markup or recurring charges.';
        $model = $this->createMock(GeminiService::class);
        $model->method('generateContent')->willReturn(['text' => json_encode(['overall_score' => 88,
            'factual_accuracy' => true, 'intent_relevance' => true, 'feedback' => [], 'blocking_issues' => []], JSON_THROW_ON_ERROR)]);
        $review = (new AdminMonitorService($model))->reviewAdCopy(new AdCopy([
            'strategy_id' => $strategy->id, 'platform' => 'Google Ads', ...$copy,
        ]));
        $this->assertSame('needs_revision', $review['overall_status']);
        $this->assertFalse($review['programmatic_validation']['fee_scope']['is_valid']);
    }

    private function campaign(): array
    {
        config(['cache.default' => 'array', 'ai.text_provider' => 'gemini']);
        Cache::put('gcp_vertex_access_token', 'fake-offer-clarity-token', 3000);
        $customer = Customer::factory()->create(['website' => 'https://bikes.example',
            'description' => 'Bicycle repair for local riders.', 'currency_code' => 'USD']);
        $customer->pages()->create(['url' => $customer->website, 'title' => 'Bicycle Repair',
            'content' => 'Bicycle repair for local riders. Book a repair to prepare your bike for your next ride.']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'product_focus' => 'Bicycle repair',
            'keywords' => ['bicycle repair']]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'ad_copy_strategy' => 'Promote our bicycle repair service.',
            'creative_candidates' => [['title' => 'Account handover']],
            'creative_concepts' => [['headline' => 'Workflow anchor headline', 'description' => 'Workflow anchor description.',
                'supporting_copy' => 'Copy this administrative message.', 'selling_idea' => 'Account administration is everything.',
                'subject' => 'A mechanic servicing a bicycle']]]);

        return [$campaign, $strategy];
    }

    private function fakeGeneration(bool $recover, bool $clearOpening = false): void
    {
        $attempt = 0;
        Http::fake(function (Request $request) use (&$attempt, $recover, $clearOpening) {
            $prompt = $request['contents'][0]['parts'][0]['text'];
            $review = str_starts_with($prompt, 'Review the following ad copy');
            if ($review) {
                $this->assertStringContainsString('Offer clarity is a required intent check, not a style preference.', $prompt);
                $this->assertStringContainsString('Set intent_relevance=false', $prompt);
                $this->assertStringContainsString('The first headline must name that offer', $prompt);
                $this->assertStringContainsString('the first description must explain the offer and supported customer value', $prompt);
                $this->assertStringContainsString('Negative example: for a bicycle repair service', $prompt);
                $this->assertStringContainsString('Positive example:', $prompt);
                $this->assertStringContainsString('Bicycle Repair Near You', $prompt);
                $this->assertStringContainsString('a clear first asset cannot excuse an ambiguous or process-dominated remainder', $prompt);
                $this->assertStringContainsString('at most ONE headline may be mainly administrative workflow', $prompt);
                $this->assertStringContainsString('Use feedback.headlines for one numbered entry per headline', $prompt);
                $clear = $recover && $attempt > 1;
                $data = ['overall_score' => $clear ? 62 : 99, 'factual_accuracy' => true,
                    'intent_relevance' => $clear, 'feedback' => ['headlines' => [], 'descriptions' => []],
                    'blocking_issues' => $clear ? [] : ['The headline set is dominated by account workflow instead of supported bicycle repair value.']];
            } else {
                $attempt++;
                $this->assertStringContainsString('The first headline must clearly name the actual product or service', $prompt);
                $this->assertStringNotContainsString('Workflow anchor headline', $prompt);
                $this->assertStringNotContainsString('Workflow anchor description.', $prompt);
                $this->assertStringNotContainsString('Copy this administrative message.', $prompt);
                $this->assertStringNotContainsString('Account administration is everything.', $prompt);
                $this->assertStringContainsString('A mechanic servicing a bicycle', $prompt);
                $this->assertStringNotContainsString('concepts, IN ORDER', $prompt);
                if ($attempt > 1) {
                    $this->assertStringContainsString('CRITICAL CORRECTIONS REQUIRED', $prompt);
                    if (! $recover || $attempt === 2) {
                        $this->assertStringContainsString('The headline set is dominated by account workflow', $prompt);
                    }
                }
                $data = $recover && $attempt > 1 ? $this->clearCopy() : $this->processCopy($clearOpening);
            }

            return Http::response([['candidates' => [['content' => ['parts' => [['text' => json_encode($data, JSON_THROW_ON_ERROR)]]],
                'finishReason' => 'STOP']]]]);
        });
    }

    private function clearCopy(): array
    {
        return ['headlines' => ['Bicycle Repair Near You', 'Bike Repairs for Local Riders', 'Get Your Bike Ready to Ride',
            'Book Your Bicycle Repair', 'Care for Your Next Ride'],
            'descriptions' => ['Book a bicycle repair to get your bike ready for your next ride.',
                'Bicycle repair for local riders. Explore the service and book your repair.',
                'Get help with your bicycle repair. See what the service includes.']];
    }

    private function processCopy(bool $clearOpening = false): array
    {
        return ['headlines' => [$clearOpening ? 'Bicycle Repair Near You' : 'Your Account, Your Rules', 'Ready for Handover', 'Review Before Launch',
            'Keep Account Control', 'Your Next Step Starts Here'],
            'descriptions' => [$clearOpening ? 'Book a bicycle repair to get your bike ready for your next ride.' : 'Review your account and approve the handover when you are ready.',
                'See your account details in one place and choose your next step.',
                'Control your account access and review your settings before launch.']];
    }
}
