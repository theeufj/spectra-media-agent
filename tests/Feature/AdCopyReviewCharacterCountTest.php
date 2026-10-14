<?php

namespace Tests\Feature;

use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\AdminMonitorService;
use App\Services\Campaigns\AdvertisingEvidence;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdCopyReviewCharacterCountTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_verified_thirty_character_headline_is_not_blocked_by_the_production_model_miscount(): void
    {
        $blocker = "Headline 'Google Ads Campaign Management' exceeds 30-character limit";
        $style = "One headline exceeds Google's 30-character limit: 'Google Ads Campaign Management' (31 chars)";
        $review = $this->review([$blocker], feedback: ['headlines' => [$style, 'Use a clearer value proposition.'], 'descriptions' => ['Reduce repetition.']]);

        $this->assertSame('approved', $review['overall_status']);
        $this->assertTrue($review['programmatic_validation']['is_valid']);
        $this->assertSame([], $review['gemini_feedback']['blocking_issues']);
        $this->assertSame([$blocker], $review['gemini_feedback']['reported_blocking_issues']);
        $this->assertSame([$style, 'Use a clearer value proposition.'], $review['gemini_feedback']['feedback']['headlines']);
        $this->assertSame(78, $review['gemini_feedback']['overall_score']);
        $this->assertSame('model_false_positive', $review['warnings'][0]['code']);
        $verified = $review['gemini_feedback']['verified_length_false_positives'][0];
        $this->assertSame(30, $verified['characters']);
        $this->assertSame(30, $verified['max_characters']);
        $this->assertSame('verified_character_count_within_platform_limits', $verified['reason']);
    }

    public static function overlengthReviews(): array
    {
        return [
            'model identifies the true length failure' => [["Headline 'Google Ads Campaign Management!' exceeds 30-character limit"]],
            'model overlooks the true length failure' => [[]],
        ];
    }

    #[DataProvider('overlengthReviews')]
    public function test_a_real_thirty_one_character_headline_is_rejected_by_code_regardless_of_the_model(array $blockers): void
    {
        $review = $this->review($blockers, firstHeadline: 'Google Ads Campaign Management!');

        $this->assertSame('needs_revision', $review['overall_status']);
        $this->assertFalse($review['programmatic_validation']['is_valid']);
        $this->assertSame(31, $review['programmatic_validation']['numeric_policy']['headlines']['assets'][0]['characters']);
        $this->assertSame($blockers, $review['gemini_feedback']['blocking_issues']);
        $this->assertSame([], $review['warnings']);
    }

    public function test_an_unsupported_currency_claim_remains_blocking_alongside_a_false_length_objection(): void
    {
        $length = "Headline 'Google Ads Campaign Management' exceeds 30-character limit";
        $currency = 'The A$149 price contradicts the source price in US dollars.';
        $review = $this->review([$length, $currency], factualAccuracy: false, secondHeadline: 'Management For A$149/Month');

        $this->assertSame('needs_revision', $review['overall_status']);
        $this->assertSame([$currency], $review['gemini_feedback']['blocking_issues']);
        $this->assertFalse($review['gemini_feedback']['factual_accuracy']);
        $this->assertFalse($review['programmatic_validation']['is_valid']);
        $this->assertSame('model_false_positive', $review['warnings'][0]['code']);
    }

    public static function unrecognizedBlockers(): array
    {
        return [
            'unknown policy reason' => ['A platform policy issue requires review.'],
            'mixed length and factual objection' => ["Headline 'Google Ads Campaign Management' exceeds 30-character limit and makes an unsupported guarantee."],
            'different quoted candidate' => ["Headline 'Google Ads Management Campaign' exceeds 30-character limit"],
            'different numeric policy' => ["Headline 'Google Ads Campaign Management' exceeds 25-character limit"],
            'unquoted length objection' => ['One headline exceeds the character limit.'],
        ];
    }

    #[DataProvider('unrecognizedBlockers')]
    public function test_unknown_or_non_length_blockers_cannot_be_dismissed_by_numeric_validation(string $blocker): void
    {
        $review = $this->review([$blocker]);

        $this->assertSame('needs_revision', $review['overall_status']);
        $this->assertSame([$blocker], $review['gemini_feedback']['blocking_issues']);
        $this->assertSame([], $review['warnings']);
    }

    public static function failedEvidenceReviews(): array
    {
        return ['factual review fails' => [false, true], 'intent review fails' => [true, false]];
    }

    #[DataProvider('failedEvidenceReviews')]
    public function test_a_false_length_alarm_does_not_override_factual_or_intent_failure(bool $facts, bool $intent): void
    {
        $review = $this->review(["Headline 'Google Ads Campaign Management' exceeds 30-character limit"], factualAccuracy: $facts, intentRelevance: $intent);

        $this->assertSame('needs_revision', $review['overall_status']);
        $this->assertFalse($review['programmatic_validation']['is_valid']);
        $this->assertSame($facts, $review['gemini_feedback']['factual_accuracy']);
        $this->assertSame($intent, $review['gemini_feedback']['intent_relevance']);
    }

    private function review(array $blockers, string $firstHeadline = 'Google Ads Campaign Management', bool $factualAccuracy = true, bool $intentRelevance = true, array $feedback = [], string $secondHeadline = 'Google Ads Management'): array
    {
        $evidence = $this->createMock(AdvertisingEvidence::class);
        $evidence->method('context')->willReturn(['sources' => [['url' => 'https://example.com/pricing', 'content' => 'Google Ads management from US$149/month. Advertising spend is additional.']]]);
        $this->app->instance(AdvertisingEvidence::class, $evidence);
        $strategy = new Strategy;
        $strategy->setRelation('campaign', new Campaign);
        $ad = new AdCopy(['platform' => 'google', 'headlines' => [$firstHeadline, $secondHeadline, 'Review Before You Launch'],
            'descriptions' => ['Review keywords and Google Ads copy before launch.', 'Managed campaigns include ongoing monitoring.']]);
        $ad->setRelation('strategy', $strategy);
        $model = $this->createMock(GeminiService::class);
        $model->expects($this->once())->method('generateContent')->with(config('ai.models.default'), $this->callback(function (string $prompt) use ($firstHeadline): bool {
            $this->assertStringContainsString('Numerical platform rules are checked by deterministic code.', $prompt);
            $this->assertStringContainsString('Do not add character-count objections to blocking_issues.', $prompt);
            $this->assertStringContainsString(json_encode(['text' => $firstHeadline, 'characters' => mb_strlen($firstHeadline)], JSON_THROW_ON_ERROR), $prompt);
            $this->assertStringContainsString('"max_characters":30', $prompt);
            $this->assertStringContainsString('"max_characters":90', $prompt);

            return true;
        }))->willReturn(['text' => json_encode(['overall_score' => 78, 'feedback' => $feedback,
            'factual_accuracy' => $factualAccuracy, 'intent_relevance' => $intentRelevance, 'blocking_issues' => $blockers], JSON_THROW_ON_ERROR)]);

        return (new AdminMonitorService($model))->reviewAdCopy($ad, true);
    }
}
