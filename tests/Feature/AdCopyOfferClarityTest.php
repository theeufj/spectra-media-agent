<?php

namespace Tests\Feature;

use App\Jobs\GenerateAdCopy;
use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Prompts\AdCopyGenerationPolicy;
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

    #[DataProvider('platforms')]
    public function test_initial_generation_uses_the_shared_factual_policy_before_untrusted_brief_data(string $platform): void
    {
        $prompt = (new AdCopyPrompt('Reuse our old headline: No Recurring Fees.', $platform,
            evidence: ['landing_page' => 'https://agency.example/setup', 'sources' => [[
                'url' => 'https://agency.example/setup',
                'content' => 'Setup costs EUR120 once. Advertising spend is billed separately.',
            ]]]))->getPrompt();

        $this->assertStringContainsString(AdCopyGenerationPolicy::instructions(), $prompt);
        $this->assertLessThan(strpos($prompt, 'Reuse our old headline:'), strpos($prompt, '--- SOURCE-BACKED CLAIMS'));
        $this->assertStringContainsString('same headline or description', $prompt);
        $this->assertStringContainsString('A fee waiver needs explicit source support; a single setup price alone is not enough', $prompt);
        $this->assertStringContainsString('never infer the offer currency from the advertising account or its budget', $prompt);
        $this->assertStringContainsString('If an accurate qualification cannot fit', $prompt);
        $this->assertStringContainsString('Check every retained claim against the current offer sources, even if it previously served', $prompt);
        $this->assertStringContainsString('do not invent ongoing costs', $prompt);
        $this->assertStringContainsString('Setup costs EUR120 once.', $prompt);
        $this->assertStringNotContainsString('sitetospend', strtolower($prompt));
        $this->assertStringNotContainsString('$999', $prompt);
    }

    public function test_initial_generation_corrects_a_fee_claim_from_reviewer_feedback_before_saving(): void
    {
        [$campaign, $strategy] = $this->campaign();
        $campaign->update(['product_focus' => 'Advertising campaign setup']);
        $strategy->update(['ad_copy_strategy' => 'Explain the campaign setup service.']);
        $this->setOfferSource($campaign, 'Campaign setup costs EUR120 once with no recurring agency fees. '
            .'The service researches buyer keywords and writes the ads for you. Advertising spend is paid separately.');
        $corrected = ['headlines' => ['Advertising Campaign Setup', 'No Recurring Agency Fees', 'Keywords for Your Buyers',
            'Ad Copy Written for You', 'EUR120 Campaign Setup'],
            'descriptions' => ['Get buyer keyword research and ad copy with our campaign setup service.',
                'EUR120 is the one-time setup service fee. Advertising spend is paid separately.',
                'No recurring agency fees. Explore the campaign setup service and what it includes.']];
        $bad = $corrected;
        $bad['headlines'][1] = 'No Recurring Fees';
        $bad['headlines'][2] = str_repeat('界', 301);
        $generations = 0;
        $reviews = 0;
        Http::fake(function (Request $request) use (&$generations, &$reviews, $bad, $corrected) {
            $prompt = $request['contents'][0]['parts'][0]['text'];
            if (str_starts_with($prompt, 'Review the following ad copy')) {
                $reviews++;
                if ($reviews === 1) {
                    $this->assertStringContainsString('fee_scope_misleading_recurring_claim', $prompt);
                }
                // The factual validator must reject the first candidate even
                // when the model reviewer misses the fee problem.
                $data = ['overall_score' => 88, 'factual_accuracy' => true, 'intent_relevance' => true,
                    'feedback' => ['headlines' => [], 'descriptions' => []], 'blocking_issues' => []];
            } else {
                $generations++;
                $this->assertStringContainsString(AdCopyGenerationPolicy::instructions(), $prompt);
                if ($generations === 2) {
                    $this->assertStringContainsString('CRITICAL CORRECTIONS REQUIRED', $prompt);
                    $this->assertStringContainsString('Qualify any fee waiver as the agency/service fee', $prompt);
                    $this->assertStringContainsString('Another asset cannot qualify this claim', $prompt);
                    $this->assertStringContainsString('Campaign setup costs EUR120 once', $prompt);
                    $this->assertStringContainsString('rejected_candidate_excerpt', $prompt);
                    $this->assertStringContainsString('fee_scope', $prompt);
                    $this->assertStringContainsString('"is_valid": false', $prompt);
                    $corrections = explode("--- CRITICAL CORRECTIONS REQUIRED ---\n", $prompt, 2)[1];
                    $start = strpos($corrections, '{');
                    $end = strrpos($corrections, '}');
                    $feedback = json_decode(substr($corrections, $start, $end - $start + 1), true, flags: JSON_THROW_ON_ERROR);
                    $this->assertSame('No Recurring Fees', $feedback['rejected_candidate_excerpt']['headlines'][1]);
                    $this->assertSame(str_repeat('界', 300), $feedback['rejected_candidate_excerpt']['headlines'][2]);
                    $this->assertFalse($feedback['fee_scope']['is_valid']);
                }
                $data = $generations === 1 ? $bad : $corrected;
            }

            return Http::response([['candidates' => [['content' => ['parts' => [['text' => json_encode($data, JSON_THROW_ON_ERROR)]]],
                'finishReason' => 'STOP']]]]);
        });

        $job = (new GenerateAdCopy($campaign, $strategy, 'Google Ads'))->withFakeQueueInteractions();
        $job->handle();

        $job->assertNotFailed();
        $this->assertSame(2, $generations);
        $this->assertSame(2, $reviews);
        $this->assertSame($corrected, $strategy->adCopies()->sole()->only(['headlines', 'descriptions']));
        Http::assertSentCount(4);
    }

    public function test_initial_generation_forwards_json_temperature_and_platform_neutral_limits_to_xai(): void
    {
        [$campaign, $strategy] = $this->campaign();
        config(['ai.text_provider' => 'xai', 'services.xai.api_key' => 'test-copy-routing-key']);
        Cache::forget('xai:unavailable');
        $copy = ['headlines' => ['Local Bicycle Repairs', 'Get Ready for Your Next Ride', 'Repairs for Your Daily Commute', 'Book a Bicycle Repair', 'Bicycle Repairs for Riders'],
            'descriptions' => ['Book a bicycle repair to prepare for your next ride.',
                'Get your bicycle repaired for your daily commute.', 'Explore our bicycle repair service for local riders.']];
        $generations = 0;
        Http::fake(function (Request $request) use (&$generations, $copy) {
            $this->assertSame('https://api.x.ai/v1/chat/completions', $request->url());
            $messages = $request['messages'];
            $prompt = collect($messages)->firstWhere('role', 'user')['content'];
            if (str_starts_with($prompt, 'Review the following ad copy')) {
                $data = ['overall_score' => 88, 'factual_accuracy' => true, 'intent_relevance' => true,
                    'feedback' => ['headlines' => [], 'descriptions' => []], 'blocking_issues' => []];
            } else {
                $generations++;
                $this->assertSame(['type' => 'json_object'], $request['response_format']);
                $this->assertSame(0.2, $request['temperature']);
                $system = collect($messages)->firstWhere('role', 'system')['content'];
                $this->assertStringContainsString('requested platform asset counts and Unicode character limits', $system);
                $this->assertStringContainsString('Never remove an agency/service fee qualifier or source currency', $system);
                $this->assertStringNotContainsString('30 Unicode', $system, 'Initial generation supports platforms with different limits.');
                $data = $copy;
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode($data, JSON_THROW_ON_ERROR)]]]]);
        });

        $job = (new GenerateAdCopy($campaign, $strategy, 'Google Ads'))->withFakeQueueInteractions();
        $job->handle();

        $job->assertNotFailed();
        $this->assertSame(1, $generations);
        $this->assertSame($copy, $strategy->adCopies()->sole()->only(['headlines', 'descriptions']));
        Http::assertSentCount(2);
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
                    $this->assertStringContainsString('rejected_candidate_excerpt', $prompt);
                    if ($attempt === 2) {
                        $this->assertStringContainsString('"intent_relevance": false', $prompt);
                        $this->assertStringContainsString('Ready for Handover', $prompt);
                    }
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
