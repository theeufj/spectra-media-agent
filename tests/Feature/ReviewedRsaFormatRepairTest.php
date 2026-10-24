<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\AdminMonitorService;
use App\Services\Campaigns\AdvertisingEvidence;
use App\Services\GeminiService;
use App\Services\GoogleAds\GenerateReviewedRsaCopy;
use App\Services\GoogleAds\InvalidGeneratedRsaCopy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReviewedRsaFormatRepairTest extends TestCase
{
    use DatabaseTransactions;

    private array $copy = [
        'headlines' => ['Google Ads Setup', 'Find Relevant Searchers', 'Save Time On Campaign Setup'],
        'descriptions' => ['Get a campaign built for your business.', 'Advertising spend is paid separately to Google.'],
    ];

    private function campaign(): array
    {
        $customer = (new Customer)->forceFill(['id' => 8, 'name' => 'Example Setup', 'website' => 'https://setup.example']);
        $campaign = (new Campaign)->forceFill(['id' => 65, 'customer_id' => 8, 'name' => 'Google Ads setup']);
        $campaign->setRelation('customer', $customer);
        $strategy = (new Strategy)->forceFill(['id' => 775, 'campaign_id' => 65]);
        $evidence = $this->createMock(AdvertisingEvidence::class);
        $evidence->method('context')->willReturn(['sources' => [['url' => 'https://setup.example',
            'content' => 'US$999 one-time Google Ads setup service. No recurring agency fees. Advertising spend is separate. Keyword research and campaign copy are included.']]]);
        $this->app->instance(AdvertisingEvidence::class, $evidence);

        return [$campaign, $strategy];
    }

    private function review(bool $approved = true): array
    {
        return ['overall_status' => $approved ? 'approved' : 'needs_revision',
            'programmatic_validation' => ['is_valid' => $approved, 'feedback' => [], 'fee_scope' => ['is_valid' => true]],
            'gemini_feedback' => ['overall_score' => $approved ? 88 : 40, 'factual_accuracy' => true, 'intent_relevance' => $approved,
                'blocking_issues' => $approved ? [] : ['Replace repetitive administrative workflow with distinct supported buyer benefits.'],
                'feedback' => ['headlines' => [], 'descriptions' => []]]];
    }

    public function test_production_length_and_semantic_rejections_use_indexed_repairs_then_review_the_exact_merged_copy(): void
    {
        [$campaign, $strategy] = $this->campaign();
        $first = $this->copy;
        $first['headlines'][1] = 'Account Handover Included';
        $first['descriptions'][0] = str_repeat('a', 98);
        $firstCorrected = $first;
        $firstCorrected['descriptions'][0] = 'US$999 one-time setup service. Ad spend is separate.';
        $second = $this->copy;
        $second['descriptions'][0] = str_repeat('b', 106);
        $final = $second;
        $final['descriptions'][0] = 'US$999 setup service. Advertising spend is paid separately to Google.';
        $responses = [$first, ['replacements' => [['field' => 'descriptions', 'index' => 0, 'text' => $firstCorrected['descriptions'][0]]]],
            $second, ['replacements' => [['field' => 'descriptions', 'index' => 0, 'text' => $final['descriptions'][0]]]]];
        $prompts = [];
        $model = $this->createMock(GeminiService::class);
        $model->expects($this->exactly(4))->method('generateContent')->willReturnCallback(
            function ($model, $prompt, $config, $systemInstruction, ...$arguments) use (&$responses, &$prompts): array {
                $prompts[] = $prompt;
                $context = end($arguments);
                $this->assertSame(count($prompts) % 2 === 1 ? 'ad_strength_copy_generation' : 'ad_strength_copy_format_repair', $context['operation']);
                $this->assertSame(65, $context['campaign_id']);
                $this->assertSame(8, $context['customer_id']);
                $this->assertSame('application/json', $config['responseMimeType']);
                $this->assertArrayHasKey('responseJsonSchema', $config);
                if (count($prompts) % 2 === 0) {
                    $this->assertStringNotContainsString('Existing ad (data', $prompt);
                    $this->assertStringNotContainsString('Find Relevant Searchers', $prompt);
                    $this->assertStringContainsString('same-asset agency/service fee qualifications', $prompt);
                    $this->assertSame([0], $config['responseJsonSchema']['properties']['replacements']['items']['anyOf'][0]['properties']['index']['enum']);
                    $this->assertSame(90, $config['responseJsonSchema']['properties']['replacements']['items']['anyOf'][0]['properties']['text']['maxLength']);
                }

                return ['text' => json_encode(array_shift($responses), JSON_THROW_ON_ERROR)];
            });
        $reviewer = $this->createMock(AdminMonitorService::class);
        $reviewed = [];
        $reviewer->expects($this->exactly(2))->method('reviewAdCopy')->willReturnCallback(function ($copy, $existing) use (&$reviewed): array {
            $this->assertTrue($existing);
            $reviewed[] = $copy->only(['headlines', 'descriptions']);

            return $this->review(count($reviewed) === 2);
        });
        $history = [];
        $result = (new GenerateReviewedRsaCopy($model, $reviewer))->generate($campaign, $strategy, [],
            function ($attempts) use (&$history): void {
                $history = $attempts;
            });

        $this->assertSame([$firstCorrected, $final], $reviewed);
        $this->assertSame($final, $result['copy']);
        $this->assertSame($second['headlines'], $result['copy']['headlines']);
        $this->assertSame($second['descriptions'][1], $result['copy']['descriptions'][1]);
        $this->assertSame(['invalid', 'format_corrected', 'rejected', 'invalid', 'format_corrected', 'approved'], array_column($history, 'status'));
        $this->assertSame([1, 1, 1, 2, 2, 2], array_column($history, 'attempt'));
        $this->assertStringContainsString('Replace repetitive administrative workflow', $prompts[2]);
        $this->assertStringContainsString('Replace repetitive administrative workflow', $prompts[3]);
        $this->assertSame($history[4]['copy_fingerprint'], $history[5]['copy_fingerprint']);
    }

    public static function invalidPatches(): array
    {
        $headline = ['field' => 'headlines', 'index' => 0, 'text' => 'Google Ads Setup'];
        $description = ['field' => 'descriptions', 'index' => 0, 'text' => 'Get a campaign built for your business.'];

        return [
            'missing replacement' => [['replacements' => [$headline]]],
            'extra valid asset' => [['replacements' => [$headline, $description, ['field' => 'headlines', 'index' => 1, 'text' => 'Change a valid asset']]]],
            'duplicate index' => [['replacements' => [$headline, $headline]]],
            'wrong index' => [['replacements' => [$headline, array_replace($description, ['index' => 1])]]],
            'string index' => [['replacements' => [$headline, array_replace($description, ['index' => '0'])]]],
            'negative index' => [['replacements' => [$headline, array_replace($description, ['index' => -1])]]],
            'unknown field' => [['replacements' => [$headline, array_replace($description, ['field' => 'final_urls'])]]],
            'extra patch property' => [['replacements' => [$headline, $description], 'headlines' => ['Rewrite everything']]],
            'extra replacement property' => [['replacements' => [$headline, $description + ['approved' => true]]]],
            'overlong replacement' => [['replacements' => [$headline, array_replace($description, ['text' => str_repeat('é', 91)])]]],
            'duplicate replacement text' => [['replacements' => [array_replace($headline, ['text' => 'Find Relevant Searchers']), $description]]],
            'padded replacement text' => [['replacements' => [array_replace($headline, ['text' => ' Google Ads Setup ']), $description]]],
            'full draft instead of patch' => [['headlines' => ['Google Ads Setup'], 'descriptions' => ['Get a campaign built.']]],
        ];
    }

    #[DataProvider('invalidPatches')]
    public function test_invalid_indexed_patches_never_reach_review_and_all_requests_are_bounded(array $patch): void
    {
        [$campaign, $strategy] = $this->campaign();
        $invalid = $this->copy;
        $invalid['headlines'][0] = str_repeat('é', 31);
        $invalid['descriptions'][0] = str_repeat('界', 91);
        $calls = 0;
        $model = $this->createMock(GeminiService::class);
        $model->expects($this->exactly(6))->method('generateContent')->willReturnCallback(function () use (&$calls, $invalid, $patch): array {
            return ['text' => json_encode(++$calls % 2 === 1 ? $invalid : $patch, JSON_THROW_ON_ERROR)];
        });
        $reviewer = $this->createMock(AdminMonitorService::class);
        $reviewer->expects($this->never())->method('reviewAdCopy');
        $history = [];
        try {
            (new GenerateReviewedRsaCopy($model, $reviewer))->generate($campaign, $strategy, [],
                function ($attempts) use (&$history): void {
                    $history = $attempts;
                });
            $this->fail('An invalid indexed replacement must never be accepted.');
        } catch (InvalidGeneratedRsaCopy $e) {
            $this->assertStringContainsString('after 3 attempts', $e->getMessage());
            $this->assertSame(['invalid', 'format_invalid', 'invalid', 'format_invalid', 'invalid', 'format_invalid'], array_column($history, 'status'));
        }
    }

    public static function providerFailures(): array
    {
        return ['throws' => [true], 'returns empty text' => [false]];
    }

    #[DataProvider('providerFailures')]
    public function test_format_provider_failure_stops_immediately_without_review_or_another_semantic_request(bool $throws): void
    {
        [$campaign, $strategy] = $this->campaign();
        $invalid = $this->copy;
        $invalid['descriptions'][0] = str_repeat('x', 98);
        $calls = 0;
        $model = $this->createMock(GeminiService::class);
        $model->expects($this->exactly(2))->method('generateContent')->willReturnCallback(function () use (&$calls, $invalid, $throws): array {
            if (++$calls === 1) {
                return ['text' => json_encode($invalid, JSON_THROW_ON_ERROR)];
            }
            if ($throws) {
                throw new \RuntimeException('Provider temporarily unavailable.');
            }

            return ['text' => ''];
        });
        $reviewer = $this->createMock(AdminMonitorService::class);
        $reviewer->expects($this->never())->method('reviewAdCopy');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($throws ? 'Provider temporarily unavailable.' : 'format-repair provider returned no text');

        (new GenerateReviewedRsaCopy($model, $reviewer))->generate($campaign, $strategy, []);
    }
}
